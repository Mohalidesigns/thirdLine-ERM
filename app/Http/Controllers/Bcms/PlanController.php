<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\PlanType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\StorePlanRequest;
use App\Http\Requests\Bcms\UpdatePlanSectionRequest;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanSection;
use App\Presenters\Bcms\PlanBuilderPresenter;
use App\Presenters\Bcms\PlanLibraryPresenter;
use App\Services\Bcms\Plans\PlanAiDrafter;
use App\Services\Bcms\Plans\PlanAssembler;
use App\Services\Bcms\Plans\PlanDriftDetector;
use App\Services\Bcms\Plans\PlanService;
use App\Support\Bcms\AudienceRule;
use App\Support\Bcms\PlanBinding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The plan library and the plan builder.
 *
 * SECTIONS ARE REACHED THROUGH THEIR PLAN, ALWAYS. Every section route takes
 * `{plan}` as well as `{section}` and checks that the second belongs to the
 * first. Binding a section on its own would work — the tenancy scope stops
 * cross-tenant access — but would let somebody edit a section of a plan they had
 * not been authorised for within their own organisation, which is the scoping
 * bug that survives a tenancy test.
 *
 * IMMUTABILITY IS ENFORCED IN THE SERVICE AND EXPLAINED HERE. The service
 * refuses; this turns the refusal into a sentence that says what to do instead,
 * because "supersede it" is not something a user guesses.
 */
class PlanController extends Controller
{
    public function __construct(
        private PlanService $plans,
        private PlanAssembler $assembler,
        private PlanLibraryPresenter $library,
        private PlanBuilderPresenter $builder,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.plan.view');

        return Inertia::render('Bcms/Plans/Index', $this->library->present($request->user(), [
            'type' => $request->string('type')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ]));
    }

    public function show(Request $request, Plan $plan): Response
    {
        Gate::authorize('bcms.plan.view');

        return Inertia::render('Bcms/Plans/Show', $this->builder->present($plan, $request->user()));
    }

    /** Plans past their review date — the KRI's own list. */
    public function stale(Request $request): Response
    {
        Gate::authorize('bcms.plan.view');

        $stale = $this->plans->staleQuery()
            ->visibleTo($request->user())
            ->with(['owner:id,name', 'businessUnit:id,name'])
            ->orderBy('next_review_date')
            ->get();

        return Inertia::render('Bcms/Plans/Stale', [
            'plans' => $stale->map(fn (Plan $plan) => [
                'id' => $plan->getKey(),
                'uuid' => $plan->uuid,
                'title' => $plan->title,
                'plan_type_label' => $plan->plan_type->label(),
                'version' => $plan->version,
                'owner' => $plan->owner?->name,
                'business_unit' => $plan->businessUnit?->name,
                'next_review_date' => $plan->next_review_date?->toDateString(),
                'days_overdue' => $plan->next_review_date === null
                    ? null
                    : (int) $plan->next_review_date->diffInDays(now()->startOfDay()),
            ])->all(),
            'currency' => $this->plans->currency(),
            'can' => ['manage' => $request->user()?->can('bcms.plan.manage') === true],
        ]);
    }

