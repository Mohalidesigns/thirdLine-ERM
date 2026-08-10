<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Measure;
use App\Models\Period;
use App\Services\MeasureService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WP-07 TASK 2 — a measure over time, in the shape a reporting client wants.
 *
 * Paging /measure-values and pivoting it client-side is the alternative, and it
 * is how a client ends up plotting a trend with a period missing because it
 * stopped at page 2. A series is one question and gets one answer, with the
 * periods named so a gap is visible as a gap rather than as an absent row.
 */
class MeasureSeriesController extends Controller
{
    public function __construct(private MeasureService $measures) {}

    public function show(Request $request, Measure $measure): JsonResponse
    {
        abort_unless($measure->organization_id === TenantContext::organizationId(), 404, 'Not found.');

        $validated = $request->validate([
            'object_id' => 'required|integer',
            'scenario' => 'nullable|string|max:20',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'period_type' => 'nullable|string|max:20',
        ]);

        $periods = Period::query()
            ->when($validated['period_type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->where('start_date', '>=', $from))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->where('end_date', '<=', $to))
            ->orderBy('start_date')
            // Bounded: a client asking for "everything" over a daily calendar
            // would otherwise get years of rows in one response.
            ->limit(200)
            ->get();

        $scenario = $validated['scenario'] ?? 'actual';

        $values = $this->measures->series(
            $measure,
            (int) $validated['object_id'],
            $periods->pluck('id')->all(),
            $scenario,
            $measure->organization_id,
        );

        return response()->json([
            'data' => [
                'type' => 'measure-series',
                'id' => $measure->code,
                'attributes' => [
                    'measure' => $measure->code,
                    'name' => $measure->name,
                    'object_id' => (int) $validated['object_id'],
                    'scenario' => $scenario,
                    // Every requested period appears, with a null where nothing
                    // was recorded. A client that only receives the periods
                    // that have values cannot tell a gap from the end of the
                    // series.
                    'points' => $periods->map(fn (Period $period) => [
                        'period_id' => $period->id,
                        'period' => $period->code,
                        'start_date' => $period->start_date?->toDateString(),
                        'end_date' => $period->end_date?->toDateString(),
                        'value' => $values[$period->id] ?? null,
                    ])->values()->all(),
                ],
            ],
        ]);
    }
}
