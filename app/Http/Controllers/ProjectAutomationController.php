<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProjectAutomationController extends Controller
{
    /**
     * Trigger advanced attribution population for a project.
     * This can be called when a project is created or goal is configured.
     */
    public function populateAttribution(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        // Check if project is ready
        if (!$project->bigQueryConnection) {
            return response()->json([
                'error' => 'Project must have BigQuery connection first',
                'step' => 'Connect BigQuery credentials'
            ], 422);
        }

        if (!$project->goal_path) {
            return response()->json([
                'error' => 'Project must have a goal configured first',
                'step' => 'Set conversion goal'
            ], 422);
        }

        try {
            // Get goal ID
            $goalId = $this->getGoalId($project);

            // Dispatch the job
            $job = new \App\Jobs\PopulateAdvancedAttributionJob($project, $goalId);
            $jobId = Bus::dispatch($job);

            return response()->json([
                'message' => 'Advanced attribution population started',
                'job_id' => $jobId,
                'project' => $project->id,
                'goal' => $goalId,
                'status' => 'processing',
            ], 202); // Accepted
        } catch (\Exception $e) {
            Log::error('Failed to dispatch advanced attribution population', [
                'project' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to start population',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check population status for a project.
     */
    public function checkPopulationStatus(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        try {
            $goalId = $this->getGoalId($project);

            // Check if data exists
            $assemblyScoresCount = \App\Models\AssemblyScore::where('project_id', $project->id)
                ->where('goal_id', $goalId)
                ->count();

            $journeyStrengthsCount = \App\Models\JourneyStrength::where('project_id', $project->id)
                ->where('goal_id', $goalId)
                ->count();

            $atbValsCount = \App\Models\AtbVal::where('project_id', $project->id)
                ->where('goal_id', $goalId)
                ->count();

            $isReady = $assemblyScoresCount > 0 && $journeyStrengthsCount > 0;

            return response()->json([
                'project' => $project->id,
                'goal' => $goalId,
                'is_ready' => $isReady,
                'assembly_scores_count' => $assemblyScoresCount,
                'journey_strengths_count' => $journeyStrengthsCount,
                'atb_vals_count' => $atbValsCount,
                'recommendation' => $isReady ? 'Advanced features ready' : 'Run populate attribution first',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to check status',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get goal_id for a project.
     */
    private function getGoalId(Project $project): string
    {
        // Try goal_sets table first
        try {
            $goalSet = \App\Models\GoalSet::where('project_id', $project->id)
                ->where('is_active', true)
                ->first();

            if ($goalSet) {
                return $goalSet->goal_id;
            }
        } catch (\Exception $e) {
            // Table might not exist yet
        }

        // Fallback: generate from goal_path
        return md5($project->goal_path . $project->id);
    }
}
