<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Thresholds\ApproveRebaselineRequest;
use App\Http\Requests\Thresholds\RejectRebaselineRequest;
use App\Models\ApprovalRequest;
use App\Models\MeasureThreshold;
use App\Services\ThresholdRebaselineService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

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
        Gate::authorize('viewAny', MeasureThreshold::class);

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

        return Inertia::render('Thresholds/Rebaseline', [
            'pending' => $pending->map(function (ApprovalRequest $approval) use ($thresholds) {
                $threshold = $thresholds[$approval->entity_id] ?? null;
                $payload = $approval->payload ?? [];

                return [
                    'id' => $approval->id,
                    'measureName' => $threshold?->measure->name ?? $payload['measure_code'] ?? 'Measure',
                    'measureCode' => $payload['measure_code'] ?? null,
                    'periodCode' => $payload['period_code'] ?? null,
                    'tolerancePct' => isset($payload['tolerance']) ? (float) $payload['tolerance'] * 100 : null,
                    'raisedAgo' => $approval->requested_at?->diffForHumans(),
                    'changes' => collect($payload['changes'] ?? [])->map(fn (array $change) => [
                        'band' => $change['band'] ?? null,
                        'bound' => $change['bound'] ?? null,
                        'formula' => $change['formula'] ?? null,
                        // null means there was no prior bound at all, which is
                        // a different thing from a bound of zero — the page
                        // says "unset" rather than printing 0.00.
                        'inForce' => $change['in_force'] === null ? null : (float) $change['in_force'],
                        'computed' => (float) ($change['computed'] ?? 0),
                        'relativeChange' => $change['relative_change'] === null
                            ? null
                            : (float) $change['relative_change'] * 100,
                    ])->all(),
                ];
            })->values()->all(),

            'history' => $history->map(fn (ApprovalRequest $decision) => [
                'id' => $decision->id,
                'measureCode' => $decision->payload['measure_code'] ?? null,
                'periodCode' => $decision->payload['period_code'] ?? null,
                'status' => $decision->status,
                'reviewedBy' => $decision->reviewedBy?->name,
                'reviewedAt' => $decision->reviewed_at?->format('d M Y'),
                'note' => $decision->comments ?? $decision->rejection_reason,
            ])->values()->all(),

            'canDecide' => Gate::allows('rebaselineApprove', MeasureThreshold::class),
        ]);
    }

    public function approve(ApproveRebaselineRequest $request, ApprovalRequest $approval)
    {
        $this->assertRebaselineRequest($approval);

        if (! $approval->isPending()) {
            return back()->with('error', 'That re-baselining request has already been decided.');
        }

        $validated = $request->validated();

        $replacement = $this->rebaseline->apply($approval, $request->user(), $validated['comments'] ?? null);

        return back()->with(
            'success',
            'Threshold re-baselined. The new band set takes effect '
            .$replacement->effective_from->format('d M Y').'; the previous one is retained.'
        );
    }

    public function reject(RejectRebaselineRequest $request, ApprovalRequest $approval)
    {
        $this->assertRebaselineRequest($approval);

        if (! $approval->isPending()) {
            return back()->with('error', 'That re-baselining request has already been decided.');
        }

        $validated = $request->validated();

        $this->rebaseline->reject($approval, $request->user(), $validated['reason']);

        return back()->with('success', 'Re-baselining rejected. The band in force is unchanged.');
    }

    /**
     * The request has to be this tenant's AND actually a re-baselining.
     *
     * A 404 for the wrong action rather than a 403: the id addresses an
     * ApprovalRequest that exists but is not one of these, and this route does
     * not decide other kinds of approval. Kept out of the policy because the
     * policy answers "may this user re-baseline", which needs no instance —
     * see MeasureThresholdPolicy.
     */
    private function assertRebaselineRequest(ApprovalRequest $approval): void
    {
        abort_unless($approval->organization_id === TenantContext::organizationId(), 403);
        abort_unless($approval->action === ThresholdRebaselineService::ACTION, 404);
    }
}
