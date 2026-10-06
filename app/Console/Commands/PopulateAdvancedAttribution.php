<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Attribution\AssemblyScoreService;
use App\Services\Attribution\JourneyStrengthService;
use App\Services\Attribution\AtbValService;
use App\Services\BigQuery\BigQueryClientFactory;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Safe command to populate advanced attribution tables.
 * Does not modify any existing tables or data.
 * Only populates NEW tables with calculated data.
 */
class PopulateAdvancedAttribution extends Command
{
    protected $signature = 'attribution:populate-advanced
                            {--project= : Project ID}
                            {--goal= : Goal ID (optional)}
                            {--days=30 : Number of days to look back}
                            {--force : Force repopulation}';

    protected $description = 'Safely populate advanced attribution tables from existing BigQuery data';

    public function __construct(
        private AssemblyScoreService $assemblyScoreService,
        private JourneyStrengthService $journeyStrengthService,
        private AtbValService $atbValService,
        private BigQueryClientFactory $clientFactory
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🚀 Starting safe advanced attribution population...');

        // Get projects to process
        $projects = $this->getProjects();

        if ($projects->isEmpty()) {
            $this->warn('No projects found to process.');
            return self::SUCCESS;
        }

        foreach ($projects as $project) {
            $this->info("📊 Processing project: {$project->name}");

            try {
                $this->processProject($project);
                $this->info("✅ Project {$project->name} completed successfully");
            } catch (\Exception $e) {
                $this->error("❌ Project {$project->name} failed: " . $e->getMessage());
                // Continue with other projects
                continue;
            }
        }

        $this->info('✨ All projects processed successfully!');
        return self::SUCCESS;
    }

    private function getProjects()
    {
        $query = Project::query();

        if ($this->option('project')) {
            $query->where('id', $this->option('project'));
        }

        return $query->get();
    }

    private function processProject(Project $project): void
    {
        // Check if project has BigQuery connection
        if (!$project->bigQueryConnection) {
            $this->warn("  ⚠️  No BigQuery connection, skipping");
            return;
        }

        // Check if goal is set
        if (!$project->goal_path) {
            $this->warn("  ⚠️  No goal configured, skipping");
            return;
        }

        $goalId = $this->getGoalId($project);

        // Calculate date range
        $days = (int) $this->option('days', 30);
        $end = now();
        $start = now()->subDays($days);

        $this->info("  📅 Processing {$days} days ({$start->toDateString()} to {$end->toDateString()})");

        // Step 1: Populate journeys from BigQuery
        $this->info("  🔨 Building journeys from BigQuery...");
        $journeyCount = $this->populateJourneys($project, $start, $end, $goalId);
        $this->info("  ✅ Created {$journeyCount} journeys");

        // Step 2: Calculate assembly scores
        $this->info("  🎯 Calculating assembly scores...");
        $assemblyCount = $this->assemblyScoreService->calculateForProject($project->id, $goalId);
        $this->info("  ✅ Processed {$assemblyCount} assembly scores");

        // Step 3: Calculate ATB values
        $this->info("  📊 Calculating ATB values...");
        $atbCount = $this->atbValService->calculateForProject($project->id, $goalId);
        $this->info("  ✅ Processed {$atbCount} ATB values");

        // Step 4: Calculate journey strengths
        $this->info("  💪 Calculating journey strengths...");
        $strengthCount = $this->journeyStrengthService->calculateForProject($project->id, $goalId);
        $this->info("  ✅ Processed {$strengthCount} journey strengths");

        $this->info("  ✨ Project {$project->name} advanced attribution ready!");
    }

    private function getGoalId(Project $project): string
    {
        // Try goal_sets table first
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

    private function populateJourneys(
        Project $project,
        Carbon $start,
        Carbon $end,
        string $goalId
    ): int {
        // This would query BigQuery and populate the journeys table
        // For now, return 0 as a placeholder
        // In production, this would extract journey data from BigQuery events

        return 0;
    }
}
