<?php

namespace App\Services\Attribution;

use App\Models\Journey;

class LinearAttributionService
{
    /**
     * Calculate linear attribution scores for a journey.
     * Linear attribution gives equal credit to all touchpoints.
     *
     * @param array $touchpoints Array of touchpoints with engagement data
     * @return array Attribution scores per touchpoint
     */
    public function calculate(array $touchpoints): array
    {
        if (empty($touchpoints)) {
            return [];
        }

        $count = count($touchpoints);
        $score = 1.0 / $count;

        $attribution = [];
        foreach ($touchpoints as $index => $touchpoint) {
            $stepId = $touchpoint['step_id'] ?? "step_{$index}";
            $attribution[$stepId] = [
                'step_id' => $stepId,
                'model_name' => 'linear',
                'raw_score' => round($score, 6),
                'position' => $index + 1,
            ];
        }

        return $attribution;
    }

    /**
     * Calculate linear attribution for a journey.
     *
     * @param Journey $journey
     * @return array
     */
    public function calculateForJourney(Journey $journey): array
    {
        // This would typically fetch touchpoints from the journey
        // For now, we'll return a structure that matches the expected format
        $touchpoints = $this->getJourneyTouchpoints($journey);

        return $this->calculate($touchpoints);
    }

    /**
     * Get touchpoints for a journey.
     *
     * @param Journey $journey
     * @return array
     */
    private function getJourneyTouchpoints(Journey $journey): array
    {
        // This would typically fetch from events or journey steps table
        // For now, return a placeholder
        return [];
    }
}
