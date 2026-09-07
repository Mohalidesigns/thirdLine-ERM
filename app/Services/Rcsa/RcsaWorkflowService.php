<?php

namespace App\Services\Rcsa;

use App\Models\Organization;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaAssessmentTransition;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The state machine of §9.1, and the only thing allowed to move an assessment.
 *
 *   draft ──open──▶ in_progress ──submit──▶ [bu_approval ──approve──▶] submitted
 *                        ▲                                                 │
 *                        │                                            claim│
 *                        └────── returned ◀──return── under_review ◀───────┘
 *                                                          │
 *                                            validate──────┴──▶ validated ──▶ closed
 *
 * EVERY EDGE IS DECLARED, and a move that is not an edge is refused with the
 * state it was in rather than silently writing the status. The alternative —
 * each controller setting `status` for itself — is how an assessment ends up
 * validated without ever having been reviewed, and no test anywhere would have
 * noticed.
 *
 * THE LOG IS WRITTEN IN THE SAME TRANSACTION AS THE STATUS. §9.1 requires user,
 * timestamp, from-state, to-state and reason on every transition; writing the
 * row afterwards would mean a crash between the two leaves a status nothing
 * explains, which is exactly the row an auditor picks out.
 *
 * THE BU-HEAD STEP IS PER TENANT, and it is a setting rather than a column on
 * the methodology: the methodology is LOCKED once a cycle is open, so putting
 * a workflow policy there would mean a bank could not turn the approval step on
 * without versioning its scoring engine. It lives in
 * `organizations.settings['rcsa']['bu_approval_required']`, beside
 * `board_pack` and `mfa_required_roles`.
 */
class RcsaWorkflowService
{
    public function __construct(
        private readonly RcsaAuditRecorder $audit,
        private readonly RcsaTreatmentOverrideService $overrides,
    ) {}

    /**
     * The legal edges. from-state => list of to-states.
     *
     * `closed` is absent as a source: a closed cycle is final, and RcsaCycle
     * is what puts an assessment there.
     *
     * @var array<string, list<string>>
     */
    public const EDGES = [
        RcsaAssessment::DRAFT => [RcsaAssessment::IN_PROGRESS, RcsaAssessment::BU_APPROVAL, RcsaAssessment::SUBMITTED],
        RcsaAssessment::IN_PROGRESS => [RcsaAssessment::BU_APPROVAL, RcsaAssessment::SUBMITTED],
        RcsaAssessment::RETURNED => [RcsaAssessment::BU_APPROVAL, RcsaAssessment::SUBMITTED],
        RcsaAssessment::BU_APPROVAL => [RcsaAssessment::SUBMITTED, RcsaAssessment::RETURNED],
        RcsaAssessment::SUBMITTED => [RcsaAssessment::UNDER_REVIEW, RcsaAssessment::RETURNED, RcsaAssessment::VALIDATED],
        RcsaAssessment::UNDER_REVIEW => [RcsaAssessment::VALIDATED, RcsaAssessment::RETURNED, RcsaAssessment::UNDER_REVIEW],
        RcsaAssessment::VALIDATED => [RcsaAssessment::CLOSED],
    ];

    /**
     * Whether this tenant puts the business-unit head between the assessor and
     * the ORM (§9.1's optional step, and §14 Q6).
     */
    public function requiresBuApproval(RcsaAssessment|Organization|int|null $subject): bool
    {
        $organization = match (true) {
            $subject instanceof Organization => $subject,
            $subject instanceof RcsaAssessment => Organization::find($subject->organization_id),
            is_int($subject) => Organization::find($subject),
            default => null,
        };

        $settings = $organization?->settings;

        return (bool) (is_array($settings) ? ($settings['rcsa']['bu_approval_required'] ?? false) : false);
    }

    /**
     * The state a submission lands in for this tenant.
     */
    public function submissionTarget(RcsaAssessment $assessment): string
    {
        return $this->requiresBuApproval($assessment)
            ? RcsaAssessment::BU_APPROVAL
            : RcsaAssessment::SUBMITTED;
    }

    /* ------------------------------------------------------------------ */
    /*  The named transitions */
    /* ------------------------------------------------------------------ */

