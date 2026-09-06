<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Concerns\PersistsConfiguredAttributes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Treatments\ApproveTreatmentPlanRequest;
use App\Http\Requests\Treatments\RejectTreatmentPlanRequest;
use App\Http\Requests\Treatments\StoreTreatmentCommentRequest;
use App\Http\Requests\Treatments\StoreTreatmentPlanRequest;
use App\Http\Requests\Treatments\UpdateTreatmentPlanRequest;
use App\Models\TreatmentPlan;
use App\Presenters\FormSchemaPresenter;
use App\Presenters\GridPresenter;
use App\Services\Treatments\TreatmentPlanService;
use App\Support\Authorization\GraphScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Treatment plans (migration Phase 3.5).
 *
 * Every figure this used to compute inline now comes from
 * TreatmentPlanService; every authorisation goes through TreatmentPlanPolicy,
 * which absorbed the `approve-treatment-plan` and `resubmit-treatment-plan`
 * closures; every write body is validated by a Form Request in
 * App\Http\Requests\Treatments.
 *
 * Node scoping is the policy's job now (withinReach, resolved through the
 * risk), so the EnforcesNodeScope trait and its six
 * abortUnlessNodeVisibleThrough() calls are gone — they and the six
 * hand-written organization_id comparisons said what `Gate::authorize` says
 * here, one line earlier.
 */
class TreatmentPlanController extends Controller
{
    // WP-05 TASK 2 — receives the fields a tenant added through the builder.
    // Without it, a configured field would render on the form, accept what was
    // typed, and discard it on submit.
    use PersistsConfiguredAttributes;

    /** The object type whose configured fields the create/edit forms render. */
    private const OBJECT_TYPE = 'TreatmentPlan';

