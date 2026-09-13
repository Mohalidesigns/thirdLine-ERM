<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Concerns\EnforcesNodeScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assessments\ApproveRiskAssessmentRequest;
use App\Http\Requests\Assessments\PreviewAssessmentRequest;
use App\Http\Requests\Assessments\RejectRiskAssessmentRequest;
use App\Http\Requests\Assessments\StoreAssessmentRequest;
use App\Http\Requests\Assessments\UpdateAssessmentRequest;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Presenters\GridPresenter;
use App\Services\AssessmentChainService;
use App\Services\Assessments\AssessmentService;
use App\Services\NotificationService;
use App\Services\Workflow\ModuleApprovals;
use App\Support\Authorization\GraphScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The risk assessment journey (migration Phase 3.3).
 *
 * WP-10a rebuilt this around the chain a risk expert's review set as canonical:
 *
 *   Risk → Root Cause → Likelihood → Impact → Inherent Risk →
 *   Existing Controls → Control Effectiveness → Residual Risk →
 *   Risk Treatment → Action Plan → Owner → Due Date → KRI
 *
 * The chain itself now lives in AssessmentService (the journey) and
 * AssessmentChainService (the arithmetic). Each action here authorises through
 * RiskAssessmentPolicy, hands the work to one of them, and renders a page.
 *
 * The lifecycle guards — "only a draft can be edited", "only an in-review
 * assessment can be approved" — stay here rather than moving into the policy.
 * They answer with a flash message, not a 403, and WorkflowEngine::canAct()
 * asks the policy the same question about tasks that are not yet decided.
 */
class RiskAssessmentController extends Controller
{
    // WP-00 node scoping. RiskAssessment does not use ScopedToGraph, so unlike
    // a Risk there is no route-binding 404 to inherit: an assessment inherits
    // its visibility from the risk it assesses, and this is where that is
    // enforced. 404 rather than the policy's 403, deliberately — the caller
    // may hold every permission in the product, and leaking the existence of
    // a sibling branch's assessment is itself a disclosure. The policy checks
    // the same reach as defence in depth for callers holding the model some
    // other way.
    use EnforcesNodeScope;

    public function __construct(
        private readonly AssessmentService $assessments,
        private readonly AssessmentChainService $chain,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index */
    /* ------------------------------------------------------------------ */

    /**
     * List all assessments. Search, filters, sorting and pagination all moved
     * into the shared data grid (WP-09) — see
     * App\Grids\Definitions\RiskAssessmentsGrid.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', RiskAssessment::class);

        // WP-00: scoped through the risk, matching RiskAssessmentsGrid.
        $total = GraphScope::applyThrough(
            RiskAssessment::where('organization_id', TenantContext::organizationId()),
            'risk'
        )->count();

        return Inertia::render('Assessments/Index', [
            'total' => $total,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('assessments'), $request, $request->user()),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create / Store */
    /* ------------------------------------------------------------------ */

    /**
     * Show the assessment form.
     *
     * Step 1 — the risk — is chosen before the rest of the chain can be drawn:
     * which controls to rate and which causes to review are properties of the
     * risk, so without one there is no form to render. A request with no
     * `risk_id` therefore gets the risk picker rather than a form full of
     * empty selects.
     */
    public function create(Request $request)
    {
        Gate::authorize('create', RiskAssessment::class);

        $risk = $request->filled('risk_id')
            ? \App\Models\Risk::where('organization_id', TenantContext::organizationId())
                ->visibleTo()
                ->find($request->integer('risk_id'))
            : null;

        if ($risk === null) {
            return Inertia::render('Assessments/SelectRisk', [
                'risks' => $this->assessments->selectableRisks(),
            ]);
        }

        return Inertia::render('Assessments/Create', $this->assessments->formData($risk));
    }

    public function store(StoreAssessmentRequest $request)
    {
        $risk = $request->assessedRisk();
        $validated = $request->validated();

        $assessment = DB::transaction(fn () => $this->assessments->persistChain(
            new RiskAssessment([
                'risk_id' => $risk->id,
                'assessor_id' => $request->user()?->id,
                'status' => 'draft',
            ]),
            $validated,
            $risk,
            $request->user()?->id,
        ));

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', ($validated['action'] ?? null) === 'submit'
                ? 'Risk assessment submitted for review.'
                : 'Risk assessment saved as a draft.');
    }

    /* ------------------------------------------------------------------ */
    /*  The live preview (Decision 5b) */
    /* ------------------------------------------------------------------ */

