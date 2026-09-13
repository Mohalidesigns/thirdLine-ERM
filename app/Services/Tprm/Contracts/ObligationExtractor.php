<?php

namespace App\Services\Tprm\Contracts;

use App\Models\Tprm\Contract;
use App\Models\Tprm\Obligation;
use App\Support\Tprm\ObligationTemplates;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Turning a contract into a register of duties — FR-CTR-06.
 *
 * ONLY SATISFIED CLAUSES GENERATE OBLIGATIONS, and that is the decision worth
 * arguing with. A clause the contract does not contain creates no duty on
 * anybody: generating "report service levels monthly" from a contract with no
 * SLA clause would put a duty on the register that the provider never agreed
 * to, and the first time it showed as breached the register would lose its
 * credibility. The missing clause is a GAP, tracked as a gap; when it is added
 * by amendment and accepted, the obligation appears then.
 *
 * FIRST DUE DATES ARE ANCHORED TO THE CONTRACT'S EFFECTIVE DATE, not to today.
 * An annual duty under an agreement signed in March falls due in March, not on
 * whatever day somebody happened to run this. Anchoring to today would make
 * every register in the product renew on the day it was migrated.
 *
 * RE-RUNNING IS SAFE AND DOES NOT RESET ANYTHING. Obligations are matched on
 * contract, source reference and title; an existing one keeps its owner, its
 * status and its next due date. A generator that overwrote them would wipe a
 * year of evidence every time somebody re-ran the clause analysis.
 */
class ObligationExtractor
{
    public function __construct(private readonly ClauseResolver $resolver) {}

    /**
     * Generate the obligations a contract's satisfied clauses create.
     *
     * @return array{created: int, existing: int, skipped_gaps: int}
     */
    public function generate(Contract $contract, ?int $userId = null): array
    {
        $contract->loadMissing('engagement');

        if ($contract->engagement === null) {
            return ['created' => 0, 'existing' => 0, 'skipped_gaps' => 0];
        }

        $resolution = $this->resolver->resolve($contract->engagement, $contract);

        $created = 0;
        $existing = 0;
        $skipped = 0;

        DB::transaction(function () use ($resolution, $contract, $userId, &$created, &$existing, &$skipped) {
            foreach ($resolution->applicable as $clause) {
                $templates = ObligationTemplates::for($clause->code);

                if ($templates === []) {
                    continue;
                }

                $determination = $resolution->determinationFor($clause);

                if ($determination === null || ! $determination->isSatisfied()) {
                    $skipped += count($templates);

                    continue;
                }

                foreach ($templates as $template) {
                    $reference = $clause->code;

                    $obligation = Obligation::query()
                        ->where('contract_id', $contract->getKey())
                        ->where('source_reference', $reference)
                        ->where('title', $template['title'])
                        ->first();

                    if ($obligation !== null) {
                        $existing++;

                        continue;
                    }

                    $due = $this->firstDueDate($contract, $template['frequency']);

                    Obligation::create([
                        'organization_id' => $contract->organization_id,
                        'contract_id' => $contract->getKey(),
                        'engagement_id' => $contract->engagement_id,
                        'source' => 'contract',
                        'source_reference' => $reference,
                        'title' => $template['title'],
                        'description' => $template['description'],
                        'obligor' => $template['obligor'],
                        'frequency' => $template['frequency'],
                        'due_date' => $due?->toDateString(),
                        'next_due_date' => $due?->toDateString(),
                        'evidence_required' => $template['evidence_required'],
                        // The clause's own citation, so an obligation on the
                        // register can answer "who says we have to" without a
                        // reader going back to the contract.
                        'citation' => $clause->citation,
                        // The relationship owner by default: the person who
                        // can actually chase the vendor. Reassignable, and an
                        // unowned duty is a duty nobody does.
                        'owner_id' => $contract->engagement->relationship_owner_id,
                        'created_by' => $userId,
                    ]);

                    $created++;
                }
            }
        });

        return ['created' => $created, 'existing' => $existing, 'skipped_gaps' => $skipped];
    }

    /**
     * The first occurrence of a recurring duty.
     *
     * Anchored to the contract's effective date and advanced by whole
     * intervals until it reaches the future — so an annual duty under a
     * three-year-old agreement falls due on its anniversary this year, not
     * three years ago as an instant breach nobody could have prevented.
     */
    private function firstDueDate(Contract $contract, string $frequency): ?CarbonInterface
    {
        $months = match ($frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi_annual' => 6,
            'annual' => 12,
            // An `on_event` duty has no clock until the event, and a one-off
            // takes its date from whoever records it. Neither gets an invented
            // date here: a due date nobody agreed to is a breach nobody owns.
            default => null,
        };

        if ($months === null) {
            return null;
        }

        $anchor = $contract->effective_date?->copy() ?? now()->startOfDay();
        $due = $anchor->copy()->addMonths($months);

        while ($due->isBefore(now()->startOfDay())) {
            $due->addMonths($months);
        }

        return $due;
    }
}
