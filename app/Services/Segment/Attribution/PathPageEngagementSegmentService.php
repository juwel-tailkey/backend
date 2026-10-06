<?php

namespace App\Services\Segment\Attribution;

use App\Models\UserSegment;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class PathPageEngagementSegmentService
{
    /**
     * Calculate path-page engagement gap segments.
     * Identifies pages where model trust ≠ actual engagement.
     *
     * @param Project $project
     * @param string $goalId
     * @param float|null $gapThreshold
     * @return int Number of pages segmented
     */
    public function calculateForProject(
        Project $project,
        string $goalId,
        ?float $gapThreshold = null
    ): int {
        $gapThreshold = $gapThreshold ?? 0.3; // Default 30% gap threshold

        // This would typically calculate engagement scores using the algorithm
        // from the specification and compare with assembly scores

        $segmented = 0;

        // Get paths with engagement gap data
        // This is a placeholder - actual implementation would query calculated engagement data
        $engagementGaps = $this->getEngagementGapData($project, $goalId, $gapThreshold);

        foreach ($engagementGaps as $gap) {
            $pagePath = $gap['page_path'] ?? null;
            if (!$pagePath) {
                continue;
            }

            $segmentKey = $this->generateSegmentKey($goalId, $pagePath);
            $segmentData = [
                'goal_id' => $goalId,
                'page_path' => $pagePath,
                'assembly_score' => (float) ($gap['assembly_score'] ?? 0),
                'path_page_engagement' => (float) ($gap['path_page_engagement'] ?? 0),
                'credit_engagement_gap' => (float) ($gap['credit_engagement_gap'] ?? 0),
                'gap_threshold' => $gapThreshold,
            ];

            // Create segment for gap analysis (this is a diagnostic segment, not per-device)
            UserSegment::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'device_id' => 'diagnostic', // Not device-specific
                    'segment_family' => 'attribution',
                    'segment_type' => 'engagement_gap',
                    'segment_key' => $segmentKey,
                ],
                [
                    'segment_data' => $segmentData,
                    'assigned_at' => now(),
                    'expires_at' => now()->addDays(7),
                ]
            );

            $segmented++;
        }

        return $segmented;
    }

    /**
     * Get engagement gap data (placeholder).
     *
     * @param Project $project
     * @param string $goalId
     * @param float $gapThreshold
     * @return array
     */
    private function getEngagementGapData(Project $project, string $goalId, float $gapThreshold): array
    {
        // This would typically:
        // 1. Calculate path-page engagement using the time-dampened algorithm
        // 2. Compare with assembly scores
        // 3. Return pages where gap >= threshold

        return [];
    }

    /**
     * Generate segment key.
     *
     * @param string $goalId
     * @param string $pagePath
     * @return string
     */
    private function generateSegmentKey(string $goalId, string $pagePath): string
    {
        $pagePart = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', substr($pagePath, 0, 50)));
        return "engagement_gap_{$goalId}_{$pagePart}";
    }

    /**
     * Get pages with high engagement gaps.
     *
     * @param Project $project
     * @param string $goalId
     * @return \Illuminate\Support\Collection
     */
    public function getHighGapPages(Project $project, string $goalId): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'attribution')
            ->where('segment_type', 'engagement_gap')
            ->where('expires_at', '>', now())
            ->select('segment_key', 'segment_data')
            ->get()
            ->map(function ($item) {
                $data = $item->segment_data;
                $item->page_path = $data['page_path'] ?? null;
                $item->assembly_score = (float) ($data['assembly_score'] ?? 0);
                $item->path_page_engagement = (float) ($data['path_page_engagement'] ?? 0);
                $item->credit_engagement_gap = (float) ($data['credit_engagement_gap'] ?? 0);
                return $item;
            })
            ->sortByDesc('credit_engagement_gap')
            ->values();
    }

    /**
     * Calculate path-page engagement score (from specification).
     *
     * @param float $engagementTimeMsec
     * @return float
     */
    public function calculateTimeScore(float $engagementTimeMsec): float
    {
        $seconds = max(0, $engagementTimeMsec / 1000);
        $timeScoreCap = 300; // 5 minutes

        $score = log1p($seconds / 30) / log1p($timeScoreCap / 30);

        return min(1.0, $score);
    }

    /**
     * Calculate position weight (from specification).
     *
     * @param int $stepIndex
     * @param int $totalSteps
     * @return float
     */
    public function calculatePositionWeight(int $stepIndex, int $totalSteps): float
    {
        $stepsFromConversion = $totalSteps - 1 - $stepIndex;
        $halfLifeSteps = 3;

        return pow(0.5, $stepsFromConversion / $halfLifeSteps);
    }

    /**
     * Calculate path-page engagement for a converting path.
     *
     * @param array $pathSteps Array of steps with engagement_time_msec
     * @return array Normalized engagement scores
     */
    public function calculatePathPageEngagement(array $pathSteps): array
    {
        $totalSteps = count($pathSteps);
        if ($totalSteps === 0) {
            return [];
        }

        $engagementScores = [];

        // Calculate time scores and position weights
        foreach ($pathSteps as $index => $step) {
            $engagementTime = $step['engagement_time_msec'] ?? 0;
            $timeScore = $this->calculateTimeScore($engagementTime);
            $positionWeight = $this->calculatePositionWeight($index, $totalSteps);

            $engagementScores[$index] = [
                'page_path' => $step['page_path'] ?? null,
                'step_index' => $index,
                'time_score' => $timeScore,
                'position_weight' => $positionWeight,
                'raw_score' => $timeScore * $positionWeight,
            ];
        }

        // Calculate total path score
        $totalPathScore = array_sum(array_column($engagementScores, 'raw_score'));

        // Exclude zero-engagement paths
        if ($totalPathScore === 0) {
            return [];
        }

        // Normalize to sum to 1.0
        foreach ($engagementScores as $index => $score) {
            $engagementScores[$index]['path_page_engagement'] = round(
                $score['raw_score'] / $totalPathScore,
                4
            );
        }

        return $engagementScores;
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
        return $this->getHighGapPages($project, $goalId)
            ->filter(function ($item) {
                return $item->credit_engagement_gap >= 0.3;
            })
            ->map(function ($item) {
                return [
                    'value' => $item->segment_key,
                    'label' => "{$item->page_path} (Gap: " . round($item->credit_engagement_gap * 100, 1) . "%)",
                    'page_path' => $item->page_path,
                    'gap' => $item->credit_engagement_gap,
                ];
            })
            ->toArray();
    }
}
