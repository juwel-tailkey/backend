<?php

namespace App\Services\BigQuery;

use App\Models\Project;
use App\Models\Segment;
use Carbon\Carbon;
use Google\Cloud\Core\Exception\ServiceException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ExitPointsConversionQueryService
{
    private const RANK_BY_OPTIONS = ['unique_views', 'sessions', 'exit_rate'];

    private const ROW_LIMIT = 50;

    public function __construct(
        private readonly BigQueryClientFactory $clientFactory,
        private readonly SegmentFilterBuilder $segmentFilter
    ) {
    }

    public function fetch(Project $project, Carbon $start, Carbon $end, string $rankBy, ?Segment $segment = null): array
    {
        if (! in_array($rankBy, self::RANK_BY_OPTIONS, true)) {
            throw new RuntimeException('Invalid rankBy value.');
        }

        if (! $project->goal_path || ! $project->goal_event_type) {
            throw new RuntimeException('Set a conversion goal for this project before viewing exit point attribution.');
        }

        $ttl = config('bigquery.exit_points_cache_ttl', config('bigquery.country_sessions_cache_ttl', 3600));

        if ($ttl <= 0) {
            return $this->fetchFromBigQuery($project, $start, $end, $rankBy, $segment);
        }

        $cacheKey = $this->cacheKey($project, $start, $end, $rankBy, $segment);

        return Cache::remember($cacheKey, $ttl, fn () => $this->fetchFromBigQuery($project, $start, $end, $rankBy, $segment));
    }

    private function cacheKey(Project $project, Carbon $start, Carbon $end, string $rankBy, ?Segment $segment): string
    {
        $project->loadMissing('bigQueryConnection');
        $connectionStamp = $project->bigQueryConnection?->updated_at?->timestamp ?? 0;

        return sprintf(
            'exit_points:%d:%s:%s:%s:%s:%s:%d:%s:%d',
            $project->id,
            $start->toDateString(),
            $end->toDateString(),
            $rankBy,
            $project->goal_path,
            $project->goal_event_type,
            $connectionStamp,
            $segment?->id ?? 0,
            $segment?->updated_at?->timestamp ?? 0
        );
    }

    private function fetchFromBigQuery(Project $project, Carbon $start, Carbon $end, string $rankBy, ?Segment $segment = null): array
    {
        $connection = $this->clientFactory->connectionFor($project);
        $client = $this->clientFactory->forProject($project);

        $eventsTable = config('bigquery.ga4.events_table', 'events_*');
        $pageParam = config('bigquery.ga4.page_param', 'page_location');
        $goalIsEvent = $project->goal_event_type === 'Event';

        $priorStart = $start->copy()->subYear();
        $priorEnd = $end->copy()->subYear();

        $tableFqn = sprintf(
            '`%s.%s.%s`',
            $connection->gcp_project_id,
            $connection->dataset_id,
            $eventsTable
        );

        $orderColumn = match ($rankBy) {
            'sessions' => 'sessions',
            'exit_rate' => 'exit_rate',
            default => 'unique_views',
        };

        $segmentConditions = $segment?->conditions;

        $sql = <<<SQL
WITH
current_exit_sessions AS (
  {$this->exitSessionsCte($tableFqn, 'current', $segmentConditions)}
),
prior_exit_sessions AS (
  {$this->exitSessionsCte($tableFqn, 'prior', $segmentConditions)}
),
current_page_sessions AS (
  {$this->pageSessionsCte($tableFqn, 'current', $segmentConditions)}
),
prior_page_sessions AS (
  {$this->pageSessionsCte($tableFqn, 'prior', $segmentConditions)}
),
current_metrics AS (
  SELECT
    es.exit_page,
    COUNT(DISTINCT es.user_pseudo_id) AS unique_views,
    COUNT(DISTINCT CONCAT(es.user_pseudo_id, '-', es.ga_session_id)) AS sessions,
    SAFE_DIVIDE(
      COUNT(DISTINCT CONCAT(es.user_pseudo_id, '-', es.ga_session_id)),
      MAX(ps.page_sessions)
    ) * 100 AS exit_rate
  FROM current_exit_sessions es
  INNER JOIN current_page_sessions ps ON ps.page_path = es.exit_page
  GROUP BY es.exit_page
),
prior_metrics AS (
  SELECT
    es.exit_page,
    SAFE_DIVIDE(
      COUNT(DISTINCT CONCAT(es.user_pseudo_id, '-', es.ga_session_id)),
      MAX(ps.page_sessions)
    ) * 100 AS prior_exit_rate
  FROM prior_exit_sessions es
  INNER JOIN prior_page_sessions ps ON ps.page_path = es.exit_page
  GROUP BY es.exit_page
),
current_sources AS (
  SELECT
    exit_page,
    source_medium,
    COUNT(DISTINCT CONCAT(user_pseudo_id, '-', ga_session_id)) AS source_sessions
  FROM current_exit_sessions
  GROUP BY exit_page, source_medium
),
ranked_sources AS (
  SELECT
    exit_page,
    source_medium,
    source_sessions,
    ROW_NUMBER() OVER (PARTITION BY exit_page ORDER BY source_sessions DESC) AS source_rank
  FROM current_sources
),
top_sources AS (
  SELECT
    rs.exit_page,
    ARRAY_AGG(
      STRUCT(
        rs.source_medium AS label,
        ROUND(SAFE_DIVIDE(rs.source_sessions, cm.sessions) * 100, 1) AS percentage
      )
      ORDER BY rs.source_sessions DESC
      LIMIT 3
    ) AS top_sources
  FROM ranked_sources rs
  INNER JOIN current_metrics cm ON cm.exit_page = rs.exit_page
  WHERE rs.source_rank <= 3
  GROUP BY rs.exit_page
)
SELECT
  cm.exit_page,
  cm.unique_views,
  cm.sessions,
  cm.exit_rate,
  pm.prior_exit_rate,
  COALESCE(ts.top_sources, []) AS top_sources
FROM current_metrics cm
LEFT JOIN prior_metrics pm ON pm.exit_page = cm.exit_page
LEFT JOIN top_sources ts ON ts.exit_page = cm.exit_page
ORDER BY {$orderColumn} DESC
LIMIT @row_limit
SQL;

        $parameters = [
            'start_suffix' => $start->format('Ymd'),
            'end_suffix' => $end->format('Ymd'),
            'prior_start_suffix' => $priorStart->format('Ymd'),
            'prior_end_suffix' => $priorEnd->format('Ymd'),
            'page_param' => $pageParam,
            'goal_path' => $project->goal_path,
            'goal_is_event' => $goalIsEvent,
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

        return $this->formatResponse($project, $start, $end, $priorStart, $priorEnd, $rankBy, $results);
    }

    private function convertedSessionsSubquery(string $tableFqn, string $startParam, string $endParam): string
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
      WHERE _TABLE_SUFFIX BETWEEN {$startParam} AND {$endParam}
    )
    WHERE ga_session_id != ''
      AND (
        (@goal_is_event AND event_name = @goal_path)
        OR (NOT @goal_is_event AND event_name = 'page_view' AND normalized_path = @goal_path)
      )
SQL;
    }

    private function pageViewsSubquery(string $tableFqn, string $startParam, string $endParam, ?array $segmentConditions = null): string
    {
        $segmentFilter = $this->segmentFilter->sessionPredicate(
            $segmentConditions,
            $tableFqn,
            $startParam,
            $endParam,
            "CONCAT(pv.user_pseudo_id, '-', pv.ga_session_id)"
        );

        return <<<SQL
    SELECT
      pv.user_pseudo_id,
      pv.ga_session_id,
      pv.page_path,
      pv.source_medium,
      pv.event_timestamp
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
        ) AS page_path,
        CONCAT(
          COALESCE(traffic_source.source, '(direct)'),
          '_',
          COALESCE(traffic_source.medium, '(none)')
        ) AS source_medium
      FROM {$tableFqn}
      WHERE _TABLE_SUFFIX BETWEEN {$startParam} AND {$endParam}
        AND event_name = 'page_view'
    ) pv
    LEFT JOIN converted cs
      ON pv.user_pseudo_id = cs.user_pseudo_id
      AND pv.ga_session_id = cs.ga_session_id
    WHERE pv.page_path IS NOT NULL
      AND pv.page_path != ''
      AND pv.ga_session_id != ''
      AND cs.ga_session_id IS NULL{$segmentFilter}
