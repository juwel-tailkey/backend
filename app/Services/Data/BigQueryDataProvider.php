<?php

namespace App\Services\Data;

use App\Contracts\DataProvider;
use App\Models\Project;
use RuntimeException;

class BigQueryDataProvider implements DataProvider
{
    private function assertConfigured(): void
    {
        if (! config('bigquery.project_id')) {
            throw new RuntimeException('BIGQUERY_PROJECT_ID is required when DATA_PROVIDER=bigquery.');
        }
    }

    public function getSankey(Project $project): array
    {
        $this->assertConfigured();
        throw new RuntimeException("BigQuery Sankey query is not implemented yet for project {$project->id}.");
    }

    public function getPageviews(Project $project): array
    {
        $this->assertConfigured();
        throw new RuntimeException("BigQuery pageviews query is not implemented yet for project {$project->id}.");
    }

    public function getCountrySessions(Project $project): array
    {
        $this->assertConfigured();
        throw new RuntimeException("BigQuery country sessions query is not implemented yet for project {$project->id}.");
    }

    public function getPagePaths(Project $project): array
    {
        $this->assertConfigured();
        throw new RuntimeException("BigQuery page paths query is not implemented yet for project {$project->id}.");
    }
}