    /**
     * The BU head signs off what their unit filed, and it reaches the ORM.
     */
    public function approve(RcsaAssessment $assessment, User $actor, ?string $reason = null, ?Request $request = null): RcsaAssessment
    {
        return $this->transition(
            $assessment,
            RcsaAssessment::SUBMITTED,
            $actor,
            RcsaAssessmentTransition::APPROVE,
            $reason,
            $request,
        );
    }

    /**
     * A reviewer picks the assessment up.
     *
     * CLAIMING IS A TRANSITION, not a UI nicety. `submitted` means nobody has
     * it; `under_review` plus `reviewer_id` means this person does, which is
     * what stops two analysts challenging the same 200 lines in parallel. It
     * is idempotent for the holder and refused for anybody else — a second
     * reviewer taking over is the Head of ORM's reassignment, not a race.
     */
    public function claim(RcsaAssessment $assessment, User $actor, ?Request $request = null): RcsaAssessment
    {
        if ($assessment->status === RcsaAssessment::UNDER_REVIEW
            && $assessment->reviewer_id !== null
            && $assessment->reviewer_id !== $actor->id) {
            throw new RuntimeException('Somebody else is already reviewing this assessment.');
        }

        if ($assessment->status === RcsaAssessment::UNDER_REVIEW && $assessment->reviewer_id === $actor->id) {
            return $assessment;
        }

        return $this->transition(
            $assessment,
            RcsaAssessment::UNDER_REVIEW,
            $actor,
            RcsaAssessmentTransition::CLAIM,
            null,
            $request,
            ['reviewer_id' => $actor->id],
        );
    }

    /**
     * The ORM accepts the assessment.
     *
     * Validation clears the escalation flag: whatever the Head of ORM was
     * asked to look at has now been decided, and an assessment that stayed
     * marked escalated after its decision would sit at the top of the queue
     * for ever.
     */
    public function validate(RcsaAssessment $assessment, User $actor, ?string $reason = null, ?Request $request = null): RcsaAssessment
    {
        // §14 Q5. VALIDATION IS THE GATE, NOT SUBMISSION.
        //
        // Blocking the submission would deadlock: the approver holds
        // `rcsa_assessment.approve_override`, which the second line holds and
        // the business unit does not, so an assessor could not get their own
        // override decided in order to file. Blocking VALIDATION puts the
        // decision exactly where it belongs — in front of the reviewer who is
        // already going through the assessment line by line — and makes it
        // impossible to accept an assessment that still contains a departure
        // from the methodology nobody has agreed to.
        $undecided = $this->overrides->awaitingDecision($assessment);

        if ($undecided !== []) {
            throw new RuntimeException(sprintf(
                'This assessment cannot be validated while %d treatment override%s awaiting a decision: %s.',
                count($undecided),
                count($undecided) === 1 ? ' is' : 's are',
                implode(', ', array_column($undecided, 'risk_no')),
            ));
        }

        return $this->transition(
            $assessment,
            RcsaAssessment::VALIDATED,
            $actor,
            RcsaAssessmentTransition::VALIDATE,
            $reason,
            $request,
            [
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'escalated_at' => null,
                'escalated_by' => null,
                'escalation_reason' => null,
            ],
        );
    }

