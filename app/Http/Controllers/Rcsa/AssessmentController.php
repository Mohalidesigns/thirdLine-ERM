<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rcsa\StoreActionPlanRequest;
use App\Http\Requests\Rcsa\UpdateAssessmentLineRequest;
use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\Rcsa\RcsaScaleItem;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaSubmissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The assessment workspace — steps 4 to 7 of the process flow.
 *
 * ONE DATA SET, TWO MODES. Grid and guided are both this controller and both
 * these endpoints; the mode is a client-side choice about how to draw the same
 * lines, which is what lets a user switch without losing anything.
 *
 * THE WHOLE ASSESSMENT IS SENT, NOT A PAGE OF IT. A 500-line grid with
 * keyboard navigation cannot paginate — an assessor pressing Down at row 25
 * must not trigger a page load — and the progress panel counts every line
 * regardless. Five hundred lines of this shape is a few hundred kilobytes of
 * JSON, which is the right trade for a screen whose entire purpose is to be
 * fast to move around in. If a tenant ever runs an assessment large enough to
 * hurt, the answer is virtualised rows on the client, not pagination.
 */
class AssessmentController extends Controller
{
    public function __construct(
        private readonly RcsaAssessmentService $assessments,
        private readonly RcsaSubmissionService $submissions,
    ) {}

