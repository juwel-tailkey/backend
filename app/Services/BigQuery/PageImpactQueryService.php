<?php

namespace App\Services\BigQuery;

use App\Models\Project;
use App\Models\Segment;
use App\Services\Attribution\MarkovAttributionSolver;
use Carbon\Carbon;
use Google\Cloud\Core\Exception\ServiceException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PageImpactQueryService
{
    private const ROW_LIMIT = 50;

    private const IMPACT_HIGH = 0.5;

    private const IMPACT_MEDIUM = 0.2;

    public function __construct(
        private readonly BigQueryClientFactory $clientFactory,
        private readonly SegmentFilterBuilder $segmentFilter,
        private readonly MarkovAttributionSolver $solver
    ) {
    }

    public function fetch(Project $project, Carbon $start, Carbon $end, ?Segment $segment = null): array
    {
        if (! $project->goal_path || ! $project->goal_event_type) {
            throw new RuntimeException('Set a conversion goal for this project before viewing page impact.');
        }

        $ttl = config('bigquery.page_impact_cache_ttl', config('bigquery.country_sessions_cache_ttl', 3600));

        if ($ttl <= 0) {
            return $this->fetchFromBigQuery($project, $start, $end, $segment);
        }

        $cacheKey = $this->cacheKey($project, $start, $end, $segment);

        return Cache::remember($cacheKey, $ttl, fn () => $this->fetchFromBigQuery($project, $start, $end, $segment));
    }

    private function cacheKey(Project $project, Carbon $start, Carbon $end, ?Segment $segment): string
    {
        $project->loadMissing('bigQueryConnection');
        $connectionStamp = $project->bigQueryConnection?->updated_at?->timestamp ?? 0;

        return sprintf(
            'page_impact:%d:%s:%s:%s:%s:%d:%d:%d',
            $project->id,
            $start->toDateString(),
            $end->toDateString(),
            $project->goal_path,
            $project->goal_event_type,
            $connectionStamp,
            $segment?->id ?? 0,
            $segment?->updated_at?->timestamp ?? 0
        );
    }

    private function fetchFromBigQuery(Project $project, Carbon $start, Carbon $end, ?Segment $segment = null): array
    {
        $connection = $this->clientFactory->connectionFor($project);
        $client = $this->clientFactory->forProject($project);

        $eventsTable = config('bigquery.ga4.events_table', 'events_*');
        $pageParam = config('bigquery.ga4.page_param', 'page_location');
        $maxPages = (int) config('bigquery.page_impact_max_pages', 75);
        $goalIsEvent = $project->goal_event_type === 'Event';

        $priorStart = $start->copy()->subYear();
        $priorEnd = $end->copy()->subYear();

        $tableFqn = sprintf(
            '`%s.%s.%s`',
            $connection->gcp_project_id,
            $connection->dataset_id,
            $eventsTable
        );

        $segmentConditions = $segment?->conditions;

        $currentCtes = $this->periodCtes($tableFqn, 'current', '@start_suffix', '@end_suffix', $segmentConditions);
        $priorCtes = $this->periodCtes($tableFqn, 'prior', '@prior_start_suffix', '@prior_end_suffix', $segmentConditions);

        $sql = <<<SQL
WITH
{$currentCtes},
{$priorCtes}
SELECT period, from_state, to_state, cnt FROM transitions_current
UNION ALL
SELECT period, from_state, to_state, cnt FROM transitions_prior
UNION ALL
SELECT '__views__' AS period, page_path AS from_state, '' AS to_state, COUNT(*) AS cnt
FROM page_sessions_current
WHERE page_path IN (SELECT page_path FROM top_current)
GROUP BY page_path
SQL;

        $parameters = [
            'start_suffix' => $start->format('Ymd'),
            'end_suffix' => $end->format('Ymd'),
            'prior_start_suffix' => $priorStart->format('Ymd'),
            'prior_end_suffix' => $priorEnd->format('Ymd'),
            'page_param' => $pageParam,
            'goal_path' => $project->goal_path,
            'goal_is_event' => $goalIsEvent,
            'max_pages' => $maxPages,
            ...$this->segmentFilter->params($segmentConditions),
        ];

        try {
            $results = $client->runQuery(
                $client->query($sql)->parameters($parameters)
            );
        } catch (ServiceException $exception) {
            throw new RuntimeException(
                'BigQuery query failed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        return $this->formatResponse($project, $start, $end, $priorStart, $priorEnd, $results);
    }

    private function periodCtes(
        string $tableFqn,
        string $period,
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
pv_{$period} AS (
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
),
conv_{$period} AS (
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
page_sessions_{$period} AS (
  SELECT DISTINCT page_path, session_key FROM pv_{$period}
),
top_{$period} AS (
  SELECT page_path FROM (
    SELECT page_path, COUNT(*) AS c
    FROM page_sessions_{$period}
    GROUP BY page_path
    ORDER BY c DESC
    LIMIT @max_pages
  )
),
seq_{$period} AS (
  SELECT
    pv.session_key,
    ARRAY_AGG(IF(t.page_path IS NULL, '__other__', pv.page_path) ORDER BY pv.event_timestamp) AS pages,
    IF(MAX(IF(c.session_key IS NULL, 0, 1)) = 1, 1, 0) AS converted
  FROM pv_{$period} pv
  LEFT JOIN top_{$period} t ON t.page_path = pv.page_path
  LEFT JOIN conv_{$period} c ON c.session_key = pv.session_key
  GROUP BY pv.session_key
),
transitions_{$period} AS (
  SELECT '{$period}' AS period, from_state, to_state, COUNT(*) AS cnt
  FROM (
    SELECT
      IF(off = 0, '__start__', seq.pages[OFFSET(off - 1)]) AS from_state,
      IF(
        off = ARRAY_LENGTH(seq.pages),
        IF(seq.converted = 1, '__conversion__', '__null__'),
        seq.pages[OFFSET(off)]
      ) AS to_state
    FROM seq_{$period} seq,
    UNNEST(GENERATE_ARRAY(0, ARRAY_LENGTH(seq.pages))) AS off
    WHERE ARRAY_LENGTH(seq.pages) > 0
  )
  GROUP BY from_state, to_state
)
SQL;
    }

    private function formatResponse(
        Project $project,
        Carbon $start,
        Carbon $end,
        Carbon $priorStart,
        Carbon $priorEnd,
        iterable $results
    ): array {
        $currentTransitions = [];
        $priorTransitions = [];
        $views = [];

        foreach ($results as $row) {
            $period = (string) ($row['period'] ?? '');

            if ($period === '__views__') {
                $views[(string) ($row['from_state'] ?? '')] = (int) ($row['cnt'] ?? 0);

                continue;
            }

            $transition = [
                'from' => (string) ($row['from_state'] ?? ''),
                'to' => (string) ($row['to_state'] ?? ''),
                'count' => (int) ($row['cnt'] ?? 0),
            ];

            if ($period === 'prior') {
                $priorTransitions[] = $transition;
            } else {
                $currentTransitions[] = $transition;
            }
        }

        $currentAttribution = $this->solver->solve($currentTransitions);
        $priorAttribution = $this->solver->solve($priorTransitions);

        uasort(
            $currentAttribution,
            static fn ($a, $b) => $b['attributionValue'] <=> $a['attributionValue']
        );

        $rows = [];
        $rank = 1;

        foreach ($currentAttribution as $page => $metrics) {
            if ($rank > self::ROW_LIMIT) {
                break;
            }

            $removalEffect = (float) $metrics['removalEffect'];
            $priorRemoval = $priorAttribution[$page]['removalEffect'] ?? null;
            $impactChangePercent = null;

            if ($priorRemoval !== null && $priorRemoval > 0.0) {
                $impactChangePercent = round((($removalEffect - $priorRemoval) / $priorRemoval) * 100, 1);
            }

            $rows[] = [
                'rank' => $rank,
                'pageName' => (string) $page,
                'removalEffect' => round($removalEffect, 3),
                'attributionValue' => round((float) $metrics['attributionValue'], 3),
                'impact' => $this->impactBand($removalEffect),
                'uniqueViews' => (int) ($views[$page] ?? 0),
                'impactChangePercent' => $impactChangePercent,
            ];

            $rank++;
        }

        return [
            'title' => 'Page Impact Report',
            'goal' => [
                'path' => $project->goal_path,
                'type' => $project->goal_event_type,
            ],
            'dateRange' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'priorDateRange' => [
                'start' => $priorStart->toDateString(),
                'end' => $priorEnd->toDateString(),
            ],
            'rows' => $rows,
        ];
    }

    private function impactBand(float $removalEffect): string
    {
        if ($removalEffect >= self::IMPACT_HIGH) {
            return 'high';
        }

        if ($removalEffect >= self::IMPACT_MEDIUM) {
            return 'medium';
        }

        return 'low';
    }
}
