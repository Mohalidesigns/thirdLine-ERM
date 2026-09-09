<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\BiaAssessmentStatus;
use App\Enums\Bcms\DependencyType;
use App\Enums\Bcms\ImpactCategory;
use App\Enums\Bcms\ImpactHorizon;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\SaveBiaAssessmentRequest;
use App\Http\Requests\Bcms\StoreBiaDependencyRequest;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\Process;
use App\Presenters\Bcms\BiaWorkspacePresenter;
use App\Services\Bcms\Bia\BiaAiDrafter;
use App\Services\Bcms\Bia\BiaAssessmentService;
use App\Services\Bcms\Bia\DependencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The BIA workspace — one assessment, its impact grid and its dependencies.
 *
 * Lifecycle rules answer with a flash message, not a 403 (development standard
 * §3). "This assessment is approved and cannot be edited" is a fact about the
 * record; a 403 would tell the user their account is wrong, which it is not.
 */
class BiaController extends Controller
{
    public function __construct(
        private BiaAssessmentService $assessments,
        private DependencyService $dependencies,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.bia.view');

        $filters = $request->only(['status', 'unit', 'search', 'campaign']);

        $assessments = BiaAssessment::query()
            ->with(['process.businessUnit:id,name', 'assessor:id,name', 'campaign:id,name'])
            ->whereHas('process', fn (\Illuminate\Database\Eloquent\Builder $q) => Process::visibleQuery($q, $request->user()))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['campaign'] ?? null, fn ($q, $v) => $q->where('campaign_id', $v))
            ->when($filters['unit'] ?? null, fn ($q, $v) => $q->whereHas('process', fn ($i) => $i->where('business_unit_id', $v)))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->whereHas(
                'process',
                fn ($i) => $i->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%")
            ))
            ->orderByRaw("CASE status WHEN 'returned' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'draft' THEN 2 WHEN 'submitted' THEN 3 ELSE 4 END")
            ->paginate(40)
            ->withQueryString();

        return Inertia::render('Bcms/Bia/Index', [
            'assessments' => $assessments->through(fn (BiaAssessment $a) => [
                'id' => $a->getKey(),
                'uuid' => $a->uuid,
                'process' => $a->process?->name,
                'code' => $a->process?->code,
                'unit' => $a->process?->businessUnit?->name,
                'tier' => $a->process?->criticality_tier,
                'is_critical_service' => (bool) $a->process?->is_critical_service,
                'status' => $a->status->value,
                'status_label' => $a->status->label(),
                'assessor' => $a->assessor?->name,
                'campaign' => $a->campaign?->name,
                'rto_hours' => $a->rto_hours === null ? null : (float) $a->rto_hours,
                'mtpd_hours' => $a->mtpd_hours === null ? null : (float) $a->mtpd_hours,
                'ai_generated' => (bool) $a->ai_generated,
            ]),
            'filters' => $filters,
            'statuses' => array_map(fn (BiaAssessmentStatus $s) => [
                'value' => $s->value, 'label' => $s->label(),
            ], BiaAssessmentStatus::cases()),
            'can' => [
                'edit' => $request->user()?->can('bcms.bia.complete') === true,
                'approve' => $request->user()?->can('bcms.bia.approve') === true,
                'manage_campaigns' => $request->user()?->can('bcms.bia.campaign.manage') === true,
            ],
        ]);
    }

    public function show(Request $request, BiaAssessment $assessment, BiaWorkspacePresenter $presenter): Response
    {
        Gate::authorize('bcms.bia.view');

        return Inertia::render('Bcms/Bia/Workspace', $presenter->present($assessment, $request->user()));
    }

    /** Start an assessment for one process, off-campaign. */
    public function store(Request $request, Process $process): RedirectResponse
    {
        Gate::authorize('bcms.bia.complete');

        $assessment = $this->assessments->start($process, $process->owner_id ?? $request->user()?->getKey());

        return redirect()->route('bcms.bia.show', $assessment)
            ->with('success', "Assessment started for {$process->name}.");
    }

    public function update(SaveBiaAssessmentRequest $request, BiaAssessment $assessment): RedirectResponse
    {
        try {
            $this->assessments->save($assessment, $request->validated(), $request->user()?->getKey());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Saved.');
    }

    public function scoreImpact(Request $request, BiaAssessment $assessment): RedirectResponse
    {
        Gate::authorize('bcms.bia.complete');

        $data = $request->validate([
            'impact_category' => ['required', 'in:'.implode(',', array_column(ImpactCategory::cases(), 'value'))],
            'horizon' => ['required', 'in:'.implode(',', array_column(ImpactHorizon::cases(), 'value'))],
            'severity_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'financial_amount_minor' => ['nullable', 'integer', 'min:0'],
            'narrative' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->assessments->scoreImpact(
                $assessment,
                ImpactCategory::from($data['impact_category']),
                ImpactHorizon::from($data['horizon']),
                $data['severity_score'] ?? null,
                $data['financial_amount_minor'] ?? null,
                $data['narrative'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Impact recorded.');
    }

    /** Accept the grid's proposed MTPD as the assessor's own answer. */
    public function acceptDerivedMtpd(Request $request, BiaAssessment $assessment): RedirectResponse
    {
        Gate::authorize('bcms.bia.complete');

        if ($assessment->derived_mtpd_hours === null) {
            return back()->with('error', 'The impact grid has not proposed an MTPD — no category has reached the intolerable score at any scored horizon.');
        }

        try {
            $this->assessments->save($assessment, ['mtpd_hours' => $assessment->derived_mtpd_hours], $request->user()?->getKey());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The proposed MTPD is now recorded as this assessment\'s answer.');
    }

    public function submit(Request $request, BiaAssessment $assessment): RedirectResponse
    {
        Gate::authorize('bcms.bia.complete');

        try {
            $this->assessments->submit($assessment, $request->user()?->getKey());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Submitted for review.');
    }

    public function approve(Request $request, BiaAssessment $assessment): RedirectResponse
    {
        Gate::authorize('bcms.bia.approve');

        $data = $request->validate(['criticality_tier' => ['nullable', 'integer', 'min:1', 'max:4']]);

        try {
            $this->assessments->approve($assessment, (int) $request->user()->getKey(), $data['criticality_tier'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Approved. The recovery objectives are now fixed and the process tier is set.');
    }

    public function returnForRework(Request $request, BiaAssessment $assessment): RedirectResponse
    {
        Gate::authorize('bcms.bia.approve');

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        try {
            $this->assessments->returnForRework($assessment, (int) $request->user()->getKey(), $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Returned to the assessor.');
    }

    /**
     * Ask the model for a first draft.
     *
     * Standing rule 4 — what comes back is a draft, flagged, with its reasoning,
     * and it cannot submit or approve itself.
     */
    public function aiDraft(Request $request, BiaAssessment $assessment, BiaAiDrafter $drafter): RedirectResponse
    {
        Gate::authorize('bcms.bia.complete');

        try {
            $result = $drafter->draft($assessment);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if (! $result['ok']) {
            return back()->with('error', $result['reason']);
        }

        return back()->with('success', sprintf(
            'Draft added to %d field(s), with %d challenge question(s). Everything it proposed is marked as a draft '
            .'and needs your review before this can be submitted.',
            count($result['filled']),
            count($result['questions']),
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Dependencies */
    /* ------------------------------------------------------------------ */

    public function storeDependency(StoreBiaDependencyRequest $request, BiaAssessment $assessment): RedirectResponse
    {
        $data = $request->validated();
        $type = DependencyType::from($data['dependable_type']);

        $target = $type->modelClass()::query()->find($data['dependable_id']);

        if ($target === null) {
            return back()->with('error', 'That '.strtolower($type->label()).' is no longer in the register.');
        }

        try {
            $this->dependencies->attach($assessment, $target, [
                'dependency_type' => $data['dependency_type'],
                'criticality' => $data['criticality'],
                'single_point_of_failure' => $data['single_point_of_failure'] ?? false,
                'alternative_available' => $data['alternative_available'] ?? false,
                'recovery_notes' => $data['recovery_notes'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dependency recorded.');
    }

    public function destroyDependency(BiaAssessment $assessment, Dependency $dependency): RedirectResponse
    {
        Gate::authorize('bcms.bia.complete');

        if ((int) $dependency->assessment_id !== (int) $assessment->getKey()) {
            return back()->with('error', 'That dependency belongs to a different assessment.');
        }

        $dependency->delete();

        return back()->with('success', 'Dependency removed.');
    }
}
