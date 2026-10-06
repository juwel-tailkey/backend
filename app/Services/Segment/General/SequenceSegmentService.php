<?php

namespace App\Services\Segment\General;

use App\Models\UserSegment;
use App\Services\BigQuery\BigQueryClientFactory;
use App\Models\Project;

class SequenceSegmentService
{
    /**
     * Calculate sequence segments for a project.
     * Sequence segments detect when event_a -> event_b occurs within a time window.
     *
     * @param Project $project
     * @param string $eventA
     * @param string $eventB
     * @param int $windowHours
     * @param array $dateRange
     * @return int Number of devices segmented
     */
    public function calculateForProject(
        Project $project,
        string $eventA,
        string $eventB,
        int $windowHours = 24,
        array $dateRange = []
    ): int {
        $client = BigQueryClientFactory::forProject($project);

        // Query to find devices that completed the sequence
        $query = $this->buildSequenceQuery($project, $eventA, $eventB, $windowHours, $dateRange);

        $results = $client->runQuery($query);
        $segmented = 0;

        foreach ($results as $row) {
            $deviceId = $row['device_id'] ?? null;
            if (!$deviceId) {
                continue;
            }

            $segmentKey = $this->generateSegmentKey($eventA, $eventB, $windowHours);
            $segmentData = [
                'event_a' => $eventA,
                'event_b' => $eventB,
                'window_hours' => $windowHours,
                'event_a_ts' => $row['event_a_ts'] ?? null,
                'event_b_ts' => $row['event_b_ts'] ?? null,
                'hours_between' => (float) ($row['hours_between'] ?? 0),
            ];

            // Create or update user segment
            UserSegment::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'device_id' => $deviceId,
                    'segment_family' => 'general',
                    'segment_type' => 'sequence',
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
     * Build BigQuery query for sequence detection.
     *
     * @param Project $project
     * @param string $eventA
     * @param string $eventB
     * @param int $windowHours
     * @param array $dateRange
     * @return string
     */
    private function buildSequenceQuery(
        Project $project,
        string $eventA,
        string $eventB,
        int $windowHours,
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

        return "
            WITH event_a_times AS (
                SELECT
                    user_pseudo_id as device_id,
                    MIN(event_timestamp) as event_a_ts
                FROM
                    {$eventsTable}
                WHERE
                    event_name = '{$eventA}'
                    {$dateFilter}
                GROUP BY
                    user_pseudo_id
            ),
            event_b_times AS (
                SELECT
                    user_pseudo_id as device_id,
                    MIN(event_timestamp) as event_b_ts
                FROM
                    {$eventsTable}
                WHERE
                    event_name = '{$eventB}'
                    {$dateFilter}
                GROUP BY
                    user_pseudo_id
            ),
            sequences AS (
                SELECT
                    COALESCE(a.device_id, b.device_id) as device_id,
                    a.event_a_ts,
                    b.event_b_ts,
                    (b.event_b_ts - a.event_a_ts) / (1000 * 1000 * 3600) as hours_between
                FROM
                    event_a_times a
                INNER JOIN
                    event_b_times b
                ON
                    a.device_id = b.device_id
                    AND b.event_b_ts > a.event_a_ts
                    AND (b.event_b_ts - a.event_a_ts) / (1000 * 1000 * 3600) <= {$windowHours}
            )
            SELECT
                device_id,
                TIMESTAMP_MICROS(event_a_ts) as event_a_ts,
                TIMESTAMP_MICROS(event_b_ts) as event_b_ts,
                hours_between
            FROM
                sequences
        ";
    }

    /**
     * Generate segment key from sequence parameters.
     *
     * @param string $eventA
     * @param string $eventB
     * @param int $windowHours
     * @return string
     */
    private function generateSegmentKey(string $eventA, string $eventB, int $windowHours): string
    {
        $eventAPart = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $eventA));
        $eventBPart = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $eventB));

        return "seq_{$eventAPart}_to_{$eventBPart}_{$windowHours}h";
    }

    /**
     * Get aggregated sequence segments for a project.
     *
     * @param Project $project
     * @return \Illuminate\Support\Collection
     */
    public function getAggregatedSegments(Project $project): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'general')
            ->where('segment_type', 'sequence')
            ->select(
                'segment_key',
                \DB::raw('COUNT(DISTINCT device_id) as device_count'),
                \DB::raw('AVG(CAST(JSON_EXTRACT(segment_data, "$.hours_between") AS DECIMAL(10,2))) as avg_hours_between')
            )
            ->groupBy('segment_key')
            ->orderByDesc('device_count')
            ->get()
            ->map(function ($item) {
                $item->segment_label = $this->parseSegmentKey($item->segment_key);
                return $item;
            });
    }

    /**
     * Parse segment key into human-readable label.
     *
     * @param string $segmentKey
     * @return string
     */
    private function parseSegmentKey(string $segmentKey): string
    {
        // Remove 'seq_' prefix and '_24h' suffix
        $pattern = '/seq_(.+?)_to_(.+?)_(\d+)h$/';
        if (preg_match($pattern, $segmentKey, $matches)) {
            $eventA = ucwords(str_replace('_', ' ', $matches[1]));
            $eventB = ucwords(str_replace('_', ' ', $matches[2]));
            $window = $matches[3];
            return "{$eventA} → {$eventB} ({$window}h window)";
        }

        return ucwords(str_replace('_', ' ', $segmentKey));
    }

    /**
     * Get sequence segment options for filtering.
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
                    'avg_hours' => round($item->avg_hours_between, 1),
                ];
            })
            ->toArray();
    }

    /**
     * Predefined common sequences for SaaS/ecommerce.
     *
     * @return array
     */
    public function getCommonSequences(): array
    {
        return [
            [
                'event_a' => 'page_view',
                'event_b' => 'sign_up',
                'window_hours' => 24,
                'label' => 'Page View → Sign Up (24h)',
            ],
            [
                'event_a' => 'sign_up',
                'event_b' => 'purchase',
                'window_hours' => 168,
                'label' => 'Sign Up → Purchase (7 days)',
            ],
            [
                'event_a' => 'add_to_cart',
                'event_b' => 'purchase',
                'window_hours' => 24,
                'label' => 'Add to Cart → Purchase (24h)',
            ],
            [
                'event_a' => 'demo_request',
                'event_b' => 'purchase',
                'window_hours' => 168,
                'label' => 'Demo Request → Purchase (7 days)',
            ],
        ];
    }
}
