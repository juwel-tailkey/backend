<?php

namespace App\Services\Segment\Attribution;

use App\Models\UserSegment;
use App\Models\Journey;
use App\Models\JourneyStrength;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class DropOffSegmentService
{
    /**
     * Calculate attribution-aware drop-off / retargeting segments.
     * Identifies non-converting journeys with high-strength and critical touchpoints.
     *
     * @param Project $project
     * @param string $goalId
     * @param float|null $criticalThreshold
     * @return int Number of devices segmented
     */
    public function calculateForProject(
        Project $project,
        string $goalId,
        ?float $criticalThreshold = null
    ): int {
        $criticalThreshold = $criticalThreshold ?? 0.2; // Default threshold

        $segmented = 0;

        // Get non-converting journeys with journey strength
        $nonConverters = Journey::where('project_id', $project->id)
            ->where('goal_id', $goalId)
            ->where('goal_achieved', false)
            ->with('journeyStrength')
            ->get();

        foreach ($nonConverters as $journey) {
            $strength = $journey->journeyStrength;

            // Skip low-strength journeys (not credible near-misses)
            if ($strength && $strength->strength_score < 0.45) {
                continue;
            }

            // Find the exit touchpoint (last touchpoint before drop-off)
            $exitTouchpoint = $this->findExitTouchpoint($journey);

            if (!$exitTouchpoint) {
                continue;
            }

            $segmentKey = $this->generateSegmentKey($goalId, $exitTouchpoint);
            $segmentData = [
                'goal_id' => $goalId,
                'journey_id' => $journey->journey_id,
                'exit_touchpoint' => $exitTouchpoint,
                'strength_score' => $strength ? (float) $strength->strength_score : 0,
                'strength_tier' => $strength ? $strength->strength_tier : 'low_strength',
                'removal_effect' => $this->getTouchpointRemovalEffect($journey, $exitTouchpoint),
            ];

            // Create or update user segment
            UserSegment::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'device_id' => $journey->device_id,
                    'segment_family' => 'attribution',
                    'segment_type' => 'drop_off',
                    'segment_key' => $segmentKey,
                ],
                [
                    'segment_data' => $segmentData,
                    'assigned_at' => now(),
                    'expires_at' => now()->addDays(14), // Shorter expiry for drop-offs
                ]
            );

            $segmented++;
        }

        return $segmented;
    }

    /**
     * Find the exit touchpoint for a journey.
     *
     * @param Journey $journey
     * @return string|null
     */
    private function findExitTouchpoint(Journey $journey): ?string
    {
        // This would typically fetch the last touchpoint from journey steps
        // For now, return the source_step as a placeholder
        return $journey->source_step ?? null;
    }

    /**
     * Get removal effect for a touchpoint.
     *
     * @param Journey $journey
     * @param string $touchpoint
     * @return float
     */
    private function getTouchpointRemovalEffect(Journey $journey, string $touchpoint): float
    {
        // This would typically fetch from attribution_scores table
        // For now, return a placeholder value
        return 0.0;
    }

    /**
     * Generate segment key from goal and exit touchpoint.
     *
     * @param string $goalId
     * @param string $exitTouchpoint
     * @return string
     */
    private function generateSegmentKey(string $goalId, string $exitTouchpoint): string
    {
        $touchpointPart = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $exitTouchpoint));
        return "dropoff_{$goalId}_{$touchpointPart}";
    }

    /**
     * Get aggregated drop-off segments for a project.
     *
     * @param Project $project
     * @param string $goalId
     * @return \Illuminate\Support\Collection
     */
    public function getAggregatedSegments(Project $project, string $goalId): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'attribution')
            ->where('segment_type', 'drop_off')
            ->where('expires_at', '>', now())
            ->select(
                'segment_key',
                DB::raw('COUNT(DISTINCT device_id) as device_count'),
                DB::raw('AVG(CAST(JSON_EXTRACT(segment_data, "$.strength_score") AS DECIMAL(10,6))) as avg_strength_score'),
                DB::raw('AVG(CAST(JSON_EXTRACT(segment_data, "$.removal_effect") AS DECIMAL(10,6))) as avg_removal_effect')
            )
            ->groupBy('segment_key')
            ->orderByDesc('device_count')
            ->get()
            ->map(function ($item) {
                $exitTouchpoint = $this->parseTouchpointFromKey($item->segment_key);
                $item->segment_label = "Drop-off at: {$exitTouchpoint}";
                $item->exit_touchpoint = $exitTouchpoint;
                return $item;
            });
    }

    /**
     * Parse exit touchpoint from segment key.
     *
     * @param string $segmentKey
     * @return string
     */
    private function parseTouchpointFromKey(string $segmentKey): string
    {
        $parts = explode('_', $segmentKey);
        array_shift($parts); // Remove 'dropoff'
        array_shift($parts); // Remove goal_id
        return ucwords(str_replace('_', ' ', implode('_', $parts)));
    }

    /**
     * Get segment options for filtering.
     *
     * @param Project $project
     * @param string $goalId
     * @return array
     */
    public function getSegmentOptions(Project $project, string $goalId): array
    {
        return $this->getAggregatedSegments($project, $goalId)
            ->map(function ($item) {
                return [
                    'value' => $item->segment_key,
                    'label' => $item->segment_label . " ({$item->device_count})",
                    'count' => $item->device_count,
                    'exit_touchpoint' => $item->exit_touchpoint,
                ];
            })
            ->toArray();
    }

    /**
     * Get high-strength drop-offs prioritized by strength and removal effect.
     *
     * @param Project $project
     * @param string $goalId
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getHighStrengthDropOffs(
        Project $project,
        string $goalId,
        int $limit = 100
    ): \Illuminate\Support\Collection {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'attribution')
            ->where('segment_type', 'drop_off')
            ->where('expires_at', '>', now())
            ->whereRaw('CAST(JSON_EXTRACT(segment_data, "$.strength_score") AS DECIMAL(10,6)) >= 0.45')
            ->select('device_id', 'segment_key', 'segment_data')
            ->orderByRaw('CAST(JSON_EXTRACT(segment_data, "$.strength_score") AS DECIMAL(10,6)) DESC')
            ->orderByRaw('CAST(JSON_EXTRACT(segment_data, "$.removal_effect") AS DECIMAL(10,6)) DESC')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                $item->exit_touchpoint = $item->segment_data['exit_touchpoint'] ?? null;
                $item->strength_score = (float) ($item->segment_data['strength_score'] ?? 0);
                $item->removal_effect = (float) ($item->segment_data['removal_effect'] ?? 0);
                return $item;
            });
    }

    /**
     * Get top drop-off pages by device count.
     *
     * @param Project $project
     * @param string $goalId
     * @param int $limit
     * @return array
     */
    public function getTopDropOffPages(Project $project, string $goalId, int $limit = 10): array
    {
        return $this->getAggregatedSegments($project, $goalId)
            ->sortByDesc('device_count')
            ->take($limit)
            ->map(function ($item) {
                return [
                    'page' => $item->exit_touchpoint,
                    'device_count' => $item->device_count,
                    'avg_strength_score' => round($item->avg_strength_score, 3),
                    'avg_removal_effect' => round($item->avg_removal_effect, 3),
                ];
            })
            ->toArray();
    }
}
