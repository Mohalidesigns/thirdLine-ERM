<?php

namespace App\Http\Controllers\Tprm;

use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\FindingStatus;
use App\Http\Controllers\Controller;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\RiskAcceptance;
use App\Services\Tprm\Findings\FindingService;
use App\Services\Tprm\Findings\RiskAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The findings board — FR-FND-01, and the Kanban the phase asks for.
 *
 * SWIMLANES BY SEVERITY, COLUMNS BY STATUS. The other way round reads better
 * on a whiteboard and worse here: a board grouped by status puts a Critical
 * finding nobody has assigned in the same column as a Low one, and the eye
 * goes to the column with the most cards rather than to the row that matters.
 *
 * THE SLA BREACH IS THE HIGHLIGHT, not the status. A finding two days from its
 * target and one three months past it are both "in remediation", and only one
 * of them needs a phone call today.
 */
class FindingController extends Controller
{
    public function __construct(
        private readonly FindingService $findings,
        private readonly RiskAcceptanceService $acceptances,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Finding::class);

        $findings = Finding::query()
            ->with([
                'engagement:id,uuid,reference,name',
                'thirdParty:id,legal_name,slug,uuid',
                'owner:id,name',
                'acceptance:id,expires_at,status',
            ])
            ->when($request->filled('engagement'), fn ($q) => $q->where('engagement_id', $request->integer('engagement')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->boolean('open_only', true), fn ($q) => $q->scoring())
            ->orderByRaw($this->severityOrder())
            ->orderBy('target_date')
            ->get();

        return Inertia::render('Tprm/Findings/Index', [
            'findings' => $findings->map(fn (Finding $finding) => $this->card($finding))->values(),
            'columns' => collect(FindingStatus::cases())
                ->map(fn (FindingStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'is_open' => $status->isOpen(),
                ])->values(),
            'severities' => collect(FindingSeverity::cases())
                ->map(fn (FindingSeverity $severity) => [
                    'value' => $severity->value,
                    'label' => $severity->label(),
                ])->values(),
            'summary' => $this->summary(),
            'acceptanceRegister' => $this->acceptances->register($request->user()->organization_id),
            'filters' => [
                'engagement' => $request->integer('engagement') ?: null,
                'severity' => $request->string('severity')->toString() ?: null,
                'open_only' => $request->boolean('open_only', true),
            ],
            'can' => [
                'manage' => $request->user()->can('tprm.finding.manage'),
                'acceptRisk' => $request->user()->can('tprm.finding.accept_risk'),
            ],
        ]);
    }

    public function show(Request $request, Finding $finding)
    {
        Gate::authorize('view', $finding);

        $finding->load([
            'engagement:id,uuid,reference,name',
            'thirdParty:id,legal_name,slug,uuid',
            'owner:id,name',
            'verifier:id,name',
            'acceptances.approver:id,name',
            'ermIssue:id,issue_reference,issue_status',
        ]);

        return Inertia::render('Tprm/Findings/Show', [
            'finding' => $this->card($finding) + [
                'description' => $finding->description,
                'remediation_plan' => $finding->remediation_plan,
                'vendor_response' => $finding->vendor_response,
                'regulatory_citation' => $finding->regulatory_citation,
                'control_refs' => $finding->control_refs ?? [],
                'source' => $finding->source,
                'verified_by' => $finding->verifier?->name,
                'verified_at' => $finding->verified_at?->toDayDateTimeString(),
                'closure_type' => $finding->closure_type,
                'erm_issue' => $finding->ermIssue === null ? null : [
                    'reference' => $finding->ermIssue->issue_reference,
                    'status' => $finding->ermIssue->issue_status,
                ],
                'next_statuses' => collect($finding->status->allowedTransitions())
                    ->map(fn (FindingStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                    ->values(),
            ],
            'acceptances' => $finding->acceptances->map(fn (RiskAcceptance $acceptance) => [
                'id' => $acceptance->getKey(),
                'justification' => $acceptance->justification,
                'compensating_controls' => $acceptance->compensating_controls,
                'approver' => $acceptance->approver?->name,
                'approver_role' => $acceptance->approver_role,
                'approved_at' => $acceptance->approved_at?->toDateString(),
                'expires_at' => $acceptance->expires_at?->toDateString(),
                'days_remaining' => $acceptance->daysRemaining(),
                'status' => $acceptance->status,
                'in_force' => $acceptance->isInForce(),
            ])->values(),
            'acceptanceLimits' => [
                'permission' => RiskAcceptance::requiredPermissionFor($finding->severity),
                'maximum_months' => RiskAcceptance::maximumMonthsFor($finding->severity),
            ],
            'can' => [
                'manage' => $request->user()->can('update', $finding),
                'acceptRisk' => $request->user()->can(RiskAcceptance::requiredPermissionFor($finding->severity)),
            ],
        ]);
    }

    public function store(Request $request, Engagement $engagement)
    {
        Gate::authorize('create', Finding::class);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'severity' => 'required|in:critical,high,medium,low',
            'owner_id' => 'nullable|integer|exists:users,id',
            'regulatory_citation' => 'nullable|string|max:255',
        ]);

        $finding = $this->findings->raise(
            $engagement,
            'manual',
            FindingSeverity::from($validated['severity']),
            $validated['title'],
            $validated,
            $request->user()->id,
        );

        return redirect()
            ->route('tprm.findings.show', $finding)
            ->with('success', sprintf(
                'Raised as %s, due %s. It is on the ERM issue register too.',
                $finding->reference,
                $finding->target_date?->toFormattedDateString() ?? 'with no target date',
            ));
    }

