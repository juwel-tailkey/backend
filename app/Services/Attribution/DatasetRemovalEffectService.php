<?php

namespace App\Services\Attribution;

use App\Models\Journey;

class DatasetRemovalEffectService
{
    /**
     * Calculate dataset removal effect attribution for a journey.
     * Similar to Markov removal effect but applied at the dataset/feature level
     * rather than individual touchpoint level.
     *
     * @param array $touchpoints Array of touchpoints with engagement data
     * @param array $transitionMatrix Transition probabilities between states
     * @return array Attribution scores per touchpoint
     */
    public function calculate(array $touchpoints, array $transitionMatrix = []): array
    {
        if (empty($touchpoints)) {
            return [];
        }

        // Build the transition graph from touchpoints
        $graph = $this->buildTransitionGraph($touchpoints, $transitionMatrix);

        // Calculate removal effect for each touchpoint
        $attribution = [];
        $totalRemovalEffect = 0;

        foreach ($touchpoints as $index => $touchpoint) {
            $stepId = $touchpoint['step_id'] ?? "step_{$index}";

            // Calculate conversion probability with this touchpoint
            $probabilityWithTouchpoint = $this->calculateConversionProbability($graph);

            // Calculate conversion probability without this touchpoint
            $probabilityWithoutTouchpoint = $this->calculateConversionProbability(
                $this->removeTouchpointFromGraph($graph, $stepId)
            );

            // Removal effect is the difference in conversion probability
            $removalEffect = $probabilityWithTouchpoint - $probabilityWithoutTouchpoint;

            $attribution[$stepId] = [
                'step_id' => $stepId,
                'model_name' => 'dataset_removal_effect',
                'raw_score' => max(0, $removalEffect),
                'position' => $index + 1,
            ];

            $totalRemovalEffect += max(0, $removalEffect);
        }

        // Normalize to sum to 1.0
        if ($totalRemovalEffect > 0) {
            foreach ($attribution as $stepId => $data) {
                $attribution[$stepId]['raw_score'] = round(
                    $data['raw_score'] / $totalRemovalEffect,
                    6
                );
            }
        }

        return $attribution;
    }

    /**
     * Calculate dataset removal effect for a journey.
     *
     * @param Journey $journey
     * @return array
     */
    public function calculateForJourney(Journey $journey): array
    {
        $touchpoints = $this->getJourneyTouchpoints($journey);
        $transitionMatrix = $this->getTransitionMatrix($journey);

        return $this->calculate($touchpoints, $transitionMatrix);
    }

    /**
     * Build transition graph from touchpoints.
     *
     * @param array $touchpoints
     * @param array $transitionMatrix
     * @return array
     */
    private function buildTransitionGraph(array $touchpoints, array $transitionMatrix): array
    {
        // If transition matrix is provided, use it
        if (!empty($transitionMatrix)) {
            return $transitionMatrix;
        }

        // Otherwise, build a simple linear chain graph
        $graph = [];
        $count = count($touchpoints);

        for ($i = 0; $i < $count; $i++) {
            $currentStep = $touchpoints[$i]['step_id'] ?? "step_{$i}";

            if ($i < $count - 1) {
                $nextStep = $touchpoints[$i + 1]['step_id'] ?? "step_" . ($i + 1);
                $graph[$currentStep][$nextStep] = 1.0;
            }
        }

        return $graph;
    }

    /**
     * Calculate conversion probability from a graph.
     *
     * @param array $graph
     * @return float
     */
    private function calculateConversionProbability(array $graph): float
    {
        if (empty($graph)) {
            return 0.0;
        }

        // Simplified calculation: assume uniform probability
        // In a real implementation, this would solve the Markov chain
        $totalPaths = 0;
        $convertingPaths = 0;

        foreach ($graph as $fromState => $transitions) {
            foreach ($transitions as $toState => $probability) {
                $totalPaths++;
                if (strpos($toState, 'conversion') !== false || strpos($toState, 'goal') !== false) {
                    $convertingPaths++;
                }
            }
        }

        return $totalPaths > 0 ? $convertingPaths / $totalPaths : 0.0;
    }

    /**
     * Remove a touchpoint from the graph.
     *
     * @param array $graph
     * @param string $stepId
     * @return array
     */
    private function removeTouchpointFromGraph(array $graph, string $stepId): array
    {
        $modifiedGraph = $graph;

        // Remove all transitions from this step
        unset($modifiedGraph[$stepId]);

        // Remove all transitions to this step
        foreach ($modifiedGraph as $fromState => $transitions) {
            if (isset($modifiedGraph[$fromState][$stepId])) {
                unset($modifiedGraph[$fromState][$stepId]);
            }
        }

        return $modifiedGraph;
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
     * Get transition matrix for a journey.
     *
     * @param Journey $journey
     * @return array
     */
    private function getTransitionMatrix(Journey $journey): array
    {
        return [];
    }
}
