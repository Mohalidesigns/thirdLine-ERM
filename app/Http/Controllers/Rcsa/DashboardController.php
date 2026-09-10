<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaCycle;
use App\Services\Rcsa\RcsaDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The v1 reporting surface of §10.3 — seven views over one cycle.
 *
 * ONE CYCLE AT A TIME, chosen at the top. A dashboard that summed every cycle
 * the bank has ever run would report a risk five times and call it five risks,
 * which is the fastest way to make a Board pack wrong.
 *
 * EVERY PANEL DRILLS THROUGH. §10.3 asks for it on the heat map specifically,
 * and the same argument holds everywhere: a number on a dashboard that cannot
 * be opened is a number nobody can check, and the first question anyone asks of
 * "14 above appetite" is "which fourteen".
 */
class DashboardController extends Controller
{
    public function __construct(private readonly RcsaDashboardService $dashboard) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', RcsaAssessment::class);

        $cycle = $request->filled('cycle')
            ? RcsaCycle::query()->find($request->integer('cycle'))
            : $this->dashboard->defaultCycle();

        $cycleId = $cycle?->id;

        // §11. Every panel below answers for THIS user's business units; the
        // service refuses to be used without being told whose dashboard it is.
        $this->dashboard->for($request->user());

        $basis = $request->input('basis') === 'residual' ? 'residual' : 'inherent';

        // The drill-through of §10.3, answered in place. Only queried when a
        // cell has actually been clicked — a dashboard that loaded the register
        // behind all twenty-five cells on every render would be the slowest
        // screen in the product.
        $drill = $request->filled('likelihood') && $request->filled('impact')
            ? [
                'likelihood' => $request->integer('likelihood'),
                'impact' => $request->integer('impact'),
                'lines' => $this->dashboard->drillThrough(
                    $cycleId,
                    $basis,
                    $request->integer('likelihood'),
                    $request->integer('impact'),
                ),
            ]
            : null;

        return Inertia::render('RcsaDashboard/Index', [
            'cycle' => $cycle === null ? null : [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status,
                'period_start' => $cycle->period_start?->toDateString(),
                'period_end' => $cycle->period_end?->toDateString(),
                'due_date' => $cycle->due_date?->toDateString(),
            ],
            'cycles' => RcsaCycle::query()
                ->orderByDesc('period_start')
                ->get(['id', 'name', 'status'])
                ->all(),
            'basis' => $basis,
            'headline' => $this->dashboard->headline($cycleId),
            'heatMap' => $this->dashboard->heatMap($cycleId, $basis),
            'drill' => $drill,
            'topRisks' => $this->dashboard->topResidualRisks($cycleId),
            'aboveAppetite' => $this->dashboard->aboveAppetiteByUnit($cycleId),
            'controlEffectiveness' => $this->dashboard->controlEffectiveness($cycleId),
            'completion' => $this->dashboard->completionTracker($cycleId),
            'actionPlans' => $this->dashboard->actionPlans(),
            'movement' => $this->dashboard->movement($cycleId),
            // Why the screen is empty, when it is. An unassigned user seeing a
            // blank dashboard cannot tell it from a bank with no risks.
            'scopeNotice' => app(\App\Support\Rcsa\RcsaScope::class)->describe($request->user()),
            'can' => [
                'export' => $request->user()->can('rcsa_export.bulk'),
                'plans' => $request->user()->can('rcsa_actionplan.view'),
            ],
        ]);
    }
}