    public function transition(Request $request, Finding $finding)
    {
        Gate::authorize('update', $finding);

        $validated = $request->validate([
            'status' => 'required|in:'.implode(',', array_column(FindingStatus::cases(), 'value')),
        ]);

        $result = $this->findings->transition(
            $finding,
            FindingStatus::from($validated['status']),
            $request->user()->id,
        );

        return $result['moved']
            ? back()->with('success', 'The finding was moved.')
            : back()->with('error', $result['reason']);
    }

    public function recordPlan(Request $request, Finding $finding)
    {
        Gate::authorize('update', $finding);

        $validated = $request->validate([
            'remediation_plan' => 'required|string|min:10|max:4000',
            'vendor_response' => 'nullable|string|max:4000',
        ]);

        $this->findings->recordPlan(
            $finding,
            $validated['remediation_plan'],
            $validated['vendor_response'] ?? null,
            $request->user()->id,
        );

        return back()->with('success',
            'The plan was recorded and the finding moved into remediation. While it stays inside its SLA with '
            .'a plan, it counts at half weight in the residual score.'
        );
    }

    public function submitForVerification(Request $request, Finding $finding)
    {
        Gate::authorize('update', $finding);

        $validated = $request->validate(['evidence_document_id' => 'nullable|integer']);

        $result = $this->findings->submitForVerification(
            $finding,
            $validated['evidence_document_id'] ?? null,
            $request->user()->id,
        );

        return $result['moved']
            ? back()->with('success', 'The finding is with a verifier.')
            : back()->with('error', $result['reason']);
    }

    public function close(Request $request, Finding $finding)
    {
        Gate::authorize('update', $finding);

        $validated = $request->validate([
            'closure_type' => 'required|in:remediated,false_positive',
            'evidence_document_id' => 'nullable|integer',
        ]);

        $result = $this->findings->close(
            $finding,
            $validated['closure_type'],
            $request->user()->id,
            $validated['evidence_document_id'] ?? null,
            $request->user()->id,
        );

        return $result['closed']
            ? back()->with('success', 'The finding was closed and its mirrored issue closed with it.')
            : back()->with('error', $result['reason']);
    }

    /**
     * Accept the risk rather than remediating it — FR-FND-04.
     *
     * A refusal is a flash rather than a 403 where the reason is the period or
     * the justification, and a 403 where the user simply lacks the authority —
     * those are different conversations and a single response would blur them.
     */
    public function acceptRisk(Request $request, Finding $finding)
    {
        Gate::authorize('acceptRisk', $finding);

        $validated = $request->validate([
            'justification' => 'required|string|min:30|max:4000',
            'compensating_controls' => 'nullable|string|max:4000',
            'residual_impact' => 'nullable|string|max:4000',
            'approver_role' => 'nullable|string|max:120',
            'expires_at' => 'required|date|after:today',
        ]);

        $result = $this->acceptances->accept($finding, $request->user(), $validated);

        return $result['accepted']
            ? back()->with('success', sprintf(
                'The risk was accepted until %s. The finding still counts at half weight in the residual '
                .'score, and it reopens automatically when the acceptance expires.',
                $result['acceptance']?->expires_at?->toFormattedDateString(),
            ))
            : back()->withInput()->with('error', $result['reason']);
    }

    public function withdrawAcceptance(Request $request, Finding $finding, RiskAcceptance $acceptance)
    {
        Gate::authorize('acceptRisk', $finding);
        abort_unless($acceptance->finding_id === $finding->getKey(), 404);

        $validated = $request->validate(['reason' => 'required|string|min:10|max:2000']);

        $this->acceptances->withdraw($acceptance, $validated['reason'], $request->user()->id);

        return back()->with('success', 'The acceptance was withdrawn and the finding is open again.');
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function card(Finding $finding): array
    {
        return [
            'id' => $finding->getKey(),
            'uuid' => $finding->uuid,
            'reference' => $finding->reference,
            'title' => $finding->title,
            'severity' => $finding->severity->value,
            'severity_label' => $finding->severity->label(),
            'status' => $finding->status->value,
            'status_label' => $finding->status->label(),
            'engagement' => $finding->engagement?->reference,
            'engagement_name' => $finding->engagement?->name,
            'third_party' => $finding->thirdParty?->legal_name,
            'owner' => $finding->owner?->name,
            'identified_at' => $finding->identified_at?->toDateString(),
            'target_date' => $finding->target_date?->toDateString(),
            'days_until_target' => $finding->daysUntilTarget(),
            'sla_days' => $finding->sla_days,
            // The three the board colours by, computed once here so the card
            // and the score panel cannot disagree about the same finding.
            'is_overdue' => $finding->isOverdue(),
            'is_overdue_beyond' => $finding->isOverdueBeyond(),
            'within_sla_with_plan' => $finding->isWithinSlaWithAcceptedPlan(),
            'risk_accepted' => $finding->isRiskAccepted(),
            'acceptance_expires' => $finding->acceptance?->expires_at?->toDateString(),
            'escalation_level' => $this->findings->escalationLevelFor($finding),
            'url' => route('tprm.findings.show', $finding),
        ];
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        $base = fn () => Finding::query();

        return [
            'open' => $base()->open()->count(),
            'overdue' => $base()->overdue()->count(),
            'critical_open' => $base()->open()->where('severity', 'critical')->count(),
            'due_30' => $base()->dueWithin(30)->count(),
            'unowned' => $base()->open()->whereNull('owner_id')->count(),
            'risk_accepted' => $base()->where('status', FindingStatus::ClosedRiskAccepted->value)->count(),
        ];
    }

    /**
     * Critical first, in SQL, so paging does not reorder the board.
     *
     * Written per driver for the reason `Contract::noticeDateExpression()`
     * gives: `FIELD()` is MySQL-only and a CASE is not.
     */
    private function severityOrder(): string
    {
        return "CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END";
    }
}