    public function store(StorePlanRequest $request): RedirectResponse
    {
        $plan = $this->plans->create(
            PlanType::from($request->string('plan_type')->toString()),
            $request->string('title')->toString(),
            $request->safe()->only([
                'business_unit_id', 'site_id', 'owner_id', 'review_frequency_months', 'distribution_rule',
            ]),
            $request->string('template_key')->toString() ?: null,
            $request->user()?->getKey(),
        );

        return redirect()
            ->route('bcms.plans.show', $plan)
            ->with('success', 'Plan created'.($request->filled('template_key') ? ' from the template.' : '.'));
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        if ($plan->isImmutable()) {
            return back()->with('error', $this->immutableMessage($plan));
        }

        $organizationId = $request->user()?->organization_id;

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:250'],
            'owner_id' => ['nullable', 'integer', "exists:users,id,organization_id,{$organizationId}"],
            'review_frequency_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'next_review_date' => ['nullable', 'date'],
            'distribution_rule' => ['nullable', 'array'],
        ]);

        if (array_key_exists('distribution_rule', $data) && $data['distribution_rule'] !== null) {
            try {
                // Through the grammar's own validator: a rule that resolves to
                // nobody in six months is a coverage figure nobody can explain.
                AudienceRule::fromArray($data['distribution_rule']);
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['distribution_rule' => $e->getMessage()]);
            }
        }

        $plan->update($data + ['updated_by' => $request->user()?->getKey()]);

        return back()->with('success', 'Plan updated.');
    }

    /**
     * Set the review cycle across several plans.
     *
     * The library's bulk action. The rule that stops it being a "clear the
     * stale list" button lives in `PlanService::setReviewCycle()`.
     */
    public function setReviewCycle(Request $request): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        $data = $request->validate([
            'plan_ids' => ['required', 'array', 'min:1', 'max:200'],
            'plan_ids.*' => ['integer'],
            'review_frequency_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $result = $this->plans->setReviewCycle(
            // Narrowed to what this user may actually see before anything is
            // written: a bulk action is the easiest place to edit rows somebody
            // cannot open.
            $this->plans->libraryQuery()
                ->visibleTo($request->user())
                ->whereIn('id', $data['plan_ids'])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            $data['review_frequency_months'] ?? null,
            $request->user()?->getKey(),
        );

        return back()->with('success', sprintf(
            '%d plans updated.%s',
            $result['updated'],
            $result['undated'] > 0
                ? ' '.$result['undated'].' of them have never been approved, so they carry the cycle but no review '
                    .'date — there is nothing to count from yet.'
                : '',
        ));
    }

    public function applyTemplate(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        $data = $request->validate(['template_key' => ['required', 'string']]);

        try {
            $this->assembler->applyTemplate($plan, $data['template_key'], $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Template applied. Sections it already had were left alone.');
    }

    /** Re-render every bound section from live data. */
    public function assemble(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        try {
            $resolved = $this->assembler->assemble($plan, $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            $resolved === []
                ? 'This plan has no bound sections, so there was nothing to assemble.'
                : count($resolved).' bound sections re-rendered from live data.'
        );
    }

    public function submitForReview(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        try {
            $this->plans->submitForReview($plan, $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Plan sent for review.');
    }

    public function approve(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.approve');

        $data = $request->validate([
            'effective_from' => ['nullable', 'date'],
            'review_frequency_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        try {
            $this->plans->approve(
                $plan,
                $request->user(),
                $data['effective_from'] ?? null,
                $data['review_frequency_months'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            'Plan approved. This version is now fixed — what it renders today is what it will print for ever.'
        );
    }

    public function supersede(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        $data = $request->validate(['version' => ['required', 'string', 'max:20']]);

        try {
            $next = $this->plans->supersede($plan, $data['version'], $request->user()?->getKey());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('bcms.plans.show', $next)
            ->with('success', "Version {$next->version} drafted. Version {$plan->version} is archived and stays printable.");
    }

    /* ------------------------------------------------------------------ */
    /*  Sections */
    /* ------------------------------------------------------------------ */

    public function storeSection(UpdatePlanSectionRequest $request, Plan $plan): RedirectResponse
    {
        if ($plan->isImmutable()) {
            return back()->with('error', $this->immutableMessage($plan));
        }

        $data = $request->validated();

        $key = $request->string('section_key')->toString()
            ?: 'custom-'.strtolower(\Illuminate\Support\Str::random(6));

        if ($plan->sections()->where('section_key', $key)->exists()) {
            return back()->with('error', 'This plan already has a section with that key.');
        }

        PlanSection::query()->create([
            'organization_id' => $plan->organization_id,
            'plan_id' => $plan->getKey(),
            'section_key' => $key,
            'title' => $data['title'] ?? 'Untitled section',
            'body' => $data['body'] ?? null,
            'sort_order' => $data['sort_order'] ?? (((int) $plan->sections()->max('sort_order')) + 10),
            'source_binding' => $data['source_binding'] ?? null,
        ]);

        return back()->with('success', 'Section added.');
    }

    public function updateSection(UpdatePlanSectionRequest $request, Plan $plan, PlanSection $section): RedirectResponse
    {
        if ((int) $section->plan_id !== (int) $plan->getKey()) {
            abort(404);
        }

        if ($plan->isImmutable()) {
            return back()->with('error', $this->immutableMessage($plan));
        }

        $data = $request->validated();
        $wasBound = $section->source_binding !== null;
        $bodyChanged = array_key_exists('body', $data) && $data['body'] !== $section->body;

        $section->update(array_filter([
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
            'source_binding' => $data['source_binding'] ?? null,
            // Editing the body of a BOUND section is the human claiming it.
            // From here on assembly refreshes its fingerprint — so drift is
            // still detected — but leaves the words alone.
            'is_overridden' => ($wasBound && $bodyChanged) ? true : null,
        ], fn ($v) => $v !== null));

        return back()->with('success', 'Section saved.');
    }

    public function destroySection(Request $request, Plan $plan, PlanSection $section): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        if ((int) $section->plan_id !== (int) $plan->getKey()) {
            abort(404);
        }

        if ($plan->isImmutable()) {
            return back()->with('error', $this->immutableMessage($plan));
        }

        $section->delete();

        return back()->with('success', 'Section removed.');
    }

    /** Preview what a binding would render, without stamping anything. */
    public function previewBinding(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        $request->validate(['source_binding' => ['required', 'array']]);

        try {
            $payload = $this->assembler->preview($plan, PlanBinding::fromArray($request->input('source_binding')));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('preview', $payload);
    }

    /* ------------------------------------------------------------------ */
    /*  Drift and AI */
    /* ------------------------------------------------------------------ */

    public function checkDrift(Request $request, Plan $plan, PlanDriftDetector $detector): RedirectResponse
    {
        Gate::authorize('bcms.plan.view');

        $drifted = $detector->check($plan);

        return back()->with(
            'success',
            $drifted === []
                ? 'Every bound section still matches its source.'
                : count($drifted).' sections no longer match their source and have been flagged for review.'
        );
    }

    public function aiDraft(Request $request, Plan $plan, PlanAiDrafter $drafter): RedirectResponse
    {
        Gate::authorize('bcms.plan.manage');

        try {
            $result = $drafter->draft($plan);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if (! $result['ok']) {
            return back()->with('error', $result['reason']);
        }

        return back()
            ->with('proposed_sections', $result['proposed'])
            ->with(
                'success',
                count($result['written']).' sections drafted. Every one is marked as AI-generated and stays a '
                .'draft until somebody edits or approves it.'
            );
    }

    private function immutableMessage(Plan $plan): string
    {
        return "Version {$plan->version} is {$plan->status} and cannot be edited. Create the next version instead "
            .'— the supersession chain is the history an auditor reads, and the old version stays printable.';
    }
}
