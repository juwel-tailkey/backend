<?php

namespace App\Services\Project;

use App\Models\Project;
use App\Models\User;

class ActiveProjectService
{
    public function resolve(User $user): ?Project
    {
        $user->loadMissing('activeProject.bigQueryConnection');

        if ($user->activeProject && $this->projectBelongsToUser($user, $user->activeProject)) {
            return $user->activeProject;
        }

        $organization = $user->organizations()->first();
        if (! $organization) {
            return null;
        }

        $project = $organization->projects()
            ->with('bigQueryConnection')
            ->orderByDesc('updated_at')
            ->first();

        if (! $project) {
            return null;
        }

        $user->update(['active_project_id' => $project->id]);

        return $project->fresh(['bigQueryConnection']);
    }

    public function activate(User $user, int $projectId): array
    {
        $project = $this->findProjectForUser($user, $projectId);
        $user->update(['active_project_id' => $project->id]);

        return $this->toSummary($project->fresh(['bigQueryConnection']));
    }

    public function clearIfDeleted(User $user, int $deletedProjectId): void
    {
        if ($user->active_project_id !== $deletedProjectId) {
            return;
        }

        $user->update(['active_project_id' => null]);
        $this->resolve($user->fresh());
    }

    public function summaryForUser(User $user): ?array
    {
        $project = $this->resolve($user);

        return $project ? $this->toSummary($project) : null;
    }

    public function toSummary(Project $project): array
    {
        $project->loadMissing('bigQueryConnection');
        $connection = $project->bigQueryConnection;

        return [
            'id' => $project->id,
            'name' => $project->name,
            'isConnected' => $connection?->is_connected ?? false,
            'goal' => $project->goal_external_id ? [
                'id' => $project->goal_external_id,
                'path' => $project->goal_path,
                'type' => $project->goal_event_type,
                'occurrences' => $project->goal_occurrences,
            ] : null,
            'goalType' => $project->goal_type ?? '',
        ];
    }

    public function findProjectForUser(User $user, int $projectId): Project
    {
        $organization = $user->organizations()->firstOrFail();

        return $organization->projects()->whereKey($projectId)->firstOrFail();
    }

    private function projectBelongsToUser(User $user, Project $project): bool
    {
        return $user->organizations()
            ->whereHas('projects', fn ($query) => $query->whereKey($project->id))
            ->exists();
    }
}
