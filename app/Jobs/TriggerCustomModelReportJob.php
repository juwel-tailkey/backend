<?php

namespace App\Jobs;

use App\Models\ReportGenerator;
use App\Services\Report\CustomModelDummyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Notifies the third-party custom model service that a report is ready to be
 * generated. The service processes the report asynchronously on its own side
 * and writes the result directly into `report_generators` (response_object,
 * status = completed) when it's done — this job only has to deliver the
 * "start" signal, not wait for the report itself.
 */
class TriggerCustomModelReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(private readonly int $reportGeneratorId)
    {
    }

    public function handle(): void
    {
        $generator = ReportGenerator::find($this->reportGeneratorId);

        if (! $generator) {
            return;
        }

        if (config('services.custom_model_report.use_local_dummy')) {
            app(CustomModelDummyService::class)->accept($generator);

            return;
        }

        $endpointUrl = config('services.custom_model_report.endpoint_url');

        try {
            $response = Http::timeout(10)->post($endpointUrl, [
                'report_generator_id' => $generator->id,
                'report_id' => $generator->report_id,
                'project_id' => $generator->project_id,
                'request_object' => $generator->request_object,
            ]);

            $generator->update([
                'status' => $response->successful()
                    ? ReportGenerator::STATUS_PROCESSING
                    : ReportGenerator::STATUS_FAILED,
            ]);

            if (! $response->successful()) {
                Log::error('Custom model report trigger returned an error response', [
                    'report_generator_id' => $generator->id,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $exception) {
            $generator->update(['status' => ReportGenerator::STATUS_FAILED]);

            Log::error('Custom model report trigger failed', [
                'report_generator_id' => $generator->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
