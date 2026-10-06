<?php

namespace App\Http\Controllers;

use App\Services\Project\ActiveProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActiveProjectController extends Controller
{
    public function __construct(private readonly ActiveProjectService $activeProject)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'activeProject' => $this->activeProject->summaryForUser($request->user()),
        ]);
    }
}
