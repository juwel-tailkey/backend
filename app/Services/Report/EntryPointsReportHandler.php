<?php

namespace App\Services\Report;

use App\Contracts\ReportHandler;
use App\Models\Project;
use App\Models\Segment;
use App\Services\BigQuery\EntryPointsConversionQueryService;
use App\Support\ChartDateRange;
use RuntimeException;

class EntryPointsReportHandler implements ReportHandler
{
    private const RANK_BY_OPTIONS = ['unique_views', 'sessions', 'cvr_to_goal'];

    public function __construct(
        private readonly EntryPointsConversionQueryService $entryPoints
    ) {
    }

    public function handle(Project $project, array $payload): array
    {
        if (! $project->bigQueryConnection?->is_connected) {
            throw new RuntimeException('Connect BigQuery for the current project before viewing reports.');
        }

        if (! $project->goal_path || ! $project->goal_event_type) {
            throw new RuntimeException('Set a conversion goal for this project before viewing entry point attribution.');
        }

        $dateRange = ChartDateRange::fromArray($payload);

        $rankBy = $payload['rankBy'] ?? 'unique_views';
        if (! in_array($rankBy, self::RANK_BY_OPTIONS, true)) {
            throw new RuntimeException('Invalid rankBy value.');
        }

        $segment = $this->resolveSegment($project, $payload['segmentId'] ?? null);

        return $this->entryPoints->fetch($project, $dateRange['start'], $dateRange['end'], $rankBy, $segment);
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
