<?php

namespace App\Services\Report;

use App\Contracts\ReportHandler;
use App\Models\Project;
use App\Models\Segment;
use App\Services\BigQuery\PageImpactQueryService;
use App\Support\ChartDateRange;
use RuntimeException;

class PageImpactReportHandler implements ReportHandler
{
    public function __construct(
        private readonly PageImpactQueryService $pageImpact
    ) {
    }

    public function handle(Project $project, array $payload): array
    {
        if (! $project->bigQueryConnection?->is_connected) {
            throw new RuntimeException('Connect BigQuery for the current project before viewing reports.');
        }

        if (! $project->goal_path || ! $project->goal_event_type) {
            throw new RuntimeException('Set a conversion goal for this project before viewing page impact.');
        }

        $dateRange = ChartDateRange::fromArray($payload);
        $segment = $this->resolveSegment($project, $payload['segmentId'] ?? null);

        return $this->pageImpact->fetch($project, $dateRange['start'], $dateRange['end'], $segment);
    }

    private function resolveSegment(Project $project, mixed $segmentId): ?Segment
    {
        if ($segmentId === null || $segmentId === '') {
            return null;
        }

        $segment = Segment::query()
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->whereKey($segmentId)
            ->first();

        if (! $segment) {
            throw new RuntimeException('The selected segment is not available.');
        }

        return $segment;
    }
}
