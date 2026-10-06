<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Segment\General\AcquisitionSegmentService;
use App\Services\Segment\General\TechnologySegmentService;
use App\Services\Segment\General\BehavioralSegmentService;
use App\Services\Segment\General\SequenceSegmentService;
use App\Services\Segment\General\RFMSegmentService;
use App\Services\Segment\General\CustomParameterSegmentService;
use App\Services\Segment\Attribution\HighAssemblySegmentService;
use App\Services\Segment\Attribution\JourneyStrengthSegmentService;
use App\Services\Segment\Attribution\DropOffSegmentService;
use App\Services\Segment\Attribution\TimeBoundDropOffSegmentService;
use App\Services\Attribution\AssemblyScoreService;
use App\Services\Attribution\JourneyStrengthService as JourneyStrengthCalcService;
use App\Services\Attribution\AtbValService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdvancedSegmentController extends Controller
{
    public function __construct(
        private AcquisitionSegmentService $acquisitionService,
        private TechnologySegmentService $technologyService,
        private BehavioralSegmentService $behavioralService,
        private SequenceSegmentService $sequenceService,
        private RFMSegmentService $rfmService,
        private CustomParameterSegmentService $customParameterService,
        private HighAssemblySegmentService $highAssemblyService,
        private JourneyStrengthSegmentService $journeyStrengthService,
        private DropOffSegmentService $dropOffService,
        private TimeBoundDropOffSegmentService $timeBoundDropOffService,
        private AssemblyScoreService $assemblyScoreService,
        private JourneyStrengthCalcService $journeyStrengthCalcService,
        private AtbValService $atbValService
    ) {}

    /**
     * Get all segment options for a project.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $dateRange = $this->getDateRange($request);

        return response()->json([
            'general' => [
                'acquisition' => $this->acquisitionService->getSegmentOptions($project),
                'technology' => $this->technologyService->getSegmentOptions($project),
                'behavioral' => $this->behavioralService->getSegmentOptions($project),
                'sequence' => $this->sequenceService->getSegmentOptions($project),
                'rfm' => $this->rfmService->getSegmentOptions($project),
                'custom_parameter' => $this->customParameterService->getSegmentOptions($project),
            ],
            'attribution' => $this->getAttributionSegments($project, $request),
        ]);
    }

    /**
     * Get attribution segments (goal-specific).
     */
    protected function getAttributionSegments(Project $project, Request $request): array
    {
        $goalId = $request->query('goal_id');

        if (!$goalId) {
            return ['message' => 'Goal ID required for attribution segments'];
        }

        return [
            'high_assembly' => $this->highAssemblyService->getSegmentOptions($project, $goalId),
            'journey_strength' => $this->journeyStrengthService->getSegmentOptions($project, $goalId),
            'drop_off' => $this->dropOffService->getSegmentOptions($project, $goalId),
            'time_bound_drop_off' => $this->timeBoundDropOffService->getSegmentOptions($project, $goalId),
        ];
    }

    /**
     * Calculate general segments for a project.
     */
    public function calculateGeneral(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $segmentType = $request->input('segment_type');
        $dateRange = $this->getDateRange($request);

        try {
            $count = match($segmentType) {
                'acquisition' => $this->acquisitionService->calculateForProject($project, $dateRange),
                'technology' => $this->technologyService->calculateForProject($project, $dateRange),
                'behavioral' => $this->behavioralService->calculateForProject($project, $dateRange),
                'rfm' => $this->rfmService->calculateForProject(
                    $project,
                    $request->input('revenue_event_name'),
                    $dateRange
                ),
                default => throw new \InvalidArgumentException("Unknown segment type: {$segmentType}"),
            };

            return response()->json([
                'message' => 'Segments calculated successfully',
                'devices_segmented' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Segment calculation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate attribution segments for a project and goal.
     */
    public function calculateAttribution(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $goalId = $request->input('goal_id');
        $segmentType = $request->input('segment_type');

        if (!$goalId) {
            return response()->json(['error' => 'goal_id is required'], 422);
        }

        try {
            $count = match($segmentType) {
                'high_assembly' => $this->highAssemblyService->calculateForProject($project, $goalId),
                'journey_strength' => $this->journeyStrengthService->calculateForProject($project, $goalId),
                'drop_off' => $this->dropOffService->calculateForProject($project, $goalId),
                'time_bound_drop_off' => $this->timeBoundDropOffService->calculateForProject(
                    $project,
                    $goalId,
                    $request->input('min_wait_days'),
                    $request->input('retarget_deadline_days')
                ),
                default => throw new \InvalidArgumentException("Unknown segment type: {$segmentType}"),
            };

            return response()->json([
                'message' => 'Attribution segments calculated successfully',
                'devices_segmented' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Attribution segment calculation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get segment data for a specific device.
     */
    public function forDevice(Request $request, Project $project, string $deviceId): JsonResponse
    {
        $this->authorize('view', $project);

        $segments = \App\Models\UserSegment::where('project_id', $project->id)
            ->where('device_id', $deviceId)
            ->where('expires_at', '>', now())
            ->get();

        return response()->json([
            'device_id' => $deviceId,
            'segments' => $segments,
            'total' => $segments->count(),
        ]);
    }

    /**
     * Get aggregated segment statistics.
     */
    public function statistics(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $goalId = $request->query('goal_id');

        return response()->json([
            'general' => [
                'acquisition' => $this->acquisitionService->getAggregatedSegments($project),
                'technology' => $this->technologyService->getAggregatedSegments($project),
                'behavioral' => $this->behavioralService->getAggregatedSegments($project),
                'sequence' => $this->sequenceService->getAggregatedSegments($project),
                'rfm' => $this->rfmService->getAggregatedSegments($project),
            ],
            'attribution' => $goalId ? [
                'journey_strength' => $this->journeyStrengthService->getStrengthDistribution($project, $goalId),
                'time_bound_urgency' => $this->timeBoundDropOffService->getUrgencyDistribution($project, $goalId),
            ] : null,
        ]);
    }

    /**
     * Trigger assembly score calculation for a project.
     */
    public function calculateAssemblyScores(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $goalId = $request->input('goal_id');

        try {
            $count = $this->assemblyScoreService->calculateForProject($project->id, $goalId);

            return response()->json([
                'message' => 'Assembly scores calculated successfully',
                'journeys_processed' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Assembly score calculation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trigger journey strength calculation for a project.
     */
    public function calculateJourneyStrength(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $goalId = $request->input('goal_id');

        try {
            $count = $this->journeyStrengthCalcService->calculateForProject($project->id, $goalId);

            return response()->json([
                'message' => 'Journey strengths calculated successfully',
                'journeys_processed' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Journey strength calculation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trigger ATB-Val calculation for a project.
     */
    public function calculateAtbVals(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $goalId = $request->input('goal_id');

        try {
            $count = $this->atbValService->calculateForProject($project->id, $goalId);

            return response()->json([
                'message' => 'ATB values calculated successfully',
                'journeys_processed' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'ATB value calculation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse date range from request.
     */
    protected function getDateRange(Request $request): array
    {
        return [
            'start' => $request->input('start', now()->subDays(30)->toDateString()),
            'end' => $request->input('end', now()->toDateString()),
        ];
    }
}
