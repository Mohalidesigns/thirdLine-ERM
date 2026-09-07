<?php

namespace App\Http\Controllers\Rcsa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rcsa\UpdateActionPlanProgressRequest;
use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaCycle;
use App\Services\Rcsa\RcsaActionPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * §9.3 — the action-plan tracking register, and the owner dashboard inside it.
 *
 * ONE SCREEN, TWO AUDIENCES. "Mine" is the same register with an owner filter;
 * building a separate owner dashboard would mean two queries, two sets of
 * filters and two places for "overdue" to be decided slightly differently. The
 * tiles above the table are the owner's when the mine filter is on and the
 * whole bank's when it is not.
 *
 * THE REGISTER OUTLIVES THE CYCLE. Nothing here filters to an open cycle: a
 * plan raised in 2026 H1 and due in October is still the ORM's business in
 * 2026 H2, and a register that emptied itself when the cycle closed would be
 * the exact failure §9.3 describes.
 */
class ActionPlanController extends Controller
{
    public function __construct(private readonly RcsaActionPlanService $plans) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', RcsaActionPlan::class);

        $mine = $request->boolean('mine');

        $filters = $request->only(['status', 'overdue', 'pending_verification', 'cycle', 'business_unit'])
            + ($mine ? ['owner' => $request->user()->id] : []);

        $register = $this->plans->register($filters)->paginate(25)->withQueryString();

        $register->through(fn (RcsaActionPlan $plan) => [
            'id' => $plan->id,
            'control_to_implement' => $plan->control_to_implement,
            'owner' => $plan->getRelationValue('owner')?->name,
            'owner_id' => $plan->owner_id,
            'is_mine' => (int) $plan->owner_id === (int) $request->user()->id,
            'target_date' => $plan->target_date?->toDateString(),
            'original_target_date' => $plan->original_target_date?->toDateString(),
            'proposed_target_date' => $plan->proposed_target_date?->toDateString(),
            'extension_reason' => $plan->extension_reason,
            'has_pending_extension' => $plan->hasPendingExtension(),
            'status' => $plan->status,
            'progress_pct' => $plan->progress_pct,
            // Computed, so a plan that came due at midnight reads overdue at
            // 09:00 whether or not the nightly sweep has run yet.
            'is_overdue' => $plan->isOverdue(),
            'days_until_due' => $plan->daysUntilDue(),
            'completion_evidence' => $plan->completion_evidence,
            'closed_at' => $plan->closed_at?->toDateTimeString(),
            'verified_at' => $plan->verified_at?->toDateTimeString(),
            'verified_by' => $plan->getRelationValue('verifiedBy')?->name,
            'risk_no' => $plan->getRelationValue('line')?->risk_no,
            'potential_risk' => $plan->getRelationValue('line')?->potential_risk,
            'business_unit' => $plan->getRelationValue('line')?->business_unit_name,
            'residual_level' => $plan->getRelationValue('line')?->residual_level,
            'assessment_id' => $plan->getRelationValue('line')?->assessment_id,
            'cycle' => $plan->getRelationValue('line')?->getRelationValue('assessment')?->getRelationValue('cycle')?->name,
            'can' => [
                'update' => $request->user()->can('update', $plan),
                'decide_extension' => $request->user()->can('decideExtension', $plan),
                'verify' => $request->user()->can('verify', $plan),
            ],
        ]);

        return Inertia::render('RcsaActionPlans/Index', [
            'plans' => $register,
            'summary' => $this->plans->summaryFor($mine ? $request->user()->id : null),
            'filters' => $request->only(['status', 'overdue', 'pending_verification', 'cycle', 'business_unit', 'mine']),
            'statuses' => RcsaActionPlan::STATUSES,
            'cycles' => RcsaCycle::query()->orderByDesc('period_start')->get(['id', 'name'])->all(),
        ]);
    }

    public function progress(UpdateActionPlanProgressRequest $request, RcsaActionPlan $plan)
    {
        try {
            $this->plans->recordProgress(
                $plan,
                $request->user(),
                (int) $request->validated()['progress_pct'],
                $request->validated()['note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Progress recorded.');
    }

    public function complete(UpdateActionPlanProgressRequest $request, RcsaActionPlan $plan)
    {
        try {
            $this->plans->complete($plan, $request->user(), $request->validated()['completion_evidence']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Marked complete. It closes once the ORM has verified it.');
    }

    public function requestExtension(UpdateActionPlanProgressRequest $request, RcsaActionPlan $plan)
    {
        try {
            $this->plans->requestExtension(
                $plan,
                $request->user(),
                $request->validated()['proposed_target_date'],
                $request->validated()['extension_reason'],
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Extension requested. The plan stays due on its original date until it is approved.');
    }

    public function decideExtension(Request $request, RcsaActionPlan $plan)
    {
        Gate::authorize('decideExtension', $plan);

        $approved = $request->boolean('approve');

        try {
            $this->plans->decideExtension($plan, $request->user(), $approved);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $approved ? 'Extension approved.' : 'Extension refused. The date has not moved.');
    }

    /**
     * ORM verification of closure — the last step of §9.3.
     */
    public function verify(Request $request, RcsaActionPlan $plan)
    {
        Gate::authorize('verify', $plan);

        $accepted = $request->boolean('accept', true);

        $validated = $request->validate([
            'reason' => [$accepted ? 'nullable' : 'required', 'string', 'max:2000'],
        ]);

        try {
            $accepted
                ? $this->plans->verify($plan, $request->user())
                : $this->plans->rejectClosure($plan, $request->user(), $validated['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $accepted
            ? 'Verified and closed.'
            : 'Sent back to the owner.');
    }
}
