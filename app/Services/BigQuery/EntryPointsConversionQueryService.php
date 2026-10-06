<?php

namespace App\Services\BigQuery;

use App\Models\Project;
use App\Models\Segment;
use Carbon\Carbon;
use Google\Cloud\Core\Exception\ServiceException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class EntryPointsConversionQueryService
{
    private const RANK_BY_OPTIONS = ['unique_views', 'sessions', 'cvr_to_goal'];

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
            throw new RuntimeException('Set a conversion goal for this project before viewing entry point attribution.');
        }

        $ttl = config('bigquery.entry_points_cache_ttl', config('bigquery.country_sessions_cache_ttl', 3600));

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
            'entry_points:%d:%s:%s:%s:%s:%s:%d:%s:%d',
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
            'cvr_to_goal' => 'cvr_to_goal',
            default => 'unique_views',
        };

        $segmentConditions = $segment?->conditions;

        $sql = <<<SQL
WITH
current_sessions AS (
  {$this->sessionStatsCte($tableFqn, 'current', $segmentConditions)}
),
prior_sessions AS (
  {$this->sessionStatsCte($tableFqn, 'prior', $segmentConditions)}
),
current_metrics AS (
  SELECT
    entry_page,
    COUNT(DISTINCT CONCAT(user_pseudo_id, '-', ga_session_id)) AS sessions,
    COUNT(DISTINCT user_pseudo_id) AS unique_views,
    SUM(converted) AS converting_sessions,
    SAFE_DIVIDE(SUM(converted), COUNT(DISTINCT CONCAT(user_pseudo_id, '-', ga_session_id))) * 100 AS cvr_to_goal
  FROM current_sessions
  GROUP BY entry_page
),
prior_metrics AS (
  SELECT
    entry_page,
    COUNT(DISTINCT CONCAT(user_pseudo_id, '-', ga_session_id)) AS prior_sessions
  FROM prior_sessions
  GROUP BY entry_page
),
current_sources AS (
  SELECT
    entry_page,
    source_medium,
    COUNT(DISTINCT CONCAT(user_pseudo_id, '-', ga_session_id)) AS source_sessions
  FROM current_sessions
  GROUP BY entry_page, source_medium
),
ranked_sources AS (
  SELECT
    entry_page,
    source_medium,
    source_sessions,
    ROW_NUMBER() OVER (PARTITION BY entry_page ORDER BY source_sessions DESC) AS source_rank
  FROM current_sources
),
top_sources AS (
  SELECT
    rs.entry_page,
    ARRAY_AGG(
      STRUCT(
        rs.source_medium AS label,
        ROUND(SAFE_DIVIDE(rs.source_sessions, cm.sessions) * 100, 1) AS percentage
      )
      ORDER BY rs.source_sessions DESC
      LIMIT 3
    ) AS top_sources
  FROM ranked_sources rs
  INNER JOIN current_metrics cm ON cm.entry_page = rs.entry_page
  WHERE rs.source_rank <= 3
  GROUP BY rs.entry_page
)
SELECT
  cm.entry_page,
  cm.unique_views,
  cm.sessions,
  cm.converting_sessions,
  cm.cvr_to_goal,
  COALESCE(pm.prior_sessions, 0) AS prior_sessions,
  COALESCE(ts.top_sources, []) AS top_sources
FROM current_metrics cm
LEFT JOIN prior_metrics pm ON pm.entry_page = cm.entry_page
LEFT JOIN top_sources ts ON ts.entry_page = cm.entry_page
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

    private function sessionStatsCte(string $tableFqn, string $period, ?array $segmentConditions = null): string
    {
        $startParam = $period === 'prior' ? '@prior_start_suffix' : '@start_suffix';
        $endParam = $period === 'prior' ? '@prior_end_suffix' : '@end_suffix';
        $segmentFilter = $this->segmentFilter->sessionPredicate(
            $segmentConditions,
            $tableFqn,
            $startParam,
            $endParam,
            "CONCAT(user_pseudo_id, '-', ga_session_id)"
        );

        return <<<SQL
  SELECT
    sl.user_pseudo_id,
    sl.ga_session_id,
    sl.entry_page,
    sl.source_medium,
    IF(sc.ga_session_id IS NOT NULL, 1, 0) AS converted
  FROM (
    SELECT
      user_pseudo_id,
      ga_session_id,
      page_path AS entry_page,
      source_medium
    FROM (
      SELECT
        user_pseudo_id,
        ga_session_id,
        page_path,
        source_medium,
        ROW_NUMBER() OVER (
          PARTITION BY user_pseudo_id, ga_session_id
          ORDER BY event_timestamp ASC
        ) AS landing_rank
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
      )
      WHERE page_path IS NOT NULL AND page_path != '' AND ga_session_id != ''{$segmentFilter}
    )
    WHERE landing_rank = 1
  ) sl
  LEFT JOIN (
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
  ) sc
    ON sl.user_pseudo_id = sc.user_pseudo_id
    AND sl.ga_session_id = sc.ga_session_id
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
            $sessions = (int) ($row['sessions'] ?? 0);
            $priorSessions = (int) ($row['prior_sessions'] ?? 0);
            $trafficChangePercent = null;

            if ($priorSessions > 0) {
                $trafficChangePercent = round((($sessions - $priorSessions) / $priorSessions) * 100, 1);
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
                'pageName' => (string) ($row['entry_page'] ?? ''),
                'uniqueViews' => (int) ($row['unique_views'] ?? 0),
                'sessions' => $sessions,
                'topSources' => $topSources,
                'trafficChangePercent' => $trafficChangePercent,
                'cvrToGoal' => round((float) ($row['cvr_to_goal'] ?? 0), 1),
            ];

            $rank++;
        }

        return [
            'title' => 'Top Entry Points to Conversion',
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
