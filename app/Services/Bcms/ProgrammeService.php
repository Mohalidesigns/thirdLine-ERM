<?php

namespace App\Services\Bcms;

use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Aar;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\ClauseRef;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\DrTest;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Incident;
use App\Models\Bcms\IncidentNotification;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\ProgrammeObligation;
use App\Models\Bcms\ProgrammeScopeItem;
use App\Models\BusinessUnit;
use App\Services\Bcms\CallTrees\TreeHealthService;
use App\Services\Bcms\Incidents\NotificationService;
use App\Services\Bcms\Suppliers\SupplierResilienceService;
use App\Services\ReferenceCodeService;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The programme, its scope, its obligation register and its management reviews
 * — ISO 22301 clauses 4, 6.1 and 9.3.
 *
 * SCOPE IS A SET, NOT A PARAGRAPH. `scope_statement` is what the policy prints;
 * `bcms_programme_scope` is what the BIA, the calendar and the evidence pack
 * query. An exclusion is a **row with a rationale** (clause 4.3 requires the
 * boundary to be justified), because silence is indistinguishable from nobody
 * having considered it.
 *
 * THE OBLIGATION REGISTER IS AN APPLICABILITY DECISION, NOT A LIBRARY.
 * `bcms_clause_refs` ships what exists in the world; this records which of those
 * bind THIS institution, who owns each and what cadence it drives. A bank with
 * no open-banking licence marks those three not-applicable with a reason, and
 * the evidence pack stops demanding quarterly failover evidence it will never
 * have.
 *
 * MANAGEMENT REVIEW INPUTS ARE SNAPSHOTTED AT CAPTURE. A review held in March
 * considered March's CAPA status. Re-deriving it for a reader in December would
 * rewrite what the meeting looked at, which is the one thing the record exists
 * to preserve.
 */
class ProgrammeService
{
    public function __construct(
        private MaturityService $maturity,
        private TreeHealthService $callTreeHealth,
        private SupplierResilienceService $supplierResilience,
        private NotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?int $userId = null): Programme
    {
        return Programme::query()->create(array_merge([
            'status' => 'draft',
            'iso_clause_ref' => IsoClauseRef::Iso22301_4_3->value,
            'created_by' => $userId ?? auth()->id(),
        ], $attributes));
    }

