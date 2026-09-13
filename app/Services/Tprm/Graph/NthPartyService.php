<?php

namespace App\Services\Tprm\Graph;

use App\Enums\Tprm\DisclosureSource;
use App\Enums\Tprm\FindingSeverity;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\NthPartyEdge;
use App\Models\Tprm\Soc2Detail;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Findings\FindingService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Recording and confirming sub-processor edges — FR-NTH-01 and the Phase 3
 * carve-out proposals finally landing.
 *
 * THE CYCLE GUARD REFUSES THE WRITE, and it does so in the Action rather than
 * relying on anything downstream noticing. A cycle in this graph is not a
 * data-quality problem that shows up as a wrong number: it is a traversal that
 * does not return, and the concentration analysis, the graph canvas and the
 * rank computation all walk it.
 *
 * A PROPOSED EDGE IS NOT A FACT. Everything the discoverer and the SOC 2
 * cascade produce lands `proposed`, the same discipline the rest of the module
 * uses for machine-derived claims — and for the same reason: a sub-processor
 * edge changes concentration numbers that reach a board pack.
 */
class NthPartyService
{
    public function __construct(
        private readonly NthPartyGraph $graph,
        private readonly FindingService $findings,
    ) {}

    /**
     * Record an edge, refusing one that would close a loop.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidArgumentException
     */
    public function record(
        ThirdParty $parent,
        string $childName,
        ?ThirdParty $child,
        DisclosureSource $source,
        array $attributes = [],
        ?int $userId = null,
    ): NthPartyEdge {
        if ($this->graph->wouldCycle($parent->getKey(), $child?->getKey())) {
            throw new InvalidArgumentException(sprintf(
                'Recording %s as a sub-processor of %s would create a loop: %s already depends on %s, '
                .'directly or through its own chain. A cycle here is not a data-quality problem — it is a '
                .'traversal that does not return, and the concentration analysis and the graph both walk this.',
                $child->legal_name ?? $childName,
                $parent->legal_name,
                $child->legal_name ?? $childName,
                $parent->legal_name,
            ));
        }

        $existing = NthPartyEdge::query()
            ->where('parent_third_party_id', $parent->getKey())
            ->when(
                $child !== null,
                fn ($query) => $query->where('child_third_party_id', $child->getKey()),
                fn ($query) => $query->whereNull('child_third_party_id')->where('child_name_raw', $childName),
            )
            ->where('is_active', true)
            ->first();

        if ($existing !== null) {
            // A second disclosure of the same relationship is not a second
            // edge. It may, though, be the disclosure that makes an undeclared
            // sub-processor declared — so the source is upgraded where the new
            // one comes from the vendor and the old one did not.
            if ($source->isVendorDisclosure() && ! $existing->isVendorDisclosed()) {
                $existing->forceFill(['disclosure_source' => $source->value])->save();
            }

            return $existing->refresh();
        }

        return NthPartyEdge::create($attributes + [
            'organization_id' => $parent->organization_id,
            'parent_third_party_id' => $parent->getKey(),
            'child_third_party_id' => $child?->getKey(),
            'child_name_raw' => $childName,
            'disclosure_source' => $source->value,
            'rank' => $attributes['rank'] ?? 1,
            'created_by' => $userId,
        ]);
    }

    /**
     * Confirm a proposed edge.
     *
     * The cycle check runs AGAIN here, not only at proposal. An edge proposed
     * last month may have become a cycle since, because the rest of the graph
     * moved — and confirming it would close the loop just as surely as
     * recording it would have.
     *
     * @return array{confirmed: bool, reason: string|null}
     */
    public function confirm(NthPartyEdge $edge, ?int $userId = null): array
    {
        if ($edge->child_third_party_id !== null
            && $this->graph->wouldCycle($edge->parent_third_party_id, $edge->child_third_party_id)) {
            return [
                'confirmed' => false,
                'reason' => 'Confirming this edge would create a loop in the sub-processor graph. The chain '
                    .'has changed since this was proposed.',
            ];
        }

        $edge->forceFill([
            'confirmation_status' => NthPartyEdge::STATUS_CONFIRMED,
            'confirmed_by' => $userId,
        ])->save();

        return ['confirmed' => true, 'reason' => null];
    }

    public function reject(NthPartyEdge $edge, ?int $userId = null): NthPartyEdge
    {
        $edge->forceFill([
            'confirmation_status' => NthPartyEdge::STATUS_REJECTED,
            'confirmed_by' => $userId,
        ])->save();

        return $edge->refresh();
    }

