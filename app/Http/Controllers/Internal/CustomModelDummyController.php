<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ReportGenerator;
use App\Services\Report\CustomModelDummyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Local stand-in for the real third-party custom model endpoint. Only
 * reachable outside production, and only used when a real HTTP round trip
 * is actually taken (e.g. a separate `queue:work` process) rather than the
 * default in-process CustomModelDummyService call.
 */
class CustomModelDummyController extends Controller
{
    public function accept(Request $request, CustomModelDummyService $dummy): JsonResponse
    {
        abort_unless(! app()->environment('production'), 404);

        $validator = Validator::make($request->all(), [
            'report_generator_id' => ['required', 'integer', 'exists:report_generators,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid request.'], 422);
        }

        $generator = ReportGenerator::findOrFail($request->input('report_generator_id'));
        $dummy->accept($generator);

        return response()->json(['accepted' => true]);
    }
}
