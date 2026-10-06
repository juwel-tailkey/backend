<?php

namespace App\Services\Attribution;

use App\Models\AttributionScore;
use App\Models\AssemblyScore;
use App\Models\Journey;
use App\Services\BigQuery\BigQueryClientFactory;

class AssemblyScoreService
{
    /**
     * List of attribution models to combine.
     */
    private const MODELS = [
        'linear',
        'first_click',
        'last_click',
        'time_decay',
        'markov',
        'shapley',
        'dataset_removal_effect',
    ];

    /**
     * Calculate assembly score for a journey.
     * Assembly score combines all 7 attribution models into a single score.
     *
     * @param Journey $journey
     * @return array Assembly scores per step
     */
    public function calculateForJourney(Journey $journey): array
    {
        // Get all attribution scores for this journey
        $attributionScores = AttributionScore::where('journey_id', $journey->journey_id)
            ->get()
            ->groupBy('step_id');

        $assemblyScores = [];

        foreach ($attributionScores as $stepId => $scores) {
            $assemblyScore = $this->calculateAssemblyScore($scores);
            $assemblyScores[] = [
                'project_id' => $journey->project_id,
                'goal_id' => $journey->goal_id,
                'journey_id' => $journey->journey_id,
                'step_id' => $stepId,
                'assembly_score' => $assemblyScore,
            ];
        }

        // Save assembly scores to database
        foreach ($assemblyScores as $scoreData) {
            AssemblyScore::updateOrCreate(
                [
                    'journey_id' => $scoreData['journey_id'],
                    'step_id' => $scoreData['step_id'],
                ],
                $scoreData
            );
        }

        return $assemblyScores;
    }

    /**
     * Calculate assembly score from multiple model scores.
     * Uses mean aggregation by default, but can use other methods.
     *
     * @param \Illuminate\Support\Collection $scores
     * @return float
     */
    private function calculateAssemblyScore($scores): float
    {
        $modelScores = [];

        foreach (self::MODELS as $model) {
            $modelScore = $scores->firstWhere('model_name', $model);
            if ($modelScore) {
                $modelScores[] = (float) $modelScore->raw_score;
            }
        }

        if (empty($modelScores)) {
            return 0.0;
        }

        // Calculate mean (can be enhanced with weighted mean, median, etc.)
        $mean = array_sum($modelScores) / count($modelScores);

        return round($mean, 6);
    }

    /**
     * Calculate assembly scores for all journeys in a project.
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
                \Log::error("Failed to calculate assembly score for journey {$journey->journey_id}: " . $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Get high-assembly threshold (top quartile).
     *
     * @param int $projectId
     * @param string $goalId
     * @return float
     */
    public function getHighAssemblyThreshold(int $projectId, string $goalId): float
    {
        $scores = AssemblyScore::where('project_id', $projectId)
            ->where('goal_id', $goalId)
            ->pluck('assembly_score')
            ->sort()
            ->values();

        if ($scores->isEmpty()) {
            return 0.75; // Default threshold
        }

        // Calculate 75th percentile (top quartile)
        $index = min((int) (count($scores) * 0.75), count($scores) - 1);

        return (float) $scores->get($index, 0.75);
    }

    /**
     * Get high-assembly touchpoint engagers.
     *
     * @param int $projectId
     * @param string $goalId
     * @param float|null $threshold
     * @return \Illuminate\Support\Collection
     */
    public function getHighAssemblyEngagers(
        int $projectId,
        string $goalId,
        ?float $threshold = null
    ): \Illuminate\Support\Collection {
        $threshold = $threshold ?? $this->getHighAssemblyThreshold($projectId, $goalId);

        return \DB::table('assembly_scores as es')
            ->join('journeys as j', 'es.journey_id', '=', 'j.journey_id')
            ->select(
                'j.device_id',
                \DB::raw('COUNT(DISTINCT es.step_id) as high_score_touchpoint_count'),
                \DB::raw('SUM(es.assembly_score) as touchpoint_engagement_score')
            )
            ->where('es.project_id', $projectId)
            ->where('es.goal_id', $goalId)
            ->where('es.assembly_score', '>=', $threshold)
            ->groupBy('j.device_id')
            ->orderByDesc('touchpoint_engagement_score')
            ->get();
    }
}