    public function __construct(
        private readonly TreatmentPlanService $plans,
        private readonly FormSchemaPresenter $schemas,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Dashboard / review queue */
    /* ------------------------------------------------------------------ */

    public function dashboard()
    {
        Gate::authorize('viewAny', TreatmentPlan::class);

        return Inertia::render('Treatments/Dashboard', [
            'stats' => $this->plans->dashboard(),
            'budgetByStrategy' => $this->plans->budgetByStrategy(),
            'strategyMix' => $this->plans->strategyMix(),
            'statusMix' => $this->plans->statusMix(),
            'completionTrend' => $this->plans->completionTrend(),
            'activeTreatments' => $this->plans->activeTreatments()->map($this->row(...))->all(),
            'upcomingDeadlines' => $this->plans->upcomingDeadlines()->map($this->row(...))->all(),
            'recentActivity' => $this->plans->recentActivity(),
        ]);
    }

    public function review()
    {
        Gate::authorize('viewAny', TreatmentPlan::class);

        return Inertia::render('Treatments/Review', [
            'pendingPlans' => $this->plans->pendingReview()
                ->map(fn (TreatmentPlan $plan) => $this->row($plan) + [
                    'description' => $plan->description,
                    'budget' => (float) ($plan->cost_estimate_ngn ?? 0),
                    'canApprove' => Gate::allows('approve', $plan),
                    'canComment' => Gate::allows('comment', $plan),
                ])
                ->all(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  List */
    /* ------------------------------------------------------------------ */

    public function index(Request $request, GridPresenter $presenter)
    {
        // WP-09: filtering, search, sorting, pagination, bulk delete and
        // export live in the shared data grid
        // (App\Grids\Definitions\TreatmentPlansGrid); the header only needs
        // the total. WP-00: scoped through the risk, matching the grid, so the
        // header total counts the rows the grid beneath it will show.
        $total = GraphScope::applyThrough(
            TreatmentPlan::where('organization_id', TenantContext::organizationId()),
            'risk',
        )->count();

        return Inertia::render('Treatments/Index', [
            'total' => $total,
            'lastUpdated' => now()->format('M d, Y'),
            'grid' => fn () => $presenter->present(GridRegistry::resolve('treatments'), $request, $request->user()),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create / store */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        Gate::authorize('create', TreatmentPlan::class);

        return Inertia::render('Treatments/Create', [
            'schema' => $this->schemas->form(
                self::OBJECT_TYPE,
                sections: ['Details', 'Plan', 'Expected Outcome'],
                defaults: ['risk_id' => $request->query('risk_id')],
            ),
            // The AI Treatment Plan Builder posts to a route behind
            // `permission:ai.use`. The Blade page drew the card
            // unconditionally, so a tenant without the permission got a button
            // that 403'd.
            'canDraftWithAi' => $request->user()?->can('ai.use') ?? false,
        ]);
    }

    public function store(StoreTreatmentPlanRequest $request)
    {
        $plan = $this->plans->create($request->validated(), $request->user()?->id);

        $this->saveConfiguredAttributes($request, $plan);

        return redirect()->route('risk.treatments.show', $plan)
            ->with('success', "Treatment plan {$plan->treatment_code} has been created.");
    }

    /* ------------------------------------------------------------------ */
    /*  Show / edit / update / destroy */
    /* ------------------------------------------------------------------ */

    public function show(TreatmentPlan $treatment)
    {
        Gate::authorize('view', $treatment);

        $treatment->load(['risk.category', 'risk.riskOwner', 'owner']);

        return Inertia::render('Treatments/Show', [
            'plan' => $this->detail($treatment),
            'can' => [
                'update' => Gate::allows('update', $treatment),
                'approve' => Gate::allows('approve', $treatment),
                'resubmit' => Gate::allows('resubmit', $treatment),
                'submit' => Gate::allows('submit', $treatment),
            ],
        ]);
    }

    public function edit(TreatmentPlan $treatment)
    {
        Gate::authorize('update', $treatment);

        return Inertia::render('Treatments/Edit', [
            'plan' => [
                'id' => $treatment->id,
                'title' => $treatment->title,
                'milestones' => $this->milestones($treatment),
            ],
            // risk_id is omitted: update() does not accept it, so offering it
            // would be an editable field that never saves.
            'schema' => $this->schemas->form(self::OBJECT_TYPE, $treatment, omit: ['risk_id']),
        ]);
    }

    public function update(UpdateTreatmentPlanRequest $request, TreatmentPlan $treatment)
    {
        $this->plans->update($treatment, $request->validated(), $request->user()?->id);

        $this->saveConfiguredAttributes($request, $treatment);

        return redirect()->route('risk.treatments.show', $treatment)
            ->with('success', "Treatment plan {$treatment->treatment_code} has been updated.");
    }

    public function destroy(TreatmentPlan $treatment)
    {
        Gate::authorize('delete', $treatment);

        $code = $treatment->treatment_code;

        $this->plans->destroy($treatment);

        return redirect()->route('risk.treatments.index')
            ->with('success', "Treatment plan {$code} has been deleted.");
    }

    /* ------------------------------------------------------------------ */
    /*  Approval lifecycle */
    /* ------------------------------------------------------------------ */

    /**
     * The lifecycle guards below answer a wrong status with a flash message
     * rather than a 403, exactly as they did: the policy decides WHO may act,
     * the status decides WHETHER there is anything to act on, and conflating
     * them would turn "this plan is not pending review" into "you may not
     * review plans".
     */
    public function approve(ApproveTreatmentPlanRequest $request, TreatmentPlan $treatment)
    {
        if ($treatment->status !== 'pending_review') {
            return back()->with('error', 'Only plans pending review can be approved.');
        }

        $final = $this->plans->approve($treatment, $request->user(), $request->validated('comments'));

        return back()->with('success', $final
            ? 'Treatment plan approved.'
            : 'Recorded. The plan has moved to the next approval step.');
    }

    public function reject(RejectTreatmentPlanRequest $request, TreatmentPlan $treatment)
    {
        if ($treatment->status !== 'pending_review') {
            return back()->with('error', 'Only plans pending review can be rejected.');
        }

        $this->plans->reject($treatment, $request->user(), $request->validated('rejection_reason'));

        return back()->with('success', 'Treatment plan rejected. The plan owner has been notified.');
    }

    public function submitForReview(Request $request, TreatmentPlan $treatment)
    {
        Gate::authorize('submit', $treatment);

        if (! in_array($treatment->status, ['draft', 'rejected', 'not_started'], true)) {
            return back()->with('error', 'This plan is not in a state that can be submitted for review.');
        }

        $this->plans->submitForReview($treatment, $request->user());

        return back()->with('success', 'Plan submitted for review.');
    }

    public function resubmit(TreatmentPlan $treatment)
    {
        Gate::authorize('resubmit', $treatment);

        if ($treatment->status !== 'rejected') {
            return back()->with('error', 'Only rejected plans can be resubmitted.');
        }

        $this->plans->returnToDraft($treatment);

        return back()->with('success', 'Plan returned to draft. Edit it and submit again for review.');
    }

    public function comment(StoreTreatmentCommentRequest $request, TreatmentPlan $treatment)
    {
        $this->plans->comment($treatment, $request->validated('comment'));

        return back()->with('success', 'Comment added.');
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation */
    /* ------------------------------------------------------------------ */

    /**
     * One plan as a table row.
     *
     * @return array<string, mixed>
     */
    private function row(TreatmentPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->treatment_code,
            'title' => $plan->title,
            'strategy' => $plan->strategy,
            'priority' => $plan->priority,
            'status' => $plan->status,
            'progress' => $plan->progress,
            'owner' => $plan->owner?->name,
            'riskCode' => $plan->risk?->risk_code,
            'targetDate' => $plan->target_date?->format('d M Y'),
            'url' => route('risk.treatments.show', $plan),
        ];
    }

    /**
     * The show page's plan.
     *
     * `daysRemaining` is a signed whole number of days, negative when the
     * target has passed — the page words it. Carbon 3's diffInDays returns a
     * signed float, so it is floored here rather than in three places there.
     *
     * @return array<string, mixed>
     */
    private function detail(TreatmentPlan $plan): array
    {
        $target = $plan->target_date;

        return $this->row($plan) + [
            'description' => $plan->description,
            'progressNotes' => $plan->progress_notes,
            'budget' => (float) ($plan->cost_estimate_ngn ?? 0),
            'actualSpend' => (float) ($plan->actual_cost_ngn ?? 0),
            'daysRemaining' => $target
                ? (int) floor(now()->startOfDay()->diffInDays($target->copy()->startOfDay(), false))
                : null,
            'completionDate' => $plan->completion_date?->format('d M Y'),
            'createdAt' => $plan->created_at?->format('M d, Y'),
            'rejectionReason' => $plan->rejection_reason,
            'dependencies' => $plan->dependencies,
            'successCriteria' => $plan->success_criteria,
            'milestones' => $this->milestones($plan),
            'residual' => $plan->expected_residual_likelihood && $plan->expected_residual_impact
                ? $this->residualRating((int) $plan->expected_residual_likelihood * (int) $plan->expected_residual_impact)
                : null,
            'risk' => $plan->risk ? [
                'code' => $plan->risk->risk_code,
                'title' => $plan->risk->title,
                'rating' => $plan->risk->residual_rating,
                'status' => $plan->risk->status,
                'url' => route('risk.register.show', $plan->risk),
            ] : null,
        ];
    }

    /**
     * Milestones are stored as a JSON string and were read back three
     * different ways across the two views. One way here.
     *
     * @return list<array<string, mixed>>
     */
    private function milestones(TreatmentPlan $plan): array
    {
        $milestones = $plan->milestones;

        if (is_string($milestones)) {
            $milestones = json_decode($milestones, true) ?: [];
        }

        if (! is_array($milestones)) {
            return [];
        }

        return array_values(array_map(fn ($milestone) => is_array($milestone) ? [
            'title' => $milestone['title'] ?? $milestone['name'] ?? '',
            'due_date' => $milestone['due_date'] ?? null,
            'responsible' => $milestone['responsible'] ?? null,
            'completed' => (bool) ($milestone['completed'] ?? false),
        ] : [
            'title' => (string) $milestone,
            'due_date' => null,
            'responsible' => null,
            'completed' => false,
        ], $milestones));
    }

    /** The 5x5 bands the show sidebar labelled the expected residual with. */
    private function residualRating(int $score): string
    {
        return match (true) {
            $score >= 20 => 'critical',
            $score >= 12 => 'high',
            $score >= 5 => 'medium',
            default => 'low',
        };
    }
}
