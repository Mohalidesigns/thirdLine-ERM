<?php

namespace App\Services\Tprm\Contracts;

use App\Models\Tprm\ClauseLibraryEntry;
use App\Models\Tprm\Contract;
use App\Models\Tprm\ContractClause;
use App\Models\Tprm\Engagement;
use App\Services\Tprm\Scoring\EngagementContext;
use App\Support\Tprm\RuleEvaluator;
use Illuminate\Support\Collection;

/**
 * Which clauses this engagement needs, and what the contract chain says about
 * each — FR-CTR-04 and the input to the AC-06 gate.
 *
 * TWO QUESTIONS, AND KEEPING THEM SEPARATE IS THE DESIGN.
 *
 *   APPLICABILITY comes from the ENGAGEMENT. A clause required only of
 *   cross-border personal-data processors is not a gap on a stationery
 *   contract. Get this wrong in the generous direction and the gap report
 *   fills with irrelevant rows, people learn to ignore it, and the blocking
 *   gate becomes an obstacle to route around rather than a control.
 *
 *   DETERMINATION comes from the CONTRACT CHAIN, newest first. An MSA silent
 *   on audit rights, amended a year later to grant them, HAS audit rights.
 *   Reading the MSA alone would raise a finding against a term the parties
 *   agreed in writing, which is the fastest way to lose a client's trust in
 *   the report.
 *
 * AN UNRESOLVED FACT IS REPORTED, NOT SWALLOWED. `RuleEvaluator` treats a
 * missing fact as false, so a clause whose rule names a fact nobody has
 * recorded would silently stop applying. The resolver collects those names, and
 * the gap report says "this clause could not be evaluated because the
 * engagement does not record X" rather than leaving the clause quietly out.
 */
class ClauseResolver
{
    public function __construct(
        private readonly EngagementContext $context,
        private readonly RuleEvaluator $rules,
    ) {}

    /**
     * The clause set this engagement is subject to, with each clause's
     * effective determination from the contract chain.
     */
    public function resolve(Engagement $engagement, ?Contract $contract = null): ClauseResolution
    {
        $facts = $this->context->build($engagement);

        $applicable = [];
        $inapplicable = [];
        $unresolvable = [];

        foreach ($this->library() as $clause) {
            if (empty($clause->applicability_rule)) {
                $applicable[] = $clause;

                continue;
            }

            // Evaluate FIRST, then ask what could not be resolved: the
            // evaluator reports on its last run, and a missing fact reads as
            // false, so the verdict alone cannot tell "this does not apply"
            // apart from "we do not know".
            $applies = $this->rules->evaluate($clause->applicability_rule, $facts);
            $missing = $this->rules->unresolvedFacts();

            if ($missing !== []) {
                // Recorded rather than decided. A clause whose rule cannot be
                // evaluated is not "not applicable" — it is a question the
                // engagement record cannot answer yet, and a reviewer has to
                // see that distinction rather than find the clause quietly
                // absent from the report.
                $unresolvable[] = ['clause' => $clause, 'missing_facts' => $missing];

                continue;
            }

            if ($applies) {
                $applicable[] = $clause;
            } else {
                $inapplicable[] = $clause;
            }
        }

        return new ClauseResolution(
            engagement: $engagement,
            contract: $contract,
            applicable: collect($applicable),
            inapplicable: collect($inapplicable),
            unresolvable: $unresolvable,
            determinations: $contract === null ? collect() : $this->determinations($contract),
        );
    }

    /**
     * The effective determination per clause across the contract family,
     * newest contract first — amendment precedence.
     *
     * @return Collection<int, ContractClause> keyed by clause_library_id
     */
    public function determinations(Contract $contract): Collection
    {
        $family = $contract->family();

        $rows = ContractClause::query()
            ->whereIn('contract_id', $family->pluck('id'))
            ->with(['clause', 'waiver', 'contract:id,reference,title,effective_date'])
            ->get();

        // The family is already newest-first, so the FIRST determination
        // found for a clause is the one that stands.
        $order = $family->pluck('id')->flip();

        return $rows
            ->sortBy(fn (ContractClause $row) => $order[$row->contract_id] ?? PHP_INT_MAX)
            // `unique` before `keyBy`, and the order matters: `keyBy` keeps
            // the LAST row for a repeated key, which would hand precedence to
            // the OLDEST document in the chain — the master agreement's
            // silence beating the amendment that granted the clause, which is
            // precisely the failure amendment precedence exists to prevent.
            ->unique('clause_library_id')
            ->keyBy('clause_library_id');
    }

    /**
     * Every published clause available to this tenant — the shipped library
     * plus its own additions.
     *
     * @return Collection<int, ClauseLibraryEntry>
     */
    public function library(): Collection
    {
        return ClauseLibraryEntry::query()
            ->availableTo()
            ->published()
            ->orderBy('category')
            ->orderBy('code')
            ->get();
    }
}
