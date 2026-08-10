<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\MeasureThreshold;
use App\Services\ThresholdRebaselineService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * The re-baselining approval queue.
 *
 * A formula threshold that has drifted past tolerance raises a task here rather
 * than moving itself. Approving writes a NEW effective-dated band set; the band
 * that was in force is closed, never edited, so last quarter's breaches keep
 * reading against last quarter's limit.
 */
class ThresholdController extends Controller
{
    public function __construct(private ThresholdRebaselineService $rebaseline) {}

    public function index()
    {
        $organizationId = TenantContext::organizationId();

        $pending = $this->rebaseline->pending($organizationId);

        $thresholds = MeasureThreshold::query()
            ->whereIn('id', $pending->pluck('entity_id'))
            ->with(['measure.unit', 'object'])
            ->get()
            ->keyBy('id');

        $history = ApprovalRequest::query()
            ->where('organization_id', $organizationId)
            ->where('action', ThresholdRebaselineService::ACTION)
            ->whereIn('status', ['approved', 'rejected'])
            ->with('requestedBy', 'reviewedBy')
            ->orderByDesc('reviewed_at')
            ->limit(25)
            ->get();

        return view('risk.thresholds.rebaseline', compact('pending', 'thresholds', 'history'));
    }

    public function approve(Request $request, ApprovalRequest $approval)
    {
        abort_unless($approval->organization_id === TenantContext::organizationId(), 403);
        abort_unless($approval->action === ThresholdRebaselineService::ACTION, 404);

        if (! $approval->isPending()) {
            return back()->with('error', 'That re-baselining request has already been decided.');
        }

        $validated = $request->validate(['comments' => 'nullable|string|max:1000']);

        $replacement = $this->rebaseline->apply($approval, $request->user(), $validated['comments'] ?? null);

        return back()->with(
            'success',
            'Threshold re-baselined. The new band set takes effect '
            .$replacement->effective_from->format('d M Y').'; the previous one is retained.'
        );
    }

    public function reject(Request $request, ApprovalRequest $approval)
    {
        abort_unless($approval->organization_id === TenantContext::organizationId(), 403);
        abort_unless($approval->action === ThresholdRebaselineService::ACTION, 404);

        if (! $approval->isPending()) {
            return back()->with('error', 'That re-baselining request has already been decided.');
        }

        $validated = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        $this->rebaseline->reject($approval, $request->user(), $validated['reason']);

        return back()->with('success', 'Re-baselining rejected. The band in force is unchanged.');
    }
}
