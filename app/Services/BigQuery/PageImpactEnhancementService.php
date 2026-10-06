<?php

namespace App\Services\BigQuery;

use App\Models\AssemblyScore;
use App\Models\AtbVal;
use App\Models\JourneyStrength;
use App\Models\Project;
use App\Models\Segment;
use App\Services\Attribution\AssemblyScoreService;
use Carbon\Carbon;

/**
 * Safe enhancement service that adds assembly scores without breaking existing functionality.
 * This service provides parallel code paths with graceful fallbacks.
 */
class PageImpactEnhancementService
{
    public function __construct(
        private readonly AssemblyScoreService $assemblyScoreService
    ) {}

    /**
     * Safely get assembly scores for pages.
     * Returns empty array if:
     * - Feature flag is disabled
     * - Tables don't exist
     * - No data available
     *
     * This ensures backward compatibility.
     */
    public function getAssemblyScoresSafely(
        Project $project,
        Carbon $start,
        Carbon $end,
        string $goalId
    ): array {
        // Check 1: Feature flag
        if (!config('advanced.use_assembly_scores', false)) {
            return [];
        }

        // Check 2: Tables exist
        if (!$this->tablesExist()) {
            return [];
        }

        // Check 3: Has data for this project/goal
        try {
            $assemblyScores = AssemblyScore::join('journeys as j', 'assembly_scores.journey_id', '=', 'j.journey_id')
                ->where('assembly_scores.project_id', $project->id)
                ->where('assembly_scores.goal_id', $goalId)
                ->whereBetween('j.journey_ts', [$start, $end])
                ->select('assembly_scores.*')
                ->limit(1) // Just check if data exists
                ->get();

            if ($assemblyScores->isEmpty()) {
                return [];
            }

            // Get actual data now
            return $this->getAssemblyScoresData($project, $start, $end, $goalId);

        } catch (\Exception $e) {
            // Log error but don't break existing functionality
            \Log::warning('Assembly scores query failed, falling back to Markov: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if required tables exist.
     */
    private function tablesExist(): bool
    {
        try {
            // Check if assembly_scores table exists
            \Schema::hasTable('assembly_scores') &&
            \Schema::hasTable('atb_val') &&
            \Schema::hasTable('journey_strength') &&
            \Schema::hasTable('journeys');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get assembly scores data (called only when we know data exists).
     */
    private function getAssemblyScoresData(
        Project $project,
        Carbon $start,
        Carbon $end,
        string $goalId
    ): array {
        $assemblyScores = AssemblyScore::join('journeys as j', 'assembly_scores.journey_id', '=', 'j.journey_id')
            ->where('assembly_scores.project_id', $project->id)
            ->where('assembly_scores.goal_id', $goalId)
            ->whereBetween('j.journey_ts', [$start, $end])
            ->select('assembly_scores.*')
            ->get();

        // Get ATB values for dashboard display
        $atbVals = AtbVal::where('project_id', $project->id)
            ->where('goal_id', $goalId)
            ->whereIn('journey_id', $assemblyScores->pluck('journey_id'))
            ->get()
            ->keyBy(function ($item) {
                return $item->journey_id . '|' . $item->step_id;
            });

        // Get journey strengths for context
        $journeyStrengths = JourneyStrength::where('project_id', $project->id)
            ->where('goal_id', $goalId)
            ->whereIn('journey_id', $assemblyScores->pluck('journey_id'))
            ->get()
            ->keyBy('journey_id');

        // Aggregate scores by page (step_id)
        $pageScores = [];
        foreach ($assemblyScores as $score) {
            $page = $score->step_id;

            if (!isset($pageScores[$page])) {
                $pageScores[$page] = [
                    'assembly_score' => 0,
                    'atb_val' => 0,
                    'journey_strength' => 0,
                    'strength_tier' => null,
                    'count' => 0,
                ];
            }

            $key = $score->journey_id . '|' . $score->step_id;
            $atbVal = $atbVals->get($key);
            $journeyStrength = $journeyStrengths->get($score->journey_id);

            $pageScores[$page]['assembly_score'] += (float) $score->assembly_score;
            $pageScores[$page]['atb_val'] += $atbVal ? (float) $atbVal->atb_val : (float) $score->assembly_score;
            $pageScores[$page]['journey_strength'] += $journeyStrength ? (float) $journeyStrength->strength_score : 0;
            $pageScores[$page]['strength_tier'] = $journeyStrength ? $journeyStrength->strength_tier : null;
            $pageScores[$page]['count']++;
        }

        // Average the scores
        foreach ($pageScores as $page => $data) {
            if ($data['count'] > 0) {
                $pageScores[$page]['assembly_score'] /= $data['count'];
                $pageScores[$page]['atb_val'] /= $data['count'];
                $pageScores[$page]['journey_strength'] /= $data['count'];
            }
        }

        return $pageScores;
    }

    /**
     * Check if advanced attribution is available for a project.
     */
    public function isAdvancedAvailable(Project $project, ?string $goalId = null): bool
    {
        if (!config('advanced.use_advanced_attribution', false)) {
            return false;
        }

        if (!$this->tablesExist()) {
            return false;
        }

        if (!$goalId) {
            return false;
        }

        try {
            // Check if there's any data
            return AssemblyScore::where('project_id', $project->id)
                ->where('goal_id', $goalId)
                ->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get goal_id from project goal_path.
     * In production, this would fetch from goal_sets table.
     */
    public function getGoalId(Project $project): ?string
    {
        if (!$project->goal_path) {
            return null;
        }

        // Try to get from goal_sets table first
        try {
            $goalSet = \App\Models\GoalSet::where('project_id', $project->id)
                ->where('goal_event_name', $project->goal_path)
                ->first();

            if ($goalSet) {
                return $goalSet->goal_id;
            }
        } catch (\Exception $e) {
            // Table might not exist yet
        }

        // Fallback: generate from goal_path
        return md5($project->goal_path);
    }
}
