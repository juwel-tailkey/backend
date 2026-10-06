<?php

namespace App\Http\Controllers;

use App\Models\Segment;
use App\Services\Project\ActiveProjectService;
use App\Services\Segment\SegmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SegmentController extends Controller
{
    public function __construct(
        private readonly SegmentService $segments,
        private readonly ActiveProjectService $activeProject
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->activeProject->resolve($request->user());

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        return response()->json([
            'segments' => $this->segments->list($project),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->activeProject->resolve($request->user());

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        try {
            return response()->json(
                $this->segments->create($request->user(), $project, $request),
                201
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function update(Request $request, int $segment): JsonResponse
    {
        $project = $this->activeProject->resolve($request->user());

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        $model = $this->findForProject($project->id, $segment);

        if (! $model) {
            return response()->json(['error' => 'Segment not found'], 404);
        }

        try {
            return response()->json(
                $this->segments->update($model, $request)
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Request $request, int $segment): JsonResponse
    {
        $project = $this->activeProject->resolve($request->user());

        if (! $project) {
            return response()->json(['error' => 'No project selected'], 422);
        }

        $model = $this->findForProject($project->id, $segment);

        if (! $model) {
            return response()->json(['error' => 'Segment not found'], 404);
        }

        $this->segments->delete($model);

        return response()->json(['ok' => true]);
    }

    private function findForProject(int $projectId, int $segmentId): ?Segment
    {
        return Segment::query()
            ->where('project_id', $projectId)
            ->whereKey($segmentId)
            ->first();
    }
}
