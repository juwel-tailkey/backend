<?php

namespace App\Services\Attribution;

use App\Models\Journey;

class ShapleyAttributionService
{
    /**
     * Calculate Shapley value attribution for a journey.
     * Shapley values fairly distribute credit based on each touchpoint's
     * marginal contribution to all possible coalitions.
     *
     * @param array $touchpoints Array of touchpoints with baseline conversion values
     * @param float $baselineConversion Baseline conversion probability (no touchpoints)
     * @return array Attribution scores per touchpoint
     */
    public function calculate(array $touchpoints, float $baselineConversion = 0.0): array
    {
        $count = count($touchpoints);

        if ($count === 0) {
            return [];
        }

        if ($count === 1) {
            $stepId = $touchpoints[0]['step_id'] ?? 'step_0';
            return [
                $stepId => [
                    'step_id' => $stepId,
                    'model_name' => 'shapley',
                    'raw_score' => 1.0,
                    'position' => 1,
                ],
            ];
        }

        // For each touchpoint, calculate its marginal contribution
        // across all possible coalitions
        $attribution = [];
        $touchpointKeys = array_keys($touchpoints);

        foreach ($touchpointKeys as $index => $stepId) {
            $shapleyValue = 0.0;

            // Calculate marginal contribution for all coalition sizes
            for ($coalitionSize = 0; $coalitionSize < $count; $coalitionSize++) {
                // Number of coalitions of this size that include this touchpoint
                $coalitionCount = $this->binomialCoefficient($count - 1, $coalitionSize);

                // Weight for this coalition size
                $weight = 1.0 / ($count * $this->binomialCoefficient($count, $coalitionSize + 1));

                // Average marginal contribution for coalitions of this size
                $marginalContribution = $this->estimateMarginalContribution(
                    $touchpoints,
                    $index,
                    $coalitionSize,
                    $baselineConversion
                );

                $shapleyValue += $weight * $marginalContribution;
            }

            $attribution[$stepId] = [
                'step_id' => $stepId,
                'model_name' => 'shapley',
                'raw_score' => round(max(0, $shapleyValue), 6),
                'position' => $index + 1,
            ];
        }

        // Normalize to sum to 1.0
        $totalScore = array_sum(array_column($attribution, 'raw_score'));
        if ($totalScore > 0) {
            foreach ($attribution as $stepId => $data) {
                $attribution[$stepId]['raw_score'] = round(
                    $data['raw_score'] / $totalScore,
                    6
                );
            }
        }

        return $attribution;
    }

    /**
     * Calculate Shapley attribution for a journey.
     *
     * @param Journey $journey
     * @return array
     */
    public function calculateForJourney(Journey $journey): array
    {
        $touchpoints = $this->getJourneyTouchpoints($journey);
        $baselineConversion = $this->getBaselineConversion($journey);

        return $this->calculate($touchpoints, $baselineConversion);
    }

    /**
     * Estimate marginal contribution of a touchpoint.
     *
     * @param array $touchpoints
     * @param int $touchpointIndex
     * @param int $coalitionSize
     * @param float $baselineConversion
     * @return float
     */
    private function estimateMarginalContribution(
        array $touchpoints,
        int $touchpointIndex,
        int $coalitionSize,
        float $baselineConversion
    ): float {
        // Simplified estimation: assume each touchpoint adds equal value
        // In a real implementation, this would use conversion lift data
        $touchpointContribution = ($touchpoints[$touchpointIndex]['contribution'] ?? 0.1);

        // Scale by coalition size (diminishing returns)
        return $touchpointContribution / (1 + $coalitionSize * 0.1);
    }

    /**
     * Calculate binomial coefficient C(n, k).
     *
     * @param int $n
     * @param int $k
     * @return int
     */
    private function binomialCoefficient(int $n, int $k): int
    {
        if ($k < 0 || $k > $n) {
            return 0;
        }

        if ($k === 0 || $k === $n) {
            return 1;
        }

        $result = 1;
        for ($i = 0; $i < min($k, $n - $k); $i++) {
            $result = $result * ($n - $i) / ($i + 1);
        }

        return (int) round($result);
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

    /**
     * Get baseline conversion probability.
     *
     * @param Journey $journey
     * @return float
     */
    private function getBaselineConversion(Journey $journey): float
    {
        return 0.0; // Would be calculated from historical data
    }
}
