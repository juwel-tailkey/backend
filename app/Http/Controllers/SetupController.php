<?php

namespace App\Http\Controllers;

use App\Services\Setup\SetupService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SetupController extends Controller
{
    public function __construct(private readonly SetupService $setup)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->setup->getStateForUser($request->user()));
    }

    public function updateProject(Request $request): JsonResponse
    {
        $body = $request->all();
        $basics = $body['basics'] ?? null;
        $currentStep = isset($body['currentStep']) ? (int) $body['currentStep'] : null;
        unset($body['basics'], $body['currentStep']);

        $user = $request->user();

        if ($basics) {
            $this->setup->saveOrganization($user, $basics, $currentStep);
        }

        $state = $this->setup->saveProject($user, $body, $currentStep);

        return response()->json($state);
    }

    public function connect(Request $request): JsonResponse
    {
        $state = $this->setup->connectBigQuery($request->user(), $request);

        return response()->json($state);
    }

    public function goals(Request $request): JsonResponse
    {
        $start = Carbon::parse((string) config('bigquery.goals_lookback_start', '2015-01-01'))->startOfDay();
        $end = Carbon::today();

        try {
            return response()->json([
                'goals' => $this->setup->getAvailableGoals(
                    $request->user(),
                    $start,
                    $end
                ),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function updateGoal(Request $request): JsonResponse
    {
        $state = $this->setup->saveGoal($request->user(), $request->all());

        return response()->json($state);
    }

    public function complete(Request $request): JsonResponse
    {
        $state = $this->setup->completeSetup($request->user());

        return response()->json($state);
    }

    public function addUser(): JsonResponse
    {
        return response()->json(['error' => 'Team invites are not available yet.'], 410);
    }
}
