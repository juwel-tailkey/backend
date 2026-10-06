<?php

namespace App\Services\BigQuery;

use App\Models\Project;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Google\Cloud\Core\Exception\ServiceException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PageviewsQueryService
{
    public function __construct(private readonly BigQueryClientFactory $clientFactory)
    {
    }

    public function fetch(Project $project, Carbon $start, Carbon $end): array
    {
        $ttl = config('bigquery.pageviews_cache_ttl', config('bigquery.country_sessions_cache_ttl', 3600));

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
            'pageviews:%d:%s:%s:%d',
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
        $tableFqn = sprintf(
            '`%s.%s.%s`',
            $connection->gcp_project_id,
            $connection->dataset_id,
            $eventsTable
        );

        $dailySql = <<<SQL
SELECT
  event_date AS event_date,
  COUNTIF(event_name = 'page_view') AS pageviews,
  COUNT(DISTINCT IF(
    event_name = 'page_view',
    CONCAT(
      user_pseudo_id,
      '-',
      COALESCE(CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key = 'ga_session_id') AS STRING), '')
    ),
    NULL
  )) AS unique_pageviews
FROM {$tableFqn}
WHERE _TABLE_SUFFIX BETWEEN @start_suffix AND @end_suffix
  AND event_name = 'page_view'
GROUP BY event_date
ORDER BY event_date
SQL;

        $summarySql = <<<SQL
SELECT
  COUNTIF(event_name = 'page_view') AS pageviews,
  COUNT(DISTINCT IF(
    event_name = 'page_view',
    CONCAT(
      user_pseudo_id,
      '-',
      COALESCE(CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key = 'ga_session_id') AS STRING), '')
    ),
    NULL
  )) AS unique_pageviews,
  AVG(IF(
    event_name = 'page_view',
    (SELECT value.int_value FROM UNNEST(event_params) WHERE key = 'engagement_time_msec'),
    NULL
  )) AS avg_engagement_msec,
  COALESCE(SUM(IF(event_name = 'purchase', ecommerce.purchase_revenue, 0)), 0) AS page_value
FROM {$tableFqn}
WHERE _TABLE_SUFFIX BETWEEN @start_suffix AND @end_suffix
SQL;

        $parameters = [
            'start_suffix' => $start->format('Ymd'),
            'end_suffix' => $end->format('Ymd'),
        ];

        try {
            $dailyResults = $client->runQuery(
                $client->query($dailySql)->parameters($parameters)
            );
            $summaryResults = $client->runQuery(
                $client->query($summarySql)->parameters($parameters)
            );
        } catch (ServiceException $exception) {
            throw new RuntimeException(
                'BigQuery query failed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $dailyByDate = [];
        foreach ($dailyResults as $row) {
            $dateKey = $this->normalizeEventDate((string) ($row['event_date'] ?? ''));
            if ($dateKey === '') {
                continue;
            }
            $dailyByDate[$dateKey] = [
                'pageviews' => (int) ($row['pageviews'] ?? 0),
                'uniquePageviews' => (int) ($row['unique_pageviews'] ?? 0),
            ];
        }

        $summaryRow = null;
        foreach ($summaryResults as $row) {
            $summaryRow = $row;
            break;
        }

        return $this->formatResponse($start, $end, $dailyByDate, $summaryRow);
    }

    private function formatResponse(Carbon $start, Carbon $end, array $dailyByDate, mixed $summaryRow): array
    {
        $series = [];
        foreach (CarbonPeriod::create($start, $end) as $date) {
            $dateKey = $date->toDateString();
            $point = $dailyByDate[$dateKey] ?? ['pageviews' => 0, 'uniquePageviews' => 0];
            $series[] = [
                'date' => $dateKey,
                'pageviews' => $point['pageviews'],
                'uniquePageviews' => $point['uniquePageviews'],
            ];
        }

        return [
            'title' => 'Pageviews — Market Analysis',
            'summary' => [
                'pageviews' => (int) ($summaryRow['pageviews'] ?? 0),
                'uniquePageviews' => (int) ($summaryRow['unique_pageviews'] ?? 0),
                'averageTimeOnPage' => $this->formatDuration(
                    isset($summaryRow['avg_engagement_msec']) ? (float) $summaryRow['avg_engagement_msec'] : null
                ),
                'pageValue' => round((float) ($summaryRow['page_value'] ?? 0), 2),
            ],
            'series' => $series,
        ];
    }

    private function normalizeEventDate(string $eventDate): string
    {
        if (preg_match('/^\d{8}$/', $eventDate)) {
            return Carbon::createFromFormat('Ymd', $eventDate)->toDateString();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            return $eventDate;
        }

        return '';
    }

    private function formatDuration(?float $milliseconds): string
    {
        if ($milliseconds === null || $milliseconds <= 0) {
            return '00:00:00';
        }

        $seconds = (int) round($milliseconds / 1000);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }
}
