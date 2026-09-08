<?php

namespace App\Services\Bcms;

use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\PlanType;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanAttestation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The BC policy lifecycle — ISO 22301 clause 5.2.
 *
 * AN APPROVED VERSION IS IMMUTABLE. It is superseded, never edited. That is the
 * whole of clause 7.5's document control in one rule: the version an auditor was
 * shown in March has to still be the version they were shown, and a chain whose
 * links can be rewritten is not a history. `supersede()` creates v2 as a new row
 * pointing back at v1; v1 becomes `archived` and stays retrievable forever.
 *
 * THE POLICY IS A `bcms_plans` ROW. It is a versioned, approved, supersedable
 * document with an owner and an approver, which is exactly what that table
 * already models; a table of its own would duplicate the version chain and the
 * approval trail to hold one row per tenant. Recorded as an assumption in the
 * Phase 0 clause map and settled by ADR 0008.
 *
 * ATTESTATION IS A DATED RECORD WITH A SIGNER, NOT A CHECKBOX. The CBN
 * Corporate Governance Guidelines make board oversight a board act, and the
 * statement the signer agreed to is stored **with** the signature — an
 * attestation whose wording can be edited afterwards attests to nothing. The
 * signer's name and role are snapshotted too: a director who has since left the
 * board still attested on the day they attested.
 */
class PolicyService
{
    public const DEFAULT_STATEMENT = 'I confirm that the board has reviewed this business continuity policy, '
        .'that it remains appropriate to the organisation, and that adequate resources are committed to the '
        .'business continuity management system it establishes.';

    /** @param array<string, mixed> $attributes */
    public function draft(string $title, array $attributes = [], ?int $userId = null): Plan
    {
        return Plan::query()->create(array_merge([
            'plan_type' => PlanType::Policy->value,
            'title' => $title,
            'version' => '1.0',
            'status' => 'draft',
            'iso_clause_ref' => IsoClauseRef::Iso22301_5_2->value,
            'created_by' => $userId ?? auth()->id(),
        ], $attributes));
    }

    public function submitForReview(Plan $policy, ?int $userId = null): Plan
    {
        $this->assertEditable($policy);

        $policy->update(['status' => 'review', 'updated_by' => $userId ?? auth()->id()]);

        return $policy->refresh();
    }

    /**
     * Approve the policy.
     *
     * THE APPROVER IS NAMED AND IS NOT THE AUTHOR. Clause 5.2 makes the policy
     * top management's; a document approved by the person who wrote it has had
     * no oversight, and enforcing that here rather than in a policy class means
     * a job and a controller cannot disagree about it.
     */
    public function approve(Plan $policy, User $approver, ?string $effectiveFrom = null): Plan
    {
        if ($policy->status === 'approved') {
            throw new InvalidArgumentException('This policy version is already approved.');
        }

        if ((int) $approver->getKey() === (int) $policy->created_by) {
            throw new InvalidArgumentException(
                'A business continuity policy must be approved by somebody other than its author (ISO 22301 clause 5.2).'
            );
        }

        $policy->update([
            'status' => 'approved',
            'approver_id' => $approver->getKey(),
            'approved_at' => now(),
            'effective_from' => $effectiveFrom ?? now()->toDateString(),
            'updated_by' => $approver->getKey(),
        ]);

        return $policy->refresh();
    }

    /**
     * Create the next version, archiving the one it replaces.
     *
     * The new row starts as a DRAFT COPY of the approved text, because the
     * realistic act is "amend last year's policy", and forcing a blank page
     * produces a policy that quietly loses a clause somebody added in 2024.
     */
    public function supersede(Plan $approved, string $newVersion, ?int $userId = null): Plan
    {
        if ($approved->status !== 'approved') {
            throw new InvalidArgumentException('Only an approved policy version can be superseded.');
        }

        return DB::transaction(function () use ($approved, $newVersion, $userId) {
            $next = Plan::query()->create([
                'plan_type' => PlanType::Policy->value,
                'title' => $approved->title,
                'version' => $newVersion,
                'status' => 'draft',
                'supersedes_plan_id' => $approved->getKey(),
                'owner_id' => $approved->owner_id,
                'business_unit_id' => $approved->business_unit_id,
                'content' => $approved->content,
                'iso_clause_ref' => IsoClauseRef::Iso22301_5_2->value,
                'created_by' => $userId ?? auth()->id(),
            ]);

            // v1 is archived, NOT deleted. It stays retrievable forever: an
            // examiner asking "what did your policy say in 2026" is asking for
            // this row.
            $approved->update(['status' => 'archived', 'updated_by' => $userId ?? auth()->id()]);

            return $next;
        });
    }

    /**
     * Record a board attestation.
     *
     * ONE PER SIGNER PER YEAR PER TYPE, enforced by a unique index. A second
     * attempt is an update of the same act, not a second attestation.
     */
    public function attest(
        Plan $policy,
        User $signer,
        ?int $periodYear = null,
        string $type = 'board',
        ?string $statement = null,
        ?string $ipAddress = null,
    ): PlanAttestation {
        if ($policy->status !== 'approved') {
            throw new InvalidArgumentException('Only an approved policy version can be attested.');
        }

        return PlanAttestation::query()->updateOrCreate(
            [
                'plan_id' => $policy->getKey(),
                'attestation_type' => $type,
                'period_year' => $periodYear ?? (int) now()->year,
                'attested_by' => $signer->getKey(),
            ],
            [
                // Snapshotted: a director who has since left the board still
                // attested on the day they attested.
                'attested_by_name' => $signer->name,
                'attested_by_role' => $signer->job_title,
                'attested_at' => now(),
                'statement' => $statement ?? self::DEFAULT_STATEMENT,
                'ip_address' => $ipAddress,
                'iso_clause_ref' => IsoClauseRef::Cbn_cg_board->value,
            ]
        );
    }

    /** The current approved policy, or null. */
    public function current(): ?Plan
    {
        return Plan::query()
            ->where('plan_type', PlanType::Policy->value)
            ->where('status', 'approved')
            ->orderByDesc('approved_at')
            ->first();
    }

    /**
     * The full version chain, newest first.
     *
     * @return \Illuminate\Support\Collection<int, Plan>
     */
    public function history(): \Illuminate\Support\Collection
    {
        return Plan::query()
            ->where('plan_type', PlanType::Policy->value)
            ->with('attestations.attestor')
            ->orderByDesc('id')
            ->get();
    }

    private function assertEditable(Plan $policy): void
    {
        if ($policy->isImmutable()) {
            throw new InvalidArgumentException(
                'An approved policy version cannot be edited. Supersede it with a new version instead — '
                .'the version chain is the history an auditor reads.'
            );
        }
    }
}
