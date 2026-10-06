<?php

namespace App\Services\Segment\General;

use App\Models\UserSegment;
use App\Models\TrackedEvent;
use App\Services\BigQuery\BigQueryClientFactory;
use App\Models\Project;

class BehavioralSegmentService
{
    /**
     * Calculate behavioral segments for a project.
     * Behavioral segments are based on tracked events configured for the project.
     *
     * @param Project $project
     * @param array $dateRange
     * @return int Number of devices segmented
     */
    public function calculateForProject(Project $project, array $dateRange = []): int
    {
        // Get tracked events for this project
        $trackedEvents = TrackedEvent::where('project_id', $project->id)
            ->active()
            ->get();

        if ($trackedEvents->isEmpty()) {
            return 0;
        }

        $client = BigQueryClientFactory::forProject($project);
        $segmented = 0;

        foreach ($trackedEvents as $trackedEvent) {
            $query = $this->buildBehavioralQuery($project, $trackedEvent, $dateRange);
            $results = $client->runQuery($query);

            foreach ($results as $row) {
                $deviceId = $row['device_id'] ?? null;
                if (!$deviceId) {
                    continue;
                }

                $segmentKey = $this->generateSegmentKey($trackedEvent->event_key);
                $segmentData = [
                    'event_key' => $trackedEvent->event_key,
                    'event_name' => $trackedEvent->event_name,
                    'event_category' => $trackedEvent->event_category,
                    'event_count' => (int) ($row['event_count'] ?? 0),
                    'last_occurred' => $row['last_occurred'] ?? null,
                ];

                // Create or update user segment
                UserSegment::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'device_id' => $deviceId,
                        'segment_family' => 'general',
                        'segment_type' => 'behavioral',
                        'segment_key' => $segmentKey,
                    ],
                    [
                        'segment_data' => $segmentData,
                        'assigned_at' => now(),
                    ]
                );

                $segmented++;
            }
        }

        return $segmented;
    }

    /**
     * Build BigQuery query for behavioral segmentation.
     *
     * @param Project $project
     * @param TrackedEvent $trackedEvent
     * @param array $dateRange
     * @return string
     */
    private function buildBehavioralQuery(
        Project $project,
        TrackedEvent $trackedEvent,
        array $dateRange
    ): string {
        $dataset = $project->dataset_id;
        $eventsTable = "`{$project->gcp_project_id}.{$dataset}.events_*`";
        $eventName = $trackedEvent->event_name;

        $dateFilter = "";
        if (!empty($dateRange)) {
            $startDate = $dateRange['start'];
            $endDate = $dateRange['end'];
            $dateFilter = "AND event_date BETWEEN '{$startDate}' AND '{$endDate}'";
        }

        // Build parameter filters if specified
        $parameterFilter = "";
        if ($trackedEvent->parameters) {
            foreach ($trackedEvent->parameters as $key => $value) {
                $parameterFilter .= "AND (SELECT value.string_value FROM UNNEST(event_params) WHERE key = '{$key}') = '{$value}'";
            }
        }

        return "
            WITH event_behavior AS (
                SELECT
                    user_pseudo_id as device_id,
                    COUNT(*) as event_count,
                    MAX(event_timestamp) as last_occurred
                FROM
                    {$eventsTable}
                WHERE
                    event_name = '{$eventName}'
                    {$dateFilter}
                    {$parameterFilter}
                GROUP BY
                    user_pseudo_id
            )
            SELECT
                device_id,
                event_count,
                TIMESTAMP_MICROS(last_occurred) as last_occurred
            FROM
                event_behavior
        ";
    }

    /**
     * Generate segment key from event key.
     *
     * @param string $eventKey
     * @return string
     */
    private function generateSegmentKey(string $eventKey): string
    {
        return 'behavior_' . strtolower($eventKey);
    }

    /**
     * Get aggregated behavioral segments for a project.
     *
     * @param Project $project
     * @return \Illuminate\Support\Collection
     */
    public function getAggregatedSegments(Project $project): \Illuminate\Support\Collection
    {
        $trackedEvents = TrackedEvent::where('project_id', $project->id)
            ->active()
            ->get()
            ->keyBy('event_key');

        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'general')
            ->where('segment_type', 'behavioral')
            ->select(
                'segment_key',
                \DB::raw('COUNT(DISTINCT device_id) as device_count'),
                \DB::raw('SUM(CAST(JSON_EXTRACT(segment_data, "$.event_count") AS UNSIGNED)) as total_events')
            )
            ->groupBy('segment_key')
            ->orderByDesc('device_count')
            ->get()
            ->map(function ($item) use ($trackedEvents) {
                $eventKey = str_replace('behavior_', '', $item->segment_key);
                $trackedEvent = $trackedEvents->get($eventKey);

                $item->segment_label = $trackedEvent
                    ? ($trackedEvent->event_name . ' (' . $trackedEvent->event_category . ')')
                    : ucwords(str_replace('_', ' ', $eventKey));

                $item->event_category = $trackedEvent->event_category ?? null;
                return $item;
            });
    }

    /**
     * Get behavioral segment options for filtering.
     *
     * @param Project $project
     * @param string|null $category
     * @return array
     */
    public function getSegmentOptions(Project $project, ?string $category = null): array
    {
        $query = $this->getAggregatedSegments($project);

        if ($category) {
            $query = $query->where('event_category', $category);
        }

        return $query->map(function ($item) {
            return [
                'value' => $item->segment_key,
                'label' => $item->segment_label . " ({$item->device_count})",
                'count' => $item->device_count,
                'total_events' => $item->total_events,
            ];
        })->toArray();
    }

    /**
     * Get devices that performed a specific behavior.
     *
     * @param Project $project
     * @param string $eventKey
     * @return \Illuminate\Support\Collection
     */
    public function getDevicesForBehavior(Project $project, string $eventKey): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'general')
            ->where('segment_type', 'behavioral')
            ->where('segment_key', $this->generateSegmentKey($eventKey))
            ->where('expires_at', '>', now()) // Only active segments
            ->select('device_id', 'segment_data')
            ->get();
    }
}
