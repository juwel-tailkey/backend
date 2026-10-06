<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ChartDateRange
{
    /**
     * @return array{start: Carbon, end: Carbon}|JsonResponse
     */
    public static function fromRequest(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid date range.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $start = Carbon::createFromFormat('Y-m-d', $request->query('start'))->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $request->query('end'))->startOfDay();

        if ($start->diffInDays($end) > 366) {
            return response()->json(['error' => 'Date range cannot exceed 366 days.'], 422);
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{start: Carbon, end: Carbon}
     *
     * @throws RuntimeException when the payload doesn't contain a valid date range.
     */
    public static function fromArray(array $payload): array
    {
        $validator = Validator::make($payload, [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException('Invalid date range: '.$validator->errors()->first());
        }

        $start = Carbon::createFromFormat('Y-m-d', $payload['start'])->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $payload['end'])->startOfDay();

        if ($start->diffInDays($end) > 366) {
            throw new RuntimeException('Date range cannot exceed 366 days.');
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }
}
