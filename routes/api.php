<?php

use App\Http\Controllers\ActiveProjectController;
use App\Http\Controllers\AdvancedSegmentController;
use App\Http\Controllers\AttributionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChartController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Internal\CustomModelDummyController;
use App\Http\Controllers\ProjectAutomationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportGeneratorController;
use App\Http\Controllers\SegmentController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'show']);

// NEW: Local stand-in for the real third-party custom model endpoint (dev/test only).
Route::post('/internal/custom-model-dummy', [CustomModelDummyController::class, 'accept']);

// NEW: Safe system status endpoints
Route::get('/system/status', [SystemStatusController::class, 'systemStatus']);
Route::get('/system/advanced-attribution-status', [SystemStatusController::class, 'advancedAttributionStatus']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/active-project', [ActiveProjectController::class, 'show']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/attribution/source-traffic', [AttributionController::class, 'sourceTraffic']);
    Route::get('/attribution/entry-points', [AttributionController::class, 'entryPoints']);
    Route::get('/attribution/exit-points', [AttributionController::class, 'exitPoints']);
    Route::get('/attribution/page-impact', [AttributionController::class, 'pageImpact']);
    Route::get('/attribution/converting-paths', [AttributionController::class, 'convertingPaths']);
    Route::get('/attribution/block-flow', [AttributionController::class, 'blockFlow']);

    Route::get('/charts/sankey', [ChartController::class, 'sankey']);
    Route::get('/charts/pageviews', [ChartController::class, 'pageviews']);
    Route::get('/charts/country-sessions', [ChartController::class, 'countrySessions']);
    Route::get('/tables/page-paths', [ChartController::class, 'pagePaths']);

    Route::get('/setup', [SetupController::class, 'show']);
    Route::put('/setup/project', [SetupController::class, 'updateProject']);
    Route::post('/setup/connect', [SetupController::class, 'connect']);
    Route::get('/setup/goals', [SetupController::class, 'goals']);
    Route::put('/setup/goal', [SetupController::class, 'updateGoal']);
    Route::post('/setup/complete', [SetupController::class, 'complete']);
    Route::post('/setup/users', [SetupController::class, 'addUser']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::put('/projects/{project}/activate', [ProjectController::class, 'activate']);
    Route::get('/projects/{project}/goals', [ProjectController::class, 'goals']);
    Route::put('/projects/{project}/goal', [ProjectController::class, 'updateGoal']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::put('/projects/{project}', [ProjectController::class, 'update']);
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);

    Route::get('/segments', [SegmentController::class, 'index']);
    Route::post('/segments', [SegmentController::class, 'store']);
    Route::put('/segments/{segment}', [SegmentController::class, 'update']);
    Route::delete('/segments/{segment}', [SegmentController::class, 'destroy']);

    // NEW: Goal management routes
    Route::post('/projects/{project}/goals', [GoalController::class, 'store']);
    Route::get('/projects/{project}/goals/{goalId}', [GoalController::class, 'show']);
    Route::put('/projects/{project}/goals/{goalId}', [GoalController::class, 'update']);
    Route::delete('/projects/{project}/goals/{goalId}', [GoalController::class, 'destroy']);
    Route::put('/projects/{project}/goals/{goalId}/set-active', [GoalController::class, 'setActive']);

    // NEW: Advanced segment routes
    Route::get('/projects/{project}/advanced-segments', [AdvancedSegmentController::class, 'index']);
    Route::get('/projects/{project}/advanced-segments/statistics', [AdvancedSegmentController::class, 'statistics']);
    Route::get('/projects/{project}/advanced-segments/device/{deviceId}', [AdvancedSegmentController::class, 'forDevice']);
    Route::post('/projects/{project}/advanced-segments/calculate/general', [AdvancedSegmentController::class, 'calculateGeneral']);
    Route::post('/projects/{project}/advanced-segments/calculate/attribution', [AdvancedSegmentController::class, 'calculateAttribution']);

    // NEW: Attribution calculation triggers
    Route::post('/projects/{project}/attribution/calculate/assembly-scores', [AdvancedSegmentController::class, 'calculateAssemblyScores']);
    Route::post('/projects/{project}/attribution/calculate/journey-strength', [AdvancedSegmentController::class, 'calculateJourneyStrength']);
    Route::post('/projects/{project}/attribution/calculate/atb-vals', [AdvancedSegmentController::class, 'calculateAtbVals']);

    // NEW: Project automation endpoints
    Route::post('/projects/{project}/automate/populate-attribution', [ProjectAutomationController::class, 'populateAttribution']);
    Route::get('/projects/{project}/automate/status', [ProjectAutomationController::class, 'checkPopulationStatus']);

    // NEW: Generic report generator (direct BigQuery vs custom model)
    Route::get('/reports/catalog', [ReportGeneratorController::class, 'catalog']);
    Route::post('/reports/generate', [ReportGeneratorController::class, 'generate']);
    Route::get('/reports/generate/{reportGenerator}', [ReportGeneratorController::class, 'status']);
});
