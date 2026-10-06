<?php

namespace App\Services\Attribution;

use App\Models\Journey;

class TimeDecayAttributionService
{
    /**
     * Half-life in hours for the time decay function.
     * Touchpoints closer to conversion get more credit.
     */
    private const HALF_LIFE_HOURS = 168; // 7 days

    /**
     * Calculate time-decay attribution scores for a journey.
     * Touchpoints closer to conversion get exponentially more credit.
     * Uses a half-life decay function with 168-hour (7-day) half-life.
     *
     * @param array $touchpoints Array of touchpoints with timestamps
     * @param int $conversionTimestamp Unix timestamp of conversion
     * @return array Attribution scores per touchpoint
     */
    public function calculate(array $touchpoints, int $conversionTimestamp): array
    {
        if (empty($touchpoints)) {
            return [];
        }

        $attribution = [];
        $totalWeight = 0;
        $weights = [];

        // Calculate decay weight for each touchpoint
        foreach ($touchpoints as $index => $touchpoint) {
            $stepId = $touchpoint['step_id'] ?? "step_{$index}";
            $touchpointTimestamp = $touchpoint['timestamp'] ?? $conversionTimestamp;

            $hoursFromConversion = max(0, ($conversionTimestamp - $touchpointTimestamp) / 3600);

            // Time decay formula: 0.5 ^ (hours / half_life)
            $weight = pow(0.5, $hoursFromConversion / self::HALF_LIFE_HOURS);
            $weights[$stepId] = $weight;
            $totalWeight += $weight;

            $attribution[$stepId] = [
                'step_id' => $stepId,
                'model_name' => 'time_decay',
                'raw_score' => 0, // Will calculate after normalization
                'position' => $index + 1,
                'hours_from_conversion' => $hoursFromConversion,
                'weight' => $weight,
            ];
        }

        // Normalize weights to sum to 1.0
        if ($totalWeight > 0) {
            foreach ($attribution as $stepId => $data) {
                $attribution[$stepId]['raw_score'] = round(
                    $weights[$stepId] / $totalWeight,
                    6
                );
            }
        }

        return $attribution;
    }

    /**
     * Calculate time-decay attribution for a journey.
     *
     * @param Journey $journey
     * @return array
     */
    public function calculateForJourney(Journey $journey): array
    {
        $touchpoints = $this->getJourneyTouchpoints($journey);
        $conversionTimestamp = $journey->goal_completed_ts?
            ->getTimestamp() : now()->getTimestamp();

        return $this->calculate($touchpoints, $conversionTimestamp);
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