    /**
     * Approve the programme.
     *
     * The approver is named and is not the owner, for the reason clause 5.2
     * gives about the policy: a programme signed off by the person who wrote it
     * has had no oversight.
     */
    public function approve(Programme $programme, int $approverId): Programme
    {
        if ($programme->status === 'approved' || $programme->status === 'active') {
            throw new InvalidArgumentException('This programme is already approved.');
        }

        if ($approverId === (int) $programme->owner_id) {
            throw new InvalidArgumentException('A programme must be approved by somebody other than its owner.');
        }

        $programme->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approved_at' => now(),
            'updated_by' => $approverId,
        ]);

        return $programme->refresh();
    }

    public function activate(Programme $programme, ?int $userId = null): Programme
    {
        if ($programme->status !== 'approved') {
            throw new InvalidArgumentException('Only an approved programme can be activated.');
        }

        $programme->update(['status' => 'active', 'updated_by' => $userId ?? auth()->id()]);

        return $programme->refresh();
    }

    /* ------------------------------------------------------------------ */
    /*  Scope */
    /* ------------------------------------------------------------------ */

    public function setScope(Programme $programme, Model $scopable, bool $inScope, ?string $rationale = null): ProgrammeScopeItem
    {
        if (! $scopable instanceof BusinessUnit && ! $scopable instanceof Process) {
            throw new InvalidArgumentException('A programme scope item is a business unit or a BCMS process.');
        }

        if (! $inScope && blank($rationale)) {
            throw new InvalidArgumentException(
                'An exclusion from the BCMS scope must be justified (ISO 22301 clause 4.3).'
            );
        }

        return ProgrammeScopeItem::query()->updateOrCreate(
            [
                'programme_id' => $programme->getKey(),
                'scopable_type' => $scopable->getMorphClass(),
                'scopable_id' => $scopable->getKey(),
            ],
            ['in_scope' => $inScope, 'rationale' => $rationale, 'created_by' => auth()->id()]
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Obligations */
    /* ------------------------------------------------------------------ */

    /**
     * Seed the tenant's obligation register from the shipped clause library.
     *
     * IDEMPOTENT, and it never overwrites an applicability decision somebody has
     * already made. A re-run after the library grows adds the new obligations
     * and leaves the twelve a compliance officer has already marked
     * not-applicable exactly as they left them.
     */
    public function seedObligations(Programme $programme): int
    {
        $existing = ProgrammeObligation::query()
            ->where('programme_id', $programme->getKey())
            ->pluck('clause_ref')
            ->all();

        $added = 0;

        // Only the regulator-facing refs. Seeding all 52 would put ISO clause
        // 4.1 in a register whose purpose is "which rules bind us", and a
        // register of everything is a register nobody reads.
        $refs = ClauseRef::query()
            ->whereIn('standard', ['CBN', 'BOFIA 2020 / NDIC', 'NDPA 2023'])
            ->orderBy('sort_order')
            ->get();

        foreach ($refs as $ref) {
            if (in_array($ref->code, $existing, true)) {
                continue;
            }

            ProgrammeObligation::query()->create([
                'programme_id' => $programme->getKey(),
                'clause_ref' => $ref->code,
                // TRUE BY DEFAULT, and that is the safe direction: an
                // obligation wrongly marked applicable produces an evidence
                // request somebody dismisses; one wrongly marked not applicable
                // produces silence until an examination.
                'applies' => true,
                'cadence' => $this->cadenceFor($ref->code),
                'cadence_per_year' => $this->cadencePerYearFor($ref->code),
                'created_by' => auth()->id(),
            ]);

            $added++;
        }

        return $added;
    }

    private function cadenceFor(string $code): ?string
    {
        return match ($code) {
            IsoClauseRef::Cbn_ob_failover->value => 'Quarterly failover exercise',
            IsoClauseRef::Cbn_ob_dr_test->value => 'Disaster recovery test every six months',
            IsoClauseRef::Cbn_rcf_csat->value => 'Annual self-assessment',
            IsoClauseRef::Cbn_rcf_drills->value => 'At least annually, plus industry exercises',
            IsoClauseRef::Cbn_cg_board->value => 'Board reporting at the board cycle',
            default => null,
        };
    }

    private function cadencePerYearFor(string $code): ?int
    {
        // Only where a named rule states the number. Inventing one for the
        // qualitative obligations would put our opinion in a compliance file.
        return match ($code) {
            IsoClauseRef::Cbn_ob_failover->value => 4,
            IsoClauseRef::Cbn_ob_dr_test->value => 2,
            IsoClauseRef::Cbn_rcf_csat->value => 1,
            default => null,
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Management review — clause 9.3 */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $attributes */
    public function openManagementReview(Programme $programme, string $title, array $attributes = [], ?int $userId = null): ManagementReview
    {
        return ManagementReview::query()->create(array_merge([
            'programme_id' => $programme->getKey(),
            'reference' => ReferenceCodeService::generate('bcms_management_reviews', 'reference', 'BCMR'),
            'title' => $title,
            'held_on' => now()->toDateString(),
            'status' => 'draft',
            'iso_clause_ref' => IsoClauseRef::Iso22301_9_3_results->value,
            'created_by' => $userId ?? auth()->id(),
        ], $attributes));
    }

    /**
     * Capture the clause 9.3 inputs as they stand right now.
     *
     * A SNAPSHOT, and the timestamp beside it is what makes it one. The
     * alternative — a screen that re-queries on every open — shows a reader in
     * December a meeting that considered December's numbers, which is not what
     * happened.
     *
     * EXTENDED ONCE, PHASE 11 (phase-11-spec §2.4, ADR 0021 §1/§4). The
     * original four blocks (maturity, findings, corrective actions, exercises,
     * plans) are joined by everything the later phases made available:
     * previous-review actions, internal audit, incidents, exercise evaluation
     * outputs, call-tree/EMNS performance, DR achievement, supplier
     * continuity, interested-party feedback, BIA/risk changes, context
     * changes and improvement opportunities — in one change, because a
     * snapshot extended twice produces two shapes of stored json the pack
     * then has to tolerate for ever.
     *
     * `$manual` carries the two sections nothing in this system computes:
     * `internal_audit` (report reference, date, auditor, independence
     * statement, conclusion — ADR 0021 §1, no audit-programme table exists to
     * read this from) and `interested_party_feedback` (free text — no
     * feedback register exists, phase-11-spec §2.4). Passing nothing PRESERVES
     * whatever was already captured for this review, so a re-capture that
     * refreshes the computed sections does not blow away a manually entered
     * audit block along the way.
     *
     * A1: THE APPROVED-REVIEW LOCK LIVES HERE, NOT ONLY IN THE CONTROLLER.
     * `ProgrammeController::captureReviewInputs()` already refused an
     * approved review, but that left the service itself, and every other
     * caller of it, free to rewrite a minuted, signed-off record's 9.2
     * block. B12 rejected the same rewrite through the HTTP path; this is
     * the same rule enforced at the one place every caller actually goes
     * through.
     *
     * @param  array{internal_audit?: array<string, mixed>, interested_party_feedback?: ?string, context_changes?: ?string}  $manual
     */
    public function captureReviewInputs(ManagementReview $review, array $manual = []): ManagementReview
    {
        if ($review->status === 'approved') {
            throw new InvalidArgumentException(
                'This management review is already approved. Its inputs are locked — open a new review to record later audit results.'
            );
        }

        $maturity = $this->maturity->latest();
        $existing = $review->inputs ?? [];

        $internalAudit = $manual['internal_audit'] ?? ($existing['internal_audit'] ?? null);
        $interestedPartyFeedback = array_key_exists('interested_party_feedback', $manual)
            ? $manual['interested_party_feedback']
            : ($existing['interested_party_feedback'] ?? null);
        $contextChangesNote = array_key_exists('context_changes', $manual)
            ? $manual['context_changes']
            : ($existing['context_changes']['note'] ?? null);

        // Eloquent, not DB::table(), for every one of these. `Finding`,
        // `CorrectiveAction`, `ExerciseOccurrence` and `Plan` all carry
        // `BelongsToOrganization`'s global scope and `SoftDeletes`;
        // `DB::table()` passes through neither, in a request context or out
        // of one. That combination is what let one tenant's minuted clause
        // 9.3 record be computed off every other tenant's rows — the
        // `whereNull('deleted_at')` calls this replaced existed only because
        // `DB::table()` also bypassed soft-deletes, which is the tell that
        // the wrong tool was reached for in the first place.
        // A7: `toDateString()` truncated `$yearEnd` to a bare date
        // ('2026-12-31'), which `whereBetween` on a DATETIME column reads as
        // midnight — every incident, alert, DR test etc. on 31 December
        // after 00:00:00 fell outside "this year" for every review captured
        // that day. Full datetime bounds are safe against a DATE-cast
        // column too (`scheduled_date`, `test_date`): MariaDB widens the
        // DATE column to midnight for the comparison, so a date of
        // 2026-12-31 is still `<=` a bound of `2026-12-31 23:59:59`.
        // A7: `toDateString()` truncated `$yearEnd` to a bare date
        // ('2026-12-31'), which `whereBetween` on a DATETIME column reads as
        // midnight — every incident, alert, DR test etc. on 31 December
        // after 00:00:00 fell outside "this year" for every review captured
        // that day. Full datetime bounds are safe against a DATE-cast
        // column too (`scheduled_date`, `test_date`): MariaDB widens the
        // DATE column to midnight for the comparison, so a date of
        // 2026-12-31 is still `<=` a bound of `2026-12-31 23:59:59`.
        $yearStart = now()->startOfYear()->toDateTimeString();
        $yearEnd = now()->endOfYear()->toDateTimeString();

        $review->update([
            'inputs' => [
                'captured_at' => now()->toIso8601String(),
                'maturity' => $maturity === null ? null : [
                    'assessed_at' => $maturity->assessed_at?->toIso8601String(),
                    'overall_score' => $maturity->overall_score,
                    'method_version' => $maturity->method_version,
                ],
                'findings' => [
                    'open' => Finding::query()->where('status', 'open')->count(),
                    'nonconformities_open' => Finding::query()
                        ->where('status', 'open')
                        ->where('classification', FindingClassification::Nonconformity->value)
                        ->count(),
                ],
                'corrective_actions' => [
                    'open' => CorrectiveAction::query()->whereIn('status', ['open', 'in_progress'])->count(),
                    'overdue' => CorrectiveAction::query()->where('status', 'overdue')->count(),
                    'verified' => CorrectiveAction::query()->where('status', 'verified')->count(),
                ],
                'exercises' => [
                    // A closed date range rather than `whereYear()`: the
                    // column is already `date`-cast, and wrapping it in a
                    // function on every row rules out the index on it
                    // (development standard's MariaDB note).
                    'planned' => ExerciseOccurrence::query()->whereBetween('scheduled_date', [$yearStart, $yearEnd])->count(),
                    'completed' => ExerciseOccurrence::query()->whereBetween('scheduled_date', [$yearStart, $yearEnd])->where('status', 'completed')->count(),
                    'missed' => ExerciseOccurrence::query()->whereBetween('scheduled_date', [$yearStart, $yearEnd])->whereIn('status', ['missed', 'cancelled'])->count(),
                ],
                'plans' => [
                    'approved' => Plan::query()->where('status', 'approved')->count(),
                    'review_overdue' => Plan::query()->where('status', 'approved')
                        ->whereNotNull('next_review_date')->where('next_review_date', '<', now()->toDateString())->count(),
                ],

                /* -------------------------------------------------------- */
                /*  Phase 11 additions — phase-11-spec §2.4, ADR 0021 §1/§4 */
                /* -------------------------------------------------------- */

                // The one section on this page that is a FORM, not a
                // computed read — no audit-programme table exists to compute
                // it from (ADR 0021 §1). This is also what
                // `compliance-evidence-matrix.md`'s 9.2 amber state reads.
                'internal_audit' => $internalAudit,

                'previous_review_actions' => $this->previousReviewActions($review),

                'incidents' => $this->incidentReportingOutcomes($yearStart, $yearEnd),

                'exercise_evaluation_outputs' => [
                    'aars_final' => Aar::query()->where('status', 'final')
                        ->whereHas('occurrence', fn ($q) => $q->whereBetween('scheduled_date', [$yearStart, $yearEnd]))
                        ->count(),
                    'quantitative_misses' => \App\Models\Bcms\ExerciseScore::query()
                        ->whereNotNull('score')->where('score', '<', 3)->count(),
                ],

                'call_tree_and_emns_performance' => $this->callTreeAndEmnsPerformance($yearStart, $yearEnd),

                'dr_achievement' => [
                    'tests_in_period' => DrTest::query()->whereBetween('test_date', [$yearStart, $yearEnd])->count(),
                    'met_objectives' => DrTest::query()->whereBetween('test_date', [$yearStart, $yearEnd])->where('met_objectives', true)->count(),
                ],

                'supplier_continuity' => [
                    'chase_list_count' => count($this->supplierResilience->chaseList()),
                ],

                // Free text only. There is no interested-party feedback
                // register, and inventing one is out of scope
                // (phase-11-spec §2.4's own note).
                'interested_party_feedback' => $interestedPartyFeedback,

                'bia_and_risk_changes' => [
                    'bias_approved_this_year' => \App\Models\Bcms\BiaAssessment::query()
                        ->where('status', 'approved')
                        ->whereBetween('approved_at', [$yearStart, $yearEnd])
                        ->count(),
                ],

                'context_changes' => ['note' => $contextChangesNote],

                'improvement_opportunities' => [
                    'open' => Finding::query()->where('classification', FindingClassification::Improvement->value)
                        ->where('status', 'open')->count(),
                ],
            ],
            'inputs_captured_at' => now(),
        ]);

        return $review->refresh();
    }

    /**
     * The status of actions raised from the previous approved review —
     * clause 9.3.2's first input.
     *
     * @return array<string, mixed>
     */
    private function previousReviewActions(ManagementReview $review): array
    {
        $previous = ManagementReview::query()
            ->where('status', 'approved')
            ->where('id', '!=', $review->getKey())
            ->where(fn ($q) => $q->where('held_on', '<', $review->held_on)
                ->orWhere(fn ($q2) => $q2->where('held_on', $review->held_on)->where('id', '<', $review->getKey())))
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            return ['previous_review_id' => null, 'note' => 'No earlier approved management review exists.'];
        }

        $findingIds = Finding::query()
            ->where('source', FindingSource::ManagementReview->value)
            ->where('management_review_id', $previous->getKey())
            ->pluck('id');

        $actions = CorrectiveAction::query()->whereIn('finding_id', $findingIds)->get();

        return [
            'previous_review_id' => $previous->getKey(),
            'previous_review_title' => $previous->title,
            'previous_review_held_on' => $previous->held_on?->toDateString(),
            'actions_raised' => $actions->count(),
            'actions_closed' => $actions->whereNotNull('completed_at')->count(),
            'actions_open' => $actions->whereNull('completed_at')->count(),
        ];
    }

    /**
     * Incident counts and their regulatory-notification outcome for the
     * review's period — clause 9.3.2's incident input.
     *
     * ADR 0020 §2: `bcms_incidents.reporting_due_at`/`regulator_notified_at`/
     * `cbn_reference` are retired in place and never read here.
     * `bcms_incident_notifications` (one row per regulator obligation) is
     * where "was this notified on time" now lives — `App\Services\Bcms\
     * Incidents\NotificationService::overdueQuery()` is the one place that
     * classifies "never submitted, now overdue", reused rather than
     * re-implemented; a submission recorded AFTER its own `due_at` is a
     * second late case `overdueQuery()` does not cover (it has already been
     * submitted, so it is no longer "open"), added alongside it.
     *
     * @return array<string, mixed>
     */
    private function incidentReportingOutcomes(string $yearStart, string $yearEnd): array
    {
        $reportableIds = Incident::query()
            ->whereBetween('detected_at', [$yearStart, $yearEnd])
            ->where('is_reportable', true)
            ->pluck('id');

        $notifiedWithinDue = IncidentNotification::query()
            ->whereIn('incident_id', $reportableIds)
            ->whereNotNull('submitted_at')
            ->whereNotNull('due_at')
            ->whereColumn('submitted_at', '<=', 'due_at')
            ->count();

        $missing = $reportableIds->isEmpty() ? 0 : $this->notifications->overdueQuery()
            ->whereIn('incident_id', $reportableIds)
            ->count();

        $late = IncidentNotification::query()
            ->whereIn('incident_id', $reportableIds)
            ->whereNotNull('submitted_at')
            ->whereNotNull('due_at')
            ->whereColumn('submitted_at', '>', 'due_at')
            ->count();

        return [
            'count' => Incident::query()->whereBetween('detected_at', [$yearStart, $yearEnd])->count(),
            'by_severity' => Incident::query()
                ->whereBetween('detected_at', [$yearStart, $yearEnd])
                ->whereNotNull('severity')
                ->selectRaw('severity, count(*) as total')
                ->groupBy('severity')
                ->pluck('total', 'severity')
                ->all(),
            'notified_within_due' => $notifiedWithinDue,
            'notified_late_or_missing' => $missing + $late,
        ];
    }

    /**
     * Call-tree and EMNS performance — phase-11-spec §2.4. The call-tree
     * figures are `TreeHealthService`'s own, read here rather than
     * recomputed, so this snapshot and the call-tree dashboard cannot show
     * two different numbers for the same fact.
     *
     * B6: the acknowledgement window is measured from dispatch, absolute —
     * under Carbon 3, `$earlier->diffInMinutes($later)` is SIGNED by
     * default (negative when `$earlier` is actually later), so a recipient
     * who acknowledged two hours after dispatch was previously counted as
     * "within 15 minutes" (a -120 minute diff passing `<= 15`). Scoped to
     * the review period and read with a cursor rather than loading every
     * alert recipient the tenant has ever had.
     *
     * @return array<string, mixed>
     */
    private function callTreeAndEmnsPerformance(string $yearStart, string $yearEnd): array
    {
        $callTreeKris = collect($this->callTreeHealth->kris())->keyBy('code');

        $alerts = Alert::query()
            ->whereNotNull('dispatched_at')
            ->whereBetween('dispatched_at', [$yearStart, $yearEnd])
            ->get(['id', 'dispatched_at'])
            ->keyBy('id');

        $measured = 0;
        $within = 0;

        if ($alerts->isNotEmpty()) {
            AlertRecipient::query()
                ->whereIn('alert_id', $alerts->keys())
                ->select(['alert_id', 'acknowledged_at'])
                ->cursor()
                ->each(function (AlertRecipient $recipient) use ($alerts, &$measured, &$within) {
                    $measured++;

                    $alert = $alerts->get($recipient->alert_id);

                    if ($recipient->acknowledged_at !== null && $alert?->dispatched_at !== null
                        && $alert->dispatched_at->diffInMinutes($recipient->acknowledged_at, absolute: true) <= 15) {
                        $within++;
                    }
                });
        }

        $ackWithin15 = $measured > 0 ? round($within / $measured * 100, 1) : null;

        return [
            'call_tree_completion_rate' => $callTreeKris->get('BCMS-CT-COMPLETION')['value'] ?? null,
            'call_tree_confidence' => $callTreeKris->get('BCMS-CT-CONFIDENCE')['value'] ?? null,
            'emns_ack_within_15_minutes_rate' => $ackWithin15,
            'emns_recipients_measured' => $measured,
        ];
    }

    public function approveManagementReview(ManagementReview $review, int $approverId): ManagementReview
    {
        if ($review->inputs_captured_at === null) {
            throw new InvalidArgumentException(
                'A management review cannot be approved before its clause 9.3 inputs have been captured — '
                .'the record has to show what the meeting actually considered.'
            );
        }

        $review->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approved_at' => now(),
            'updated_by' => $approverId,
        ]);

        return $review->refresh();
    }
}