    /**
     * Send it back — and THIS IS P5'S ACCEPTANCE CRITERION.
     *
     * Only the lines the reviewer flagged or challenged are unlocked. The rest
     * keep `locked_at` and stay read-only, which is what makes a return an
     * instruction ("fix these four") rather than an invitation to re-open a
     * quarter's work. Enforced on the LINE, in RcsaAssessmentLine::acceptsEdits().
     *
     * A RETURN WITH NOTHING FLAGGED IS REFUSED. It would reopen nothing, and
     * the assessor would be handed back an assessment they cannot change — a
     * dead end that reads like a bug and is impossible to act on. The reviewer
     * is told to flag what they want changed.
     *
     * @return array{assessment: RcsaAssessment, reopened: int}
     */
    public function returnForRework(
        RcsaAssessment $assessment,
        User $actor,
        string $reason,
        ?Request $request = null,
        bool $requireFlags = true,
    ): array {
        $flagged = $assessment->lines()
            ->whereIn('orm_status', RcsaAssessmentLine::ORM_REOPENS)
            ->pluck('id');

        if ($requireFlags && $flagged->isEmpty()) {
            throw new RuntimeException(
                'Flag or challenge the risks you want changed first — returning with nothing flagged '
                .'would send the assessment back with every line still locked.'
            );
        }

        $updated = $this->transition(
            $assessment,
            RcsaAssessment::RETURNED,
            $actor,
            RcsaAssessmentTransition::RETURN,
            $reason,
            $request,
            [
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'returned_reason' => $reason,
                'escalated_at' => null,
                'escalated_by' => null,
                'escalation_reason' => null,
            ],
            function (RcsaAssessment $assessment) use ($flagged, $requireFlags) {
                $assessment->lines()
                    // A wholesale return — the BU head's, who has flagged
                    // nothing because they have no per-line challenge — reopens
                    // everything. The ORM's return never takes this branch.
                    ->when($requireFlags, fn ($q) => $q->whereIn('id', $flagged))
                    ->update(['locked_at' => null]);
            },
        );

        $this->notifyAssessor($updated, $actor, $reason, $flagged->count());

        return ['assessment' => $updated, 'reopened' => $requireFlags ? $flagged->count() : $updated->lines()->count()];
    }

    /**
     * Raise it to the Head of ORM without deciding it.
     *
     * NOT A STATE CHANGE. §9.1's machine has no escalated state and §9.2 lists
     * Escalate as a third action beside Validate and Return — an escalated
     * assessment is still under review, with somebody senior now watching. It
     * is a from == to row in the log, three columns on the assessment, and a
     * notification.
     */
    public function escalate(RcsaAssessment $assessment, User $actor, string $reason, ?Request $request = null): RcsaAssessment
    {
        if (! $assessment->acceptsReview()) {
            throw new RuntimeException('Only an assessment waiting on ORM review can be escalated.');
        }

        DB::transaction(function () use ($assessment, $actor, $reason, $request) {
            $assessment->forceFill([
                'escalated_at' => now(),
                'escalated_by' => $actor->id,
                'escalation_reason' => $reason,
            ])->save();

            $this->log($assessment, $actor, $assessment->status, $assessment->status, RcsaAssessmentTransition::ESCALATE, $reason, $request);
        });

        $this->notifyEscalation($assessment, $actor, $reason);

        return $assessment->refresh();
    }

    /* ------------------------------------------------------------------ */
    /*  The core */
    /* ------------------------------------------------------------------ */

    /**
     * Move an assessment, logging the move.
     *
     * @param  array<string, mixed>  $attributes  Written alongside the status, in the same transaction.
     * @param  (callable(RcsaAssessment): void)|null  $inside  Runs in the transaction, after the save.
     */
    public function transition(
        RcsaAssessment $assessment,
        string $to,
        ?User $actor,
        ?string $event = null,
        ?string $reason = null,
        ?Request $request = null,
        array $attributes = [],
        ?callable $inside = null,
    ): RcsaAssessment {
        $from = (string) $assessment->status;

        if (! in_array($to, self::EDGES[$from] ?? [], true)) {
            throw new RuntimeException(sprintf(
                'An assessment that is %s cannot become %s.',
                str_replace('_', ' ', $from),
                str_replace('_', ' ', $to),
            ));
        }

        DB::transaction(function () use ($assessment, $to, $from, $actor, $event, $reason, $request, $attributes, $inside) {
            $assessment->forceFill(['status' => $to] + $attributes)->save();

            $this->log($assessment, $actor, $from, $to, $event, $reason, $request);

            if ($inside !== null) {
                $inside($assessment);
            }
        });

        return $assessment->refresh();
    }

