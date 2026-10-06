<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Segment;
use App\Services\BigQuery\BlockFlowQueryService;
use App\Services\BigQuery\EntryPointsConversionQueryService;
use App\Services\BigQuery\ExitPointsConversionQueryService;
use App\Services\BigQuery\PageImpactQueryService;
use App\Services\BigQuery\SourceTrafficQueryService;
use App\Services\BigQuery\TopConvertingPathsQueryService;
use App\Services\Project\ActiveProjectService;
use App\Support\ChartDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class AttributionController extends Controller
{
    private const ENTRY_RANK_BY_OPTIONS = ['unique_views', 'sessions', 'cvr_to_goal'];

    private const EXIT_RANK_BY_OPTIONS = ['unique_views', 'sessions', 'exit_rate'];

    private const SOURCE_RANK_BY_OPTIONS = ['unique_views', 'sessions', 'bounce_rate', 'engagement_rate'];

    public function __construct(
        private readonly ActiveProjectService $activeProject,
        private readonly EntryPointsConversionQueryService $entryPoints,
        private readonly ExitPointsConversionQueryService $exitPoints,
        private readonly SourceTrafficQueryService $sourceTraffic,
        private readonly PageImpactQueryService $pageImpact,
        private readonly TopConvertingPathsQueryService $convertingPaths,
        private readonly BlockFlowQueryService $blockFlow
    ) {
    }

    public function blockFlow(Request $request): JsonResponse
    {
        $project = $this->activeProject->resolve($request->user());

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        if (! $project->bigQueryConnection?->is_connected) {
            return response()->json([
                'error' => 'Connect BigQuery for the current project before viewing reports.',
            ], 422);
        }

        if (! $project->goal_path || ! $project->goal_event_type) {
            return response()->json([
                'error' => 'Set a conversion goal for this project before viewing the block view.',
            ], 422);
        }

        $dateRange = ChartDateRange::fromRequest($request);
        if ($dateRange instanceof JsonResponse) {
            return $dateRange;
        }

        $sourceChannel = $request->query('sourceChannel');
        $sourceChannel = is_string($sourceChannel) && $sourceChannel !== '' ? $sourceChannel : null;

        try {
            return response()->json(
                $this->blockFlow->fetch(
                    $project,
                    $dateRange['start'],
                    $dateRange['end'],
                    $this->resolveSegment($project, $request),
                    $sourceChannel
                )
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function convertingPaths(Request $request): JsonResponse
    {
        $project = $this->activeProject->resolve($request->user());

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        if (! $project->bigQueryConnection?->is_connected) {
            return response()->json([
                'error' => 'Connect BigQuery for the current project before viewing reports.',
            ], 422);
        }

        if (! $project->goal_path || ! $project->goal_event_type) {
            return response()->json([
                'error' => 'Set a conversion goal for this project before viewing converting paths.',
            ], 422);
        }

        $dateRange = ChartDateRange::fromRequest($request);
        if ($dateRange instanceof JsonResponse) {
            return $dateRange;
        }

        try {
            return response()->json(
                $this->convertingPaths->fetch(
                    $project,
                    $dateRange['start'],
                    $dateRange['end'],
                    $this->resolveSegment($project, $request)
                )
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function pageImpact(Request $request): JsonResponse
    {
        $project = $this->activeProject->resolve($request->user());

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        if (! $project->bigQueryConnection?->is_connected) {
            return response()->json([
                'error' => 'Connect BigQuery for the current project before viewing reports.',
            ], 422);
        }

        if (! $project->goal_path || ! $project->goal_event_type) {
            return response()->json([
                'error' => 'Set a conversion goal for this project before viewing page impact.',
            ], 422);
        }

        $dateRange = ChartDateRange::fromRequest($request);
        if ($dateRange instanceof JsonResponse) {
            return $dateRange;
        }

        try {
            return response()->json(
                $this->pageImpact->fetch(
                    $project,
                    $dateRange['start'],
                    $dateRange['end'],
                    $this->resolveSegment($project, $request)
                )
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function entryPoints(Request $request): JsonResponse
    {
        $project = $this->activeProject->resolve($request->user());

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        if (! $project->bigQueryConnection?->is_connected) {
            return response()->json([
                'error' => 'Connect BigQuery for the current project before viewing reports.',
            ], 422);
        }

        if (! $project->goal_path || ! $project->goal_event_type) {
            return response()->json([
                'error' => 'Set a conversion goal for this project before viewing entry point attribution.',
            ], 422);
        }

        $dateRange = ChartDateRange::fromRequest($request);
        if ($dateRange instanceof JsonResponse) {
            return $dateRange;
        }

        $rankByValidator = Validator::make($request->query(), [
            'rankBy' => ['nullable', 'string', 'in:'.implode(',', self::ENTRY_RANK_BY_OPTIONS)],
        ]);

        if ($rankByValidator->fails()) {
            return response()->json([
                'error' => 'Invalid rankBy value.',
                'errors' => $rankByValidator->errors(),
            ], 422);
        }

        $rankBy = $request->query('rankBy', 'unique_views');

        try {
            return response()->json(
                $this->entryPoints->fetch(
                    $project,
                    $dateRange['start'],
                    $dateRange['end'],
                    $rankBy,
                    $this->resolveSegment($project, $request)
                )
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function exitPoints(Request $request): JsonResponse
    {
        $project = $this->activeProject->resolve($request->user());

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        if (! $project->bigQueryConnection?->is_connected) {
            return response()->json([
                'error' => 'Connect BigQuery for the current project before viewing reports.',
            ], 422);
        }

        if (! $project->goal_path || ! $project->goal_event_type) {
            return response()->json([
                'error' => 'Set a conversion goal for this project before viewing exit point attribution.',
            ], 422);
        }

        $dateRange = ChartDateRange::fromRequest($request);
        if ($dateRange instanceof JsonResponse) {
            return $dateRange;
        }

        $rankByValidator = Validator::make($request->query(), [
            'rankBy' => ['nullable', 'string', 'in:'.implode(',', self::EXIT_RANK_BY_OPTIONS)],
        ]);

        if ($rankByValidator->fails()) {
            return response()->json([
                'error' => 'Invalid rankBy value.',
                'errors' => $rankByValidator->errors(),
            ], 422);
        }

        $rankBy = $request->query('rankBy', 'unique_views');

        try {
            return response()->json(
                $this->exitPoints->fetch(
                    $project,
                    $dateRange['start'],
                    $dateRange['end'],
                    $rankBy,
                    $this->resolveSegment($project, $request)
                )
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function sourceTraffic(Request $request): JsonResponse
    {
        $project = $this->activeProject->resolve($request->user());

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        if (! $project->bigQueryConnection?->is_connected) {
            return response()->json([
                'error' => 'Connect BigQuery for the current project before viewing reports.',
            ], 422);
        }

        if (! $project->goal_path || ! $project->goal_event_type) {
            return response()->json([
                'error' => 'Set a conversion goal for this project before viewing source traffic.',
            ], 422);
        }

        $dateRange = ChartDateRange::fromRequest($request);
        if ($dateRange instanceof JsonResponse) {
            return $dateRange;
        }

        $rankByValidator = Validator::make($request->query(), [
            'rankBy' => ['nullable', 'string', 'in:'.implode(',', self::SOURCE_RANK_BY_OPTIONS)],
        ]);

        if ($rankByValidator->fails()) {
            return response()->json([
                'error' => 'Invalid rankBy value.',
                'errors' => $rankByValidator->errors(),
            ], 422);
        }

        $rankBy = $request->query('rankBy', 'unique_views');

        try {
            return response()->json(
                $this->sourceTraffic->fetch(
                    $project,
                    $dateRange['start'],
                    $dateRange['end'],
                    $rankBy,
                    $this->resolveSegment($project, $request)
                )
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    private function resolveSegment(Project $project, Request $request): ?Segment
    {
        $segmentId = $request->query('segmentId');

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
