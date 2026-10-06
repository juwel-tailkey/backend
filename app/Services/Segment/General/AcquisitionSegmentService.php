<?php

namespace App\Services\Segment\General;

use App\Models\UserSegment;
use App\Services\BigQuery\BigQueryClientFactory;
use App\Models\Project;

class AcquisitionSegmentService
{
    /**
     * Calculate acquisition segments for a project.
     * Acquisition segments are based on first-touch attribution.
     *
     * @param Project $project
     * @param array $dateRange
     * @return int Number of devices segmented
     */
    public function calculateForProject(Project $project, array $dateRange = []): int
    {
        $client = BigQueryClientFactory::forProject($project);

        // Query to get first-touch acquisition data for each device
        $query = $this->buildAcquisitionQuery($project, $dateRange);

        $results = $client->runQuery($query);
        $segmented = 0;

        foreach ($results as $row) {
            $deviceId = $row['device_id'] ?? null;
            if (!$deviceId) {
                continue;
            }

            // Create segment key from first-touch data
            $source = $row['first_source'] ?? '(none)';
            $medium = $row['first_medium'] ?? '(none)';
            $campaign = $row['first_campaign'] ?? '(none)';

            $segmentKey = $this->generateSegmentKey($source, $medium, $campaign);
            $segmentData = [
                'first_source' => $source,
                'first_medium' => $medium,
                'first_campaign' => $campaign,
                'first_touch_ts' => $row['first_touch_ts'] ?? null,
            ];

            // Create or update user segment
            UserSegment::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'device_id' => $deviceId,
                    'segment_family' => 'general',
                    'segment_type' => 'acquisition',
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
     * Build BigQuery query for acquisition segmentation.
     *
     * @param Project $project
     * @param array $dateRange
     * @return string
     */
    private function buildAcquisitionQuery(Project $project, array $dateRange): string
    {
        $dataset = $project->dataset_id;
        $eventsTable = "`{$project->gcp_project_id}.{$dataset}.events_*`";

        $dateFilter = "";
        if (!empty($dateRange)) {
            $startDate = $dateRange['start'];
            $endDate = $dateRange['end'];
            $dateFilter = "AND event_date BETWEEN '{$startDate}' AND '{$endDate}'";
        }

        return "
            WITH first_touches AS (
                SELECT
                    user_pseudo_id as device_id,
                    traffic_source.name as first_source,
                    traffic_source.medium as first_medium,
                    traffic_source.campaign as first_campaign,
                    MIN(event_timestamp) as first_touch_ts,
                    ROW_NUMBER() OVER (PARTITION BY user_pseudo_id ORDER BY event_timestamp ASC) as rn
                FROM
                    {$eventsTable}
                WHERE
                    event_name = 'session_start'
                    AND traffic_source IS NOT NULL
                    {$dateFilter}
                GROUP BY
                    user_pseudo_id,
                    traffic_source.name,
                    traffic_source.medium,
                    traffic_source.campaign
            )
            SELECT
                device_id,
                first_source,
                first_medium,
                first_campaign,
                first_touch_ts
            FROM
                first_touches
            WHERE
                rn = 1
        ";
    }

    /**
     * Generate segment key from acquisition dimensions.
     *
     * @param string $source
     * @param string $medium
     * @param string $campaign
     * @return string
     */
    private function generateSegmentKey(string $source, string $medium, string $campaign): string
    {
        $parts = array_filter([
            strtolower($source),
            strtolower($medium),
            strtolower($campaign),
        ]);

        return 'acq_' . implode('_', array_slice($parts, 0, 2)); // Max 2 parts for readability
    }

    /**
     * Get aggregated acquisition segments for a project.
     *
     * @param Project $project
     * @return \Illuminate\Support\Collection
     */
    public function getAggregatedSegments(Project $project): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'general')
            ->where('segment_type', 'acquisition')
            ->select(
                'segment_key',
                \DB::raw('COUNT(DISTINCT device_id) as device_count')
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
        $parts = explode('_', str_replace('acq_', '', $segmentKey));
        return ucwords(implode(' / ', $parts));
    }

    /**
     * Get acquisition segment options for filtering.
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
}
