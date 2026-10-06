<?php

namespace App\Http\Controllers;

use App\Contracts\DataProvider;
use App\Models\Project;
use App\Services\BigQuery\CountrySessionsQueryService;
use App\Services\BigQuery\PageviewsQueryService;
use App\Services\Project\ActiveProjectService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\ChartDateRange;
use RuntimeException;

class ChartController extends Controller
{
    public function __construct(
        private readonly DataProvider $provider,
        private readonly ActiveProjectService $activeProject,
        private readonly CountrySessionsQueryService $countrySessions,
        private readonly PageviewsQueryService $pageviews
    ) {
    }

    public function sankey(Request $request): JsonResponse
    {
        return $this->chartResponse($request, fn ($project) => $this->provider->getSankey($project));
    }

    public function pageviews(Request $request): JsonResponse
    {
        $resolved = $this->resolveProjectAndDateRange($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        try {
            return response()->json(
                $this->pageviews->fetch($resolved['project'], $resolved['start'], $resolved['end'])
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function countrySessions(Request $request): JsonResponse
    {
        $resolved = $this->resolveProjectAndDateRange($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        try {
            return response()->json(
                $this->countrySessions->fetch($resolved['project'], $resolved['start'], $resolved['end'])
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function pagePaths(Request $request): JsonResponse
    {
        return $this->chartResponse($request, fn ($project) => $this->provider->getPagePaths($project));
    }

    /**
     * @return array{project: Project, start: Carbon, end: Carbon}|JsonResponse
     */
    private function resolveProjectAndDateRange(Request $request): array|JsonResponse
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

        $dateRange = ChartDateRange::fromRequest($request);
        if ($dateRange instanceof JsonResponse) {
            return $dateRange;
        }

        return [
            'project' => $project,
            'start' => $dateRange['start'],
            'end' => $dateRange['end'],
        ];
    }

    private function chartResponse(Request $request, callable $callback): JsonResponse
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

        return response()->json($callback($project));
    }
}