    /**
     * Turn a confirmed SOC 2's carve-outs into proposed edges — the Phase 3
     * proposals the phase prompt said would land here.
     *
     * INCLUSIVE SUBSERVICE ORGANISATIONS ARE SKIPPED. The report covers them,
     * so there is no assurance gap; a carve-out says the auditor examined
     * nothing that organisation does, which is exactly the exposure an edge
     * records.
     *
     * @return Collection<int, NthPartyEdge>
     */
    public function fromSoc2Carveouts(Soc2Detail $soc2, ?int $userId = null)
    {
        $soc2->loadMissing(['document', 'subserviceOrgs']);

        $parent = $this->parentFor($soc2->document);

        if ($parent === null) {
            return collect();
        }

        $created = collect();

        foreach ($soc2->subserviceOrgs as $org) {
            if (! $org->isCarvedOut()) {
                continue;
            }

            try {
                $edge = $this->record(
                    $parent,
                    $org->name,
                    $this->matchToRegister($org->name, $parent->organization_id),
                    DisclosureSource::Soc2Carveout,
                    [
                        'service_description' => $org->services,
                        'disclosed_at' => $soc2->period_end?->toDateString(),
                        'engagement_id' => $soc2->document?->owner_type === Document::OWNER_ENGAGEMENT
                            ? $soc2->document->owner_id
                            : null,
                    ],
                    $userId,
                );

                $org->forceFill(['proposed_nth_party_edge_id' => $edge->getKey()])->save();
                $created->push($edge);
            } catch (InvalidArgumentException $exception) {
                // A carve-out that would close a loop is skipped and logged
                // rather than failing the confirmation that produced it: the
                // SOC 2 is still correctly recorded, and one edge being
                // impossible is not a reason to lose the other three.
                logger()->warning('TPRM carve-out edge refused', [
                    'soc2_id' => $soc2->getKey(),
                    'name' => $org->name,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        return $created;
    }

    /**
     * Raise findings for sub-processors the vendor never declared.
     *
     * @return Collection<int, \App\Models\Tprm\Finding>
     */
    public function raiseUndeclaredFindings(ThirdParty $thirdParty, ?int $userId = null)
    {
        $undeclared = $this->graph->undeclared($thirdParty->getKey());

        if ($undeclared->isEmpty()) {
            return collect();
        }

        $engagement = Engagement::query()
            ->where('third_party_id', $thirdParty->getKey())
            ->whereNotIn('status', ['terminated', 'archived'])
            ->orderByDesc('effective_tier')
            ->first();

        if ($engagement === null) {
            return collect();
        }

        return $undeclared->map(fn (NthPartyEdge $edge) => $this->findings->raise(
            $engagement,
            'monitoring',
            FindingSeverity::Medium,
            'Undeclared sub-processor: '.$edge->displayName(),
            [
                'source_id' => $edge->getKey(),
                'description' => sprintf(
                    '%s appears as a sub-processor of %s in %s, and in nothing the provider has declared to '
                    ."us.\n\nThat is a disclosure obligation the contract almost certainly imposes, and it is "
                    .'provable from two records rather than asserted: the entity is in the evidence and absent '
                    .'from the declaration. Ask the provider to confirm its current sub-processor list.',
                    $edge->displayName(),
                    $thirdParty->legal_name,
                    $edge->disclosure_source->label(),
                ),
                'regulatory_citation' => 'NDPA §29(2); GAID Art. 34(2)(i)–(j)',
            ],
            $userId,
        ));
    }

    /**
     * Match a disclosed name against the register.
     *
     * Exact on the legal name, then on the slug. Deliberately NOT fuzzy: a
     * wrong match here silently attributes one vendor's chain to another and
     * moves concentration numbers that reach a board pack. An unmatched name
     * is recorded as a name, which is honest and reversible; a wrong match is
     * neither.
     */
    public function matchToRegister(string $name, int $organizationId): ?ThirdParty
    {
        $normalised = trim($name);

        return ThirdParty::query()
            ->where('organization_id', $organizationId)
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(legal_name) = ?', [strtolower($normalised)])
                ->orWhere('slug', \Illuminate\Support\Str::slug($normalised)))
            ->first();
    }

    private function parentFor(?Document $document): ?ThirdParty
    {
        if ($document === null) {
            return null;
        }

        if ($document->owner_type === Document::OWNER_THIRD_PARTY) {
            return ThirdParty::query()->find($document->owner_id);
        }

        $engagement = Engagement::query()->find($document->owner_id);

        return $engagement === null ? null : ThirdParty::query()->find($engagement->third_party_id);
    }
}
