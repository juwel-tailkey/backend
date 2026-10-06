<?php

namespace App\Services\Project;

use App\Events\GoalUpdated;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\BigQuery\BigQueryConnectionService;
use App\Services\BigQuery\BigQueryGoalsQueryService;
use App\Services\Project\ProjectAutomationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

class ProjectService
{
    public function __construct(
        private readonly BigQueryConnectionService $connectionService,
        private readonly BigQueryGoalsQueryService $goalsQuery,
        private readonly ActiveProjectService $activeProjectService,
        private readonly ProjectAutomationService $automationService
    ) {
    }

    public function list(User $user): Collection
    {
        $organization = $this->getOrganization($user);
        $user->refresh();

        return $organization->projects()
            ->with('bigQueryConnection')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Project $project) => $this->toArray($project, $user));
    }

    public function findForUser(User $user, int $id): array
    {
        $project = $this->findProjectModel($user, $id);
        $project->load('bigQueryConnection');

        return $this->toArray($project, $user);
    }

    public function create(User $user, Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $organization = $this->getOrganization($user);

        $project = $organization->projects()->create([
            'name' => $validated['name'],
            'industry' => $organization->industry,
            'timezone' => 'UTC -4:00 - Tokyo',
            'setup_step' => 4,
            'setup_completed' => false,
        ]);

        $this->connectionService->upsertFromRequest($project, $request, true);
        $project->load('bigQueryConnection');
        $this->activeProjectService->activate($user, $project->id);
        $user->refresh();

        return $this->toArray($project, $user);
    }

    public function update(User $user, int $id, Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $project = $this->findProjectModel($user, $id);
        $project->update(['name' => $validated['name']]);

        $hasExistingFile = (bool) $project->bigQueryConnection?->service_account_path;
        $this->connectionService->upsertFromRequest($project, $request, ! $hasExistingFile);
        $project->load('bigQueryConnection');

        return $this->toArray($project, $user);
    }

    public function delete(User $user, int $id): void
    {
        $project = $this->findProjectModel($user, $id);
        $projectId = $project->id;

        $this->activeProjectService->clearIfDeleted($user, $projectId);

        $project->delete();
        $this->connectionService->deleteStorageForProject($projectId);
    }

    public function getGoalsForProject(User $user, int $projectId, Carbon $start, Carbon $end): array
    {
        $project = $this->findProjectModel($user, $projectId);
        $project->load('bigQueryConnection');

        if (! $project->bigQueryConnection?->is_connected) {
            throw new RuntimeException('BigQuery must be connected before loading goals.');
        }

        return $this->goalsQuery->fetch($project, $start, $end);
    }

    public function saveGoal(User $user, int $projectId, array $goal): array
    {
        $project = $this->findProjectModel($user, $projectId);

        $goalTypeLabel = ($goal['type'] ?? '') === 'Event' ? 'Event purchase' : 'Page load goal';

        $project->update([
            'goal_external_id' => $goal['id'] ?? null,
            'goal_path' => $goal['path'] ?? null,
            'goal_event_type' => $goal['type'] ?? null,
            'goal_occurrences' => $goal['occurrences'] ?? null,
            'goal_type' => $goalTypeLabel,
        ]);

        $project->load('bigQueryConnection');

        // NEW: Trigger advanced attribution population if ready
        $this->triggerAutomationIfReady($project);

        return $this->toArray($project, $user);
    }

    private function findProjectModel(User $user, int $id): Project
    {
        $organization = $this->getOrganization($user);

        return $organization->projects()->whereKey($id)->firstOrFail();
    }

    private function getOrganization(User $user): Organization
    {
        return $user->organizations()->firstOrCreate(
            [],
            ['name' => '', 'industry' => '']
        );
    }

    private function toArray(Project $project, User $user): array
    {
        $connection = $project->bigQueryConnection;

        return [
            'id' => $project->id,
            'name' => $project->name,
            'isCurrent' => $user->active_project_id === $project->id,
            'gcpProjectId' => $connection?->gcp_project_id ?? '',
            'datasetId' => $connection?->dataset_id ?? '',
            'location' => $connection?->location ?? 'US',
            'serviceAccountFilename' => $connection?->service_account_filename ?? '',
            'isConnected' => $connection?->is_connected ?? false,
            'selectedReport' => $connection?->selected_report,
            'connectedAt' => $connection?->connected_at?->toIso8601String(),
            'goal' => $project->goal_external_id ? [
                'id' => $project->goal_external_id,
                'path' => $project->goal_path,
                'type' => $project->goal_event_type,
                'occurrences' => $project->goal_occurrences,
            ] : null,
            'goalType' => $project->goal_type ?? '',
        ];
    }

    /**
     * Trigger automated advanced attribution population if project is ready.
     * This happens automatically when goals are configured.
     */
    private function triggerAutomationIfReady(Project $project): void
    {
        try {
            if ($this->automationService->isReadyForAdvanced($project)) {
                $goalId = $this->generateGoalId($project);
                $this->automationService->onGoalConfigured($project, $goalId);
            }
        } catch (\Exception $e) {
            // Log error but don't break goal saving
            \Log::warning('Failed to trigger advanced attribution automation', [
                'project' => $project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate goal_id from project configuration.
     */
    private function generateGoalId(Project $project): string
    {
        // Try to get from goal_sets table
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

        // Fallback: generate from goal_path and project_id
        return md5($project->goal_path . $project->id);
    }
}
