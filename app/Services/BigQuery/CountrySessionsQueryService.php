<?php

namespace App\Services\BigQuery;

use App\Models\Project;
use Carbon\Carbon;
use Google\Cloud\Core\Exception\ServiceException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class CountrySessionsQueryService
{
    private const COUNTRY_CODES = [
        'United States' => 'US',
        'USA' => 'US',
        'United Kingdom' => 'GB',
        'Canada' => 'CA',
        'Australia' => 'AU',
        'Germany' => 'DE',
        'France' => 'FR',
        'Spain' => 'ES',
        'Italy' => 'IT',
        'Netherlands' => 'NL',
        'Belgium' => 'BE',
        'Switzerland' => 'CH',
        'Sweden' => 'SE',
        'Norway' => 'NO',
        'Denmark' => 'DK',
        'Finland' => 'FI',
        'Poland' => 'PL',
        'Austria' => 'AT',
        'Ireland' => 'IE',
        'Portugal' => 'PT',
        'China' => 'CN',
        'Japan' => 'JP',
        'India' => 'IN',
        'South Korea' => 'KR',
        'Singapore' => 'SG',
        'Brazil' => 'BR',
        'Mexico' => 'MX',
        'Argentina' => 'AR',
        'South Africa' => 'ZA',
        'New Zealand' => 'NZ',
        'Russia' => 'RU',
        'Turkey' => 'TR',
        'Indonesia' => 'ID',
        'Malaysia' => 'MY',
        'Philippines' => 'PH',
        'Thailand' => 'TH',
        'Vietnam' => 'VN',
        'Israel' => 'IL',
        'United Arab Emirates' => 'AE',
        'Saudi Arabia' => 'SA',
    ];

    public function __construct(private readonly BigQueryClientFactory $clientFactory)
    {
    }

    public function fetch(Project $project, Carbon $start, Carbon $end): array
    {
        $ttl = config('bigquery.country_sessions_cache_ttl', 3600);

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
            'country_sessions:%d:%s:%s:%d',
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
        $sessionEvent = config('bigquery.ga4.session_event', 'session_start');

        $tableFqn = sprintf(
            '`%s.%s.%s`',
            $connection->gcp_project_id,
            $connection->dataset_id,
            $eventsTable
        );

        $sql = <<<SQL
SELECT
  geo.country AS country,
  COUNT(1) AS sessions
FROM {$tableFqn}
WHERE _TABLE_SUFFIX BETWEEN @start_suffix AND @end_suffix
  AND event_name = @session_event
  AND geo.country IS NOT NULL
GROUP BY country
ORDER BY sessions DESC
LIMIT 50
SQL;

        try {
            $queryJobConfig = $client->query($sql)->parameters([
                'start_suffix' => $start->format('Ymd'),
                'end_suffix' => $end->format('Ymd'),
                'session_event' => $sessionEvent,
            ]);

            $results = $client->runQuery($queryJobConfig);
        } catch (ServiceException $exception) {
            throw new RuntimeException(
                'BigQuery query failed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $rows = [];
        foreach ($results as $row) {
            $rows[] = [
                'country' => (string) ($row['country'] ?? ''),
                'sessions' => (int) ($row['sessions'] ?? 0),
            ];
        }

        return $this->formatResponse($rows);
    }

    private function formatResponse(array $rows): array
    {
        $totalSessions = array_sum(array_column($rows, 'sessions'));

        $countries = array_map(function (array $row) use ($totalSessions) {
            $sessions = $row['sessions'];
            $percentage = $totalSessions > 0
                ? round(($sessions / $totalSessions) * 100, 1)
                : 0.0;

            return [
                'country' => $row['country'],
                'code' => $this->countryCode($row['country']),
                'sessions' => $sessions,
                'percentage' => $percentage,
            ];
        }, $rows);

        return [
            'title' => 'Session by Country',
            'totalSessions' => $totalSessions,
            'countries' => $countries,
        ];
    }

    private function countryCode(string $country): string
    {
        if (isset(self::COUNTRY_CODES[$country])) {
            return self::COUNTRY_CODES[$country];
        }

        $normalized = trim($country);
        if (strlen($normalized) === 2) {
            return strtoupper($normalized);
        }

        return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $normalized) ?: 'XX', 0, 2));
    }
}
