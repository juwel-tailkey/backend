<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Safe status controller that provides system information
 * without exposing sensitive data or breaking existing functionality.
 */
class SystemStatusController extends Controller
{
    /**
     * Get advanced attribution status.
     * Safe endpoint that checks what's available without modifying anything.
     */
    public function advancedAttributionStatus(Request $request): JsonResponse
    {
        $this->authorize('view', $request->user()?->currentProject);

        return response()->json([
            'feature_flags' => [
                'use_advanced_attribution' => config('advanced.use_advanced_attribution', false),
                'use_advanced_segments' => config('advanced.use_advanced_segments', false),
                'use_journey_strength' => config('advanced.use_journey_strength', false),
                'use_assembly_scores' => config('advanced.use_assembly_scores', false),
            ],
            'tables' => [
                'goal_sets' => $this->tableExists('goal_sets'),
                'tracked_events' => $this->tableExists('tracked_events'),
                'journeys' => $this->tableExists('journeys'),
                'attribution_scores' => $this->tableExists('attribution_scores'),
                'assembly_scores' => $this->tableExists('assembly_scores'),
                'atb_val' => $this->tableExists('atb_val'),
                'journey_strength' => $this->tableExists('journey_strength'),
                'churn_scores' => $this->tableExists('churn_scores'),
                'user_segments' => $this->tableExists('user_segments'),
            ],
            'data_status' => $this->getDataStatus($request->user()?->currentProject),
        ]);
    }

    /**
     * Get general system status.
     */
    public function systemStatus(Request $request): JsonResponse
    {
        return response()->json([
            'environment' => config('app.env'),
            'data_provider' => config('bigquery.data_provider'),
            'bigquery_configured' => !empty(config('bigquery.bigquery_project_id')),
            'advanced_available' => $this->isAdvancedAvailable(),
            'recommendations' => $this->getRecommendations(),
        ]);
    }

    /**
     * Check if advanced features are available.
     */
    private function isAdvancedAvailable(): bool
    {
        return $this->tableExists('assembly_scores') &&
               $this->tableExists('journey_strength') &&
               $this->tableExists('atb_val');
    }

    /**
     * Check if a table exists.
     */
    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get data status for a project.
     */
    private function getDataStatus(?Project $project): ?array
    {
        if (!$project) {
            return null;
        }

        try {
            $assemblyScoresCount = \App\Models\AssemblyScore::where('project_id', $project->id)->count();
            $journeyStrengthCount = \App\Models\JourneyStrength::where('project_id', $project->id)->count();
            $userSegmentsCount = \App\Models\UserSegment::where('project_id', $project->id)->count();

            return [
                'has_assembly_scores' => $assemblyScoresCount > 0,
                'assembly_scores_count' => $assemblyScoresCount,
                'has_journey_strength' => $journeyStrengthCount > 0,
                'journey_strength_count' => $journeyStrengthCount,
                'has_user_segments' => $userSegmentsCount > 0,
                'user_segments_count' => $userSegmentsCount,
                'ready_for_advanced' => $assemblyScoresCount > 0 && $journeyStrengthCount > 0,
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Could not check data status',
                'ready_for_advanced' => false,
            ];
        }
    }

    /**
     * Get recommendations for the user.
     */
    private function getRecommendations(): array
    {
        $recommendations = [];

        // Check if migrations have run
        if (!$this->tableExists('assembly_scores')) {
            $recommendations[] = [
                'type' => 'required',
                'message' => 'Run database migrations to create advanced attribution tables',
                'command' => 'php artisan migrate',
            ];
        }

        // Check if feature flags are enabled
        if (!config('advanced.use_advanced_attribution')) {
            $recommendations[] = [
                'type' => 'optional',
                'message' => 'Enable advanced attribution feature flags in .env file',
                'command' => 'USE_ADVANCED_ATTRIBUTION=true',
            ];
        }

        // Check if data exists
        if ($this->tableExists('assembly_scores')) {
            try {
                $hasData = \App\Models\AssemblyScore::exists();
                if (!$hasData) {
                    $recommendations[] = [
                        'type' => 'recommended',
                        'message' => 'Populate advanced attribution tables with existing data',
                        'command' => 'php artisan attribution:populate-advanced',
                    ];
                }
            } catch (\Exception $e) {
                // Table exists but can't query - skip
            }
        }

        return $recommendations;
    }
}
