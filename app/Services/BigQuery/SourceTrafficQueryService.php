<?php

namespace App\Services\BigQuery;

use App\Models\Project;
use App\Models\Segment;
use Carbon\Carbon;
use Google\Cloud\Core\Exception\ServiceException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SourceTrafficQueryService
{
    private const RANK_BY_OPTIONS = ['unique_views', 'sessions', 'bounce_rate', 'engagement_rate'];

    private const ROW_LIMIT = 50;

    private const SOURCE_CAP = 500;

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
            throw new RuntimeException('Set a conversion goal for this project before viewing source traffic.');
        }

        $ttl = config('bigquery.source_traffic_cache_ttl', config('bigquery.country_sessions_cache_ttl', 3600));

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
            'source_traffic:%d:%s:%s:%s:%s:%s:%d:%s:%d',
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

        $tableFqn = sprintf(
            '`%s.%s.%s`',
            $connection->gcp_project_id,
            $connection->dataset_id,
            $eventsTable
        );

        $segmentConditions = $segment?->conditions;
        $sessionSourcesCte = $this->sessionSourcesCte($tableFqn, $segmentConditions);
        $channelCase = $this->channelCaseSql('medium');

        $rowsSql = <<<SQL
WITH
converted AS (
{$this->convertedSessionsSubquery($tableFqn)}
),
session_sources AS (
{$sessionSourcesCte}
)
SELECT
  source,
  medium,
  {$channelCase} AS channel,
  COUNT(DISTINCT user_pseudo_id) AS unique_views,
  COUNT(*) AS sessions,
  SUM(engaged) AS engaged_sessions
FROM session_sources
GROUP BY source, medium, channel
ORDER BY sessions DESC
LIMIT @source_cap
SQL;

        $totalsSql = <<<SQL
