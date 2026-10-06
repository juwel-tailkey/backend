<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'provider' => config('bigquery.data_provider', 'mock'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
