<?php

namespace App\Services\Tprm\Contracts;

use App\Models\Tprm\ClauseLibraryEntry;
use App\Models\Tprm\Contract;
use App\Models\Tprm\ContractClause;
use App\Models\Tprm\Engagement;
use Illuminate\Support\Collection;

/**
 * What the clause set looks like for one engagement and one contract chain.
 *
 * THE GAP LIST IS THE PRODUCT. Everything else here exists so that a gap can
 * be explained: which clauses applied and why, what the chain said about each,
 * and which could not be evaluated at all.
 */
class ClauseResolution
{
    /**
     * @param  Collection<int, ClauseLibraryEntry>  $applicable
     * @param  Collection<int, ClauseLibraryEntry>  $inapplicable
     * @param  list<array{clause: ClauseLibraryEntry, missing_facts: list<string>}>  $unresolvable
     * @param  Collection<int, ContractClause>  $determinations  keyed by clause_library_id
     */
    public function __construct(
        public readonly Engagement $engagement,
        public readonly ?Contract $contract,
        public readonly Collection $applicable,
        public readonly Collection $inapplicable,
        public readonly array $unresolvable,
        public readonly Collection $determinations,
    ) {}

    public function determinationFor(ClauseLibraryEntry $clause): ?ContractClause
    {
        return $this->determinations->get($clause->getKey());
    }

    /**
     * Applicable clauses the contract does not satisfy.
     *
     * A clause with NO determination at all is a gap. That is the case a
     * lenient implementation gets wrong: "we have not looked at this clause"
     * and "this clause is present" are not the same, and defaulting an
     * unanalysed contract to compliant would admit every vendor whose contract
     * nobody read.
     *
     * @return Collection<int, array<string, mixed>> each row is
     *                                               `{clause: ClauseLibraryEntry, determination: ContractClause|null}`
     */
    public function gaps(): Collection
    {
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $this->applicable
            ->map(fn (ClauseLibraryEntry $clause) => [
                'clause' => $clause,
                'determination' => $this->determinationFor($clause),
            ])
            ->reject(fn (array $row) => $row['determination']?->isSatisfied() === true
                || $row['determination']?->isNotApplicable() === true)
            ->values();

        return $rows;
    }

    /**
     * The gaps that stop activation — AC-06.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function blockingGaps(): Collection
    {
        return $this->gaps()
            ->filter(fn (array $row) => (bool) $row['clause']->is_blocking)
            // A waived gap is still a gap on the report; it simply does not
            // block. Both facts survive.
            ->reject(fn (array $row) => $row['determination']?->hasLiveWaiver() === true)
            ->values();
    }

    public function isClear(): bool
    {
        return $this->blockingGaps()->isEmpty();
    }

    /**
     * The gaps a person has already looked at, as against those nobody has.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unreviewedGaps(): Collection
    {
        return $this->gaps()
            ->filter(fn (array $row) => $row['determination'] === null
                || $row['determination']->reviewer_status === ContractClause::REVIEW_PENDING)
            ->values();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'applicable_count' => $this->applicable->count(),
            'inapplicable_count' => $this->inapplicable->count(),
            'gap_count' => $this->gaps()->count(),
            'blocking_gap_count' => $this->blockingGaps()->count(),
            'unreviewed_gap_count' => $this->unreviewedGaps()->count(),
            'is_clear' => $this->isClear(),
            'clauses' => $this->applicable->map(function (ClauseLibraryEntry $clause) {
                $determination = $this->determinationFor($clause);

                return [
                    'id' => $clause->getKey(),
                    'code' => $clause->code,
                    'title' => $clause->title,
                    'category' => $clause->category,
                    'citation' => $clause->citation,
                    'regulatory_source' => $clause->regulatory_source,
                    'is_blocking' => (bool) $clause->is_blocking,
                    'is_system_owned' => (bool) $clause->is_system_owned,
                    'guidance' => $clause->guidance,
                    'model_text' => $clause->model_text,
                    'presence' => $determination?->presence->value ?? 'absent',
                    'presence_label' => $determination?->presence->label() ?? 'Not analysed',
                    'reviewer_status' => $determination?->reviewer_status,
                    'located_text' => $determination?->located_text,
                    'page_reference' => $determination?->page_reference,
                    'confidence' => $determination?->confidence === null
                        ? null
                        : (float) $determination->confidence,
                    'detected_by' => $determination?->detected_by,
                    // Which document in the chain settled this clause. On a
                    // contract with amendments, "the MSA says nothing but the
                    // 2026 amendment grants it" is the answer a reviewer needs.
                    'determined_by' => $determination?->contract?->reference,
                    'satisfied' => $determination?->isSatisfied() ?? false,
                    'waived' => $determination?->hasLiveWaiver() ?? false,
                    'blocks_activation' => (bool) $clause->is_blocking
                        && ! ($determination?->isSatisfied() ?? false)
                        && ! ($determination?->isNotApplicable() ?? false)
                        && ! ($determination?->hasLiveWaiver() ?? false),
                    'contract_clause_id' => $determination?->getKey(),
                ];
            })->values(),
            // Named, not hidden. A clause that could not be evaluated is a gap
            // in the ENGAGEMENT RECORD, and it is fixed by recording the fact
            // rather than by editing the clause.
            'unresolvable' => array_map(fn (array $row) => [
                'code' => $row['clause']->code,
                'title' => $row['clause']->title,
                'missing_facts' => $row['missing_facts'],
            ], $this->unresolvable),
        ];
    }
}
