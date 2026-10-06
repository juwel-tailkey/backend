<?php

namespace App\Services\BigQuery;

use App\Models\BigQueryConnection;
use App\Models\Project;
use Google\Cloud\BigQuery\BigQueryClient;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BigQueryClientFactory
{
    public function forProject(Project $project): BigQueryClient
    {
        $project->loadMissing('bigQueryConnection');
        $connection = $project->bigQueryConnection;

        if (! $connection?->is_connected) {
            throw new RuntimeException('BigQuery is not connected for this project.');
        }

        if (! $connection->dataset_id) {
            throw new RuntimeException('BigQuery dataset ID is required for this project.');
        }

        $keyPath = $this->resolveKeyPath($connection);

        return new BigQueryClient([
            'projectId' => $connection->gcp_project_id,
            'keyFilePath' => $keyPath,
            'location' => $connection->location ?: 'US',
        ]);
    }

    public function connectionFor(Project $project): BigQueryConnection
    {
        $project->loadMissing('bigQueryConnection');
        $connection = $project->bigQueryConnection;

        if (! $connection?->is_connected) {
            throw new RuntimeException('BigQuery is not connected for this project.');
        }

        if (! $connection->dataset_id) {
            throw new RuntimeException('BigQuery dataset ID is required for this project.');
        }

        return $connection;
    }

    private function resolveKeyPath(BigQueryConnection $connection): string
    {
        if (! $connection->service_account_path) {
            throw new RuntimeException('Service account JSON file is missing for this project.');
        }

        if (! Storage::disk('local')->exists($connection->service_account_path)) {
            throw new RuntimeException('Service account JSON file could not be found on the server.');
        }

        return Storage::disk('local')->path($connection->service_account_path);
    }
}
