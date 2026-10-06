<?php

namespace App\Services\Segment\General;

use App\Models\UserSegment;
use App\Services\BigQuery\BigQueryClientFactory;
use App\Models\Project;
use Carbon\Carbon;

class RFMSegmentService
{
    /**
     * Calculate RFM (Recency, Frequency, Monetary) segments for a project.
     * RFM segments customer engagement and value.
     *
     * @param Project $project
     * @param string|null $revenueEventName
     * @param array $dateRange
     * @return int Number of devices segmented
     */
    public function calculateForProject(
        Project $project,
        ?string $revenueEventName = null,
        array $dateRange = []
    ): int {
        $client = BigQueryClientFactory::forProject($project);

        // Query to get RFM metrics for each device
        $query = $this->buildRFMQuery($project, $revenueEventName, $dateRange);

        $results = $client->runQuery($query);
        $segmented = 0;

        foreach ($results as $row) {
            $deviceId = $row['device_id'] ?? null;
            if (!$deviceId) {
                continue;
            }

            // Calculate RFM scores
            $recencyDays = (int) ($row['recency_days'] ?? 0);
            $sessionCount = (int) ($row['session_count'] ?? 0);
            $totalRevenue = (float) ($row['total_revenue'] ?? 0);

            $rScore = $this->calculateRecencyScore($recencyDays);
            $fScore = $this->calculateFrequencyScore($sessionCount);
            $mScore = $revenueEventName ? $this->calculateMonetaryScore($totalRevenue) : null;

            $segmentKey = $this->generateSegmentKey($rScore, $fScore, $mScore);
            $segmentData = [
                'recency_days' => $recencyDays,
                'session_count' => $sessionCount,
                'total_revenue' => $totalRevenue,
                'r_score' => $rScore,
                'f_score' => $fScore,
                'm_score' => $mScore,
            ];

            // Create or update user segment
            UserSegment::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'device_id' => $deviceId,
                    'segment_family' => 'general',
                    'segment_type' => $mScore ? 'rfm' : 'rf', // RF if no monetary data
                    'segment_key' => $segmentKey,
                ],
                [
                    'segment_data' => $segmentData,
                    'assigned_at' => now(),
                ]
            );

