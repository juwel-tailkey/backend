<?php

namespace App\Services\Segment\Attribution;

use App\Models\UserSegment;
use App\Models\JourneyStrength;
use App\Models\Project;

class JourneyStrengthSegmentService
{
    /**
     * Calculate journey strength tier segments.
     * Devices are segmented by journey quality: high, moderate, or low strength.
     *
     * @param Project $project
     * @param string $goalId
     * @return int Number of devices segmented
     */
    public function calculateForProject(Project $project, string $goalId): int
    {
        $segmented = 0;

        // Get all journey strengths for this goal
        $journeyStrengths = JourneyStrength::where('project_id', $project->id)
            ->where('goal_id', $goalId)
            ->with('journey')
            ->get();

        foreach ($journeyStrengths as $strength) {
            $journey = $strength->journey;
            if (!$journey) {
                continue;
            }

            $segmentKey = $this->generateSegmentKey($goalId, $strength->strength_tier);
            $segmentData = [
                'goal_id' => $goalId,
                'journey_id' => $strength->journey_id,
                'strength_score' => (float) $strength->strength_score,
                'strength_tier' => $strength->strength_tier,
                'diversity' => (float) $strength->diversity,
                'length_fit' => (float) $strength->length_fit,
                'balance' => (float) $strength->balance,
                'context' => (float) $strength->context,
                'goal_achieved' => (bool) $journey->goal_achieved,
            ];

            // Create or update user segment
            UserSegment::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'device_id' => $journey->device_id,
                    'segment_family' => 'attribution',
                    'segment_type' => 'journey_strength',
                    'segment_key' => $segmentKey,
                ],
                [
                    'segment_data' => $segmentData,
                    'assigned_at' => now(),
                    'expires_at' => now()->addDays(30),
                ]
            );

            $segmented++;
        }

        return $segmented;
    }

    /**
     * Generate segment key from journey strength tier.
     *
     * @param string $goalId
     * @param string $strengthTier
     * @return string
     */
    private function generateSegmentKey(string $goalId, string $strengthTier): string
    {
        return "strength_{$goalId}_{$strengthTier}";
    }

    /**
     * Get aggregated journey strength segments for a project.
     *
     * @param Project $project
     * @param string $goalId
     * @return \Illuminate\Support\Collection
     */
    public function getAggregatedSegments(Project $project, string $goalId): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'attribution')
            ->where('segment_type', 'journey_strength')
            ->where('expires_at', '>', now())
            ->select(
                'segment_key',
                \DB::raw('COUNT(DISTINCT device_id) as device_count'),
                \DB::raw('AVG(CAST(JSON_EXTRACT(segment_data, "$.strength_score") AS DECIMAL(10,6))) as avg_strength_score')
            )
            ->groupBy('segment_key')
            ->orderByDesc('device_count')
            ->get()
            ->map(function ($item) {
                $tier = $this->parseTierFromKey($item->segment_key);
                $item->segment_label = $this->getTierLabel($tier);
                $item->strength_tier = $tier;
                return $item;
            });
    }

    /**
     * Parse tier from segment key.
     *
     * @param string $segmentKey
     * @return string
     */
    private function parseTierFromKey(string $segmentKey): string
    {
        $parts = explode('_', $segmentKey);
        return end($parts);
    }

    /**
     * Get human-readable label for tier.
     *
     * @param string $tier
     * @return string
     */
    private function getTierLabel(string $tier): string
    {
        return match($tier) {
            'high_strength' => 'High Strength Journeys',
            'moderate_strength' => 'Moderate Strength Journeys',
            'low_strength' => 'Low Strength Journeys',
            default => 'Unknown Strength',
        };
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
                    'avg_score' => round($item->avg_strength_score, 3),
                ];
            })
            ->sortByDesc('avg_score')
            ->values()
            ->toArray();
    }

    /**
     * Get high-strength non-converters for retargeting.
     *
     * @param Project $project
     * @param string $goalId
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getHighStrengthNonConverters(
        Project $project,
        string $goalId,
        int $limit = 100
    ): \Illuminate\Support\Collection {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'attribution')
            ->where('segment_type', 'journey_strength')
            ->where('segment_key', 'LIKE', '%high_strength')
            ->where('expires_at', '>', now())
            ->select('device_id', 'segment_data')
            ->limit($limit)
            ->get()
            ->filter(function ($item) {
                return !($item->segment_data['goal_achieved'] ?? false);
            })
            ->map(function ($item) {
                $item->strength_score = (float) ($item->segment_data['strength_score'] ?? 0);
                $item->journey_id = $item->segment_data['journey_id'] ?? null;
                return $item;
            });
    }

    /**
     * Get strength tier distribution.
     *
     * @param Project $project
     * @param string $goalId
     * @return array
     */
    public function getStrengthDistribution(Project $project, string $goalId): array
    {
        $segments = $this->getAggregatedSegments($project, $goalId);

        return [
            'high_strength' => $segments->firstWhere('strength_tier', 'high_strength'),
            'moderate_strength' => $segments->firstWhere('strength_tier', 'moderate_strength'),
            'low_strength' => $segments->firstWhere('strength_tier', 'low_strength'),
        ];
    }
}