SQL;
    }

    private function exitSessionsCte(string $tableFqn, string $period, ?array $segmentConditions = null): string
    {
        $startParam = $period === 'prior' ? '@prior_start_suffix' : '@start_suffix';
        $endParam = $period === 'prior' ? '@prior_end_suffix' : '@end_suffix';
        $converted = $this->convertedSessionsSubquery($tableFqn, $startParam, $endParam);

        return <<<SQL
  SELECT
    user_pseudo_id,
    ga_session_id,
    page_path AS exit_page,
    source_medium
  FROM (
    SELECT
      user_pseudo_id,
      ga_session_id,
      page_path,
      source_medium,
      ROW_NUMBER() OVER (
        PARTITION BY user_pseudo_id, ga_session_id
        ORDER BY event_timestamp DESC
      ) AS exit_rank
    FROM (
      WITH converted AS (
{$converted}
      )
{$this->pageViewsSubquery($tableFqn, $startParam, $endParam, $segmentConditions)}
    )
  )
  WHERE exit_rank = 1
SQL;
    }

    private function pageSessionsCte(string $tableFqn, string $period, ?array $segmentConditions = null): string
    {
        $startParam = $period === 'prior' ? '@prior_start_suffix' : '@start_suffix';
        $endParam = $period === 'prior' ? '@prior_end_suffix' : '@end_suffix';
        $converted = $this->convertedSessionsSubquery($tableFqn, $startParam, $endParam);

        return <<<SQL
  SELECT
    page_path,
    COUNT(DISTINCT CONCAT(user_pseudo_id, '-', ga_session_id)) AS page_sessions
  FROM (
    WITH converted AS (
{$converted}
    )
{$this->pageViewsSubquery($tableFqn, $startParam, $endParam, $segmentConditions)}
  )
  GROUP BY page_path
SQL;
    }

    private function formatResponse(
        Project $project,
        Carbon $start,
        Carbon $end,
        Carbon $priorStart,
        Carbon $priorEnd,
        string $rankBy,
        iterable $results
    ): array {
        $rows = [];
        $rank = 1;

        foreach ($results as $row) {
            $currentExitRate = (float) ($row['exit_rate'] ?? 0);
            $priorExitRate = $row['prior_exit_rate'] ?? null;
            $exitRateChangePercent = null;

            if ($priorExitRate !== null && $priorExitRate !== '') {
                $exitRateChangePercent = round($currentExitRate - (float) $priorExitRate, 1);
            }

            $topSources = [];
            foreach ($row['top_sources'] ?? [] as $source) {
                $topSources[] = [
                    'label' => (string) ($source['label'] ?? ''),
                    'percentage' => (float) ($source['percentage'] ?? 0),
                ];
            }

            $rows[] = [
                'rank' => $rank,
                'pageName' => (string) ($row['exit_page'] ?? ''),
                'uniqueViews' => (int) ($row['unique_views'] ?? 0),
                'sessions' => (int) ($row['sessions'] ?? 0),
                'topSources' => $topSources,
                'exitRateChangePercent' => $exitRateChangePercent,
            ];

            $rank++;
        }

        return [
            'title' => 'Exit Report From Conversion Path',
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
            'rankBy' => $rankBy,
            'rows' => $rows,
        ];
    }
}
