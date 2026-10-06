<?php

namespace App\Contracts;

use App\Models\Project;

interface ReportHandler
{
    /**
     * Build the report data directly from BigQuery for the given project and payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the payload is invalid or the project isn't ready.
     */
    public function handle(Project $project, array $payload): array;
}
