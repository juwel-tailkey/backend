<?php

namespace App\Services\Segment\Attribution;

use App\Models\UserSegment;
use App\Models\Project;
use App\Services\Attribution\AssemblyScoreService;
use Illuminate\Support\Facades\DB;

class HighAssemblySegmentService
{
    protected AssemblyScoreService $assemblyScoreService;

    public function __construct(AssemblyScoreService $assemblyScoreService)
    {
        $this->assemblyScoreService = $assemblyScoreService;
    }

    /**
     * Calculate high-assembly touchpoint engager segments.
     * Devices that interacted with high-scoring touchpoints.
     *
     * @param Project $project
     * @param string $goalId
     * @param float|null $threshold
     * @return int Number of devices segmented
     */
    public function calculateForProject(
        Project $project,
        string $goalId,
        ?float $threshold = null
    ): int {
        $threshold = $threshold ?? $this->assemblyScoreService->getHighAssemblyThreshold(
            $project->id,
            $goalId
        );

        // Get high-assembly engagers from the service
        $engagers = $this->assemblyScoreService->getHighAssemblyEngagers(
            $project->id,
            $goalId,
            $threshold
        );

        $segmented = 0;

        foreach ($engagers as $engager) {
            $segmentKey = $this->generateSegmentKey($goalId, $threshold);
            $segmentData = [
                'goal_id' => $goalId,
                'high_score_touchpoint_count' => (int) $engager->high_score_touchpoint_count,
                'touchpoint_engagement_score' => (float) $engager->touchpoint_engagement_score,
                'threshold' => $threshold,
            ];

            // Create or update user segment
            UserSegment::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'device_id' => $engager->device_id,
                    'segment_family' => 'attribution',
                    'segment_type' => 'high_assembly_engager',
                    'segment_key' => $segmentKey,
                ],
                [
                    'segment_data' => $segmentData,
                    'assigned_at' => now(),
                    'expires_at' => now()->addDays(30), // Expire after 30 days
                ]
            );

            $segmented++;
        }

        return $segmented;
    }

    /**
     * Generate segment key.
     *
     * @param string $goalId
     * @param float $threshold
     * @return string
     */
    private function generateSegmentKey(string $goalId, float $threshold): string
    {
        return "high_assembly_{$goalId}_" . round($threshold * 100);
    }

    /**
     * Get aggregated high-assembly segments for a project.
     *
     * @param Project $project
     * @param string $goalId
     * @return \Illuminate\Support\Collection
     */
    public function getAggregatedSegments(Project $project, string $goalId): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'attribution')
            ->where('segment_type', 'high_assembly_engager')
            ->where('expires_at', '>', now())
            ->select(
                'segment_key',
                DB::raw('COUNT(DISTINCT device_id) as device_count'),
                DB::raw('AVG(CAST(JSON_EXTRACT(segment_data, "$.high_score_touchpoint_count") AS UNSIGNED)) as avg_touchpoint_count'),
                DB::raw('AVG(CAST(JSON_EXTRACT(segment_data, "$.touchpoint_engagement_score") AS DECIMAL(10,6))) as avg_engagement_score')
            )
            ->groupBy('segment_key')
            ->orderByDesc('device_count')
            ->get()
            ->map(function ($item) {
                $item->segment_label = "High-Assembly Engagers ({$item->device_count} devices)";
                return $item;
            });
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
                    'label' => $item->segment_label,
                    'count' => $item->device_count,
                ];
            })
            ->toArray();
    }

    /**
     * Get high-assembly engagers ranked by engagement score.
     *
     * @param Project $project
     * @param string $goalId
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getTopEngagers(Project $project, string $goalId, int $limit = 100): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'attribution')
            ->where('segment_type', 'high_assembly_engager')
            ->where('expires_at', '>', now())
            ->orderByRaw('CAST(JSON_EXTRACT(segment_data, "$.touchpoint_engagement_score") AS DECIMAL(10,6)) DESC')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                $item->touchpoint_count = (int) ($item->segment_data['high_score_touchpoint_count'] ?? 0);
                $item->engagement_score = (float) ($item->segment_data['touchpoint_engagement_score'] ?? 0);
                return $item;
            });
    }
}
