<?php

namespace App\Services\Attribution;

use App\Models\AssemblyScore;
use App\Models\Journey;
use App\Models\JourneyStrength;
use Illuminate\Support\Facades\DB;

class JourneyStrengthService
{
    /**
     * Journey strength tier thresholds.
     */
    private const HIGH_STRENGTH_THRESHOLD = 0.75;
    private const MODERATE_STRENGTH_THRESHOLD = 0.45;

    /**
     * Calculate journey strength for a journey.
     * Strength score considers multiple factors: diversity, length_fit, balance, context.
     *
     * @param Journey $journey
     * @return JourneyStrength
     */
    public function calculateForJourney(Journey $journey): JourneyStrength
    {
        // Get assembly scores for this journey
        $assemblyScores = AssemblyScore::where('journey_id', $journey->journey_id)->get();

        if ($assemblyScores->isEmpty()) {
            return $this->createDefaultStrength($journey);
        }

        // Calculate strength components
        $diversity = $this->calculateDiversity($assemblyScores);
        $lengthFit = $this->calculateLengthFit($assemblyScores);
        $balance = $this->calculateBalance($assemblyScores);
        $context = $this->calculateContext($journey, $assemblyScores);

        // Calculate overall strength score
        $strengthScore = $this->calculateOverallStrength($diversity, $lengthFit, $balance, $context);

        // Apply goal bonus if journey converted
        $goalBonus = $journey->goal_achieved ? 0.1 : 0.0;
        $strengthScore = min(1.0, $strengthScore + $goalBonus);

        // Determine strength tier
        $strengthTier = $this->determineStrengthTier($strengthScore);

        // Create or update journey strength record
        return JourneyStrength::updateOrCreate(
            [
                'project_id' => $journey->project_id,
                'journey_id' => $journey->journey_id,
            ],
            [
                'goal_id' => $journey->goal_id,
                'strength_score' => round($strengthScore, 6),
                'goal_bonus' => round($goalBonus, 6),
                'diversity' => round($diversity, 6),
                'length_fit' => round($lengthFit, 6),
                'balance' => round($balance, 6),
                'context' => round($context, 6),
                'strength_tier' => $strengthTier,
            ]
        );
    }

    /**
     * Calculate diversity score - how varied the touchpoints are.
     *
     * @param \Illuminate\Support\Collection $assemblyScores
     * @return float
     */
    private function calculateDiversity($assemblyScores): float
    {
        if ($assemblyScores->isEmpty()) {
            return 0.0;
        }

        // Calculate standard deviation of assembly scores
        $scores = $assemblyScores->pluck('assembly_score')->toArray();
        $mean = array_sum($scores) / count($scores);

        $variance = 0.0;
        foreach ($scores as $score) {
            $variance += pow($score - $mean, 2);
        }

        $stdDev = count($scores) > 1 ? sqrt($variance / count($scores)) : 0;

        // Diversity is higher when there's more variation (normalized)
        return min(1.0, $stdDev * 2);
    }

    /**
     * Calculate length fit - how optimal the journey length is.
     *
     * @param \Illuminate\Support\Collection $assemblyScores
     * @return float
     */
    private function calculateLengthFit($assemblyScores): float
    {
        $count = $assemblyScores->count();

        if ($count === 0) {
            return 0.0;
        }

        // Optimal journey length is typically 3-7 touchpoints
        $optimalMin = 3;
        $optimalMax = 7;

        if ($count >= $optimalMin && $count <= $optimalMax) {
            return 1.0;
        }

        if ($count < $optimalMin) {
            // Penalty for too short
            return max(0.0, $count / $optimalMin);
        }

        // Penalty for too long
        return max(0.0, 1.0 - ($count - $optimalMax) * 0.1);
    }

    /**
     * Calculate balance - how evenly distributed the scores are.
     *
     * @param \Illuminate\Support\Collection $assemblyScores
     * @return float
     */
    private function calculateBalance($assemblyScores): float
    {
        if ($assemblyScores->isEmpty()) {
            return 0.0;
        }

        $scores = $assemblyScores->pluck('assembly_score')->sort()->values();
        $count = $scores->count();

        if ($count < 2) {
            return 1.0;
        }

        // Calculate Gini coefficient (measure of inequality)
        $gini = 0.0;
        $cumulativeScore = 0.0;
        $totalScore = $scores->sum();

        if ($totalScore === 0) {
            return 0.0;
        }

        foreach ($scores as $index => $score) {
            $cumulativeScore += $score;
            $gini += abs(($index + 1) / $count - $cumulativeScore / $totalScore);
        }

        $gini = (2 * $gini) / $count;

        // Balance is inverse of Gini (more balanced = lower Gini = higher balance)
        return round(1.0 - $gini, 6);
    }

