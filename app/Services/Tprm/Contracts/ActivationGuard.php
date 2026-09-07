<?php

namespace App\Services\Tprm\Contracts;

use App\Models\Tprm\Contract;
use App\Models\Tprm\Engagement;

/**
 * The blocking-clause gate — FR-CTR-05 and AC-06.
 *
 * "A `TransitionEngagement` guard preventing `active` while any applicable
 * blocking clause is `absent` or `partial` without an approved waiver. The
 * error message names the clause and its citation."
 *
 * THE MESSAGE IS THE FEATURE, not the refusal. Any product can return 422. A
 * message reading "Cannot activate: contract gaps" teaches a user to find
 * whoever can override it. A message reading "This engagement cannot be
 * activated because its contract does not grant a right to audit the provider
 * or to receive its audit reports (CBN Cyber 2024 §2.3(v))" tells them what to
 * ask the vendor for, and cites the authority when the vendor pushes back.
 *
 * IT LIVES ON THE TRANSITION PATH, NOT IN A FORM REQUEST. Same reasoning as
 * the prohibited-outsourcing guard in `IntakeService`: a validator sits in
 * front of one HTTP route, and the bulk importer, the API and any future
 * "duplicate this engagement" button all reach the transition without passing
 * it.
 *
 * NO CONTRACT AT ALL IS A REFUSAL, and that is deliberate rather than
 * incidental. An engagement with no contract has every blocking clause absent;
 * treating "nothing to check" as "nothing wrong" would let the entire gate be
 * bypassed by not uploading the contract.
 */
class ActivationGuard
{
    public function __construct(private readonly ClauseResolver $resolver) {}

    /**
     * Whether this engagement may become active, and why not.
     */
    public function check(Engagement $engagement): ActivationVerdict
    {
        $contract = $this->governingContract($engagement);

        if ($contract === null) {
            return ActivationVerdict::refused(
                'This engagement cannot be activated because no executed contract is recorded against it. '
                .'Every mandatory clause is therefore absent, including the ones that are conditions of '
                .'activation. Record the executed contract and run the clause analysis, or waive each blocking '
                .'clause individually with a rationale and an expiry.',
                [],
            );
        }

        $resolution = $this->resolver->resolve($engagement, $contract);
        $blocking = $resolution->blockingGaps();

        if ($blocking->isEmpty()) {
            return ActivationVerdict::allowed($resolution);
        }

        return ActivationVerdict::refused(
            $this->message($blocking),
            $blocking->map(fn (array $row) => [
                'code' => $row['clause']->code,
                'title' => $row['clause']->title,
                'citation' => $row['clause']->citation,
                'regulatory_source' => $row['clause']->regulatory_source,
                'presence' => $row['determination']?->presence->value ?? 'absent',
                'guidance' => $row['clause']->guidance,
                'model_text' => $row['clause']->model_text,
                'contract_clause_id' => $row['determination']?->getKey(),
            ])->all(),
            $resolution,
        );
    }

    /**
     * The contract whose chain governs this engagement.
     *
     * The most recent EXECUTED root agreement. A draft contract does not gate
     * anything — it also does not satisfy anything, so an engagement whose
     * only contract is in negotiation is refused by the branch above rather
     * than assessed against terms nobody has signed.
     */
    public function governingContract(Engagement $engagement): ?Contract
    {
        return Contract::query()
            ->where('engagement_id', $engagement->getKey())
            ->where('status', Contract::STATUS_EXECUTED)
            ->whereNull('parent_contract_id')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first()
            // A tenant that recorded only the amendment, with no parent, still
            // gets assessed: falling back to any executed contract is better
            // than refusing an engagement because its paperwork is filed in an
            // order this module did not anticipate.
            ?? Contract::query()
                ->where('engagement_id', $engagement->getKey())
                ->where('status', Contract::STATUS_EXECUTED)
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->first();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $blocking
     */
    private function message($blocking): string
    {
        $lines = $blocking->map(function (array $row) {
            $presence = $row['determination']?->presence->value ?? 'absent';

            return sprintf(
                '· %s — %s%s. %s',
                $row['clause']->code,
                lcfirst($row['clause']->title),
                $row['clause']->citation ? ' ('.$row['clause']->citation.')' : '',
                // "Partial" is named as such rather than folded into
                // "missing": a clause that is present but incomplete is a
                // different conversation with the vendor from one that is
                // absent, and the redline is shorter.
                $presence === 'partial'
                    ? 'The contract addresses this but stops short of the obligation.'
                    : 'The contract does not contain this term.'
            );
        })->implode("\n");

        return sprintf(
            "This engagement cannot be activated: its contract is missing %d clause(s) that are conditions of "
            ."activation.\n\n%s\n\nEach can be added by amendment, or waived individually by the risk function "
            .'with a rationale and an expiry — a waiver appears on the override register and is reported to the '
            .'risk committee.',
            $blocking->count(),
            $lines,
        );
    }
}
