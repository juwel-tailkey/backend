<?php

namespace App\Services\BigQuery;

use App\Models\Project;
use Carbon\Carbon;
use Google\Cloud\Core\Exception\ServiceException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class BigQueryGoalsQueryService
{
    private const EXCLUDED_EVENTS = [
        'page_view',
        'session_start',
        'user_engagement',
        'first_visit',
        'first_open',
        'app_remove',
        'os_update',
        'app_update',
    ];

    public function __construct(private readonly BigQueryClientFactory $clientFactory)
    {
    }

    public function fetch(Project $project, Carbon $start, Carbon $end): array
    {
        $ttl = config('bigquery.goals_cache_ttl', config('bigquery.country_sessions_cache_ttl', 3600));

        if ($ttl <= 0) {
            return $this->fetchFromBigQuery($project, $start, $end);
        }

        $cacheKey = $this->cacheKey($project, $start, $end);

        return Cache::remember($cacheKey, $ttl, fn () => $this->fetchFromBigQuery($project, $start, $end));
    }

    private function cacheKey(Project $project, Carbon $start, Carbon $end): string
    {
        $project->loadMissing('bigQueryConnection');
        $connectionStamp = $project->bigQueryConnection?->updated_at?->timestamp ?? 0;

        return sprintf(
            'goals:%d:%s:%s:%d',
            $project->id,
            $start->toDateString(),
            $end->toDateString(),
            $connectionStamp
        );
    }

    private function fetchFromBigQuery(Project $project, Carbon $start, Carbon $end): array
    {
        $connection = $this->clientFactory->connectionFor($project);
        $client = $this->clientFactory->forProject($project);

        $eventsTable = config('bigquery.ga4.events_table', 'events_*');
        $pageParam = config('bigquery.ga4.page_param', 'page_location');
        $excludedEvents = implode("', '", self::EXCLUDED_EVENTS);

        $tableFqn = sprintf(
            '`%s.%s.%s`',
            $connection->gcp_project_id,
            $connection->dataset_id,
            $eventsTable
        );

        $sql = <<<SQL
WITH page_location_values AS (
  SELECT
    (SELECT value.string_value FROM UNNEST(event_params) WHERE key = @page_param) AS page_location
  FROM {$tableFqn}
  WHERE _TABLE_SUFFIX BETWEEN @start_suffix AND @end_suffix
    AND event_name = 'page_view'
),
page_goals AS (
  SELECT
    COALESCE(
      NULLIF(REGEXP_EXTRACT(page_location, r'https?://[^/]+(/[^?#]*)'), ''),
      NULLIF(page_location, '')
    ) AS path,
    COUNT(1) AS occurrences
  FROM page_location_values
  WHERE page_location IS NOT NULL
  GROUP BY path
  HAVING path IS NOT NULL AND path != ''
),
event_goals AS (
  SELECT
    event_name AS path,
    COUNT(1) AS occurrences
  FROM {$tableFqn}
  WHERE _TABLE_SUFFIX BETWEEN @start_suffix AND @end_suffix
    AND event_name NOT IN ('{$excludedEvents}')
  GROUP BY event_name
)
SELECT path, 'Page load' AS goal_type, occurrences FROM page_goals
UNION ALL
SELECT path, 'Event' AS goal_type, occurrences FROM event_goals
ORDER BY occurrences DESC
LIMIT 100
SQL;

        try {
            $results = $client->runQuery(
                $client->query($sql)->parameters([
                    'start_suffix' => $start->format('Ymd'),
                    'end_suffix' => $end->format('Ymd'),
                    'page_param' => $pageParam,
                ])
            );
        } catch (ServiceException $exception) {
            throw new RuntimeException(
                'BigQuery query failed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $goals = [];
        foreach ($results as $row) {
            $path = trim((string) ($row['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $type = (string) ($row['goal_type'] ?? '');
            $goalType = $type === 'Event' ? 'Event' : 'Page load';

            $goals[] = [
                'id' => $this->goalId($path, $goalType),
                'path' => $path,
                'type' => $goalType,
                'occurrences' => (int) ($row['occurrences'] ?? 0),
            ];
        }

        return $goals;
    }

    private function goalId(string $path, string $type): string
    {
        return 'goal-'.substr(md5($path.'|'.$type), 0, 12);
    }
}
