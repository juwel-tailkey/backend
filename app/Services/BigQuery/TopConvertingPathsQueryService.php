<?php

namespace App\Services\BigQuery;

use App\Models\Project;
use App\Models\Segment;
use Carbon\Carbon;
use Google\Cloud\Core\Exception\ServiceException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class TopConvertingPathsQueryService
{
    private const ROW_LIMIT = 8;

    public function __construct(
        private readonly BigQueryClientFactory $clientFactory,
        private readonly SegmentFilterBuilder $segmentFilter
    ) {
    }

    public function fetch(Project $project, Carbon $start, Carbon $end, ?Segment $segment = null): array
    {
        if (! $project->goal_path || ! $project->goal_event_type) {
            throw new RuntimeException('Set a conversion goal for this project before viewing converting paths.');
        }

        $ttl = config('bigquery.converting_paths_cache_ttl', config('bigquery.country_sessions_cache_ttl', 3600));

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
            'converting_paths:%d:%s:%s:%s:%s:%d:%d:%d',
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
        $maxSteps = (int) config('bigquery.converting_paths_max_steps', 4);
        $goalIsEvent = $project->goal_event_type === 'Event';

        $tableFqn = sprintf(
            '`%s.%s.%s`',
            $connection->gcp_project_id,
            $connection->dataset_id,
            $eventsTable
        );

        $segmentConditions = $segment?->conditions;
        $segmentFilter = $this->segmentFilter->sessionPredicate(
            $segmentConditions,
            $tableFqn,
            '@start_suffix',
            '@end_suffix',
            "CONCAT(user_pseudo_id, '-', ga_session_id)"
        );

        $sql = <<<SQL
WITH
converted AS (
{$this->convertedSessionsSubquery($tableFqn)}
),
session_source AS (
  SELECT
    CONCAT(user_pseudo_id, '-', ga_session_id) AS session_key,
    ANY_VALUE(source) AS source,
    ANY_VALUE(medium) AS medium
  FROM (
    SELECT
      events.user_pseudo_id,
      COALESCE(CAST((SELECT value.int_value FROM UNNEST(events.event_params) WHERE key = 'ga_session_id') AS STRING), '') AS ga_session_id,
      COALESCE(events.traffic_source.source, '(direct)') AS source,
      COALESCE(events.traffic_source.medium, '(none)') AS medium
    FROM {$tableFqn} AS events
    WHERE _TABLE_SUFFIX BETWEEN @start_suffix AND @end_suffix
  )
  WHERE ga_session_id != ''
    AND CONCAT(user_pseudo_id, '-', ga_session_id) IN (
      SELECT CONCAT(user_pseudo_id, '-', ga_session_id) FROM converted
    ){$segmentFilter}
  GROUP BY user_pseudo_id, ga_session_id
),
pv AS (
  SELECT
    CONCAT(user_pseudo_id, '-', ga_session_id) AS session_key,
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
    WHERE _TABLE_SUFFIX BETWEEN @start_suffix AND @end_suffix
      AND event_name = 'page_view'
  )
  WHERE page_path IS NOT NULL AND page_path != '' AND ga_session_id != ''
    AND CONCAT(user_pseudo_id, '-', ga_session_id) IN (
      SELECT CONCAT(user_pseudo_id, '-', ga_session_id) FROM converted
    )
),
ordered AS (
  SELECT
    session_key,
    page_path,
    LAG(page_path) OVER (PARTITION BY session_key ORDER BY event_timestamp) AS prev_path,
    ROW_NUMBER() OVER (PARTITION BY session_key ORDER BY event_timestamp) AS rn
  FROM pv
),
dedup AS (
  SELECT session_key, page_path, rn
  FROM ordered
  WHERE prev_path IS NULL OR prev_path != page_path
),
goal_pos AS (
  SELECT session_key, MIN(rn) AS goal_rn
  FROM dedup
  WHERE page_path = @goal_path
  GROUP BY session_key
),
trimmed AS (
  SELECT d.session_key, d.page_path, d.rn
  FROM dedup d
  LEFT JOIN goal_pos g ON g.session_key = d.session_key
  WHERE @goal_is_event OR g.goal_rn IS NULL OR d.rn < g.goal_rn
),
capped AS (
  SELECT
    session_key,
    page_path,
    ROW_NUMBER() OVER (PARTITION BY session_key ORDER BY rn) AS pos
  FROM trimmed
),
paths AS (
  SELECT session_key, ARRAY_AGG(page_path ORDER BY pos) AS pages
  FROM capped
  WHERE pos <= @max_steps
  GROUP BY session_key
)
SELECT
  source,
  medium,
  ANY_VALUE(pages) AS pages,
  COUNT(*) AS conversions
FROM (
  SELECT
    ss.source,
    ss.medium,
    IFNULL(p.pages, ARRAY<STRING>[]) AS pages,
    ARRAY_TO_STRING(IFNULL(p.pages, ARRAY<STRING>[]), CODE_POINTS_TO_STRING([31])) AS page_sig
  FROM session_source ss
  LEFT JOIN paths p ON p.session_key = ss.session_key
)
GROUP BY source, medium, page_sig
ORDER BY conversions DESC
LIMIT @row_limit
SQL;

        $parameters = [
            'start_suffix' => $start->format('Ymd'),
            'end_suffix' => $end->format('Ymd'),
            'page_param' => $pageParam,
            'goal_path' => $project->goal_path,
            'goal_is_event' => $goalIsEvent,
            'max_steps' => $maxSteps,
            'row_limit' => self::ROW_LIMIT,
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

        return $this->formatResponse($project, $start, $end, $results);
    }

    private function convertedSessionsSubquery(string $tableFqn): string
    {
        return <<<SQL
    SELECT DISTINCT
      user_pseudo_id,
      ga_session_id
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
      WHERE _TABLE_SUFFIX BETWEEN @start_suffix AND @end_suffix
    )
    WHERE ga_session_id != ''
      AND (
        (@goal_is_event AND event_name = @goal_path)
        OR (NOT @goal_is_event AND event_name = 'page_view' AND normalized_path = @goal_path)
      )
SQL;
    }

    private function formatResponse(
        Project $project,
        Carbon $start,
        Carbon $end,
        iterable $results
    ): array {
        $rows = [];
        $rank = 1;

        foreach ($results as $row) {
            $source = (string) ($row['source'] ?? '');
            $medium = (string) ($row['medium'] ?? '');

            $nodes = [[
                'type' => 'entry',
                'label' => $this->buildLabel($source, $medium),
            ]];

            foreach ($row['pages'] ?? [] as $page) {
                $label = (string) $page;
                $nodes[] = [
                    'type' => 'touchpoint',
                    'label' => $label !== '' ? $label : 'Not set',
                ];
            }

            $nodes[] = [
                'type' => 'goal',
                'label' => (string) $project->goal_path,
            ];

            $rows[] = [
                'rank' => $rank,
                'conversions' => (int) ($row['conversions'] ?? 0),
                'nodes' => $nodes,
            ];

            $rank++;
        }

        return [
            'title' => 'Top Converting Paths',
            'goal' => [
                'path' => $project->goal_path,
                'type' => $project->goal_event_type,
            ],
            'dateRange' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'rows' => $rows,
        ];
    }

    private function buildLabel(string $source, string $medium): string
    {
        $sourceLabel = $source !== '' ? ucfirst($source) : '(direct)';
        $mediumLabel = $this->displayMedium($medium);

        return trim($sourceLabel.' '.$mediumLabel);
    }

    private function displayMedium(string $medium): string
    {
        $normalized = strtolower(trim($medium));

        return match ($normalized) {
            '', '(none)' => '',
            'cpc', 'ppc', 'cpm', 'sms', 'crm' => strtoupper($normalized),
            default => ucfirst($normalized),
        };
    }
}