WITH
converted AS (
{$this->convertedSessionsSubquery($tableFqn)}
),
session_sources AS (
{$sessionSourcesCte}
)
SELECT COUNT(DISTINCT user_pseudo_id) AS total_users FROM session_sources
SQL;

        $parameters = [
            'start_suffix' => $start->format('Ymd'),
            'end_suffix' => $end->format('Ymd'),
            'page_param' => $pageParam,
            'goal_path' => $project->goal_path,
            'goal_is_event' => $goalIsEvent,
            'source_cap' => self::SOURCE_CAP,
            ...$this->segmentFilter->params($segmentConditions),
        ];

        try {
            $rowResults = $client->runQuery(
                $client->query($rowsSql)->parameters($parameters)
            );
            $totalsResults = $client->runQuery(
                $client->query($totalsSql)->parameters($parameters)
            );
        } catch (ServiceException $exception) {
            throw new RuntimeException(
                'BigQuery query failed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $totalUsers = 0;
        foreach ($totalsResults as $row) {
            $totalUsers = (int) ($row['total_users'] ?? 0);
            break;
        }

        return $this->formatResponse($project, $start, $end, $rankBy, $rowResults, $totalUsers);
    }

    private function sessionSourcesCte(string $tableFqn, ?array $segmentConditions = null): string
    {
        $segmentFilter = $this->segmentFilter->sessionPredicate(
            $segmentConditions,
            $tableFqn,
            '@start_suffix',
            '@end_suffix',
            "CONCAT(user_pseudo_id, '-', ga_session_id)"
        );

        return <<<SQL
  SELECT
    user_pseudo_id,
    ga_session_id,
    ANY_VALUE(source) AS source,
    ANY_VALUE(medium) AS medium,
    MAX(engaged) AS engaged
  FROM (
    SELECT
      events.user_pseudo_id,
      COALESCE(CAST((SELECT value.int_value FROM UNNEST(events.event_params) WHERE key = 'ga_session_id') AS STRING), '') AS ga_session_id,
      COALESCE(events.traffic_source.source, '(direct)') AS source,
      COALESCE(events.traffic_source.medium, '(none)') AS medium,
      CASE
        WHEN (SELECT value.string_value FROM UNNEST(events.event_params) WHERE key = 'session_engaged') = '1'
          OR (SELECT value.int_value FROM UNNEST(events.event_params) WHERE key = 'session_engaged') = 1
        THEN 1 ELSE 0
      END AS engaged
    FROM {$tableFqn} AS events
    WHERE _TABLE_SUFFIX BETWEEN @start_suffix AND @end_suffix
  )
  WHERE ga_session_id != ''
    AND CONCAT(user_pseudo_id, '-', ga_session_id) IN (
      SELECT CONCAT(user_pseudo_id, '-', ga_session_id) FROM converted
    ){$segmentFilter}
  GROUP BY user_pseudo_id, ga_session_id
SQL;
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

    private function channelCaseSql(string $mediumColumn): string
    {
        return <<<SQL
CASE
  WHEN LOWER({$mediumColumn}) = 'organic' THEN 'Organic'
  WHEN LOWER({$mediumColumn}) IN ('cpc', 'ppc', 'cpm', 'paid', 'display', 'paidsearch', 'paid_search')
    OR LOWER({$mediumColumn}) LIKE '%paid%'
    OR LOWER({$mediumColumn}) LIKE '%cpc%' THEN 'Paid'
  WHEN LOWER({$mediumColumn}) IN ('email', 'e-mail', 'crm', 'newsletter', 'affiliate', 'sms') THEN 'CRM'
  ELSE 'Other'
END
SQL;
    }

    private function formatResponse(
        Project $project,
        Carbon $start,
        Carbon $end,
        string $rankBy,
        iterable $results,
        int $totalUsers
    ): array {
        $rows = [];
        $channelSessions = [];
        $totalSessions = 0;

        foreach ($results as $row) {
            $sessions = (int) ($row['sessions'] ?? 0);
            $engagedSessions = (int) ($row['engaged_sessions'] ?? 0);
            $engagementRate = $sessions > 0 ? round(($engagedSessions / $sessions) * 100, 2) : 0.0;
            $bounceRate = $sessions > 0 ? round(100 - $engagementRate, 2) : 0.0;

            $source = (string) ($row['source'] ?? '');
            $medium = (string) ($row['medium'] ?? '');
            $channel = (string) ($row['channel'] ?? 'Other');

            $rows[] = [
                'source' => $source,
                'medium' => $medium,
                'label' => $this->buildLabel($source, $medium),
                'channel' => $channel,
                'uniqueViews' => (int) ($row['unique_views'] ?? 0),
                'sessions' => $sessions,
                'bounceRate' => $bounceRate,
                'engagementRate' => $engagementRate,
            ];

            $channelSessions[$channel] = ($channelSessions[$channel] ?? 0) + $sessions;
            $totalSessions += $sessions;
        }

        usort($rows, function (array $a, array $b) use ($rankBy) {
            $key = match ($rankBy) {
                'sessions' => 'sessions',
                'bounce_rate' => 'bounceRate',
                'engagement_rate' => 'engagementRate',
                default => 'uniqueViews',
            };

            return $b[$key] <=> $a[$key];
        });

        $rows = array_slice($rows, 0, self::ROW_LIMIT);
        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        $channels = [];
        foreach (['Organic', 'CRM', 'Paid', 'Other'] as $channelName) {
            $sessions = $channelSessions[$channelName] ?? 0;
            if ($sessions <= 0) {
                continue;
            }

            $channels[] = [
                'label' => $channelName,
                'sessions' => $sessions,
                'percentage' => $totalSessions > 0 ? round(($sessions / $totalSessions) * 100, 1) : 0.0,
            ];
        }

        return [
            'title' => 'Source Traffic Overview',
            'goal' => [
                'path' => $project->goal_path,
                'type' => $project->goal_event_type,
            ],
            'dateRange' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'rankBy' => $rankBy,
            'totalUsers' => $totalUsers,
            'channels' => $channels,
            'rows' => array_values($rows),
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
