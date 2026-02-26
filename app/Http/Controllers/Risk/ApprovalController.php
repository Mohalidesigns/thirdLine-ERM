<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Services\ApprovalService;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    protected ApprovalService $approvalService;

    public function __construct(ApprovalService $approvalService)
    {
        $this->approvalService = $approvalService;
    }

    /**
     * Show pending approvals dashboard
     */
    public function dashboard()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $pending = $this->approvalService->getPendingApprovals($orgId);
        $stats = $this->approvalService->getStatistics($orgId);

        // Group by entity type for better organization
        $groupedByEntity = $pending->groupBy('entity_type');

        return view('risk.approvals.dashboard', compact('pending', 'groupedByEntity', 'stats'));
    }

    /**
     * Approve a pending approval request
     */
    public function approve(ApprovalRequest $approval, Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($approval->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this approval.');
        }

        if (!$approval->isPending()) {
            return back()->with('error', 'This approval request is no longer pending.');
        }

        $validated = $request->validate([
            'comments' => 'nullable|string|max:1000',
        ]);

        $this->approvalService->approve(
            $approval,
            auth()->id(),
            $validated['comments'] ?? null
        );

        return back()->with('success', 'Approval request has been approved.');
    }

    /**
     * Reject a pending approval request
     */
    public function reject(ApprovalRequest $approval, Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($approval->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this approval.');
        }

        if (!$approval->isPending()) {
            return back()->with('error', 'This approval request is no longer pending.');
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $this->approvalService->reject(
            $approval,
            auth()->id(),
            $validated['rejection_reason']
        );

        return back()->with('success', 'Approval request has been rejected.');
    }

    /**
     * View approval history
     */
    public function history(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $entityType = $request->get('entity_type');
        $history = $this->approvalService->getHistory($orgId, $entityType, 100);

        // Get unique entity types for filter
        $entityTypes = ApprovalRequest::where('organization_id', $orgId)
            ->distinct()
            ->pluck('entity_type');

        return view('risk.approvals.history', compact('history', 'entityTypes', 'entityType'));
    }
}
