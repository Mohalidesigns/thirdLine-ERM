<?php

namespace App\Services\Tprm\Contracts;

use App\Models\Tprm\Contract;
use App\Models\Tprm\Engagement;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creating, amending and keeping a contract record honest.
 *
 * TWO INVARIANTS ARE ENFORCED HERE RATHER THAN IN A FORM REQUEST, because the
 * importer and any future API reach this and not the form.
 *
 *   A SUBORDINATE DOCUMENT NEEDS A PARENT AND THE PARENT MUST BE ON THE SAME
 *   ENGAGEMENT. An amendment filed under another vendor's master agreement
 *   would put that vendor's clauses into this engagement's effective set —
 *   which is not a data-quality problem, it is an activation gate reading the
 *   wrong contract.
 *
 *   A CONTRACT CANNOT BE ITS OWN ANCESTOR. `lineage()` is bounded so a cycle
 *   cannot hang a request, but a bounded walk over a cycle still returns the
 *   wrong effective clause set, so the cycle is refused at the point it would
 *   be created.
 */
class ContractService
{
    public function __construct(private readonly ClauseResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Engagement $engagement, array $attributes, ?int $userId = null): Contract
    {
        $parent = isset($attributes['parent_contract_id'])
            ? Contract::query()->find($attributes['parent_contract_id'])
            : null;

        $this->assertHierarchy($engagement, $attributes['contract_type'] ?? 'msa', $parent);

        return Contract::create($attributes + [
            'organization_id' => $engagement->organization_id,
            'engagement_id' => $engagement->getKey(),
            'reference' => $this->nextReference(),
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Contract $contract, array $attributes, ?int $userId = null): Contract
    {
        if (array_key_exists('parent_contract_id', $attributes)) {
            $parent = $attributes['parent_contract_id'] === null
                ? null
                : Contract::query()->find($attributes['parent_contract_id']);

            $this->assertHierarchy(
                $contract->engagement,
                $attributes['contract_type'] ?? $contract->contract_type,
                $parent,
                $contract,
            );
        }

        $contract->fill($attributes + ['updated_by' => $userId])->save();

        return $contract->refresh();
    }

    /**
     * Recount the blocking gaps on a contract.
     *
     * The count is denormalised onto `tp_contracts` so the register can colour
     * a badge without joining three tables per row — but NOTHING GATES ON IT.
     * `ActivationGuard` re-reads the clause rows every time, because a
     * denormalised number is exactly the thing that goes stale when a clause
     * is promoted to blocking or a waiver lapses, and a stale gate is a gate
     * that admits a vendor it should have refused.
     */
    public function refreshBlockingGapCount(Contract $contract): int
    {
        $contract->loadMissing('engagement');

        if ($contract->engagement === null) {
            return 0;
        }

        $count = $this->resolver->resolve($contract->engagement, $contract)->blockingGaps()->count();

        $contract->forceFill(['blocking_gaps_count' => $count])->save();

        return $count;
    }

    /**
     * Mark a contract executed.
     *
     * Kept out of a plain `update()` because it is the moment a document
     * starts binding the parties, and because the clause analysis wants
     * running against it — an executed contract nobody has analysed is a
     * contract whose gaps are unknown, which the register shows as
     * `not_started` rather than as zero gaps.
     */
    public function execute(Contract $contract, ?int $userId = null): Contract
    {
        $contract->forceFill([
            'status' => Contract::STATUS_EXECUTED,
            'updated_by' => $userId,
        ])->save();

        return $contract->refresh();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertHierarchy(?Engagement $engagement, string $type, ?Contract $parent, ?Contract $self = null): void
    {
        $subordinate = in_array($type, Contract::SUBORDINATE_TYPES, true);

        if ($subordinate && $parent === null) {
            throw new InvalidArgumentException(
                'An amendment, statement of work, order form or SLA schedule has to sit under the agreement it '
                .'amends or is issued under. Without that link its clauses cannot take precedence over the '
                .'master agreement, and the effective clause set would read the master alone.'
            );
        }

        if ($parent === null) {
            return;
        }

        if ($engagement !== null && $parent->engagement_id !== $engagement->getKey()) {
            throw new InvalidArgumentException(
                'The parent agreement belongs to a different engagement. Filing a document under another '
                .'engagement\'s contract would put that engagement\'s terms into this one\'s effective clause '
                .'set, and the activation gate would then be reading the wrong contract.'
            );
        }

        if ($self !== null && $this->wouldCycle($self, $parent)) {
            throw new InvalidArgumentException(
                'That parent sits beneath this contract, so the link would form a loop and the effective '
                .'clause set could not be resolved.'
            );
        }
    }

    private function wouldCycle(Contract $self, Contract $parent): bool
    {
        return $parent->lineage()->contains(fn (Contract $ancestor) => $ancestor->is($self));
    }

    /**
     * `CTR-{year}-{seq}`, sequential within the tenant and the year.
     *
     * Derived from the highest existing reference rather than from a count,
     * for the reason `IntakeService::nextReference()` gives: a reference that
     * has appeared on an executed document must never be reissued.
     */
    private function nextReference(): string
    {
        $year = now()->year;
        $prefix = "CTR-{$year}-";

        $highest = Contract::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $highest === null ? 1 : ((int) Str::afterLast($highest, '-')) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
