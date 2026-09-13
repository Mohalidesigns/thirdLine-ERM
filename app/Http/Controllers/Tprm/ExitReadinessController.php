<?php

namespace App\Http\Controllers\Tprm;

use App\Enums\Tprm\ExitPlanStatus;
use App\Http\Controllers\Controller;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ExitPlan;
use App\Services\Tprm\Exit\ExitPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The exit readiness dashboard — Phase 9, build item 6.
 *
 * IT LEADS WITH THE ENGAGEMENTS THAT NEED A PLAN AND HAVE NONE. A dashboard of
 * traffic lights over the plans that exist flatters a programme that has
 * written three plans and needs thirty; the interesting number is the gap, and
 * it is the first thing on the page.
 *
 * THE TRAFFIC LIGHT IS ABOUT TEST CURRENCY, NOT ABOUT EXISTENCE. A plan
 * approved two years ago and never exercised is a document about exiting; the
 * light for it is the same red as no plan at all, because the bank's actual
 * ability to leave is the same in both cases.
 */
class ExitReadinessController extends Controller
{
    public function __construct(private readonly ExitPlanService $exitPlans) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.exit.view');

        $engagements = Engagement::query()
            ->whereNotIn('status', ['draft', 'terminated', 'archived'])
            ->with(['thirdParty:id,uuid,legal_name'])
            ->get();

        $plans = ExitPlan::query()
            ->whereIn('engagement_id', $engagements->modelKeys())
            ->get()
            ->keyBy('engagement_id');

        $rows = $engagements->map(function (Engagement $engagement) use ($plans): array {
            /** @var ExitPlan|null $plan */
            $plan = $plans->get($engagement->getKey());
            $requirement = $this->exitPlans->requirement($engagement);

            return [
                'uuid' => $engagement->uuid,
                'reference' => $engagement->reference,
                'name' => $engagement->name,
                'third_party' => $engagement->thirdParty?->legal_name,
                'tier' => $engagement->effective_tier?->value,
                'required' => $requirement['required'],
                'requirement_basis' => $requirement['basis'],
                'has_plan' => $plan !== null,
                'status' => $plan?->status->value,
                'credibility' => $plan?->credibility(),
                'last_tested_at' => $plan?->last_tested_at?->toDateString(),
                'next_test_due' => $plan?->next_test_due?->toDateString(),
                'light' => $this->light($plan, $requirement['required']),
            ];
        })->values();

        return Inertia::render('Tprm/Exit/Index', [
            'engagements' => $rows,
            'summary' => [
                // The gap first: a dashboard of lights over the plans that
                // exist flatters a programme that has written three and needs
                // thirty.
                'required_without_plan' => $rows->where('required', true)->where('has_plan', false)->count(),
                'never_tested' => $rows->where('has_plan', true)->whereNull('last_tested_at')->count(),
                'stale' => $rows->where('light', 'red')->where('has_plan', true)->count(),
                'current' => $rows->where('light', 'green')->count(),
                'total_required' => $rows->where('required', true)->count(),
            ],
            'can' => ['manage' => $request->user()->can('tprm.exit.manage')],
        ]);
    }

    public function recordTest(Request $request, ExitPlan $exitPlan)
    {
        Gate::authorize('tprm.exit.manage');

        $validated = $request->validate([
            'test_date' => ['required', 'date', 'before_or_equal:now'],
            'test_type' => ['required', 'in:desktop,partial,full'],
            'outcome' => ['required', 'in:successful,partial,failed'],
            'scenario' => ['nullable', 'string', 'max:2000'],
            'participants' => ['nullable', 'array'],
            'gaps_identified' => ['nullable', 'array'],
            'evidence_document_id' => ['nullable', 'integer', 'exists:tp_documents,id'],
        ]);

        $this->exitPlans->recordTest($exitPlan, $validated, $request->user()->id);

        return back()->with('success', sprintf(
            'Test recorded. The next one is due %s.',
            $exitPlan->refresh()->next_test_due?->toFormattedDateString() ?? 'per the tier policy',
        ));
    }

    /**
     * Green, amber or red.
     *
     * A PLAN NEVER TESTED IS RED, the same as no plan at all. The bank's
     * actual ability to leave is identical in both cases, and an amber that
     * said "we wrote something" would let a programme report progress it has
     * not made.
     */
    private function light(?ExitPlan $plan, bool $required): string
    {
        if (! $required) {
            return $plan === null ? 'grey' : 'green';
        }

        if ($plan === null || $plan->last_tested_at === null) {
            return 'red';
        }

        if ($plan->status === ExitPlanStatus::Stale || $plan->testIsOverdue()) {
            return 'red';
        }

        // Within three months of the next test is amber: enough warning to
        // book a room, which is what the light is for.
        if ($plan->next_test_due !== null && $plan->next_test_due->isBefore(now()->addMonths(3))) {
            return 'amber';
        }

        return 'green';
    }
}
