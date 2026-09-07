<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rcsa\OpenCycleRequest;
use App\Http\Requests\Rcsa\StoreCycleRequest;
use App\Models\BusinessUnit;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Services\Rcsa\RcsaCycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use RuntimeException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * RCSA cycles — step 1 of the process flow, and the completion tracker.
 */
class CycleController extends Controller
{
    public function __construct(private readonly RcsaCycleService $cycles) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', RcsaCycle::class);

        $cycles = RcsaCycle::query()
            ->withCount('assessments')
            ->with('methodology:id,name,version')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $cycles->through(fn (RcsaCycle $cycle) => [
            'id' => $cycle->id,
            'name' => $cycle->name,
            'description' => $cycle->description,
            'period_start' => $cycle->period_start?->toDateString(),
            'period_end' => $cycle->period_end?->toDateString(),
            'due_date' => $cycle->due_date?->toDateString(),
            'status' => $cycle->status,
            'assessments_count' => $cycle->assessments_count,
            'methodology' => $cycle->getRelationValue('methodology')?->name,
            'opened_at' => $cycle->opened_at?->toDateString(),
            'closed_at' => $cycle->closed_at?->toDateString(),
        ]);

        return Inertia::render('RcsaCycles/Index', [
            'cycles' => $cycles,
            'filters' => $request->only('status'),
            'options' => [
                'statuses' => RcsaCycle::STATUSES,
                'methodologies' => RcsaMethodology::query()
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get(['id', 'name', 'version'])
                    ->map(fn (RcsaMethodology $m) => [
                        'id' => $m->id,
                        'label' => $m->name.' v'.$m->version,
                    ])
                    ->all(),
                // Shown on the create form so a coordinator knows whether the
                // universe is ready before they schedule anything.
                'publishedRisks' => RcsaRegisterRisk::query()->assessable()->count(),
            ],
            'can' => [
                'manage' => $request->user()->can('rcsa_cycle.manage'),
                'open' => $request->user()->can('rcsa_cycle.open'),
                'close' => $request->user()->can('rcsa_cycle.close'),
            ],
        ]);
    }

    /**
     * One cycle: its assessments, their progress, and who is behind.
     */
    public function show(Request $request, RcsaCycle $cycle)
    {
        Gate::authorize('view', $cycle);

        $assessments = $cycle->assessments()
            ->with(['businessUnit:id,name,code', 'assignee:id,name'])
            ->withCount('lines')
            ->get()
            ->map(fn (RcsaAssessment $assessment) => [
                'id' => $assessment->id,
                'business_unit' => $assessment->getRelationValue('businessUnit')?->name,
                'status' => $assessment->status,
                'completion_pct' => $assessment->completion_pct,
                'lines_count' => $assessment->lines_count,
                'assignee' => $assessment->getRelationValue('assignee')?->name,
                'submitted_at' => $assessment->submitted_at?->toDateString(),
            ])
            ->values()
            ->all();

        return Inertia::render('RcsaCycles/Show', [
            'cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'description' => $cycle->description,
                'period_start' => $cycle->period_start?->toDateString(),
                'period_end' => $cycle->period_end?->toDateString(),
                'due_date' => $cycle->due_date?->toDateString(),
                'status' => $cycle->status,
                'opened_at' => $cycle->opened_at?->toDateString(),
                'methodology' => $cycle->getRelationValue('methodology')?->name,
            ],
            'assessments' => $assessments,
            'can' => [
                'open' => $request->user()->can('open', $cycle),
                'close' => $request->user()->can('close', $cycle),
            ],
        ]);
    }

    public function store(StoreCycleRequest $request)
    {
        $cycle = RcsaCycle::create($request->validated() + [
            'organization_id' => TenantContext::organizationId(),
            'status' => RcsaCycle::DRAFT,
        ]);

        return redirect()
            ->route('rcsa.cycles.show', $cycle)
            ->with('success', "\"{$cycle->name}\" created as a draft. Opening it copies the published universe into an assessment for every business unit.");
    }

    public function update(StoreCycleRequest $request, RcsaCycle $cycle)
    {
        // An open cycle's period and methodology are load-bearing: assessments
        // already reference the methodology and were provisioned against the
        // period. Changing them underneath would silently re-date work already
        // done.
        if ($cycle->status !== RcsaCycle::DRAFT) {
            return back()->with('error', 'Only a draft cycle can be edited. This one has assessments behind it.');
        }

        $cycle->update($request->validated());

        return back()->with('success', 'Cycle updated.');
    }

    public function open(OpenCycleRequest $request, RcsaCycle $cycle)
    {
        try {
            $result = $this->cycles->open(
                $cycle,
                $request->user(),
                $request->validated('business_unit_ids') ?: null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('rcsa.cycles.show', $cycle)
            ->with('success', sprintf(
                '%s is open: %d assessments provisioned with %d risks in total.',
                $cycle->name,
                $result['assessments'],
                $result['lines'],
            ));
    }

    public function close(Request $request, RcsaCycle $cycle)
    {
        Gate::authorize('close', $cycle);

        try {
            $this->cycles->close($cycle, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$cycle->name} is closed. Its assessments are now read-only.");
    }

    public function destroy(Request $request, RcsaCycle $cycle)
    {
        Gate::authorize('delete', $cycle);

        if ($cycle->status !== RcsaCycle::DRAFT) {
            return back()->with('error', 'A cycle that has been opened is not deleted — close it instead, so its assessments survive.');
        }

        $cycle->delete();

        return redirect()->route('rcsa.cycles.index')->with('success', 'Draft cycle deleted.');
    }

    /**
     * The units a cycle would provision, for the open confirmation.
     */
    public function scope(Request $request, RcsaCycle $cycle)
    {
        Gate::authorize('view', $cycle);

        $counts = RcsaRegisterRisk::query()
            ->assessable()
            ->selectRaw('business_unit_id, count(*) as risks')
            ->groupBy('business_unit_id')
            ->pluck('risks', 'business_unit_id');

        $units = BusinessUnit::query()
            ->whereIn('id', $counts->keys())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (BusinessUnit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'risks' => (int) $counts[$unit->id],
            ])
            ->all();

        return response()->json(['units' => $units]);
    }
}