    /**
     * The assessments this user can work on — the picker for steps 1 and 2.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', RcsaAssessment::class);

        $assessments = RcsaAssessment::query()
            ->with(['cycle:id,name,status,due_date', 'businessUnit:id,name'])
            ->withCount('lines')
            ->when($request->filled('cycle'), fn ($q) => $q->where('cycle_id', $request->input('cycle')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            // Open cycles first: an assessor's own work is almost always in one.
            ->orderByRaw("case when status in ('draft','in_progress','returned') then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $assessments->through(fn (RcsaAssessment $assessment) => [
            'id' => $assessment->id,
            'cycle' => $assessment->getRelationValue('cycle')?->name,
            'cycle_status' => $assessment->getRelationValue('cycle')?->status,
            'due_date' => $assessment->getRelationValue('cycle')?->due_date?->toDateString(),
            'business_unit' => $assessment->getRelationValue('businessUnit')?->name,
            'status' => $assessment->status,
            'completion_pct' => $assessment->completion_pct,
            'lines_count' => $assessment->lines_count,
        ]);

        return Inertia::render('RcsaAssessments/Index', [
            'assessments' => $assessments,
            'filters' => $request->only(['cycle', 'status']),
        ]);
    }

    /**
     * The workspace itself.
     */
    public function show(Request $request, RcsaAssessment $assessment)
    {
        Gate::authorize('view', $assessment);

        $assessment->load(['cycle', 'businessUnit:id,name,code']);

        $methodology = RcsaMethodology::withoutGlobalScopes()
            ->with(['scaleItems', 'bands', 'impactCriteria'])
            ->findOrFail($assessment->cycle->methodology_id);

        $lines = $assessment->lines()
            ->with(['priorLine:id,inherent_score,inherent_level,residual_score,residual_level,control_effectiveness', 'actionPlans.owner:id,name'])
            ->get();

        $outstanding = $this->submissions->blockers($assessment, $request->user()->id);

        return Inertia::render('RcsaAssessments/Workspace', [
            'assessment' => [
                'id' => $assessment->id,
                'status' => $assessment->status,
                'completion_pct' => $assessment->completion_pct,
                'business_unit' => $assessment->getRelationValue('businessUnit')?->name,
                'editable' => $assessment->acceptsEdits(),
                'cycle' => [
                    'id' => $assessment->cycle->id,
                    'name' => $assessment->cycle->name,
                    'status' => $assessment->cycle->status,
                    'due_date' => $assessment->cycle->due_date?->toDateString(),
                ],
            ],
            'lines' => $lines->map(fn (RcsaAssessmentLine $line) => $this->toRow($line))->all(),
            // The methodology travels with the page so the client mirror can
            // compute a badge in the same frame — see resources/js/lib/rcsa-calc.js.
            'methodology' => $methodology->toCalculatorPayload(),
            'impactCriteria' => $this->impactCriteria($methodology),
            'controlGuidance' => array_values(array_map(
                fn (RcsaScaleItem $item) => [
                    'label' => $item->label,
                    'band' => $item->percent_band,
                    'description' => $item->description,
                ],
                $methodology->scale(RcsaScaleItem::TYPE_CONTROL_EFFECTIVENESS)
            )),
            'outstanding' => $outstanding,
            'owners' => \App\Models\User::query()
                ->where('organization_id', $assessment->organization_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'can' => [
                'complete' => $request->user()->can('complete', $assessment),
                'submit' => $request->user()->can('submit', $assessment),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Action plans — workbook columns U, V and W (§8.4) */
    /* ------------------------------------------------------------------ */

    public function storePlan(StoreActionPlanRequest $request, RcsaAssessment $assessment, RcsaAssessmentLine $line)
    {
        abort_unless($line->assessment_id === $assessment->id, 404);

        $line->actionPlans()->create($request->validated() + [
            'organization_id' => $assessment->organization_id,
            'status' => RcsaActionPlan::OPEN,
        ]);

        return back()->with('success', 'Action plan added.');
    }

    public function updatePlan(StoreActionPlanRequest $request, RcsaAssessment $assessment, RcsaAssessmentLine $line, RcsaActionPlan $plan)
    {
        abort_unless($line->assessment_id === $assessment->id && $plan->line_id === $line->id, 404);

        $plan->update($request->validated());

        return back()->with('success', 'Action plan updated.');
    }

    public function destroyPlan(Request $request, RcsaAssessment $assessment, RcsaAssessmentLine $line, RcsaActionPlan $plan)
    {
        Gate::authorize('complete', $assessment);
        abort_unless($line->assessment_id === $assessment->id && $plan->line_id === $line->id, 404);

        $plan->delete();

        return back()->with('success', 'Action plan removed.');
    }

    /* ------------------------------------------------------------------ */
    /*  Submission — step 8 (§8.4) */
    /* ------------------------------------------------------------------ */

    public function submit(Request $request, RcsaAssessment $assessment)
    {
        Gate::authorize('submit', $assessment);

        try {
            $result = $this->submissions->submit($assessment, $request->user());
        } catch (\RuntimeException $e) {
            // The blockers are already on the page and each carries a jump
            // link, so the flash says how many rather than repeating them into
            // a toast nobody can act on.
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('rcsa.cycles.show', $assessment->cycle_id)
            ->with('success', sprintf(
                'Submitted for ORM review. %d risks are locked, and %d %s been notified.',
                $result['lines'],
                $result['notified'],
                $result['notified'] === 1 ? 'person has' : 'people have',
            ));
    }

    /**
     * Per-cell autosave.
     *
     * Answers 409 on a version mismatch and sends the CURRENT line back, so the
     * grid can show the user what the other person put there rather than only
     * telling them they lost.
     */
    public function updateLine(UpdateAssessmentLineRequest $request, RcsaAssessment $assessment, RcsaAssessmentLine $line)
    {
        abort_unless($line->assessment_id === $assessment->id, 404);

        // The advisory lock: someone else has this row open in guided mode.
        // Distinct from the version check, which catches a genuine collision;
        // this one prevents most of them from happening at all.
        if ($line->isHeldByAnother($request->user()->id)) {
            return response()->json([
                'message' => 'Somebody else is editing this risk right now.',
                'line' => $this->toRow($line),
            ], 423);
        }

        $result = $this->assessments->apply(
            line: $line,
            input: $request->validated(),
            actor: $request->user(),
            expectedVersion: $request->validated('version'),
            request: $request,
        );

        if ($result['status'] === RcsaAssessmentService::CONFLICT) {
            return response()->json([
                'message' => 'Somebody else changed this risk while you were working on it. Their answer is shown; yours was not saved.',
                'line' => $this->toRow($result['line']),
            ], 409);
        }

        return response()->json([
            'line' => $this->toRow($result['line']),
            'completion_pct' => $assessment->fresh()->completion_pct,
        ]);
    }

    /**
     * Apply one answer to several lines (§8.3).
     */
    public function bulkApply(Request $request, RcsaAssessment $assessment)
    {
        Gate::authorize('complete', $assessment);

        $validated = $request->validate([
            'line_ids' => ['required', 'array', 'min:1', 'max:500'],
            'line_ids.*' => ['integer'],
            'control_effectiveness' => ['nullable', 'string'],
            'inherent_likelihood' => ['nullable', 'integer', 'min:1', 'max:10'],
            'inherent_impact' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $answers = array_filter(
            array_intersect_key($validated, array_flip(['control_effectiveness', 'inherent_likelihood', 'inherent_impact'])),
            fn ($value) => $value !== null,
        );

        if ($answers === []) {
            return back()->with('error', 'Choose an answer to apply.');
        }

        // Scoped to THIS assessment: a line id from another assessment simply
        // does not come back, so a crafted request cannot reach across.
        $lines = $assessment->lines()->whereIn('id', $validated['line_ids'])->get();

        $changed = $this->assessments->applyToMany($lines, $answers, $request->user(), $request);

        return back()->with('success', "{$changed} risks updated.");
    }

    /**
     * Take or release the advisory lock on a line (guided mode).
     */
    public function lock(Request $request, RcsaAssessment $assessment, RcsaAssessmentLine $line)
    {
        Gate::authorize('complete', $assessment);
        abort_unless($line->assessment_id === $assessment->id, 404);

        if ($request->boolean('release')) {
            $line->forceFill(['locked_by' => null, 'lock_expires_at' => null])->save();

            return response()->json(['held' => false]);
        }

        if ($line->isHeldByAnother($request->user()->id)) {
            return response()->json(['held' => true, 'by_other' => true], 423);
        }

        $line->forceFill([
            'locked_by' => $request->user()->id,
            'lock_expires_at' => now()->addMinutes(RcsaAssessmentLine::LOCK_MINUTES),
        ])->save();

        return response()->json(['held' => true, 'by_other' => false]);
    }

    /**
     * The validation panel, refreshed on demand.
     */
    public function outstanding(Request $request, RcsaAssessment $assessment)
    {
        Gate::authorize('view', $assessment);

        return response()->json($this->assessments->outstanding($assessment));
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation */
    /* ------------------------------------------------------------------ */

    /**
     * One row of the grid.
     *
     * Every field is read off a column that exists — checked against the
     * migration, not assumed. The prior-cycle block is nested rather than
     * flattened so the delta panel can tell "no previous cycle" from "previous
     * cycle, same answer".
     *
     * @return array<string, mixed>
     */
    private function toRow(RcsaAssessmentLine $line): array
    {
        $prior = $line->getRelationValue('priorLine');

        return [
            'id' => $line->id,
            'version' => $line->version,
            'sort_order' => $line->sort_order,

            /* --- Snapshotted master data --------------------------------- */
            'risk_no' => $line->risk_no,
            'business_unit_name' => $line->business_unit_name,
            'process_name' => $line->process_name,
            'sub_process_name' => $line->sub_process_name,
            'system_names' => $line->system_names ?? [],
            'potential_risk' => $line->potential_risk,
            'risk_driver' => $line->risk_driver,
            'risk_category' => $line->risk_category,
            'secondary_categories' => $line->secondary_categories ?? [],
            'existing_control' => $line->existing_control,

            /* --- Assessed ------------------------------------------------- */
            'inherent_likelihood' => $line->inherent_likelihood,
            'inherent_impact' => $line->inherent_impact,
            'control_effectiveness' => $line->control_effectiveness,
            'residual_likelihood' => $line->residual_likelihood,
            'residual_impact' => $line->residual_impact,

            /* --- Calculated (read-only on the client) --------------------- */
            'inherent_score' => $line->inherent_score,
            'inherent_level' => $line->inherent_level,
            'ce_modifier' => $line->ce_modifier,
            'residual_score' => $line->residual_score,
            'residual_level' => $line->residual_level,
            'risk_treatment' => $line->risk_treatment,
            'appetite_status' => $line->appetite_status,

            /* --- The assessor's own additions ----------------------------- */
            'treatment_override' => $line->treatment_override,
            'treatment_override_reason' => $line->treatment_override_reason,
            'assessment_rationale' => $line->assessment_rationale,
            'assessed_at' => $line->assessed_at?->toDateTimeString(),
            'is_scored' => $line->isScored(),
            'action_plans_count' => $line->relationLoaded('actionPlans') ? $line->actionPlans->count() : 0,
            'action_plans' => $line->relationLoaded('actionPlans')
                ? $line->actionPlans->map(fn (RcsaActionPlan $plan) => [
                    'id' => $plan->id,
                    'control_to_implement' => $plan->control_to_implement,
                    'owner_id' => $plan->owner_id,
                    'owner' => $plan->getRelationValue('owner')?->name,
                    'target_date' => $plan->target_date?->toDateString(),
                    'status' => $plan->status,
                    'is_overdue' => $plan->target_date !== null
                        && $plan->target_date->isPast()
                        && ! in_array($plan->status, [RcsaActionPlan::COMPLETED, RcsaActionPlan::CLOSED], true),
                ])->all()
                : [],

            /* --- Last cycle (§8.3) ---------------------------------------- */
            'prior' => $prior === null ? null : [
                'inherent_score' => $prior->inherent_score,
                'inherent_level' => $prior->inherent_level,
                'residual_score' => $prior->residual_score,
                'residual_level' => $prior->residual_level,
                'control_effectiveness' => $prior->control_effectiveness,
            ],
            'moved_materially' => $this->assessments->movedMaterially($line),
        ];
    }

    /**
     * The impact criteria as a level => dimension => descriptor map, for the
     * impact selector's guidance (§3.2).
     *
     * @return array<int, array<string, string>>
     */
    private function impactCriteria(RcsaMethodology $methodology): array
    {
        $criteria = [];

        foreach ($methodology->impactCriteria as $row) {
            $criteria[(int) $row->impact_value][$row->dimension] = $row->descriptor;
        }

        return $criteria;
    }
}
