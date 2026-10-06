<?php

namespace App\Http\Controllers;

use App\Services\Project\ActiveProjectService;
use App\Services\Project\ProjectService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly ActiveProjectService $activeProject
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'projects' => $this->projects->list($request->user()),
        ]);
    }

    public function show(Request $request, int $project): JsonResponse
    {
        return response()->json(
            $this->projects->findForUser($request->user(), $project)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $record = $this->projects->create($request->user(), $request);

        return response()->json($record, 201);
    }

    public function update(Request $request, int $project): JsonResponse
    {
        $record = $this->projects->update($request->user(), $project, $request);

        return response()->json($record);
    }

    public function destroy(Request $request, int $project): JsonResponse
    {
        $this->projects->delete($request->user(), $project);

        return response()->json(['ok' => true]);
    }

    public function goals(Request $request, int $project): JsonResponse
    {
        $start = Carbon::parse((string) config('bigquery.goals_lookback_start', '2015-01-01'))->startOfDay();
        $end = Carbon::today();

        try {
            return response()->json([
                'goals' => $this->projects->getGoalsForProject(
                    $request->user(),
                    $project,
                    $start,
                    $end
                ),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function updateGoal(Request $request, int $project): JsonResponse
    {
        return response()->json(
            $this->projects->saveGoal($request->user(), $project, $request->all())
        );
    }

    public function activate(Request $request, int $project): JsonResponse
    {
        return response()->json([
            'activeProject' => $this->activeProject->activate($request->user(), $project),
        ]);
    }
}
