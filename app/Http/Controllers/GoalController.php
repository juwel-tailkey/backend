<?php

namespace App\Http\Controllers;

use App\Models\GoalSet;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class GoalController extends Controller
{
    /**
     * List all goals for a project.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $goals = GoalSet::where('project_id', $project->id)
            ->orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'goals' => $goals,
            'active_goal' => $goals->firstWhere('is_active', true),
        ]);
    }

    /**
     * Store a new goal.
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validator = Validator::make($request->all(), [
            'goal_id' => 'required|string|max:255',
            'goal_name' => 'required|string|max:255',
            'goal_event_name' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $goal = GoalSet::create([
            'project_id' => $project->id,
            'goal_id' => $request->goal_id,
            'goal_name' => $request->goal_name,
            'goal_event_name' => $request->goal_event_name,
            'is_active' => $request->is_active ?? true,
        ]);

        return response()->json($goal, 201);
    }

    /**
     * Show a specific goal.
     */
    public function show(Request $request, Project $project, string $goalId): JsonResponse
    {
        $this->authorize('view', $project);

        $goal = GoalSet::where('project_id', $project->id)
            ->where('goal_id', $goalId)
            ->firstOrFail();

        return response()->json($goal);
    }

    /**
     * Update a goal.
     */
    public function update(Request $request, Project $project, string $goalId): JsonResponse
    {
        $this->authorize('update', $project);

        $goal = GoalSet::where('project_id', $project->id)
            ->where('goal_id', $goalId)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'goal_name' => 'string|max:255',
            'goal_event_name' => 'string|max:255',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('goal_name')) {
            $goal->goal_name = $request->goal_name;
        }

        if ($request->has('goal_event_name')) {
            $goal->goal_event_name = $request->goal_event_name;
        }

        if ($request->has('is_active')) {
            $goal->is_active = $request->is_active;
        }

        $goal->save();

        return response()->json($goal);
    }

    /**
     * Delete a goal.
     */
    public function destroy(Request $request, Project $project, string $goalId): JsonResponse
    {
        $this->authorize('update', $project);

        $goal = GoalSet::where('project_id', $project->id)
            ->where('goal_id', $goalId)
            ->firstOrFail();

        $goal->delete();

        return response()->json(null, 204);
    }

    /**
     * Set a goal as active.
     */
    public function setActive(Request $request, Project $project, string $goalId): JsonResponse
    {
        $this->authorize('update', $project);

        // Deactivate all other goals for this project
        GoalSet::where('project_id', $project->id)
            ->where('goal_id', '!=', $goalId)
            ->update(['is_active' => false]);

        // Activate the selected goal
        $goal = GoalSet::where('project_id', $project->id)
            ->where('goal_id', $goalId)
            ->firstOrFail();

        $goal->is_active = true;
        $goal->save();

        return response()->json($goal);
    }
}
