<?php

namespace App\Services\Tprm\Portal;

use App\Enums\Tprm\DisclosureSource;
use App\Enums\Tprm\FindingSeverity;
use App\Models\Tprm\ClauseLibraryEntry;
use App\Models\Tprm\Contract;
use App\Models\Tprm\ContractClause;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\NthPartyEdge;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Services\NotificationService;
use App\Services\Tprm\Findings\FindingService;
use App\Services\Tprm\Graph\NthPartyService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use ThirdLine\Platform\Tenancy\OrganizationScope;

/**
 * A vendor declaring its own sub-processors, and telling us when they change —
 * FR-PRT-06.
 *
 * THE CONSENT GATE IS THE FEATURE. Most portals let a vendor edit a list; this
 * one asks whether the CONTRACT requires our consent before the change may
 * happen, and where it does, the change is recorded as PROPOSED and does not
 * enter the graph until somebody here approves it. That is the difference
 * between a notification and a control.
 *
 * WHERE THE CONTRACT IS SILENT, WE DO NOT INVENT A CONSENT RIGHT. If the
 * agreement has no sub-contracting clause the vendor may appoint whom it
 * likes; the change is recorded, a review task is raised, and the register
 * says plainly that the bank has no contractual standing to object. Pretending
 * otherwise would have the module tell a relationship owner they can block
 * something they cannot.
 *
 * A DECLARATION IS ALWAYS `vendor_declared`, which is what makes the Phase 7
 * undeclared-sub-processor finding possible: an entity that shows up in a SOC 2
 * carve-out and never here is a broken disclosure obligation, provable from
 * two rows rather than asserted.
 */
class SubprocessorDeclarationService
{
    /** The clause that, when present, makes a change consent-gated. */
    public const CONSENT_CLAUSE_CODE = 'GEN-SUBCON';

    public function __construct(
        private readonly NthPartyService $edges,
        private readonly FindingService $findings,
    ) {}

    /**
     * What this vendor has declared.
     *
     * @return Collection<int, NthPartyEdge>
     */
    public function declared(PortalUser $user): Collection
    {
        return NthPartyEdge::query()
            ->where('parent_third_party_id', $user->third_party_id)
            ->live()
            ->with('child:id,legal_name')
            ->orderBy('child_name_raw')
            ->get();
    }

    /**
     * Declare a sub-processor, or notify a change to one.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidArgumentException
     */
    public function declare(PortalUser $user, string $name, array $attributes = []): array
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Give the sub-processor\'s name.');
        }

        /** @var ThirdParty $parent */
        $parent = ThirdParty::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->findOrFail($user->third_party_id);

        $gate = $this->consentGate($parent);

        try {
            $edge = $this->edges->record(
                $parent,
                $name,
                $this->edges->matchToRegister($name, (int) $parent->organization_id),
                DisclosureSource::VendorDeclared,
                [
                    'service_description' => $attributes['service_description'] ?? null,
                    'country_of_processing' => $attributes['country_of_processing'] ?? null,
                    'criticality' => $attributes['criticality'] ?? null,
                    'data_categories' => $attributes['data_categories'] ?? null,
                    'disclosed_at' => now()->toDateString(),
                    'rank' => 1,
                ],
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'That would create a loop in the supply chain — you cannot name a company that already '
                .'depends on you.'
            );
        }

        $this->raiseReviewTask($parent, $edge, $gate);

        return [
            'edge' => $edge,
            'consent_required' => $gate['required'],
            /*
             * The vendor is told PLAINLY whether they may proceed. A portal
             * that accepts the declaration silently and then blocks the change
             * elsewhere teaches vendors that telling us is what causes
             * trouble, which is the opposite of what FR-PRT-06 wants.
             */
            'message' => $gate['required']
                ? 'Recorded, and sent for approval. Your contract requires our consent before this '
                    .'sub-processor is used, so please wait for confirmation before switching.'
                : 'Recorded. Your contract does not require our consent for this, so no approval is needed — '
                    .'but your client will review it.',
        ];
    }

    /**
     * Whether the contract requires consent before a change.
     *
     * @return array{required: bool, basis: string, engagement: Engagement|null}
     */
    public function consentGate(ThirdParty $parent): array
    {
        /** @var Engagement|null $engagement */
        $engagement = Engagement::query()
            ->where('third_party_id', $parent->getKey())
            ->whereNotIn('status', ['draft', 'terminated', 'archived'])
            ->orderByRaw("CASE effective_tier WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END")
            ->first();

        if ($engagement === null) {
            return [
                'required' => false,
                'basis' => 'There is no live engagement, so no contract governs this.',
                'engagement' => null,
            ];
        }

        $clause = ClauseLibraryEntry::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('code', self::CONSENT_CLAUSE_CODE)
            ->first();

        if ($clause === null) {
            return [
                'required' => false,
                'basis' => 'The sub-contracting clause is not in the library, so the contract cannot be checked.',
                'engagement' => $engagement,
            ];
        }

        $contractIds = Contract::query()
            ->where('engagement_id', $engagement->getKey())
            ->pluck('id');

        $present = ContractClause::query()
            ->whereIn('contract_id', $contractIds)
            ->where('clause_library_id', $clause->getKey())
            ->where('presence', 'present')
            ->exists();

        return [
            'required' => $present,
            'basis' => $present
                ? 'The contract carries a sub-contracting consent clause (GEN-SUBCON).'
                // NOT "no consent needed, all fine". The absence of the clause
                // is itself worth a relationship owner knowing about.
                : 'The contract has no sub-contracting consent clause, so there is no contractual right to '
                    .'object to this change.',
            'engagement' => $engagement,
        ];
    }

    /**
     * @param  array{required: bool, basis: string, engagement: Engagement|null}  $gate
     */
    private function raiseReviewTask(ThirdParty $parent, NthPartyEdge $edge, array $gate): void
    {
        $engagement = $gate['engagement'];

        if ($engagement === null) {
            return;
        }

        $this->findings->raise(
            $engagement,
            'monitoring',
            $gate['required'] ? FindingSeverity::High : FindingSeverity::Medium,
            sprintf('Sub-processor change to assess: %s', $edge->displayName()),
            [
                'source_id' => $edge->getKey(),
                'description' => sprintf(
                    "%s has declared %s as a sub-processor%s.\n\n%s\n\nAssess the change and, where consent is "
                    .'required, decide it — the declaration is recorded as proposed and is not counted in the '
                    .'concentration analysis until it is confirmed.',
                    $parent->legal_name,
                    $edge->displayName(),
                    $edge->service_description !== null ? ' for '.$edge->service_description : '',
                    $gate['basis'],
                ),
                'regulatory_citation' => 'DORA Art. 30(2)(a); NDPA §29(2)',
            ],
        );

        if ($engagement->relationship_owner_id !== null) {
            NotificationService::send(
                organizationId: (int) $engagement->organization_id,
                userId: (int) $engagement->relationship_owner_id,
                type: 'tprm.portal.subprocessor_change',
                subject: sprintf('%s declared a new sub-processor', $parent->legal_name),
                body: sprintf('%s. %s', $edge->displayName(), $gate['basis']),
                metadata: ['edge_id' => $edge->getKey(), 'consent_required' => $gate['required']],
            );
        }
    }
}