    /**
     * Score the chain as the form currently holds it.
     *
     * The only endpoint on this controller that returns JSON rather than a
     * page: it is called on a debounce while the assessor works, and swapping
     * the whole page for every keystroke is not what Inertia is for.
     */
    public function preview(PreviewAssessmentRequest $request)
    {
        return response()->json(
            $this->chain->preview($request->assessedRisk(), $request->validated())
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Show */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, RiskAssessment $assessment)
    {
        $this->abortUnlessNodeVisibleThrough($assessment, 'risk');
        Gate::authorize('view', $assessment);

        $user = $request->user();

        return Inertia::render('Assessments/Show', array_merge(
            $this->assessments->detail($assessment),
            [
                'can' => [
                    'update' => $user->can('update', $assessment)
                        && in_array($assessment->status, ['draft', 'rejected'], true),
                    'submit' => $user->can('submit', $assessment)
                        && in_array($assessment->status, ['draft', 'rejected'], true),
                    'decide' => $user->can('approve', $assessment) && $assessment->status === 'in_review',
                    'resubmit' => $user->can('resubmit', $assessment) && $assessment->status === 'rejected',
                ],
            ],
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Edit / Update */
    /* ------------------------------------------------------------------ */

    /** Edit a draft — the same thirteen-step form, pre-filled. */
    public function edit(RiskAssessment $assessment)
    {
        $this->abortUnlessNodeVisibleThrough($assessment, 'risk');
        Gate::authorize('update', $assessment);

        if (! in_array($assessment->status, ['draft', 'rejected'], true)) {
            return redirect()->route('risk.assessments.show', $assessment)
                ->with('error', 'Only draft or rejected assessments can be edited.');
        }

        return Inertia::render('Assessments/Create', $this->assessments->formData($assessment->risk, $assessment));
    }

    public function update(UpdateAssessmentRequest $request, RiskAssessment $assessment)
    {
        $this->abortUnlessNodeVisibleThrough($assessment, 'risk');

        if (! in_array($assessment->status, ['draft', 'in_review', 'rejected'], true)) {
            return back()->with('error', 'Only draft or in-review assessments can be updated.');
        }

        DB::transaction(fn () => $this->assessments->persistChain(
            $assessment,
            $request->validated(),
            $assessment->risk,
            $request->user()?->id,
        ));

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle */
    /* ------------------------------------------------------------------ */

    public function submit(Request $request, RiskAssessment $assessment, ModuleApprovals $approvals)
    {
        $this->abortUnlessNodeVisibleThrough($assessment, 'risk');
        Gate::authorize('submit', $assessment);

        if (! in_array($assessment->status, ['draft', 'rejected'], true)) {
            return back()->with('error', 'Only draft or rejected assessments can be submitted for review.');
        }

        $orgId = TenantContext::organizationId();

        // WP-06. The workflow engine raises the task, resolves the reviewer and
        // notifies them. Where a tenant has not published the definition yet,
        // the status change below is what it always was.
        if (! $approvals->submit('risk_assessment_approval', $assessment, [], $request->user())) {
            $approvals->markSubmitted($assessment);

            foreach (User::role(['risk-manager', 'chief-risk-officer'])->where('organization_id', $orgId)->get() as $approver) {
                NotificationService::send(
                    $orgId,
                    $approver->id,
                    'approval_request',
                    'Risk assessment awaiting review: ASS-'.str_pad((string) $assessment->id, 4, '0', STR_PAD_LEFT),
                    "Assessment for risk {$assessment->risk?->risk_code} has been submitted for review.",
                    ['entity_type' => 'risk_assessment', 'entity_id' => $assessment->id]
                );
            }
        }

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment submitted for review.');
    }

    public function approve(ApproveRiskAssessmentRequest $request, RiskAssessment $assessment, ModuleApprovals $approvals)
    {
        $this->abortUnlessNodeVisibleThrough($assessment, 'risk');
        Gate::authorize('approve', $assessment);

        if ($assessment->status !== 'in_review') {
            return back()->with('error', 'Only in-review assessments can be approved.');
        }

        $validated = $request->validated();

        // WP-06. Pushing the approved scores onto the parent risk and firing
        // AssessmentApproved now lives in RiskAssessmentBinding, so it happens
        // however the decision was reached — this screen, My Tasks, the API, or
        // an SLA auto-approval — rather than only when this method runs.
        if (! $approvals->decide($assessment, 'approve', $request->user(), $validated)) {
            DB::transaction(fn () => $approvals->decideDirectly(
                $assessment, 'approve', $request->user(), $validated['comments'] ?? null
            ));
        }

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment approved and risk scores updated.');
    }

    public function reject(RejectRiskAssessmentRequest $request, RiskAssessment $assessment, ModuleApprovals $approvals)
    {
        $this->abortUnlessNodeVisibleThrough($assessment, 'risk');
        Gate::authorize('reject', $assessment);

        if ($assessment->status !== 'in_review') {
            return back()->with('error', 'Only in-review assessments can be rejected.');
        }

        $validated = $request->validated();

        // The reason lands on review_comments either way — the binding writes
        // it — and the decision timeline now lives on the workflow instance
        // rather than in approval_requests.
        $decided = $approvals->decide($assessment, 'reject', $request->user(), [
            'comments' => $validated['rejection_reason'],
        ]);

        if (! $decided) {
            $approvals->decideDirectly($assessment, 'reject', $request->user(), $validated['rejection_reason']);
        }

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment rejected. The assessor has been notified.');
    }

    /** Assessor resubmits a rejected assessment — status moves back to draft. */
    public function resubmit(RiskAssessment $assessment)
    {
        $this->abortUnlessNodeVisibleThrough($assessment, 'risk');
        Gate::authorize('resubmit', $assessment);

        if ($assessment->status !== 'rejected') {
            return back()->with('error', 'Only rejected assessments can be resubmitted.');
        }

        $assessment->update(['status' => 'draft', 'review_comments' => null]);

        return back()->with('success', 'Assessment returned to draft. Edit and submit again for review.');
    }
}
