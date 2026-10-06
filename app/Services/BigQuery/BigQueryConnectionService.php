<?php

namespace App\Services\BigQuery;

use App\Models\BigQueryConnection;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BigQueryConnectionService
{
    public function upsertFromRequest(Project $project, Request $request, bool $requireFile): BigQueryConnection
    {
        $existing = $project->bigQueryConnection;

        $validated = $request->validate([
            'gcp_project_id' => ['required', 'string', 'max:255'],
            'dataset_id' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:32'],
            'service_account' => [
                $requireFile ? 'required' : 'nullable',
                'file',
                'mimes:json',
                'max:2048',
            ],
        ]);

        $path = $existing?->service_account_path;
        $filename = $existing?->service_account_filename;

        if ($request->hasFile('service_account')) {
            /** @var UploadedFile $file */
            $file = $request->file('service_account');
            $path = $file->storeAs(
                "bigquery/{$project->id}",
                'service-account.json',
                'local'
            );
            $filename = $file->getClientOriginalName();
        }

        if (! $path) {
            throw new RuntimeException('Service account JSON file is required.');
        }

        return BigQueryConnection::updateOrCreate(
            ['project_id' => $project->id],
            [
                'gcp_project_id' => $validated['gcp_project_id'],
                'dataset_id' => $validated['dataset_id'] ?? null,
                'location' => $validated['location'] ?? 'US',
                'service_account_path' => $path,
                'service_account_filename' => $filename,
                'is_connected' => true,
                'selected_report' => $existing?->selected_report ?? 'GA4 purchase attribution export',
                'connected_at' => now(),
            ]
        );
    }

    public function deleteStorageForProject(int $projectId): void
    {
        Storage::disk('local')->deleteDirectory("bigquery/{$projectId}");
    }
}
