<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAuditTrail;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RiskAssessmentController extends Controller
{
    /**
     * List all assessments with filters.
     */
    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = RiskAssessment::where('organization_id', $orgId)
            ->with(['risk', 'assessor']);

        if ($request->filled('risk_id')) {
            $query->where('risk_id', $request->risk_id);
        }

        if ($request->filled('assessment_type')) {
            $query->where('assessment_type', $request->assessment_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->where('assessment_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('assessment_date', '<=', $request->date_to);
        }

        $assessments = $query->orderByDesc('assessment_date')->paginate(25)->withQueryString();

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();

        return view('risk.assessments.index', compact('assessments', 'risks'));
    }

    /**
     * Show the create assessment form.
     */
    public function create(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

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
            $impactScore = max(
                (int) $validated['impact_financial'],
                (int) $validated['impact_operational'],
                (int) $validated['impact_reputational'],
                (int) $validated['impact_regulatory'],
                (int) ($validated['impact_strategic'] ?? 0),
            );
            $overallScore = (int) $validated['likelihood'] * $impactScore;
            $overallRating = $this->calculateRating($overallScore);

            $residualScore = null;
            $residualRating = null;
            if (!empty($validated['residual_likelihood']) && !empty($validated['residual_impact'])) {
                $residualScore = (int) $validated['residual_likelihood'] * (int) $validated['residual_impact'];
                $residualRating = $this->calculateRating($residualScore);
            }

            $notes = trim(
                ($validated['rationale'] ?? '')
                . (!empty($validated['recommendations']) ? "\n\nRecommendations:\n" . $validated['recommendations'] : '')
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
        $orgId = auth()->user()->organization_id ?? 1;

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

        return view('risk.assessments.show', compact('assessment', 'previousAssessment'));
    }

    /**
     * Update an assessment (only if draft or in_review).
     */
    public function update(Request $request, RiskAssessment $assessment)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($assessment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this assessment.');
        }

        if (!in_array($assessment->status, ['draft', 'in_review'])) {
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

        $impactScore = max(
            (int) $validated['impact_financial'],
            (int) $validated['impact_operational'],
            (int) $validated['impact_reputational'],
            (int) $validated['impact_regulatory'],
            (int) ($validated['impact_strategic'] ?? 0),
        );
        $overallScore = (int) $validated['likelihood'] * $impactScore;
        $overallRating = $this->calculateRating($overallScore);

        $residualScore = null;
        $residualRating = null;
        if (!empty($validated['residual_likelihood']) && !empty($validated['residual_impact'])) {
            $residualScore = (int) $validated['residual_likelihood'] * (int) $validated['residual_impact'];
            $residualRating = $this->calculateRating($residualScore);
        }

        $notes = trim(
            ($validated['rationale'] ?? '')
            . (!empty($validated['recommendations']) ? "\n\nRecommendations:\n" . $validated['recommendations'] : '')
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
    public function submit(RiskAssessment $assessment, ApprovalService $approvals)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($assessment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this assessment.');
        }

        if (! in_array($assessment->status, ['draft', 'rejected'])) {
            return back()->with('error', 'Only draft or rejected assessments can be submitted for review.');
        }

        $assessment->update(['status' => 'in_review']);

        $approvals->requestApproval(
            $assessment,
            'approve_risk_assessment',
            payload: ['overall_score' => $assessment->overall_score, 'residual_score' => $assessment->residual_score],
            reviewerId: $assessment->reviewer_id,
        );

        // If no specific reviewer assigned, notify everyone with the approver role.
        if (! $assessment->reviewer_id) {
            $approvers = User::role(['risk-manager', 'chief-risk-officer'])
                ->where('organization_id', $orgId)
                ->get();
            foreach ($approvers as $approver) {
                NotificationService::send(
                    $orgId,
                    $approver->id,
                    'approval_request',
                    "Risk assessment awaiting review: ASS-" . str_pad($assessment->id, 4, '0', STR_PAD_LEFT),
                    "Assessment for risk {$assessment->risk?->risk_code} has been submitted for review.",
                    ['entity_type' => 'RiskAssessment', 'entity_id' => $assessment->id]
                );
            }
        }

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment submitted for review.');
    }

    /**
     * Approve assessment and update parent risk scores.
     */
    public function approve(Request $request, RiskAssessment $assessment, ApprovalService $approvals)
    {
        abort_unless(auth()->user()->can('approve-risk-assessment', $assessment), 403,
            'Only the assigned reviewer or a risk-manager/CRO can approve this assessment.');

        if ($assessment->status !== 'in_review') {
            return back()->with('error', 'Only in-review assessments can be approved.');
        }

        $validated = $request->validate([
            'comments' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($assessment, $approvals, $validated) {
            $assessment->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_date' => now()->toDateString(),
            ]);

            $pending = $approvals->latestPending($assessment) ?? $approvals->requestApproval($assessment, 'approve_risk_assessment');
            $approvals->approve($pending, auth()->id(), $validated['comments'] ?? null);

            // Update parent risk with approved assessment scores
            $risk = $assessment->risk;
            $updateData = [
                'inherent_likelihood' => $assessment->likelihood_score,
                'inherent_impact' => $assessment->impact_score,
                'inherent_score' => $assessment->overall_score,
                'inherent_rating' => $assessment->overall_rating,
                'last_assessment_date' => $assessment->assessment_date,
            ];

            if ($assessment->residual_score !== null) {
                $updateData['residual_likelihood'] = $assessment->residual_likelihood;
                $updateData['residual_impact'] = $assessment->residual_impact;
                $updateData['residual_score'] = $assessment->residual_score;
                $updateData['residual_rating'] = $assessment->residual_rating;
            }

            $risk->update($updateData);

            return redirect()->route('risk.assessments.show', $assessment)
                ->with('success', 'Assessment approved and risk scores updated.');
        });
    }

    /**
     * Reject assessment with comments.
     */
    public function reject(Request $request, RiskAssessment $assessment, ApprovalService $approvals)
    {
        abort_unless(auth()->user()->can('approve-risk-assessment', $assessment), 403,
            'Only the assigned reviewer or a risk-manager/CRO can reject this assessment.');

        if ($assessment->status !== 'in_review') {
            return back()->with('error', 'Only in-review assessments can be rejected.');
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:2000',
        ]);

        // `review_comments` is the existing text column; reuse it to stash the
        // reviewer's rejection reason on the assessment row (the canonical
        // audit trail lives in approval_requests).
        $assessment->update([
            'status' => 'rejected',
            'review_comments' => $validated['rejection_reason'],
            'reviewer_id' => $assessment->reviewer_id ?: auth()->id(),
            'review_date' => now()->toDateString(),
        ]);

        $pending = $approvals->latestPending($assessment) ?? $approvals->requestApproval($assessment, 'approve_risk_assessment');
        $approvals->reject($pending, auth()->id(), $validated['rejection_reason']);

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
    private function calculateRating(int $score): string
    {
        if ($score >= 20) {
            return 'Critical';
        } elseif ($score >= 12) {
            return 'High';
        } elseif ($score >= 6) {
            return 'Medium';
        } else {
            return 'Low';
        }
    }
}