    /**
     * The history of an assessment, oldest first, for the review screen.
     *
     * @return list<array<string, mixed>>
     */
    public function history(RcsaAssessment $assessment): array
    {
        return $assessment->transitions()
            ->with('user:id,name')
            ->get()
            ->sortBy('created_at')
            ->values()
            ->map(fn (RcsaAssessmentTransition $row) => [
                'id' => $row->id,
                'from' => $row->from_status,
                'to' => $row->to_status,
                'event' => $row->event,
                'reason' => $row->reason,
                'by' => $row->getRelationValue('user')?->name,
                'at' => $row->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    private function log(
        RcsaAssessment $assessment,
        ?User $actor,
        ?string $from,
        string $to,
        ?string $event,
        ?string $reason,
        ?Request $request,
    ): void {
        $this->audit->transition($assessment, $from, $to, $actor, $reason);

        RcsaAssessmentTransition::create([
            'organization_id' => $assessment->organization_id,
            'assessment_id' => $assessment->id,
            'user_id' => $actor?->id,
            'from_status' => $from,
            'to_status' => $to,
            'event' => $event,
            'reason' => $reason,
            'request_id' => $request?->header('X-Request-Id'),
            'ip_address' => $request?->ip(),
            'created_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Telling people */
    /* ------------------------------------------------------------------ */

    /**
     * A returned assessment is useless if the assessor is not told.
     *
     * The recipients are whoever filed it and whoever it is assigned to, which
     * are usually but not always the same person.
     */
    private function notifyAssessor(RcsaAssessment $assessment, User $actor, string $reason, int $reopened): void
    {
        $assessment->loadMissing(['cycle', 'businessUnit']);

        $recipients = array_values(array_unique(array_filter([
            $assessment->submitted_by,
            $assessment->assigned_to,
        ], fn ($id) => $id !== null && $id !== $actor->id)));

        if ($recipients === []) {
            return;
        }

        NotificationService::sendMany(
            organizationId: (int) $assessment->organization_id,
            userIds: array_map('intval', $recipients),
            type: 'rcsa.assessment.returned',
            subject: sprintf('%s was returned for rework', $this->unitName($assessment)),
            body: sprintf(
                '%s returned the %s assessment for %s. %d risk%s reopened for you to change; the rest stay locked. Reason: %s',
                $actor->name,
                $this->cycleName($assessment),
                $this->unitName($assessment),
                $reopened,
                $reopened === 1 ? ' is' : 's are',
                $reason,
            ),
            metadata: ['assessment_id' => $assessment->id, 'cycle_id' => $assessment->cycle_id],
            actionUrl: route('rcsa.assessments.show', $assessment, absolute: false),
            priority: 'high',
            category: 'workflow',
        );
    }

    private function notifyEscalation(RcsaAssessment $assessment, User $actor, string $reason): void
    {
        $assessment->loadMissing(['cycle', 'businessUnit']);

        // Whoever may validate — the Head of ORM in every role map this
        // product ships. Deliberately over-broad rather than under: an
        // escalation nobody receives is worse than one two people receive.
        $recipients = User::query()
            ->where('organization_id', $assessment->organization_id)
            ->where('is_active', true)
            ->whereKeyNot($actor->id)
            ->get()
            ->filter(fn (User $user) => $user->can('rcsa_assessment.validate'))
            ->pluck('id')
            ->all();

        if ($recipients === []) {
            return;
        }

        NotificationService::sendMany(
            organizationId: (int) $assessment->organization_id,
            userIds: array_map('intval', $recipients),
            type: 'rcsa.assessment.escalated',
            subject: sprintf('%s escalated the %s RCSA', $actor->name, $this->unitName($assessment)),
            body: sprintf('%s (%s) has been escalated: %s', $this->unitName($assessment), $this->cycleName($assessment), $reason),
            metadata: ['assessment_id' => $assessment->id, 'cycle_id' => $assessment->cycle_id],
            actionUrl: route('rcsa.review.show', $assessment, absolute: false),
            priority: 'high',
            category: 'workflow',
        );
    }

    private function unitName(RcsaAssessment $assessment): string
    {
        $unit = $assessment->getRelationValue('businessUnit');

        return $unit === null ? 'A business unit' : (string) $unit->name;
    }

    private function cycleName(RcsaAssessment $assessment): string
    {
        $cycle = $assessment->getRelationValue('cycle');

        return $cycle === null ? 'RCSA' : (string) $cycle->name;
    }
}
