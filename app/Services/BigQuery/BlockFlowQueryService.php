<?php

namespace App\Services\BigQuery;

use App\Models\Project;
use App\Models\Segment;
use App\Services\Attribution\MarkovAttributionSolver;
use Carbon\Carbon;
use Google\Cloud\Core\Exception\ServiceException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class BlockFlowQueryService
{
    private const START = '__start__';

    private const SYNTHETIC = ['__start__', '__conversion__', '__null__', '__other__'];

    private const MAX_CARDS_PER_COLUMN = 8;

    public function __construct(
        private readonly BigQueryClientFactory $clientFactory,
        private readonly SegmentFilterBuilder $segmentFilter,
        private readonly MarkovAttributionSolver $solver
    ) {
    }

    public function fetch(Project $project, Carbon $start, Carbon $end, ?Segment $segment = null, ?string $sourceChannel = null): array
    {
        if (! $project->goal_path || ! $project->goal_event_type) {
            throw new RuntimeException('Set a conversion goal for this project before viewing the block view.');
        }

        $sourceChannel = $sourceChannel !== null && $sourceChannel !== '' ? $sourceChannel : null;

        $ttl = config('bigquery.block_flow_cache_ttl', config('bigquery.country_sessions_cache_ttl', 3600));

        if ($ttl <= 0) {
            return $this->fetchFromBigQuery($project, $start, $end, $segment, $sourceChannel);
        }

        $cacheKey = $this->cacheKey($project, $start, $end, $segment, $sourceChannel);

        return Cache::remember($cacheKey, $ttl, fn () => $this->fetchFromBigQuery($project, $start, $end, $segment, $sourceChannel));
    }

    private function cacheKey(Project $project, Carbon $start, Carbon $end, ?Segment $segment, ?string $sourceChannel): string
    {
        $project->loadMissing('bigQueryConnection');
        $connectionStamp = $project->bigQueryConnection?->updated_at?->timestamp ?? 0;

        return sprintf(
            'block_flow:v2:%d:%s:%s:%s:%s:%d:%d:%d:%s',
            $project->id,
            $start->toDateString(),
            $end->toDateString(),
            $project->goal_path,
            $project->goal_event_type,
            $connectionStamp,
            $segment?->id ?? 0,
            $segment?->updated_at?->timestamp ?? 0,
            $sourceChannel ?? 'all'
        );
    }

    private function fetchFromBigQuery(Project $project, Carbon $start, Carbon $end, ?Segment $segment = null, ?string $sourceChannel = null): array
    {
        $connection = $this->clientFactory->connectionFor($project);
        $client = $this->clientFactory->forProject($project);

        $eventsTable = config('bigquery.ga4.events_table', 'events_*');
        $pageParam = config('bigquery.ga4.page_param', 'page_location');
        $maxPages = (int) config('bigquery.block_flow_max_pages', 40);
        $sourceCap = (int) config('bigquery.block_flow_source_cap', 100);
        $goalIsEvent = $project->goal_event_type === 'Event';

        [$filterSource, $filterMedium] = $sourceChannel !== null
            ? array_pad(explode('|', $sourceChannel, 2), 2, '')
            : ['', ''];

        $tableFqn = sprintf(
            '`%s.%s.%s`',
            $connection->gcp_project_id,
            $connection->dataset_id,
            $eventsTable
        );

        $segmentConditions = $segment?->conditions;
        $ctes = $this->transitionCtes($tableFqn, '@start_suffix', '@end_suffix', $segmentConditions);

        $sql = <<<SQL
WITH
{$ctes}
SELECT from_state, to_state, cnt FROM transitions
UNION ALL
SELECT page_path AS from_state, '__views__' AS to_state, COUNT(*) AS cnt
FROM page_sessions
WHERE page_path IN (SELECT page_path FROM top_pages)
GROUP BY page_path
SQL;

        $optionsSql = <<<SQL
WITH
{$ctes}
SELECT s.source AS source, s.medium AS medium, COUNT(DISTINCT p.session_key) AS sessions
FROM (SELECT DISTINCT session_key FROM pv) p
JOIN session_src s ON s.session_key = p.session_key
GROUP BY s.source, s.medium
ORDER BY sessions DESC
LIMIT @source_cap
SQL;

        $baseParameters = [
            'start_suffix' => $start->format('Ymd'),
            'end_suffix' => $end->format('Ymd'),
            'page_param' => $pageParam,
            'goal_path' => $project->goal_path,
            'goal_is_event' => $goalIsEvent,
            'max_pages' => $maxPages,
            'source_cap' => $sourceCap,
            'filter_source' => $filterSource,
            'filter_medium' => $filterMedium,
            ...$this->segmentFilter->params($segmentConditions),
        ];

        $flowParameters = [...$baseParameters, 'source_filter' => $sourceChannel !== null];
        $optionsParameters = [...$baseParameters, 'source_filter' => false];

        try {
            $results = $client->runQuery(
                $client->query($sql)->parameters($flowParameters)
            );
            $sourceResults = $client->runQuery(
                $client->query($optionsSql)->parameters($optionsParameters)
            );
        } catch (ServiceException $exception) {
            throw new RuntimeException(
                'BigQuery query failed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        return $this->formatResponse($project, $start, $end, $results, $sourceResults);
    }

    private function transitionCtes(
        string $tableFqn,
        string $startParam,
        string $endParam,
        ?array $segmentConditions
    ): string {
        $segmentFilter = $this->segmentFilter->sessionPredicate(
            $segmentConditions,
            $tableFqn,
            $startParam,
            $endParam,
            "CONCAT(user_pseudo_id, '-', ga_session_id)"
        );

        return <<<SQL
session_src AS (
  SELECT
    CONCAT(user_pseudo_id, '-', ga_session_id) AS session_key,
    ANY_VALUE(source) AS source,
    ANY_VALUE(medium) AS medium
  FROM (
    SELECT
      user_pseudo_id,
      COALESCE(CAST((SELECT value.int_value FROM UNNEST(events.event_params) WHERE key = 'ga_session_id') AS STRING), '') AS ga_session_id,
      COALESCE(events.traffic_source.source, '(direct)') AS source,
      COALESCE(events.traffic_source.medium, '(none)') AS medium
    FROM {$tableFqn} AS events
    WHERE _TABLE_SUFFIX BETWEEN {$startParam} AND {$endParam}
  )
  WHERE ga_session_id != ''
  GROUP BY session_key
),
pv AS (
  SELECT
    CONCAT(user_pseudo_id, '-', ga_session_id) AS session_key,
    user_pseudo_id,
    ga_session_id,
    event_timestamp,
    page_path
  FROM (
    SELECT
      user_pseudo_id,
      COALESCE(CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key = 'ga_session_id') AS STRING), '') AS ga_session_id,
      event_timestamp,
      COALESCE(
        NULLIF(REGEXP_EXTRACT(
          (SELECT value.string_value FROM UNNEST(event_params) WHERE key = @page_param),
          r'https?://[^/]+(/[^?#]*)'
        ), ''),
        NULLIF((SELECT value.string_value FROM UNNEST(event_params) WHERE key = @page_param), '')
      ) AS page_path
    FROM {$tableFqn}
    WHERE _TABLE_SUFFIX BETWEEN {$startParam} AND {$endParam}
      AND event_name = 'page_view'
  )
  WHERE page_path IS NOT NULL AND page_path != '' AND ga_session_id != ''{$segmentFilter}
    AND (
      NOT @source_filter
      OR CONCAT(user_pseudo_id, '-', ga_session_id) IN (
        SELECT session_key FROM session_src
        WHERE source = @filter_source AND medium = @filter_medium
      )
    )
),
conv AS (
  SELECT DISTINCT CONCAT(user_pseudo_id, '-', ga_session_id) AS session_key
  FROM (
    SELECT
      user_pseudo_id,
      COALESCE(CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key = 'ga_session_id') AS STRING), '') AS ga_session_id,
      event_name,
      COALESCE(
        NULLIF(REGEXP_EXTRACT(
          (SELECT value.string_value FROM UNNEST(event_params) WHERE key = @page_param),
          r'https?://[^/]+(/[^?#]*)'
        ), ''),
        NULLIF((SELECT value.string_value FROM UNNEST(event_params) WHERE key = @page_param), '')
      ) AS normalized_path
    FROM {$tableFqn}
    WHERE _TABLE_SUFFIX BETWEEN {$startParam} AND {$endParam}
  )
  WHERE ga_session_id != ''
    AND (
      (@goal_is_event AND event_name = @goal_path)
      OR (NOT @goal_is_event AND event_name = 'page_view' AND normalized_path = @goal_path)
    )
),
page_sessions AS (
  SELECT DISTINCT page_path, session_key FROM pv
),
top_pages AS (
  SELECT page_path FROM (
    SELECT page_path, COUNT(*) AS c
    FROM page_sessions
    GROUP BY page_path
    ORDER BY c DESC
    LIMIT @max_pages
  )
),
seq AS (
  SELECT
    pv.session_key,
    ARRAY_AGG(IF(t.page_path IS NULL, '__other__', pv.page_path) ORDER BY pv.event_timestamp) AS pages,
    IF(MAX(IF(c.session_key IS NULL, 0, 1)) = 1, 1, 0) AS converted
  FROM pv
  LEFT JOIN top_pages t ON t.page_path = pv.page_path
  LEFT JOIN conv c ON c.session_key = pv.session_key
  GROUP BY pv.session_key
),
transitions AS (
  SELECT from_state, to_state, COUNT(*) AS cnt
  FROM (
    SELECT
      IF(off = 0, '__start__', seq.pages[OFFSET(off - 1)]) AS from_state,
      IF(
        off = ARRAY_LENGTH(seq.pages),
        IF(seq.converted = 1, '__conversion__', '__null__'),
        seq.pages[OFFSET(off)]
      ) AS to_state
    FROM seq,
    UNNEST(GENERATE_ARRAY(0, ARRAY_LENGTH(seq.pages))) AS off
    WHERE ARRAY_LENGTH(seq.pages) > 0
  )
  GROUP BY from_state, to_state
)
SQL;
    }

    private function formatResponse(Project $project, Carbon $start, Carbon $end, iterable $results, iterable $sourceResults): array
    {
        $sources = $this->buildSources($sourceResults);

        $transitions = [];
        $views = [];
        $entrySet = [];
        $forward = [];

        foreach ($results as $row) {
            $from = (string) ($row['from_state'] ?? '');
            $to = (string) ($row['to_state'] ?? '');
            $count = (int) ($row['cnt'] ?? 0);

            if ($to === '__views__') {
                $views[$from] = $count;

                continue;
            }

            $transitions[] = ['from' => $from, 'to' => $to, 'count' => $count];

            if ($from === self::START && ! in_array($to, self::SYNTHETIC, true)) {
                $entrySet[$to] = true;
            }

            if (! in_array($from, self::SYNTHETIC, true) && ! in_array($to, self::SYNTHETIC, true) && $from !== $to) {
                $forward[$from][$to] = ($forward[$from][$to] ?? 0) + $count;
            }
        }

        $attribution = $this->solver->solve($transitions);

        $pages = array_keys($attribution);
        if ($pages === []) {
            return $this->emptyResponse($project, $start, $end, $sources);
        }

        $columns = $this->assignColumns($pages, $entrySet, $forward);
        [$nodes, $nodeIdByPage] = $this->buildNodes($pages, $columns, $views, $attribution, $forward);
        $edges = $this->buildEdges($nodes, $nodeIdByPage, $columns, $forward, $views, $attribution);

        return [
            'title' => 'Block View',
            'goal' => [
                'path' => $project->goal_path,
                'type' => $project->goal_event_type,
            ],
            'dateRange' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'sources' => $sources,
            'nodes' => array_values($nodes),
            'edges' => $edges,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, sessions: int}>
     */
    private function buildSources(iterable $results): array
    {
        $sources = [];

        foreach ($results as $row) {
            $source = (string) ($row['source'] ?? '');
            $medium = (string) ($row['medium'] ?? '');

            if ($source === '' && $medium === '') {
                continue;
            }

            $sources[] = [
                'value' => $source.'|'.$medium,
                'label' => $this->sourceLabel($source, $medium),
                'sessions' => (int) ($row['sessions'] ?? 0),
            ];
        }

        return $sources;
    }

    private function sourceLabel(string $source, string $medium): string
    {
        $normalizedSource = strtolower(trim($source));
        $normalizedMedium = strtolower(trim($medium));

        if (($normalizedSource === '(direct)' || $normalizedSource === '')
            && ($normalizedMedium === '(none)' || $normalizedMedium === '')) {
            return 'Direct';
        }

        $sourceLabel = $normalizedSource !== '' && $normalizedSource !== '(direct)'
            ? ucfirst($normalizedSource)
            : 'Direct';

        $mediumLabel = match (true) {
            in_array($normalizedMedium, ['cpc', 'ppc', 'cpm', 'paid', 'display', 'paidsearch', 'paid_search'], true),
                str_contains($normalizedMedium, 'paid'),
                str_contains($normalizedMedium, 'cpc') => 'Ad',
            $normalizedMedium === 'organic' => 'Organic',
            $normalizedMedium === '', $normalizedMedium === '(none)', $normalizedMedium === 'referral' => '',
            default => ucfirst($normalizedMedium),
        };

        return trim($sourceLabel.' '.$mediumLabel);
    }

    /**
     * Longest-path column assignment over forward edges, seeded from entry pages.
     *
     * @param  array<int, string>  $pages
     * @param  array<string, bool>  $entrySet
     * @param  array<string, array<string, int>>  $forward
     * @return array<string, int>
     */
    private function assignColumns(array $pages, array $entrySet, array $forward): array
    {
        $maxColumns = max(1, (int) config('bigquery.block_flow_max_columns', 6));
        $columns = [];

        foreach ($pages as $page) {
            $columns[$page] = isset($entrySet[$page]) ? 0 : null;
        }

        for ($pass = 0; $pass < $maxColumns; $pass++) {
            $changed = false;

            foreach ($forward as $from => $targets) {
                if (! array_key_exists($from, $columns) || $columns[$from] === null) {
                    continue;
                }

                foreach ($targets as $to => $count) {
                    if (! array_key_exists($to, $columns) || isset($entrySet[$to])) {
                        continue;
                    }

                    $candidate = min($columns[$from] + 1, $maxColumns - 1);

                    if ($columns[$to] === null || $candidate > $columns[$to]) {
                        $columns[$to] = $candidate;
                        $changed = true;
                    }
                }
            }

            if (! $changed) {
                break;
            }
        }

        foreach ($columns as $page => $column) {
            if ($column === null) {
                $columns[$page] = 0;
            }
        }

        return $columns;
    }

    /**
     * @param  array<int, string>  $pages
     * @param  array<string, int>  $columns
     * @param  array<string, int>  $views
     * @param  array<string, array{removalEffect: float, attributionValue: float}>  $attribution
     * @param  array<string, array<string, int>>  $forward
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>}
     */
    private function buildNodes(array $pages, array $columns, array $views, array $attribution, array $forward): array
    {
        $byColumn = [];
        foreach ($pages as $page) {
            $byColumn[$columns[$page]][] = $page;
        }

        $nodes = [];
        $nodeIdByPage = [];
        $index = 0;

        ksort($byColumn);

        foreach ($byColumn as $column => $columnPages) {
            usort($columnPages, static fn ($a, $b) => ($views[$b] ?? 0) <=> ($views[$a] ?? 0));
            $columnPages = array_slice($columnPages, 0, self::MAX_CARDS_PER_COLUMN);

            foreach ($columnPages as $order => $page) {
                $id = 'n'.$index;
                $nodeIdByPage[$page] = $id;
                $uniqueViews = (int) ($views[$page] ?? 0);
                $onward = array_sum($forward[$page] ?? []);
                $ctr = $uniqueViews > 0 ? min(1.0, $onward / $uniqueViews) : 0.0;
                $nodes[$page] = [
                    'id' => $id,
                    'page' => $page,
                    'column' => (int) $column,
                    'order' => $order,
                    'uniqueViews' => $uniqueViews,
                    'ctr' => round($ctr, 4),
                    'impact' => round((float) ($attribution[$page]['removalEffect'] ?? 0), 3),
                    'attributionValue' => round((float) ($attribution[$page]['attributionValue'] ?? 0), 3),
                ];
                $index++;
            }
        }

        return [$nodes, $nodeIdByPage];
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, string>  $nodeIdByPage
     * @param  array<string, int>  $columns
     * @param  array<string, array<string, int>>  $forward
     * @param  array<string, int>  $views
     * @param  array<string, array{removalEffect: float, attributionValue: float}>  $attribution
     * @return array<int, array<string, mixed>>
     */
    private function buildEdges(
        array $nodes,
        array $nodeIdByPage,
        array $columns,
        array $forward,
        array $views,
        array $attribution
    ): array {
        $maxActions = max(1, (int) config('bigquery.block_flow_max_actions', 4));
        $edges = [];
        $edgeIndex = 0;

        foreach ($forward as $from => $targets) {
            if (! isset($nodes[$from])) {
                continue;
            }

            $candidates = [];
            $sessions = (int) ($views[$from] ?? 0);

            foreach ($targets as $to => $count) {
                if (! isset($nodes[$to]) || $columns[$to] <= $columns[$from]) {
                    continue;
                }

                $ctr = $sessions > 0 ? min(1.0, $count / $sessions) : 0.0;
                $candidates[] = [
                    'to' => $to,
                    'ctr' => $ctr,
                    'impact' => round((float) ($attribution[$to]['removalEffect'] ?? 0), 3),
                ];
            }

            usort($candidates, static fn ($a, $b) => $b['ctr'] <=> $a['ctr']);
            $candidates = array_slice($candidates, 0, $maxActions);

            foreach ($candidates as $candidate) {
                $edges[] = [
                    'id' => 'e'.$edgeIndex,
                    'fromNodeId' => $nodeIdByPage[$from],
                    'toNodeId' => $nodeIdByPage[$candidate['to']],
                    'label' => (string) $candidate['to'],
                    'ctr' => round($candidate['ctr'], 4),
                    'impact' => $candidate['impact'],
                ];
                $edgeIndex++;
            }
        }

        return $edges;
    }

    private function emptyResponse(Project $project, Carbon $start, Carbon $end, array $sources = []): array
    {
        return [
            'title' => 'Block View',
            'goal' => [
                'path' => $project->goal_path,
                'type' => $project->goal_event_type,
            ],
            'dateRange' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'sources' => $sources,
            'nodes' => [],
            'edges' => [],
        ];
    }
}
