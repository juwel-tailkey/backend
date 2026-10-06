<?php

namespace App\Listeners;

use App\Events\GoalUpdated;
use App\Services\BigQuery\BigQueryClientFactory;
use App\Services\Attribution\AssemblyScoreService;
use App\Services\Attribution\JourneyStrengthService;
use App\Services\Attribution\AtbValService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Automatically populate advanced attribution when goals are updated.
 */
class PopulateAdvancedAttributionOnGoalUpdate
{
    public function __construct(
        private readonly AssemblyScoreService $assemblyScoreService,
        private readonly JourneyStrengthService $journeyStrengthService,
        private readonly AtbValService $atbValService,
        private readonly BigQueryClientFactory $clientFactory
    ) {}

    /**
     * Handle the event.
     */
    public function handle(GoalUpdated $event): void
    {
        $project = $event->project;
        $goalId = $event->goalId;

        // Only process if advanced attribution is enabled
        if (!config('advanced.use_advanced_attribution', false)) {
            Log::info('Advanced attribution disabled, skipping population', [
                'project' => $project->id,
                'goal' => $goalId,
            ]);
            return;
        }

        // Check if project has BigQuery connection
        if (!$project->bigQueryConnection) {
            Log::warning('Project has no BigQuery connection, skipping advanced attribution', [
                'project' => $project->id,
            ]);
            return;
        }

        // Queue the population job (async, doesn't block)
        try {
            $job = new \App\Jobs\PopulateAdvancedAttributionJob($project, $goalId);
            Queue::push($job);

            Log::info('Queued advanced attribution population', [
                'project' => $project->id,
                'goal' => $goalId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to queue advanced attribution population', [
                'project' => $project->id,
                'goal' => $goalId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
