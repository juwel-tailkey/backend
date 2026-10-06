<?php

namespace App\Services\Segment\General;

use App\Models\UserSegment;
use App\Services\BigQuery\BigQueryClientFactory;
use App\Models\Project;

class TechnologySegmentService
{
    /**
     * Calculate technology segments for a project.
     * Technology segments are based on device category, OS, and browser.
     *
     * @param Project $project
     * @param array $dateRange
     * @return int Number of devices segmented
     */
    public function calculateForProject(Project $project, array $dateRange = []): int
    {
        $client = BigQueryClientFactory::forProject($project);

        // Query to get technology profile for each device
        $query = $this->buildTechnologyQuery($project, $dateRange);

        $results = $client->runQuery($query);
        $segmented = 0;

        foreach ($results as $row) {
            $deviceId = $row['device_id'] ?? null;
            if (!$deviceId) {
                continue;
            }

            // Create segment key from technology dimensions
            $deviceCategory = $row['device_category'] ?? 'unknown';
            $os = $row['operating_system'] ?? 'unknown';
            $browser = $row['browser'] ?? 'unknown';

            $segmentKey = $this->generateSegmentKey($deviceCategory, $os, $browser);
            $segmentData = [
                'device_category' => $deviceCategory,
                'operating_system' => $os,
                'browser' => $browser,
                'browser_version' => $row['browser_version'] ?? null,
                'mobile_model_name' => $row['mobile_model_name'] ?? null,
                'mobile_branding' => $row['mobile_branding'] ?? null,
            ];

            // Create or update user segment
            UserSegment::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'device_id' => $deviceId,
                    'segment_family' => 'general',
                    'segment_type' => 'technology',
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
     * Build BigQuery query for technology segmentation.
     *
     * @param Project $project
     * @param array $dateRange
     * @return string
     */
    private function buildTechnologyQuery(Project $project, array $dateRange): string
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
            WITH device_technology AS (
                SELECT
                    user_pseudo_id as device_id,
                    device.category as device_category,
                    device.operating_system as operating_system,
                    device.web_info.browser as browser,
                    device.web_info.browser_version as browser_version,
                    device.mobile_model_name as mobile_model_name,
                    device.mobile_branding as mobile_branding,
                    COUNT(*) as event_count
                FROM
                    {$eventsTable}
                WHERE
                    1=1
                    {$dateFilter}
                GROUP BY
                    user_pseudo_id,
                    device.category,
                    device.operating_system,
                    device.web_info.browser,
                    device.web_info.browser_version,
                    device.mobile_model_name,
                    device.mobile_branding
            )
            SELECT
                device_id,
                device_category,
                operating_system,
                browser,
                browser_version,
                mobile_model_name,
                mobile_branding
            FROM
                device_technology
            ORDER BY
                event_count DESC
        ";
    }

    /**
     * Generate segment key from technology dimensions.
     *
     * @param string $deviceCategory
     * @param string $os
     * @param string $browser
     * @return string
     */
    private function generateSegmentKey(string $deviceCategory, string $os, string $browser): string
    {
        $parts = array_filter([
            strtolower($deviceCategory),
            strtolower($os),
            strtolower($browser),
        ]);

        return 'tech_' . implode('_', $parts);
    }

    /**
     * Get aggregated technology segments for a project.
     *
     * @param Project $project
     * @return \Illuminate\Support\Collection
     */
    public function getAggregatedSegments(Project $project): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'general')
            ->where('segment_type', 'technology')
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
        $parts = explode('_', str_replace('tech_', '', $segmentKey));
        return ucwords(implode(' / ', $parts));
    }

    /**
     * Get technology segment options for filtering.
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
