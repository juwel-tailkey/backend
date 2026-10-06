<?php

namespace App\Services\Setup;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\BigQuery\BigQueryConnectionService;
use App\Services\BigQuery\BigQueryGoalsQueryService;
use App\Services\Project\ActiveProjectService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

class SetupService
{
    public function __construct(
        private readonly BigQueryGoalsQueryService $goalsQuery,
        private readonly BigQueryConnectionService $connectionService,
        private readonly ActiveProjectService $activeProjectService
    ) {
    }

    public function getStateForUser(User $user): array
    {
        $organization = $this->getOrCreateOrganization($user);
        $project = $this->resolveOrCreateProject($user, $organization);
        $connection = $project->bigQueryConnection;

        $goal = null;
        if ($project->goal_external_id) {
            $goal = [
                'id' => $project->goal_external_id,
                'path' => $project->goal_path,
                'type' => $project->goal_event_type,
                'occurrences' => $project->goal_occurrences,
            ];
        }

        $isConnected = $connection?->is_connected ?? false;

        return [
            'currentStep' => $project->setup_step,
            'completed' => $project->setup_completed,
            'basics' => [
                'organization' => $organization->name,
                'industry' => $organization->industry ?? '',
            ],
            'project' => [
                'projectName' => $project->name,
                'industry' => $project->industry ?? $organization->industry ?? '',
                'organization' => $organization->name,
                'timezone' => $project->timezone,
                'description' => $project->description ?? '',
                'goalType' => $project->goal_type ?? '',
            ],
            'users' => [],
            'connection' => [
                'directConnected' => $isConnected,
                'gcpProjectId' => $connection?->gcp_project_id ?? '',
                'datasetId' => $connection?->dataset_id ?? '',
                'location' => $connection?->location ?? 'US',
                'serviceAccountFile' => $connection?->service_account_filename ?? '',
                'selectedReport' => $connection?->selected_report ?? '',
                'rawFileName' => '',
                'rawFileSize' => '',
                'uploadStatus' => 'idle',
            ],
            'goal' => $goal,
            'availableGoals' => [],
        ];
    }

    public function saveOrganization(User $user, array $basics, ?int $currentStep = null): array
    {
        $organization = $this->getOrCreateOrganization($user);
        $organization->update([
            'name' => $basics['organization'] ?? $organization->name,
            'industry' => $basics['industry'] ?? $organization->industry,
        ]);

        $project = $this->resolveOrCreateProject($user, $organization);
        if ($currentStep !== null) {
            $project->update(['setup_step' => max($project->setup_step, $currentStep)]);
        }

        return $this->getStateForUser($user);
    }

    public function saveProject(User $user, array $data, ?int $currentStep = null): array
    {
        $organization = $this->getOrCreateOrganization($user);
        $project = $this->resolveOrCreateProject($user, $organization);

        $project->update([
            'name' => $data['projectName'] ?? $data['name'] ?? $project->name,
            'industry' => $data['industry'] ?? $organization->industry,
            'timezone' => $data['timezone'] ?? $project->timezone,
            'description' => $data['description'] ?? $project->description,
            'setup_step' => $currentStep !== null ? max($project->setup_step, $currentStep) : $project->setup_step,
        ]);

        if (! empty($data['organization'])) {
            $organization->update(['name' => $data['organization']]);
        }

        return $this->getStateForUser($user);
    }

    public function connectBigQuery(User $user, Request $request): array
    {
        $organization = $this->getOrCreateOrganization($user);
        $project = $this->resolveOrCreateProject($user, $organization);
        $existing = $project->bigQueryConnection;

        $this->connectionService->upsertFromRequest(
            $project,
            $request,
            ! $existing?->is_connected
        );

        $project->update(['setup_step' => max($project->setup_step, 4)]);

        return $this->getStateForUser($user);
    }

    public function getAvailableGoals(User $user, Carbon $start, Carbon $end): array
    {
        $organization = $this->getOrCreateOrganization($user);
        $project = $this->resolveOrCreateProject($user, $organization);
        $connection = $project->bigQueryConnection;

        if (! $connection?->is_connected) {
            throw new RuntimeException('BigQuery must be connected before loading goals.');
        }

        return $this->goalsQuery->fetch($project, $start, $end);
    }

    public function saveGoal(User $user, array $goal): array
    {
        $organization = $this->getOrCreateOrganization($user);
        $project = $this->resolveOrCreateProject($user, $organization);

        $goalTypeLabel = ($goal['type'] ?? '') === 'Event' ? 'Event purchase' : 'Page load goal';

        $project->update([
            'goal_external_id' => $goal['id'] ?? null,
            'goal_path' => $goal['path'] ?? null,
            'goal_event_type' => $goal['type'] ?? null,
            'goal_occurrences' => $goal['occurrences'] ?? null,
            'goal_type' => $goalTypeLabel,
            'setup_step' => max($project->setup_step, 4),
        ]);

        return $this->getStateForUser($user);
    }

    public function completeSetup(User $user): array
    {
        $organization = $this->getOrCreateOrganization($user);
        $project = $this->resolveOrCreateProject($user, $organization);

        $connection = $project->bigQueryConnection;
        if (! $connection?->is_connected) {
            throw new RuntimeException('Connect to BigQuery before completing setup.');
        }

        if (! $project->goal_external_id) {
            throw new RuntimeException('Select a conversion goal before completing setup.');
        }

        $project->update([
            'setup_completed' => true,
            'setup_step' => 4,
        ]);

        return $this->getStateForUser($user);
    }

    private function getOrCreateOrganization(User $user): Organization
    {
        return $user->organizations()->firstOrCreate(
            [],
            ['name' => '', 'industry' => '']
        );
    }

    private function resolveOrCreateProject(User $user, Organization $organization): Project
    {
        $project = $this->activeProjectService->resolve($user);

        if ($project && $project->organization_id === $organization->id) {
            return $project;
        }

        $project = $organization->projects()->create([
            'name' => '',
            'industry' => $organization->industry,
            'timezone' => 'UTC -4:00 - Tokyo',
            'setup_step' => 1,
            'setup_completed' => false,
        ]);

        $this->activeProjectService->activate($user, $project->id);

        return $project->fresh(['bigQueryConnection']);
    }
}
