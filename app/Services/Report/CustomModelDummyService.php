<?php

namespace App\Services\Report;

use App\Models\ReportGenerator;
use Illuminate\Support\Facades\Log;

/**
 * Stand-in for the real third-party custom model service, used until that
 * integration exists. Simulates the "trigger accepted, now processing"
 * acknowledgement a real third party would send back immediately.
 *
 * response_object/status=completed still has to be written manually
 * (e.g. via tinker or a DB client) to simulate the third party finishing —
 * this only covers the "we told them to start" half of the flow.
 */
class CustomModelDummyService
{
    public function accept(ReportGenerator $generator): void
    {
        $generator->update(['status' => ReportGenerator::STATUS_PROCESSING]);

        Log::info('Custom model dummy accepted report generation request', [
            'report_generator_id' => $generator->id,
        ]);
    }
}
