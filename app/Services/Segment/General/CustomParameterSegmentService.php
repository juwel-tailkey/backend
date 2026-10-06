<?php

namespace App\Services\Segment\General;

use App\Models\UserSegment;
use App\Services\BigQuery\BigQueryClientFactory;
use App\Models\Project;

class CustomParameterSegmentService
{
    /**
     * Calculate custom parameter segments for a project.
     * Custom parameter segments are based on arbitrary event parameters.
     *
     * @param Project $project
     * @param string $eventName
     * @param string $parameterName
     * @param array $dateRange
     * @return int Number of devices segmented
     */
    public function calculateForProject(
        Project $project,
        string $eventName,
        string $parameterName,
        array $dateRange = []
    ): int {
        $client = BigQueryClientFactory::forProject($project);

        // Query to get parameter values for each device
        $query = $this->buildCustomParameterQuery($project, $eventName, $parameterName, $dateRange);

        $results = $client->runQuery($query);
        $segmented = 0;

        foreach ($results as $row) {
            $deviceId = $row['device_id'] ?? null;
            $paramValue = $row['param_value'] ?? null;

            if (!$deviceId || $paramValue === null) {
                continue;
            }

            $segmentKey = $this->generateSegmentKey($eventName, $parameterName, $paramValue);
            $segmentData = [
                'event_name' => $eventName,
                'parameter_name' => $parameterName,
                'parameter_value' => $paramValue,
                'occurrence_count' => (int) ($row['occurrence_count'] ?? 1),
            ];

            // Create or update user segment
            UserSegment::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'device_id' => $deviceId,
                    'segment_family' => 'general',
                    'segment_type' => 'custom_parameter',
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
     * Build BigQuery query for custom parameter segmentation.
     *
     * @param Project $project
     * @param string $eventName
     * @param string $parameterName
     * @param array $dateRange
     * @return string
     */
    private function buildCustomParameterQuery(
        Project $project,
        string $eventName,
        string $parameterName,
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
            WITH parameter_values AS (
                SELECT
                    user_pseudo_id as device_id,
                    (
                        SELECT COALESCE(value.string_value, CAST(value.int_value AS STRING), CAST(value.float_value AS STRING), CAST(value.double_value AS STRING))
                        FROM UNNEST(event_params)
                        WHERE key = '{$parameterName}'
                        LIMIT 1
                    ) as param_value,
                    COUNT(*) as occurrence_count
                FROM
                    {$eventsTable}
                WHERE
                    event_name = '{$eventName}'
                    {$dateFilter}
                GROUP BY
                    user_pseudo_id,
                    param_value
                HAVING
                    param_value IS NOT NULL
            )
            SELECT
                device_id,
                param_value,
                occurrence_count
            FROM
                parameter_values
            ORDER BY
                occurrence_count DESC
        ";
    }

    /**
     * Generate segment key from custom parameter dimensions.
     *
     * @param string $eventName
     * @param string $parameterName
     * @param string $paramValue
     * @return string
     */
    private function generateSegmentKey(string $eventName, string $parameterName, string $paramValue): string
    {
        $eventPart = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $eventName));
        $paramPart = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $parameterName));
        $valuePart = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', substr($paramValue, 0, 50))); // Limit length

        return "custom_{$eventPart}_{$paramPart}_{$valuePart}";
    }

    /**
     * Get aggregated custom parameter segments for a project.
     *
     * @param Project $project
     * @return \Illuminate\Support\Collection
     */
    public function getAggregatedSegments(Project $project): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'general')
            ->where('segment_type', 'custom_parameter')
            ->select(
                'segment_key',
                \DB::raw('COUNT(DISTINCT device_id) as device_count')
            )
            ->groupBy('segment_key')
            ->orderByDesc('device_count')
            ->limit(100) // Limit to top 100 most common
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
        // Remove 'custom_' prefix and parse components
        $pattern = '/custom_(.+?)_(.+?)_(.+)$/';
        if (preg_match($pattern, $segmentKey, $matches)) {
            $eventName = ucwords(str_replace('_', ' ', $matches[1]));
            $paramName = ucwords(str_replace('_', ' ', $matches[2]));
            $paramValue = ucwords(str_replace('_', ' ', $matches[3]));
            return "{$eventName} / {$paramName} = {$paramValue}";
        }

        return ucwords(str_replace('_', ' ', $segmentKey));
    }

    /**
     * Get custom parameter segment options for filtering.
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
     * Common custom parameter examples for SaaS/ecommerce.
     *
     * @return array
     */
    public function getCommonParameters(): array
    {
        return [
            [
                'event_name' => 'page_view',
                'parameter_name' => 'page_tier',
                'label' => 'Page Tier Viewed',
                'examples' => ['free', 'growth', 'enterprise'],
            ],
            [
                'event_name' => 'sign_up',
                'parameter_name' => 'plan_tier',
                'label' => 'Sign Up Plan Tier',
                'examples' => ['starter', 'professional', 'enterprise'],
            ],
            [
                'event_name' => 'purchase',
                'parameter_name' => 'product_category',
                'label' => 'Product Category Purchased',
                'examples' => ['electronics', 'clothing', 'books'],
            ],
            [
                'event_name' => 'content_view',
                'parameter_name' => 'content_type',
                'label' => 'Content Type Viewed',
                'examples' => ['blog', 'video', 'documentation'],
            ],
        ];
    }

    /**
     * Get unique parameter values for a specific event and parameter.
     *
     * @param Project $project
     * @param string $eventName
     * @param string $parameterName
     * @return array
     */
    public function getParameterValues(
        Project $project,
        string $eventName,
        string $parameterName
    ): array {
        $client = BigQueryClientFactory::forProject($project);

        $dataset = $project->dataset_id;
        $eventsTable = "`{$project->gcp_project_id}.{$dataset}.events_*`";

        $query = "
            SELECT DISTINCT
                (
                    SELECT COALESCE(value.string_value, CAST(value.int_value AS STRING))
                    FROM UNNEST(event_params)
                    WHERE key = '{$parameterName}'
                    LIMIT 1
                ) as param_value
            FROM
                {$eventsTable}
            WHERE
                event_name = '{$eventName}'
            LIMIT 100
        ";

        $results = $client->runQuery($query);

        return collect($results)
            ->pluck('param_value')
            ->filter()
            ->sort()
            ->values()
            ->toArray();
    }
}
