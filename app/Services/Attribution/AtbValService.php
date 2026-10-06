<?php

namespace App\Services\Attribution;

use App\Models\AssemblyScore;
use App\Models\AtbVal;
use App\Models\Journey;

class AtbValService
{
    /**
     * Calculate ATB-Val (Attribution Value for Dashboard Display).
     * ATB-Val is derived from assembly_score with removal effect correction.
     *
     * @param Journey $journey
     * @return array ATB values per step
     */
    public function calculateForJourney(Journey $journey): array
    {
        // Get assembly scores for this journey
        $assemblyScores = AssemblyScore::where('journey_id', $journey->journey_id)->get();

        $atbValues = [];

        foreach ($assemblyScores as $assemblyScore) {
            $atbVal = $this->calculateAtbVal($assemblyScore->assembly_score);
            $removalEffectCorrection = $this->calculateRemovalEffectCorrection($assemblyScore);

            $atbValues[] = [
                'project_id' => $journey->project_id,
                'goal_id' => $journey->goal_id,
                'journey_id' => $journey->journey_id,
                'step_id' => $assemblyScore->step_id,
                'atb_val' => $atbVal,
                'removal_effect_correction' => $removalEffectCorrection,
            ];
        }

        // Save ATB values to database
        foreach ($atbValues as $atbData) {
            AtbVal::updateOrCreate(
                [
                    'journey_id' => $atbData['journey_id'],
                    'step_id' => $atbData['step_id'],
                ],
                $atbData
            );
        }

        return $atbValues;
    }

    /**
     * Calculate ATB-Val from assembly score.
     * ATB-Val is optimized for dashboard display with intuitive scaling.
     *
     * @param float $assemblyScore
     * @return float
     */
    private function calculateAtbVal(float $assemblyScore): float
    {
        // Apply scaling function for better dashboard visualization
        // This can be adjusted based on dashboard requirements

        // Option 1: Linear scaling with slight enhancement
        return round(min(1.0, $assemblyScore * 1.1), 6);

        // Option 2: Logarithmic scaling for better visualization of small values
        // return round(min(1.0, log1p($assemblyScore) / log1p(1.0)), 6);

        // Option 3: Power transformation for emphasizing high scores
        // return round(min(1.0, pow($assemblyScore, 0.9)), 6);
    }

    /**
     * Calculate removal effect correction factor.
     * This adjusts ATB-Val based on Markov removal effect.
     *
     * @param AssemblyScore $assemblyScore
     * @return float|null
     */
    private function calculateRemovalEffectCorrection(AssemblyScore $assemblyScore): ?float
    {
        // This would typically fetch the Markov removal effect for this step
        // and calculate a correction factor

        // For now, return null as placeholder
        // In a real implementation, this would:
        // 1. Get Markov removal effect from attribution_scores table
        // 2. Compare with assembly_score
        // 3. Calculate correction if difference is significant

        return null;
    }

    /**
     * Calculate ATB values for all journeys in a project.
     *
     * @param int $projectId
     * @param string|null $goalId
     * @return int Number of journeys processed
     */
    public function calculateForProject(int $projectId, ?string $goalId = null): int
    {
        $query = Journey::where('project_id', $projectId);

        if ($goalId) {
            $query->where('goal_id', $goalId);
        }

        $journeys = $query->get();
        $processed = 0;

        foreach ($journeys as $journey) {
            try {
                $this->calculateForJourney($journey);
                $processed++;
            } catch (\Exception $e) {
                // Log error but continue processing
                \Log::error("Failed to calculate ATB-Val for journey {$journey->journey_id}: " . $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Get ATB values for page impact calculation.
     *
     * @param int $projectId
     * @param string $goalId
     * @param array $dateRange
     * @return \Illuminate\Support\Collection
     */
    public function getPageImpactAtbValues(
        int $projectId,
        string $goalId,
        array $dateRange
    ): \Illuminate\Support\Collection {
        return AtbVal::join('assembly_scores as es', function ($join) {
                $join->on('atb_val.journey_id', '=', 'es.journey_id');
                $join->on('atb_val.step_id', '=', 'es.step_id');
            })
            ->join('journeys as j', 'atb_val.journey_id', '=', 'j.journey_id')
            ->where('atb_val.project_id', $projectId)
            ->where('atb_val.goal_id', $goalId)
            ->whereBetween('j.journey_ts', [$dateRange['start'], $dateRange['end']])
            ->select('atb_val.*', 'es.assembly_score', 'j.device_id')
            ->get();
    }

    /**
     * Get ATB values for block flow visualization.
     *
     * @param int $projectId
     * @param string $goalId
     * @param array $dateRange
     * @return \Illuminate\Support\Collection
     */
    public function getBlockFlowAtbValues(
        int $projectId,
        string $goalId,
        array $dateRange
    ): \Illuminate\Support\Collection {
        return AtbVal::join('journeys as j', 'atb_val.journey_id', '=', 'j.journey_id')
            ->where('atb_val.project_id', $projectId)
            ->where('atb_val.goal_id', $goalId)
            ->whereBetween('j.journey_ts', [$dateRange['start'], $dateRange['end']])
            ->select('atb_val.*', 'j.device_id', 'j.source_step')
            ->orderBy('atb_val.atb_val', 'desc')
            ->get();
    }
}
