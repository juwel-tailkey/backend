<?php

namespace App\Services\Segment\Attribution;

use App\Models\UserSegment;
use App\Models\Journey;
use App\Models\Project;
use Carbon\Carbon;

class TimeBoundDropOffSegmentService
{
    /**
     * Calculate time-bound high-intent drop-off segments with urgency tiers.
     * Identifies users who haven't returned and are still in the retargeting window.
     *
     * @param Project $project
     * @param string $goalId
     * @param int|null $minWaitDays
     * @param int|null $retargetDeadlineDays
     * @return int Number of devices segmented
     */
    public function calculateForProject(
        Project $project,
        string $goalId,
        ?int $minWaitDays = null,
        ?int $retargetDeadlineDays = null
    ): int {
        $minWaitDays = $minWaitDays ?? 3; // Default minimum wait
        $retargetDeadlineDays = $retargetDeadlineDays ?? $this->calculateEmpiricalDeadline($project, $goalId);

        $segmented = 0;

        // Get non-converting journeys
        $nonConverters = Journey::where('project_id', $project->id)
            ->where('goal_id', $goalId)
            ->where('goal_achieved', false)
            ->get();

        foreach ($nonConverters as $journey) {
            // Calculate days since drop-off (using journey's last event)
            $lastEventDate = $journey->journey_ts ?? $journey->created_at;
            $daysSinceDropoff = Carbon::now()->diffInDays($lastEventDate);

            // Determine urgency tier
            $urgencyTier = $this->determineUrgencyTier($daysSinceDropoff, $minWaitDays, $retargetDeadlineDays);
            $daysRemaining = max(0, $retargetDeadlineDays - $daysSinceDropoff);

            $segmentKey = $this->generateSegmentKey($goalId, $urgencyTier);
            $segmentData = [
                'goal_id' => $goalId,
                'journey_id' => $journey->journey_id,
                'days_since_dropoff' => $daysSinceDropoff,
                'retarget_deadline_days' => $retargetDeadlineDays,
                'days_remaining' => $daysRemaining,
                'urgency_tier' => $urgencyTier,
                'min_wait_days' => $minWaitDays,
            ];

            // Create or update user segment
            UserSegment::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'device_id' => $journey->device_id,
                    'segment_family' => 'attribution',
                    'segment_type' => 'time_bound_drop_off',
                    'segment_key' => $segmentKey,
                ],
                [
                    'segment_data' => $segmentData,
                    'assigned_at' => now(),
                    'expires_at' => $urgencyTier === 'likely_lost' ? now()->addDays(7) : now()->addDays(14),
                ]
            );

            $segmented++;
        }

        return $segmented;
    }

    /**
     * Calculate empirical retargeting deadline from historical data.
     *
     * @param Project $project
     * @param string $goalId
     * @return int
     */
    private function calculateEmpiricalDeadline(Project $project, string $goalId): int
    {
        // This would analyze historical return probability data
        // to find when marginal return rate drops below 1%
        // For now, return default
        return 14;
    }

    /**
     * Determine urgency tier based on days since drop-off.
     *
     * @param int $daysSinceDropoff
     * @param int $minWaitDays
     * @param int $retargetDeadlineDays
     * @return string
     */
    private function determineUrgencyTier(
        int $daysSinceDropoff,
        int $minWaitDays,
        int $retargetDeadlineDays
    ): string {
        if ($daysSinceDropoff < $minWaitDays) {
            return 'too_early';
        }

        if ($daysSinceDropoff <= $retargetDeadlineDays) {
            return 'retarget_now';
        }

        return 'likely_lost';
    }

    /**
     * Generate segment key.
     *
     * @param string $goalId
     * @param string $urgencyTier
     * @return string
     */
    private function generateSegmentKey(string $goalId, string $urgencyTier): string
    {
        return "timebound_{$goalId}_{$urgencyTier}";
    }

    /**
     * Get urgency tier distribution.
     *
     * @param Project $project
     * @param string $goalId
     * @return array
     */
    public function getUrgencyDistribution(Project $project, string $goalId): array
    {
        $segments = UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'attribution')
            ->where('segment_type', 'time_bound_drop_off')
            ->where('expires_at', '>', now())
            ->select('segment_key', \DB::raw('COUNT(DISTINCT device_id) as device_count'))
            ->groupBy('segment_key')
            ->get()
            ->keyBy('segment_key');

        return [
            'too_early' => $segments->get("timebound_{$goalId}_too_early"),
            'retarget_now' => $segments->get("timebound_{$goalId}_retarget_now"),
            'likely_lost' => $segments->get("timebound_{$goalId}_likely_lost"),
        ];
    }

    /**
     * Get devices that need retargeting now.
     *
     * @param Project $project
     * @param string $goalId
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getRetargetNowDevices(Project $project, string $goalId, int $limit = 100): \Illuminate\Support\Collection
    {
        return UserSegment::where('project_id', $project->id)
            ->where('segment_family', 'attribution')
            ->where('segment_type', 'time_bound_drop_off')
            ->where('segment_key', 'like', '%retarget_now')
            ->where('expires_at', '>', now())
            ->with('device')
            ->orderByRaw('CAST(JSON_EXTRACT(segment_data, "$.days_remaining") AS UNSIGNED) ASC')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                $data = $item->segment_data;
                $item->days_since_dropoff = (int) ($data['days_since_dropoff'] ?? 0);
                $item->days_remaining = (int) ($data['days_remaining'] ?? 0);
                $item->urgency_score = max(0, 100 - ($item->days_remaining * 10)); // Higher urgency = fewer days remaining
                return $item;
            });
    }

    /**
     * Get retargeting return curve data.
     *
     * @param Project $project
     * @param string $goalId
     * @return array
     */
    public function getReturnCurveData(Project $project, string $goalId): array
    {
        // This would calculate historical return probabilities
        // For now, return placeholder structure
        return [
            'deadline_days' => 14,
            'marginal_return_floor' => 0.01,
            'is_empirical' => false,
            'curve' => [
                ['day' => 1, 'cumulative_return_rate' => 0.15, 'marginal_rate' => 0.15],
                ['day' => 3, 'cumulative_return_rate' => 0.35, 'marginal_rate' => 0.10],
                ['day' => 7, 'cumulative_return_rate' => 0.55, 'marginal_rate' => 0.05],
                ['day' => 14, 'cumulative_return_rate' => 0.70, 'marginal_rate' => 0.02],
            ],
        ];
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
        $distribution = $this->getUrgencyDistribution($project, $goalId);

        $labels = [
            'too_early' => 'Too Early to Retarget',
            'retarget_now' => 'Retarget Now',
            'likely_lost' => 'Likely Lost',
        ];

        $options = [];
        foreach ($distribution as $tier => $data) {
            if ($data) {
                $options[] = [
                    'value' => $data->segment_key,
                    'label' => $labels[$tier] . " ({$data->device_count})",
                    'count' => $data->device_count,
                    'tier' => $tier,
                ];
            }
        }

        return $options;
    }
}