    /**
     * Calculate contextual relevance.
     *
     * @param Journey $journey
     * @param \Illuminate\Support\Collection $assemblyScores
     * @return float
     */
    private function calculateContext(Journey $journey, $assemblyScores): float
    {
        // Context considers:
        // - Goal achievement (converting journeys get bonus)
        // - Journey duration (very short or very long journeys get penalty)
        // - Device consistency (same device throughout)

        $contextScore = 0.5; // Base score

        // Goal achievement bonus
        if ($journey->goal_achieved) {
            $contextScore += 0.2;
        }

        // Journey duration consideration
        if ($journey->journey_ts && $journey->goal_completed_ts) {
            $hours = $journey->journey_ts->diffInHours($journey->goal_completed_ts);

            // Optimal duration: 1-168 hours (1 day to 1 week)
            if ($hours >= 1 && $hours <= 168) {
                $contextScore += 0.1;
            } elseif ($hours < 1) {
                $contextScore -= 0.1; // Too fast might be accidental
            } else {
                $contextScore -= 0.05; // Too long might indicate hesitation
            }
        }

        return min(1.0, max(0.0, $contextScore));
    }

    /**
     * Calculate overall strength from components.
     *
     * @param float $diversity
     * @param float $lengthFit
     * @param float $balance
     * @param float $context
     * @return float
     */
    private function calculateOverallStrength(
        float $diversity,
        float $lengthFit,
        float $balance,
        float $context
    ): float {
        // Weighted combination of components
        $weights = [
            'diversity' => 0.2,
            'length_fit' => 0.3,
            'balance' => 0.2,
            'context' => 0.3,
        ];

        $strength = (
            $diversity * $weights['diversity'] +
            $lengthFit * $weights['length_fit'] +
            $balance * $weights['balance'] +
            $context * $weights['context']
        );

        return round($strength, 6);
    }

    /**
     * Determine strength tier based on score.
     *
     * @param float $strengthScore
     * @return string
     */
    private function determineStrengthTier(float $strengthScore): string
    {
        if ($strengthScore >= self::HIGH_STRENGTH_THRESHOLD) {
            return 'high_strength';
        }

        if ($strengthScore >= self::MODERATE_STRENGTH_THRESHOLD) {
            return 'moderate_strength';
        }

        return 'low_strength';
    }

    /**
     * Create default strength record for journey without assembly scores.
     *
     * @param Journey $journey
     * @return JourneyStrength
     */
    private function createDefaultStrength(Journey $journey): JourneyStrength
    {
        return JourneyStrength::updateOrCreate(
            [
                'project_id' => $journey->project_id,
                'journey_id' => $journey->journey_id,
            ],
            [
                'goal_id' => $journey->goal_id,
                'strength_score' => 0.0,
                'goal_bonus' => 0.0,
                'diversity' => 0.0,
                'length_fit' => 0.0,
                'balance' => 0.0,
                'context' => 0.0,
                'strength_tier' => 'low_strength',
            ]
        );
    }

    /**
     * Calculate journey strengths for all journeys in a project.
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
                \Log::error("Failed to calculate journey strength for {$journey->journey_id}: " . $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Get high-strength non-converting journeys for retargeting.
     *
     * @param int $projectId
     * @param string $goalId
     * @param array $dateRange
     * @return \Illuminate\Support\Collection
     */
    public function getHighStrengthNonConverters(
        int $projectId,
        string $goalId,
        array $dateRange
    ): \Illuminate\Support\Collection {
        return JourneyStrength::join('journeys as j', 'journey_strength.journey_id', '=', 'j.journey_id')
            ->where('journey_strength.project_id', $projectId)
            ->where('journey_strength.goal_id', $goalId)
            ->where('journey_strength.strength_score', '>=', self::HIGH_STRENGTH_THRESHOLD)
            ->where('j.goal_achieved', false)
            ->whereBetween('j.journey_ts', [$dateRange['start'], $dateRange['end']])
            ->select('journey_strength.*', 'j.device_id', 'j.source_step')
            ->orderByDesc('journey_strength.strength_score')
            ->get();
    }

    /**
     * Get journey strength distribution for a project.
     *
     * @param int $projectId
     * @param string $goalId
     * @return array
     */
    public function getStrengthDistribution(int $projectId, string $goalId): array
    {
        return JourneyStrength::where('project_id', $projectId)
            ->where('goal_id', $goalId)
            ->select(DB::raw('
                COUNT(CASE WHEN strength_tier = "high_strength" THEN 1 END) as high_count,
                COUNT(CASE WHEN strength_tier = "moderate_strength" THEN 1 END) as moderate_count,
                COUNT(CASE WHEN strength_tier = "low_strength" THEN 1 END) as low_count,
                AVG(strength_score) as avg_strength,
                MAX(strength_score) as max_strength,
                MIN(strength_score) as min_strength
            '))
            ->first()
            ->toArray();
    }
}
