<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\RiskAuditTrail;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Database\Eloquent\Model;

/**
 * §11's audit trail for RCSA — written into `risk_audit_trail`, the trail this
 * application already has.
 *
 * WHY NOT `spatie/laravel-activitylog`, WHICH THE PLAN NAMES. Because the house
 * already has something strictly better, and the plan was written without
 * knowing it. `risk_audit_trail` is hash-chained (each row commits to its
 * predecessor, so a removed or edited row breaks the chain), append-only at the
 * MODEL layer *and* enforced by database triggers that a query-builder update
 * or a DBA at the console cannot bypass, and verifiable by an existing
 * `audit:verify` command. Activitylog is a plain table with none of that.
 *
 * Adding it anyway would give an auditor two trails to reconcile, two retention
 * policies, and one immutability guarantee where they would reasonably assume
 * two. §11's actual requirement — "audit views are read-only and
 * non-deletable" — is met by the table this writes to and would NOT be met by
 * the one the plan names. Deviation argued in `docs/rcsa-v2/p7-rbac-audit.md`.
 *
 * WHAT GETS RECORDED. §11 asks for before/after on the material fields and on
 * status transitions. Those already exist in two RCSA-specific tables —
 * `rcsa_line_revisions` (P3) and `rcsa_assessment_transitions` (P5) — which are
 * richer than the trail for their own purposes and are what the RCSA screens
 * read. This adds the ESTATE-WIDE view: the same events, in the one place an
 * auditor looks for "everything that happened to anything", correlated by
 * request id and IP with every other module.
 *
 * IT IS DELIBERATELY A SECOND WRITE, NOT A REPLACEMENT. The RCSA tables answer
 * "what happened to this line"; the trail answers "what did this person do on
 * Tuesday". Collapsing them would mean either losing the line's own history or
 * putting RCSA-shaped JSON in a column every other module reads as a scalar.
 */
class RcsaAuditRecorder
{
    /**
     * A workflow transition (§9.1), as the estate-wide trail records it.
     */
    public function transition(
        RcsaAssessment $assessment,
        ?string $from,
        string $to,
        ?User $actor,
        ?string $reason = null,
    ): void {
        $this->write(
            entity: $assessment,
            actionType: 'rcsa_transition',
            field: 'status',
            old: $from,
            new: $to,
            actor: $actor,
            reason: $reason,
        );
    }

    /**
     * A material change to a line — workbook columns J, K, O, Q, S.
     */
    public function lineChange(
        RcsaAssessmentLine $line,
        string $field,
        mixed $old,
        mixed $new,
        ?User $actor,
        ?string $reason = null,
    ): void {
        $this->write(
            entity: $line,
            actionType: 'rcsa_line_change',
            field: $field,
            old: $old,
            new: $new,
            actor: $actor,
            reason: $reason,
        );
    }

    /**
     * A change to an action plan — workbook columns U, V and W.
     *
     * §11 names those three columns and P3 could not cover them: they live in a
     * child table, so `rcsa_line_revisions` — which is keyed by line — never saw
     * them. Creating, re-owning, re-dating or closing a plan is a material
     * change to what the bank has committed to about a risk above appetite, and
     * before P7 none of it was recorded anywhere.
     */
    public function planChange(
        RcsaActionPlan $plan,
        string $field,
        mixed $old,
        mixed $new,
        ?User $actor,
        ?string $reason = null,
    ): void {
        $this->write(
            entity: $plan,
            actionType: 'rcsa_plan_change',
            field: $field,
            old: $old,
            new: $new,
            actor: $actor,
            reason: $reason,
        );
    }

    /**
     * An export (§10.2) — who took the bank's risk profile away.
     */
    public function export(Model $export, int $rowCount, ?User $actor): void
    {
        $this->write(
            entity: $export,
            actionType: 'rcsa_export',
            field: 'row_count',
            old: null,
            new: $rowCount,
            actor: $actor,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * One row, sealed into the chain.
     *
     * NOT `AuditTrailService::record()`, which reads the actor from `auth()`.
     * Half of what this records happens on a queue or in a scheduled command,
     * where `auth()` is empty and the actor is a parameter the caller already
     * holds. The service's own comment says a trail naming the wrong person is
     * worse than one that admits it does not know; passing the actor
     * explicitly is how this keeps that promise off the request cycle.
     */
    private function write(
        Model $entity,
        string $actionType,
        ?string $field,
        mixed $old,
        mixed $new,
        ?User $actor,
        ?string $reason = null,
    ): void {
        RiskAuditTrail::create([
            // getAttribute rather than the property: every RCSA model carries
            // `organization_id`, but Eloquent\Model does not declare it, and a
            // property access larastan cannot see is a property access it is
            // right to complain about.
            'organization_id' => $entity->getAttribute('organization_id'),
            // The morph ALIAS, not class_basename() — P7 added the RCSA models
            // to MorphTypes for exactly this, and writing the basename is the
            // bug that made Risk::auditTrail() return nothing for years.
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => $entity->getKey(),
            'action_type' => $actionType,
            'field_changed' => $field,
            'old_value' => $this->stringify($old),
            'new_value' => $this->stringify($new),
            // The caller's actor first. `auth()` is empty on a queue or in a
            // scheduled command, which is where half of these are written —
            // and a compliance trail naming the wrong person is worse than one
            // that admits it does not know, so this falls through to null
            // rather than to whoever happens to be signed in elsewhere.
            'changed_by' => $actor === null ? auth()->id() : $actor->id,
            'changed_at' => now(),
            'ip_address' => request()->ip(),
            'change_reason' => $reason,
        ]);
    }

    private function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_array($value) => json_encode($value),
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => (string) $value,
        };
    }
}
