<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approvals\ApproveRequest;
use App\Http\Requests\Approvals\RejectRequest;
use App\Models\ApprovalRequest;
use App\Presenters\GridPresenter;
use App\Presenters\WorkflowPresenter;
use App\Services\ApprovalService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly WorkflowPresenter $presenter,
    ) {}

    /**
     * Pending approvals, grouped by the kind of record (migration Phase 3.7).
     */
    public function dashboard()
    {
        Gate::authorize('viewAny', ApprovalRequest::class);

        $organizationId = (int) TenantContext::organizationId();
        $pending = $this->approvals->getPendingApprovals($organizationId);

        return Inertia::render('Approvals/Dashboard', [
            'stats' => $this->approvals->getStatistics($organizationId),
            'groups' => $pending->groupBy('entity_type')->map(fn ($approvals, $entityType) => [
                'entity_type' => $entityType,
                'count' => $approvals->count(),
                'items' => $approvals->map(fn ($a) => $this->presenter->approval($a))->values()->all(),
            ])->values()->all(),
            'canAct' => Gate::allows('approval.act'),
            'historyUrl' => route('risk.approvals.history'),
        ]);
    }

    public function approve(ApproveRequest $request, ApprovalRequest $approval)
    {
        if (! $approval->isPending()) {
            return back()->with('error', 'This approval request is no longer pending.');
        }

        $this->approvals->approve($approval, $request->user()->id, $request->validated('comments'));

        return back()->with('success', 'Approval request has been approved.');
    }

    public function reject(RejectRequest $request, ApprovalRequest $approval)
    {
        if (! $approval->isPending()) {
            return back()->with('error', 'This approval request is no longer pending.');
        }

        $this->approvals->reject($approval, $request->user()->id, $request->validated('rejection_reason'));

        return back()->with('success', 'Approval request has been rejected.');
    }

    /**
     * WP-09: the history table is the shared data grid
     * (App\Grids\Definitions\ApprovalsHistoryGrid), which carries the same
     * approved/rejected/superseded scope getHistoryPaginated applied, plus the
     * entity-type filter and pagination. The header needs no data.
     */
    public function history(Request $request, GridPresenter $presenter)
    {
        return Inertia::render('Approvals/History', [
            'grid' => fn () => $presenter->present(GridRegistry::resolve('approvals_history'), $request, $request->user()),
        ]);
    }
}
