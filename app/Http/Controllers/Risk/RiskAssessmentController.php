<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAuditTrail;
use App\Models\User;
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
            'assessment_type' => 'required|in:initial,periodic,triggered,annual',
            'assessment_date' => 'required|date',
            'assessed_likelihood' => 'required|integer|min:1|max:5',
            'assessed_impact' => 'required|integer|min:1|max:5',
            'control_effectiveness' => 'nullable|in:effective,partially_effective,ineffective',
            'residual_likelihood' => 'nullable|integer|min:1|max:5',
            'residual_impact' => 'nullable|integer|min:1|max:5',
            'assessment_notes' => 'nullable|string|max:5000',
            'methodology' => 'nullable|string|max:500',
            'assessor_id' => 'nullable|exists:users,id',
            'next_assessment_date' => 'nullable|date|after:assessment_date',
        ]);

        // Verify risk belongs to org
        $risk = Risk::where('id', $validated['risk_id'])
            ->where('organization_id', $orgId)
            ->firstOrFail();

        return DB::transaction(function () use ($validated, $orgId, $risk) {
            // Calculate scores
            $inherentScore = $validated['assessed_likelihood'] * $validated['assessed_impact'];
            $inherentRating = $this->calculateRating($inherentScore);

            $residualScore = null;
            $residualRating = null;
            if (!empty($validated['residual_likelihood']) && !empty($validated['residual_impact'])) {
                $residualScore = $validated['residual_likelihood'] * $validated['residual_impact'];
                $residualRating = $this->calculateRating($residualScore);
            }

            $assessment = RiskAssessment::create(array_merge($validated, [
                'organization_id' => $orgId,
                'inherent_score' => $inherentScore,
                'inherent_rating' => $inherentRating,
                'residual_score' => $residualScore,
                'residual_rating' => $residualRating,
                'status' => 'draft',
                'assessor_id' => $validated['assessor_id'] ?? auth()->id(),
                'created_by' => auth()->id(),
            ]));

            // Audit trail
            \App\Services\AuditTrailService::record($assessment, 'create');

            return redirect()->route('risk.assessments.show', $assessment)
                ->with('success', 'Risk assessment created as draft.');
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
            'assessment_type' => 'required|in:initial,periodic,triggered,annual',
            'assessment_date' => 'required|date',
            'assessed_likelihood' => 'required|integer|min:1|max:5',
            'assessed_impact' => 'required|integer|min:1|max:5',
            'control_effectiveness' => 'nullable|in:effective,partially_effective,ineffective',
            'residual_likelihood' => 'nullable|integer|min:1|max:5',
            'residual_impact' => 'nullable|integer|min:1|max:5',
            'assessment_notes' => 'nullable|string|max:5000',
            'methodology' => 'nullable|string|max:500',
            'next_assessment_date' => 'nullable|date|after:assessment_date',
        ]);

        $original = $assessment->getAttributes();

        $inherentScore = $validated['assessed_likelihood'] * $validated['assessed_impact'];
        $inherentRating = $this->calculateRating($inherentScore);

        $residualScore = null;
        $residualRating = null;
        if (!empty($validated['residual_likelihood']) && !empty($validated['residual_impact'])) {
            $residualScore = $validated['residual_likelihood'] * $validated['residual_impact'];
            $residualRating = $this->calculateRating($residualScore);
        }

        $assessment->update(array_merge($validated, [
            'inherent_score' => $inherentScore,
            'inherent_rating' => $inherentRating,
            'residual_score' => $residualScore,
            'residual_rating' => $residualRating,
        ]));

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($assessment, $original);

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment updated successfully.');
    }

    /**
     * Submit assessment from draft to in_review.
     */
    public function submit(RiskAssessment $assessment)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($assessment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this assessment.');
        }

        if ($assessment->status !== 'draft') {
            return back()->with('error', 'Only draft assessments can be submitted for review.');
        }

        $assessment->update([
            'status' => 'in_review',
            'submitted_at' => now(),
            'submitted_by' => auth()->id(),
        ]);

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment submitted for review.');
    }

    /**
     * Approve assessment and update parent risk scores.
     */
    public function approve(Request $request, RiskAssessment $assessment)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($assessment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this assessment.');
        }

        if ($assessment->status !== 'in_review') {
            return back()->with('error', 'Only in-review assessments can be approved.');
        }

        return DB::transaction(function () use ($assessment, $orgId) {
            $assessment->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            // Update parent risk with approved assessment scores
            $risk = $assessment->risk;
            $updateData = [
                'inherent_likelihood' => $assessment->assessed_likelihood,
                'inherent_impact' => $assessment->assessed_impact,
                'inherent_score' => $assessment->inherent_score,
                'inherent_rating' => $assessment->inherent_rating,
                'last_assessment_date' => $assessment->assessment_date,
            ];

            if ($assessment->residual_score !== null) {
                $updateData['residual_likelihood'] = $assessment->residual_likelihood;
                $updateData['residual_impact'] = $assessment->residual_impact;
                $updateData['residual_score'] = $assessment->residual_score;
                $updateData['residual_rating'] = $assessment->residual_rating;
            }

            if ($assessment->next_assessment_date) {
                $updateData['next_review_date'] = $assessment->next_assessment_date;
            }

            $risk->update($updateData);

            // Audit trail for assessment approval
            \App\Services\AuditTrailService::record($assessment, 'approve');

            // Audit trail for risk update
            \App\Services\AuditTrailService::recordChanges($risk, $risk->getOriginal());

            return redirect()->route('risk.assessments.show', $assessment)
                ->with('success', 'Assessment approved and risk scores updated.');
        });
    }

    /**
     * Reject assessment with comments.
     */
    public function reject(Request $request, RiskAssessment $assessment)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($assessment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this assessment.');
        }

        if ($assessment->status !== 'in_review') {
            return back()->with('error', 'Only in-review assessments can be rejected.');
        }

        $request->validate([
            'rejection_reason' => 'required|string|max:2000',
        ]);

        $assessment->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
            'rejected_at' => now(),
            'rejected_by' => auth()->id(),
        ]);

        // Audit trail
        \App\Services\AuditTrailService::record($assessment, 'reject');

        return redirect()->route('risk.assessments.show', $assessment)
            ->with('success', 'Assessment has been rejected.');
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