            $segmented++;
        }

        return $segmented;
    }

    /**
     * Build BigQuery query for RFM analysis.
     *
     * @param Project $project
     * @param string|null $revenueEventName
     * @param array $dateRange
     * @return string
     */
    private function buildRFMQuery(
        Project $project,
        ?string $revenueEventName,
        array $dateRange
    ): string {
        $dataset = $project->dataset_id;
        $eventsTable = "`{$project->gcp_project_id}.{$dataset}.events_*`";

        $dateFilter = "";
        if (!empty($dateRange)) {
            $startDate = $dateRange['start'];
            $endDate = $dateRange['end'];
            $dateFilter = "AND event_date BETWEEN '{$startDate}' AND '{$endDate}'";
        }

        // Revenue aggregation if event name provided
        $revenueSelect = "";
        $revenueJoin = "";
        if ($revenueEventName) {
            $revenueSelect = ", COALESCE(revenue.total_revenue, 0) as total_revenue";
            $revenueJoin = "
                LEFT JOIN (
                    SELECT
                        user_pseudo_id,
                        SUM(CAST((SELECT value.float_value FROM UNNEST(event_params) WHERE key = 'value') AS FLOAT64)) as total_revenue
                    FROM
                        {$eventsTable}
                    WHERE
                        event_name = '{$revenueEventName}'
                        {$dateFilter}
                    GROUP BY
                        user_pseudo_id
                ) revenue ON sessions.user_pseudo_id = revenue.user_pseudo_id
            ";
        }

        return "
            WITH user_sessions AS (
                SELECT
                    user_pseudo_id,
                    COUNT(DISTINCT ga_session_id) as session_count,
                    MAX(event_timestamp) as last_event_ts,
                    CURRENT_TIMESTAMP() as current_ts
                FROM
                    {$eventsTable}
                WHERE
                    event_name = 'session_start'
                    {$dateFilter}
                GROUP BY
                    user_pseudo_id
            ),
            sessions AS (
                SELECT
                    user_pseudo_id,
                    session_count,
                    TIMESTAMP_DIFF(current_ts, TIMESTAMP_MICROS(last_event_ts), DAY) as recency_days
                FROM
                    user_sessions
            )
            SELECT
                user_pseudo_id as device_id,
                recency_days,
                session_count
                {$revenueSelect}
            FROM
                sessions
                {$revenueJoin}
            ORDER BY
                session_count DESC
        ";
    }

    /**
     * Calculate recency score (1-5).
     * Lower recency days = higher score.
     *
     * @param int $recencyDays
     * @return int
     */
    private function calculateRecencyScore(int $recencyDays): int
    {
        if ($recencyDays <= 1) return 5;
        if ($recencyDays <= 7) return 4;
        if ($recencyDays <= 30) return 3;
        if ($recencyDays <= 90) return 2;
        return 1;
    }

    /**
     * Calculate frequency score (1-5).
     * Higher session count = higher score.
     *
     * @param int $sessionCount
     * @return int
     */
    private function calculateFrequencyScore(int $sessionCount): int
    {
        if ($sessionCount >= 20) return 5;
        if ($sessionCount >= 10) return 4;
        if ($sessionCount >= 5) return 3;
        if ($sessionCount >= 2) return 2;
        return 1;
    }

    /**
     * Calculate monetary score (1-5).
     * Higher revenue = higher score.
     *
     * @param float $totalRevenue
     * @return int
     */
    private function calculateMonetaryScore(float $totalRevenue): int
    {
        if ($totalRevenue >= 1000) return 5;
        if ($totalRevenue >= 500) return 4;
        if ($totalRevenue >= 100) return 3;
        if ($totalRevenue >= 10) return 2;
        return 1;
    }

    /**
     * Generate segment key from RFM scores.
     *
     * @param int $rScore
     * @param int $fScore
     * @param int|null $mScore
     * @return string
     */
    private function generateSegmentKey(int $rScore, int $fScore, ?int $mScore): string
    {
        if ($mScore !== null) {
            return "rfm_{$rScore}{$fScore}{$mScore}";
        }
        return "rf_{$rScore}{$fScore}";
    }

    /**
     * Get aggregated RFM segments for a project.
     *
     * @param Project $project
     * @return \Illuminate\Support\Collection
     */
    public function getAggregatedSegments(Project $project): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->whereIn('segment_type', ['rfm', 'rf'])
            ->where('segment_family', 'general')
            ->select(
                'segment_key',
                'segment_type',
                \DB::raw('COUNT(DISTINCT device_id) as device_count')
            )
            ->groupBy('segment_key', 'segment_type')
            ->orderByDesc('device_count')
            ->get()
            ->map(function ($item) {
                $item->segment_label = $this->parseSegmentKey($item->segment_key, $item->segment_type);
                return $item;
            });
    }

    /**
     * Parse segment key into human-readable label.
     *
     * @param string $segmentKey
     * @param string $segmentType
     * @return string
     */
    private function parseSegmentKey(string $segmentKey, string $segmentType): string
    {
        if ($segmentType === 'rfm') {
            preg_match('/rfm_(\d)(\d)(\d)/', $segmentKey, $matches);
            if (count($matches) === 4) {
                $r = $matches[1];
                $f = $matches[2];
                $m = $matches[3];
                return "R{$r}F{$m}M{$m} (Recency: {$r}, Frequency: {$f}, Monetary: {$m})";
            }
        } else {
            preg_match('/rf_(\d)(\d)/', $segmentKey, $matches);
            if (count($matches) === 3) {
                $r = $matches[1];
                $f = $matches[2];
                return "R{$r}F{$f} (Recency: {$r}, Frequency: {$f})";
            }
        }

        return strtoupper($segmentKey);
    }

    /**
     * Get RFM segment options for filtering.
     *
     * @param Project $project
     * @return array
     */
    public function getSegmentOptions(Project $project): array
    {
        return $this->getAggregatedSegments($project)
            ->map(function ($item) {
                return [
                    'value' => $item->segment_key,
                    'label' => $item->segment_label . " ({$item->device_count})",
                    'count' => $item->device_count,
                ];
            })
            ->toArray();
    }

    /**
     * Get RFM segment definitions for reference.
     *
     * @return array
     */
    public function getSegmentDefinitions(): array
    {
        return [
            'recency' => [
                5 => 'Last interaction within 1 day',
                4 => 'Last interaction within 7 days',
                3 => 'Last interaction within 30 days',
                2 => 'Last interaction within 90 days',
                1 => 'Last interaction over 90 days ago',
            ],
            'frequency' => [
                5 => '20+ sessions',
                4 => '10-19 sessions',
                3 => '5-9 sessions',
                2 => '2-4 sessions',
                1 => '1 session',
            ],
            'monetary' => [
                5 => '$1,000+ revenue',
                4 => '$500-$999 revenue',
                3 => '$100-$499 revenue',
                2 => '$10-$99 revenue',
                1 => '<$10 revenue',
            ],
        ];
    }
}
