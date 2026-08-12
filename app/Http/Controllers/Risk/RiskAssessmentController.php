<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\RiskScoringService;
use App\Services\Workflow\ModuleApprovals;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RiskAssessmentController extends Controller
{
    /**
     * List all assessments. Search, filters, sorting and pagination all
     * moved into the shared data grid (WP-09) — see
     * App\Grids\Definitions\RiskAssessmentsGrid.
     */
    public function index(Request $request)
    {
        $total = RiskAssessment::where('organization_id', TenantContext::organizationId())->count();

        return view('risk.assessments.index', compact('total'));
    }

    /**
     * Show the create assessment form.
     */
    public function create(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $risks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->orderBy('risk_code')
            ->get();

        $selectedRiskId = $request->get('risk_id');
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.assessments.create', compact('risks', 'selectedRiskId', 'users'));
    }

    /**
     * Store a new assessment.
     */
    public function store(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validate([
            'risk_id' => 'required|exists:risks,id',
            'assessment_type' => 'required|in:initial,periodic,event_driven,triggered,annual',
            'assessment_date' => 'required|date',
            'likelihood' => 'required|integer|min:1|max:5',
            'impact_financial' => 'required|integer|min:1|max:5',
            'impact_operational' => 'required|integer|min:1|max:5',
            'impact_reputational' => 'required|integer|min:1|max:5',
            'impact_regulatory' => 'required|integer|min:1|max:5',
            'impact_strategic' => 'nullable|integer|min:1|max:5',
            'residual_likelihood' => 'nullable|integer|min:1|max:5',
            'residual_impact' => 'nullable|integer|min:1|max:5',
            'rationale' => 'required|string|max:5000',
            'recommendations' => 'nullable|string|max:5000',
            'action' => 'nullable|in:draft,submit',
        ]);

        // Verify risk belongs to org
        Risk::where('id', $validated['risk_id'])
            ->where('organization_id', $orgId)
            ->firstOrFail();

        return DB::transaction(function () use ($validated, $orgId, $request) {
            // One impact aggregation, honouring the organization's
            // configured method (config/risk.php).
            $impactScore = app(RiskScoringService::class)->calculateImpact([
                'financial' => $validated['impact_financial'] ?? null,
                'operational' => $validated['impact_operational'] ?? null,
                'reputational' => $validated['impact_reputational'] ?? null,
                'regulatory' => $validated['impact_regulatory'] ?? null,
                'strategic' => $validated['impact_strategic'] ?? null,
            ], $orgId);
            $overallScore = (int) $validated['likelihood'] * $impactScore;
            $overallRating = $this->calculateRating($overallScore);

            $residualScore = null;
            $residualRating = null;
            if (! empty($validated['residual_likelihood']) && ! empty($validated['residual_impact'])) {
                $residualScore = (int) $validated['residual_likelihood'] * (int) $validated['residual_impact'];
                $residualRating = $this->calculateRating($residualScore);
            }

            $notes = trim(
                ($validated['rationale'] ?? '')
                .(! empty($validated['recommendations']) ? "\n\nRecommendations:\n".$validated['recommendations'] : '')
            );

            $assessment = RiskAssessment::create([
                'organization_id' => $orgId,
                'risk_id' => $validated['risk_id'],
                'assessment_type' => $validated['assessment_type'],
                'assessment_date' => $validated['assessment_date'],
                'assessor_id' => auth()->id(),
                'likelihood_score' => (int) $validated['likelihood'],
                'impact_financial' => (int) $validated['impact_financial'],
                'impact_operational' => (int) $validated['impact_operational'],
                'impact_reputational' => (int) $validated['impact_reputational'],
                'impact_regulatory' => (int) $validated['impact_regulatory'],
                'impact_strategic' => isset($validated['impact_strategic']) ? (int) $validated['impact_strategic'] : null,
                'impact_score' => $impactScore,
                'overall_score' => $overallScore,
                'overall_rating' => $overallRating,
                'residual_likelihood' => $validated['residual_likelihood'] ?? null,
                'residual_impact' => $validated['residual_impact'] ?? null,
                'residual_score' => $residualScore,
                'residual_rating' => $residualRating,
                'assessment_notes' => $notes ?: null,
                'status' => $request->input('action') === 'submit' ? 'in_review' : 'draft',
            ]);

            return redirect()->route('risk.assessments.show', $assessment)
                ->with('success', 'Risk assessment saved.');
        });
    }

    /**
     * Display assessment detail with comparison to previous.
     */
    public function show(RiskAssessment $assessment)
    {
        $orgId = TenantContext::organizationId();

        if ($assessment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this assessment.');
        }

        $assessment->load(['risk.category', 'risk.riskOwner', 'assessor']);

        // Get previous assessment for comparison
        $previousAssessment = RiskAssessment::where('risk_id', $assessment->risk_id)
            ->where('organization_id', $orgId)
            ->where('assessment_date', '<', $assessment->assessment_date)
            ->where('status', 'approved')
            ->orderByDesc('assessment_date')
            ->first();

        // Full assessment history for this risk, with per-assessment score change
        // computed against the chronologically preceding assessment.
        $history = RiskAssessment::where('risk_id', $assessment->risk_id)
            ->where('organization_id', $orgId)
            ->with('assessor')
            ->orderBy('assessment_date')
            ->orderBy('id')
            ->get();

        $previousScore = null;
        foreach ($history as $item) {
            $item->setAttribute(
                'score_change',
                $previousScore === null ? 0 : ((int) $item->overall_score - $previousScore)
            );
            $previousScore = (int) $item->overall_score;
        }

        $assessmentHistory = $history->sortByDesc('assessment_date')->values();

        // Mirror the computed change onto the assessment shown in the KPI cards
        $currentInHistory = $history->firstWhere('id', $assessment->id);
        $assessment->setAttribute('score_change', $currentInHistory?->score_change ?? 0);

        // Radar chart: impact dimension scores, current vs previous
        $dimensionData = [
            'labels' => ['Financial', 'Operational', 'Reputational', 'Regulatory', 'Strategic'],
            'current' => [
                (int) $assessment->impact_financial,
                (int) $assessment->impact_operational,
                (int) $assessment->impact_reputational,
                (int) $assessment->impact_regulatory,
                (int) ($assessment->impact_strategic ?? 0),
            ],
            'previous' => $previousAssessment ? [
                (int) $previousAssessment->impact_financial,
                (int) $previousAssessment->impact_operational,
                (int) $previousAssessment->impact_reputational,
                (int) $previousAssessment->impact_regulatory,
                (int) ($previousAssessment->impact_strategic ?? 0),
            ] : [0, 0, 0, 0, 0],
        ];

        // Bar chart: side-by-side comparison with the previous assessment
        $comparisonData = [
            'labels' => ['Likelihood', 'Financial', 'Operational', 'Reputational', 'Regulatory', 'Strategic'],
            'current' => [
                (int) $assessment->likelihood_score,
                (int) $assessment->impact_financial,
                (int) $assessment->impact_operational,
                (int) $assessment->impact_reputational,
                (int) $assessment->impact_regulatory,
                (int) ($assessment->impact_strategic ?? 0),
            ],
            'previous' => $previousAssessment ? [
                (int) $previousAssessment->likelihood_score,
                (int) $previousAssessment->impact_financial,
                (int) $previousAssessment->impact_operational,
                (int) $previousAssessment->impact_reputational,
                (int) $previousAssessment->impact_regulatory,
                (int) ($previousAssessment->impact_strategic ?? 0),
            ] : [0, 0, 0, 0, 0, 0],
        ];

        return view('risk.assessments.show', compact(
            'assessment', 'previousAssessment',
            'assessmentHistory', 'dimensionData', 'comparisonData'
        ));
    }

    /**
     * Update an assessment (only if draft or in_review).
     */
    public function update(Request $request, RiskAssessment $assessment)
    {
        $orgId = TenantContext::organizationId();

        if ($assessment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this assessment.');
        }

        if (! in_array($assessment->status, ['draft', 'in_review'])) {
            return back()->with('error', 'Only draft or in-review assessments can be updated.');
        }

        $validated = $request->validate([
            'assessment_type' => 'required|in:initial,periodic,event_driven,triggered,annual',
            'assessment_date' => 'required|date',
            'likelihood' => 'required|integer|min:1|max:5',
            'impact_financial' => 'required|integer|min:1|max:5',
            'impact_operational' => 'required|integer|min:1|max:5',
            'impact_reputational' => 'required|integer|min:1|max:5',
            'impact_regulatory' => 'required|integer|min:1|max:5',
            'impact_strategic' => 'nullable|integer|min:1|max:5',
            'residual_likelihood' => 'nullable|integer|min:1|max:5',
            'residual_impact' => 'nullable|integer|min:1|max:5',
            'rationale' => 'required|string|max:5000',
            'recommendations' => 'nullable|string|max:5000',
        ]);

        $impactScore = app(RiskScoringService::class)->calculateImpact([
            'financial' => $validated['impact_financial'] ?? null,
            'operational' => $validated['impact_operational'] ?? null,
            'reputational' => $validated['impact_reputational'] ?? null,
            'regulatory' => $validated['impact_regulatory'] ?? null,
            'strategic' => $validated['impact_strategic'] ?? null,
        ], $assessment->organization_id);
        $overallScore = (int) $validated['likelihood'] * $impactScore;
        $overallRating = $this->calculateRating($overallScore);

        $residualScore = null;
        $residualRating = null;
        if (! empty($validated['residual_likelihood']) && ! empty($validated['residual_impact'])) {
            $residualScore = (int) $validated['residual_likelihood'] * (int) $validated['residual_impact'];
            $residualRating = $this->calculateRating($residualScore);
        }

        $notes = trim(
            ($validated['rationale'] ?? '')
            .(! empty($validated['recommendations']) ? "\n\nRecommendations:\n".$validated['recommendations'] : '')
        );

        $assessment->update([
            'assessment_type' => $validated['assessment_type'],
            'assessment_date' => $validated['assessment_date'],
            'likelihood_score' => (int) $validated['likelihood'],
            'impact_financial' => (int) $validated['impact_financial'],
            'impact_operational' => (int) $validated['impact_operational'],
            'impact_reputational' => (int) $validated['impact_reputational'],
            'impact_regulatory' => (int) $validated['impact_regulatory'],
            'impact_strategic' => isset($validated['impact_strategic']) ? (int) $validated['impact_strategic'] : null,
            'impact_score' => $impactScore,
            'overall_score' => $overallScore,
            'overall_rating' => $overallRating,
            'residual_likelihood' => $validated['residual_likelihood'] ?? null,
            'residual_impact' => $validated['residual_impact'] ?? null,
            'residual_score' => $residualScore,
            'residual_rating' => $residualRating,
            'assessment_notes' => $notes ?: null,
        ]);

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment updated successfully.');
    }

    /**
     * Submit assessment from draft to in_review.
     */
    public function submit(RiskAssessment $assessment, ModuleApprovals $approvals)
    {
        $orgId = TenantContext::organizationId();

        if ($assessment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this assessment.');
        }

        if (! in_array($assessment->status, ['draft', 'rejected'])) {
            return back()->with('error', 'Only draft or rejected assessments can be submitted for review.');
        }

        // WP-06. The workflow engine raises the task, resolves the reviewer and
        // notifies them. Where a tenant has not published the definition yet,
        // the status change below is what it always was.
        if (! $approvals->submit('risk_assessment_approval', $assessment, [], auth()->user())) {
            $approvals->markSubmitted($assessment);

            foreach (User::role(['risk-manager', 'chief-risk-officer'])->where('organization_id', $orgId)->get() as $approver) {
                NotificationService::send(
                    $orgId,
                    $approver->id,
                    'approval_request',
                    'Risk assessment awaiting review: ASS-'.str_pad($assessment->id, 4, '0', STR_PAD_LEFT),
                    "Assessment for risk {$assessment->risk?->risk_code} has been submitted for review.",
                    ['entity_type' => 'risk_assessment', 'entity_id' => $assessment->id]
                );
            }
        }

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment submitted for review.');
    }

    /**
     * Approve assessment and update parent risk scores.
     */
    public function approve(Request $request, RiskAssessment $assessment, ModuleApprovals $approvals)
    {
        abort_unless(auth()->user()->can('approve-risk-assessment', $assessment), 403,
            'Only the assigned reviewer or a risk-manager/CRO can approve this assessment.');

        if ($assessment->status !== 'in_review') {
            return back()->with('error', 'Only in-review assessments can be approved.');
        }

        $validated = $request->validate([
            'comments' => 'nullable|string|max:1000',
        ]);

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

    /**
     * Reject assessment with comments.
     */
    public function reject(Request $request, RiskAssessment $assessment, ModuleApprovals $approvals)
    {
        abort_unless(auth()->user()->can('approve-risk-assessment', $assessment), 403,
            'Only the assigned reviewer or a risk-manager/CRO can reject this assessment.');

        if ($assessment->status !== 'in_review') {
            return back()->with('error', 'Only in-review assessments can be rejected.');
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:2000',
        ]);

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

    /**
     * Assessor resubmits a rejected assessment — status moves back to draft.
     */
    public function resubmit(RiskAssessment $assessment)
    {
        abort_unless(auth()->user()->can('resubmit-risk-assessment', $assessment), 403,
            'Only the original assessor can resubmit.');

        if ($assessment->status !== 'rejected') {
            return back()->with('error', 'Only rejected assessments can be resubmitted.');
        }

        $assessment->update([
            'status' => 'draft',
            'review_comments' => null,
        ]);

        return back()->with('success', 'Assessment returned to draft. Edit and submit again for review.');
    }

    /**
     * Calculate risk rating based on score.
     */
    /**
     * Delegates to RiskScoringService — there is one set of rating bands.
     *
     * This used to be a private copy using >= 6 for Medium while the service
     * used >= 5, so a risk scoring exactly 5 was Medium or Low depending on
     * which screen you were looking at. The service's bands win.
     */
    private function calculateRating(int $score): string
    {
        return app(RiskScoringService::class)->calculateRating($score);
    }
}
