<?php

namespace App\Services\Attribution;

use App\Models\Journey;

class LastClickAttributionService
{
    /**
     * Calculate last-click attribution scores for a journey.
     * Last-click attribution gives 100% credit to the last touchpoint.
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
        $attribution = [];

        foreach ($touchpoints as $index => $touchpoint) {
            $stepId = $touchpoint['step_id'] ?? "step_{$index}";

            if ($index === $count - 1) {
                // Last touchpoint gets all credit
                $attribution[$stepId] = [
                    'step_id' => $stepId,
                    'model_name' => 'last_click',
                    'raw_score' => 1.0,
                    'position' => $index + 1,
                ];
            } else {
                // All other touchpoints get zero
                $attribution[$stepId] = [
                    'step_id' => $stepId,
                    'model_name' => 'last_click',
                    'raw_score' => 0.0,
                    'position' => $index + 1,
                ];
            }
        }

        return $attribution;
    }

    /**
     * Calculate last-click attribution for a journey.
     *
     * @param Journey $journey
     * @return array
     */
    public function calculateForJourney(Journey $journey): array
    {
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
        return [];
    }
}
