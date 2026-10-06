<?php

namespace App\Http\Controllers;

use App\Contracts\ReportHandler;
use App\Jobs\TriggerCustomModelReportJob;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportGenerator;
use App\Models\User;
use App\Services\Project\ActiveProjectService;
use App\Services\Settings\AppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ReportGeneratorController extends Controller
{
    public function __construct(
        private readonly ActiveProjectService $activeProject,
        private readonly AppSettingsService $settings
    ) {
    }

    /**
     * List the active reports available to generate, so the frontend can
     * resolve a report_id from a human-readable title/slug.
     */
    public function catalog(): JsonResponse
    {
        $reports = Report::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'description']);

        return response()->json($reports);
    }

    /**
     * Generate a report. Branches on the system-wide `report_source` setting:
     * - bigquery: builds the report synchronously and returns it right away.
     * - custom_model: stores the request and hands it off to the third-party
     *   service, returning a report_generator_id the frontend polls for progress.
     */
    public function generate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'report_id' => ['required_without:slug', 'integer', 'exists:reports,id'],
            'slug' => ['required_without:report_id', 'string', 'exists:reports,slug'],
            'project_id' => ['required', 'integer'],
            'payload' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid request.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $payload = $data['payload'] ?? [];

        $report = isset($data['report_id'])
            ? Report::findOrFail($data['report_id'])
            : Report::where('slug', $data['slug'])->firstOrFail();

        if (! $report->is_active) {
            return response()->json(['error' => 'This report is not currently available.'], 422);
        }

        $user = $request->user();

        try {
            $project = $this->activeProject->findProjectForUser($user, (int) $data['project_id']);
        } catch (\Throwable) {
            return response()->json(['error' => 'Project not found.'], 404);
        }

        $requestHash = $this->hashPayload($payload);

        $reused = $this->findReusableGenerator($report, $project, $requestHash);
        if ($reused) {
            return response()->json([
                'source' => 'cached',
                'report_generator_id' => $reused->id,
            ] + $this->normalizeResponseObject($reused));
        }

        $source = $this->settings->reportSource();

        if ($source === AppSettingsService::REPORT_SOURCE_CUSTOM_MODEL) {
            return $this->generateViaCustomModel($report, $project, $user, $payload, $requestHash);
        }

        return $this->generateDirectlyFromBigQuery($report, $project, $user, $payload, $requestHash);
    }

    /**
     * Finds a still-fresh, completed generator for the exact same
     * report + project + payload, if the reuse window setting allows it.
     */
    private function findReusableGenerator(Report $report, Project $project, string $requestHash): ?ReportGenerator
    {
        $windowMinutes = $this->settings->reportReuseWindowMinutes();

        if ($windowMinutes <= 0) {
            return null;
        }

        return ReportGenerator::query()
            ->where('report_id', $report->id)
            ->where('project_id', $project->id)
            ->where('request_hash', $requestHash)
            ->where('status', ReportGenerator::STATUS_COMPLETED)
            ->whereNotNull('response_object')
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->latest('id')
            ->first();
    }

    /**
     * Order-independent hash of the request payload, used to recognize
     * "the same report request" for the reuse window regardless of how the
     * frontend serialized its keys.
     */
    private function hashPayload(array $payload): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            ksort($value);

            return array_map(fn ($item) => is_array($item) ? $normalize($item) : $item, $value);
        };

        return md5(json_encode($normalize($payload)));
    }

    /**
     * Poll the progress/result of a custom-model report generation.
     */
    public function status(Request $request, ReportGenerator $reportGenerator): JsonResponse
    {
        try {
            $this->activeProject->findProjectForUser($request->user(), $reportGenerator->project_id);
        } catch (\Throwable) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        return response()->json([
            'report_generator_id' => $reportGenerator->id,
            'status' => $reportGenerator->status,
            'progress_percentage' => $reportGenerator->progress_percentage,
            'response_object' => $this->normalizeResponseObject($reportGenerator),
        ]);
    }

    /**
     * The third-party service only knows how to write the report's raw rows,
     * not the full {title, goal, dateRange, rows} envelope the BigQuery path
     * returns. If response_object comes back as a bare list, wrap it with the
     * metadata we already have on hand so the frontend can render either
     * source through the same component.
     */
    private function normalizeResponseObject(ReportGenerator $reportGenerator): mixed
    {
        $responseObject = $reportGenerator->response_object;

        if (! is_array($responseObject) || ! array_is_list($responseObject)) {
            return $responseObject;
        }

        $project = $reportGenerator->project;
        $requestObject = $reportGenerator->request_object ?? [];

        return [
            'title' => $reportGenerator->report?->title,
            'goal' => [
                'path' => $project?->goal_path,
                'type' => $project?->goal_event_type,
            ],
            'dateRange' => [
                'start' => $requestObject['start'] ?? null,
                'end' => $requestObject['end'] ?? null,
            ],
            'rows' => $responseObject,
        ];
    }

    private function generateDirectlyFromBigQuery(
        Report $report,
        Project $project,
        User $user,
        array $payload,
        string $requestHash
    ): JsonResponse {
        $handlerClass = config("report_handlers.{$report->slug}");

        if (! $handlerClass) {
            return response()->json([
                'error' => 'This report does not support direct BigQuery generation yet.',
            ], 422);
        }

        /** @var ReportHandler $handler */
        $handler = app()->make($handlerClass);

        try {
            $result = $handler->handle($project, $payload);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        // Recorded so a later identical request can reuse it within the
        // report_reuse_window_minutes setting, regardless of report_source.
        ReportGenerator::create([
            'report_id' => $report->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'request_object' => $payload,
            'request_hash' => $requestHash,
            'status' => ReportGenerator::STATUS_COMPLETED,
            'progress_percentage' => 100,
            'response_object' => $result,
        ]);

        return response()->json($result + ['source' => 'bigquery']);
    }

    private function generateViaCustomModel(
        Report $report,
        Project $project,
        User $user,
        array $payload,
        string $requestHash
    ): JsonResponse {
        $generator = ReportGenerator::create([
            'report_id' => $report->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'request_object' => $payload,
            'request_hash' => $requestHash,
            'status' => ReportGenerator::STATUS_INIT,
            'progress_percentage' => 0,
            'response_object' => null,
        ]);

        TriggerCustomModelReportJob::dispatch($generator->id);

        return response()->json([
            'source' => 'custom_model',
            'report_generator_id' => $generator->id,
            'status' => $generator->status,
            'progress_percentage' => $generator->progress_percentage,
        ], 202);
    }
}
