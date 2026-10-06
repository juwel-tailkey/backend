<?php

namespace App\Services\Project;

use App\Models\Project;
use App\Events\GoalUpdated;
use Illuminate\Support\Facades\Log;

/**
 * Enhanced project management service that handles automation.
 */
class ProjectAutomationService
{
    /**
     * Trigger advanced attribution population when goal is configured.
     */
    public function onGoalConfigured(Project $project, string $goalId): void
    {
        // Fire event to trigger population
        event(new GoalUpdated($project, $goalId));

        Log::info('Goal configured, advanced attribution population triggered', [
            'project' => $project->id,
            'goal' => $goalId,
        ]);
    }

    /**
     * Check if project is ready for advanced attribution.
     */
    public function isReadyForAdvanced(Project $project): bool
    {
        // Check 1: Feature flags enabled
        if (!config('advanced.use_advanced_attribution', false)) {
            return false;
        }

        // Check 2: Has BigQuery connection
        if (!$project->bigQueryConnection) {
            return false;
        }

        // Check 3: Has goal configured
        if (!$project->goal_path) {
            return false;
        }

        return true;
    }

    /**
     * Get automation status for a project.
     */
    public function getAutomationStatus(Project $project): array
    {
        $goalId = $this->getGoalId($project);

        return [
            'ready_for_advanced' => $this->isReadyForAdvanced($project),
            'has_bigquery' => !empty($project->bigQueryConnection),
            'has_goal' => !empty($project->goal_path),
            'feature_flags_enabled' => config('advanced.use_advanced_attribution', false),
            'data_populated' => $this->isDataPopulated($project, $goalId),
            'recommended_action' => $this->getRecommendedAction($project),
        ];
    }

    /**
     * Check if advanced attribution data is populated.
     */
    private function isDataPopulated(Project $project, ?string $goalId = null): bool
    {
        if (!$goalId) {
            return false;
        }

        try {
            return \App\Models\AssemblyScore::where('project_id', $project->id)
                ->where('goal_id', $goalId)
                ->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get recommended action for a project.
     */
    private function getRecommendedAction(Project $project): string
    {
        $goalId = $this->getGoalId($project);

        if (!$this->isReadyForAdvanced($project)) {
            return 'Configure BigQuery connection and goal';
        }

        if (!$this->isDataPopulated($project, $goalId)) {
            return 'Populate advanced attribution data';
        }

        return 'Ready for advanced features';
    }

    /**
     * Get goal_id for a project.
     */
    private function getGoalId(Project $project): ?string
    {
        try {
            $goalSet = \App\Models\GoalSet::where('project_id', $project->id)
                ->where('is_active', true)
                ->first();

            return $goalSet ? $goalSet->goal_id : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
