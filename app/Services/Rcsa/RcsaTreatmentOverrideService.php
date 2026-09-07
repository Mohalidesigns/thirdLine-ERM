<?php

namespace App\Services\Rcsa;

use App\Models\Organization;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * §14 Q5 — the second signature on a treatment override.
 *
 * P3 built the override and made its justification mandatory. What it did not
 * build is anyone having to agree: an assessor could turn TREAT into ACCEPT on
 * a risk above appetite, write a sentence, and file it. The justification was
 * recorded; the departure from the methodology was nobody's decision but the
 * person departing from it.
 *
 * OFF BY DEFAULT, per tenant, in
 * `organizations.settings['rcsa']['treatment_override_approval_required']`.
 * With the setting off this service does nothing at all — `request()` returns
 * without writing, every override stays `none`, and the module behaves exactly
 * as it did before Q5 was answered. That is deliberate: Q5 is the bank's
 * question, and shipping the approval as the new default would be answering it
 * for them.
 */
class RcsaTreatmentOverrideService
{
    public function __construct(private readonly RcsaAuditRecorder $audit) {}

    /**
     * Whether this tenant requires an override to be signed off.
     *
     * Reads the ORGANISATION, not the methodology, for the reason P5 gave about
     * the BU-approval step: the methodology locks when a cycle opens, so a
     * workflow policy stored there could not be turned on without versioning
     * the scoring engine.
     */
    public function requiresApproval(RcsaAssessment|RcsaAssessmentLine|Organization|int|null $subject): bool
    {
        $organization = match (true) {
            $subject instanceof Organization => $subject,
            $subject instanceof RcsaAssessment,
            $subject instanceof RcsaAssessmentLine => Organization::find($subject->organization_id),
            is_int($subject) => Organization::find($subject),
            default => null,
        };

        $settings = $organization?->settings;

        return (bool) (is_array($settings)
            ? ($settings['rcsa']['treatment_override_approval_required'] ?? false)
            : false);
    }

    /**
     * Put a newly written override in front of an approver.
     *
     * Called from the save path, not from a controller, so that the bulk apply,
     * the offline round trip and any future importer all reach it. A Form
     * Request would cover one of the four.
     *
     * RE-REQUESTING RESETS AN EARLIER DECISION. An assessor who changes their
     * override after it was approved is asking a new question — the approver
     * agreed to ACCEPT, not to "whatever this assessor decides next" — so the
     * decision, its author and its note are cleared with the status. The same
     * applies to a rejected override that has been changed: it goes back into
     * the queue rather than staying refused for ever.
     */
    public function request(RcsaAssessmentLine $line, ?User $actor): void
    {
        if (! $this->requiresApproval($line)) {
            return;
        }

        if (blank($line->treatment_override)) {
            return;
        }

        $line->forceFill([
            'treatment_override_status' => RcsaAssessmentLine::OVERRIDE_PENDING,
            'treatment_override_requested_by' => $actor?->id,
            'treatment_override_requested_at' => now(),
            'treatment_override_decided_by' => null,
            'treatment_override_decided_at' => null,
            'treatment_override_decision_note' => null,
        ])->save();

        $this->audit->lineChange(
            $line,
            'treatment_override_status',
            RcsaAssessmentLine::OVERRIDE_NONE,
            RcsaAssessmentLine::OVERRIDE_PENDING,
            $actor,
            $line->treatment_override_reason,
        );
    }

    /**
     * An override that is no longer there is not awaiting anything.
     *
     * Clearing the override field has to clear the request with it, or the
     * assessment sits blocked on a decision about a departure nobody is asking
     * for any more.
     */
    public function withdraw(RcsaAssessmentLine $line, ?User $actor): void
    {
        if ($line->treatment_override_status === RcsaAssessmentLine::OVERRIDE_NONE) {
            return;
        }

        $previous = (string) $line->treatment_override_status;

        $line->forceFill([
            'treatment_override_status' => RcsaAssessmentLine::OVERRIDE_NONE,
            'treatment_override_requested_by' => null,
            'treatment_override_requested_at' => null,
            'treatment_override_decided_by' => null,
            'treatment_override_decided_at' => null,
            'treatment_override_decision_note' => null,
        ])->save();

        $this->audit->lineChange(
            $line,
            'treatment_override_status',
            $previous,
            RcsaAssessmentLine::OVERRIDE_NONE,
            $actor,
            'The override was removed.',
        );
    }

    /** The approver agrees: the override takes effect from here. */
    public function approve(RcsaAssessmentLine $line, User $actor, ?string $note = null): RcsaAssessmentLine
    {
        return $this->decide($line, RcsaAssessmentLine::OVERRIDE_APPROVED, $actor, $note);
    }

    /**
     * The approver refuses, and must say why.
     *
     * The note is mandatory here and optional on approval, because an assessor
     * told only "no" cannot act on it — they do not know whether to argue, to
     * re-score, or to write a plan. It is enforced in the Form Request too; the
     * check is repeated here so the bulk path and any future API cannot skip it.
     */
    public function reject(RcsaAssessmentLine $line, User $actor, string $note): RcsaAssessmentLine
    {
        if (trim($note) === '') {
            throw new \InvalidArgumentException('A rejected override needs a reason the assessor can act on.');
        }

        return $this->decide($line, RcsaAssessmentLine::OVERRIDE_REJECTED, $actor, $note);
    }

    /**
     * Lines still waiting on a decision, for the review screen's summary.
     *
     * @return list<array{line_id: int, risk_no: string, from: string|null, to: string|null, reason: string|null}>
     */
    public function awaitingDecision(RcsaAssessment $assessment): array
    {
        return $assessment->lines()
            ->where('treatment_override_status', RcsaAssessmentLine::OVERRIDE_PENDING)
            ->get()
            ->map(fn (RcsaAssessmentLine $line) => [
                'line_id' => (int) $line->id,
                'risk_no' => (string) $line->risk_no,
                'from' => $line->risk_treatment,
                'to' => $line->treatment_override,
                'reason' => $line->treatment_override_reason,
            ])
            ->values()
            ->all();
    }

    private function decide(RcsaAssessmentLine $line, string $status, User $actor, ?string $note): RcsaAssessmentLine
    {
        if (! $line->overrideAwaitingDecision()) {
            throw new \RuntimeException('That override is not awaiting a decision.');
        }

        // THE REQUESTER MAY NOT DECIDE THEIR OWN OVERRIDE. The same rule
        // RcsaAssessmentPolicy::review() applies to the submitter, and for the
        // same reason: a second signature that can be the first one's is not a
        // second signature. Checked here rather than only in the policy so that
        // it holds on every path into this service.
        if ((int) $line->treatment_override_requested_by === (int) $actor->id) {
            throw new \RuntimeException('An override cannot be approved by the person who requested it.');
        }

        $previous = (string) $line->treatment_override_status;

        DB::transaction(function () use ($line, $status, $actor, $note) {
            $line->forceFill([
                'treatment_override_status' => $status,
                'treatment_override_decided_by' => $actor->id,
                'treatment_override_decided_at' => now(),
                'treatment_override_decision_note' => $note,
            ])->save();
        });

        $this->audit->lineChange($line, 'treatment_override_status', $previous, $status, $actor, $note);

        return $line->refresh();
    }
}
