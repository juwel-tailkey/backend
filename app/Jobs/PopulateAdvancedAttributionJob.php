<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Attribution\AssemblyScoreService;
use App\Services\Attribution\JourneyStrengthService;
use App\Services\Attribution\AtbValService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async job to populate advanced attribution tables for a project.
 * Runs in the background without blocking the user interface.
 */
class PopulateAdvancedAttributionJob extends Queueable implements ShouldQueue
{
    public $tries = 3;
    public $timeout = 120; // 2 minutes max

    public function __construct(
        public readonly Project $project,
        public readonly string $goalId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AssemblyScoreService $assemblyScoreService,
        JourneyStrengthService $journeyStrengthService,
        AtbValService $atbValService
    ): void {
        try {
            DB::beginTransaction();

            $startDate = now()->subDays(30); // Look back 30 days by default
            $endDate = now();

            Log::info('Starting advanced attribution population', [
                'project' => $this->project->id,
                'goal' => $this->goalId,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ]);

            // Step 1: Calculate assembly scores
            Log::info('Step 1: Calculating assembly scores...');
            $assemblyCount = $assemblyScoreService->calculateForProject(
                $this->project->id,
                $this->goalId
            );
            Log::info('Assembly scores calculated', ['count' => $assemblyCount]);

            // Step 2: Calculate ATB values
            Log::info('Step 2: Calculating ATB values...');
            $atbCount = $atbValService->calculateForProject(
                $this->project->id,
                $this->goalId
            );
            Log::info('ATB values calculated', ['count' => $atbCount]);

            // Step 3: Calculate journey strengths
            Log::info('Step 3: Calculating journey strengths...');
            $strengthCount = $journeyStrengthService->calculateForProject(
                $this->project->id,
                $this->goalId
            );
            Log::info('Journey strengths calculated', ['count' => $strengthCount]);

            DB::commit();

            Log::info('Advanced attribution population completed successfully', [
                'project' => $this->project->id,
                'goal' => $this->goalId,
                'assembly_scores' => $assemblyCount,
                'atb_vals' => $atbCount,
                'journey_strengths' => $strengthCount,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Advanced attribution population failed', [
                'project' => $this->project->id,
                'goal' => $this->goalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Release the job back to the queue with a delay
            $this->release(60); // Retry in 1 minute
        }
    }

    /**
     * Get the unique job ID.
     */
    public function uniqueId(): string
    {
        return 'populate_advanced_' . $this->project->id . '_' . $this->goalId;
    }
}
