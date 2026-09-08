<?php

namespace App\Services\Tprm\Reporting;

use App\Enums\Tprm\AccessGrantStatus;
use App\Enums\Tprm\EngagementStatus;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The CBN Cyber Framework Appendix II §1.4 register — FR-RPT-01, AC-13.
 *
 * WHAT THE ARTEFACT IS. An examiner asks a Nigerian bank for the list of its
 * ICT third parties and cloud service providers, and for each one: what it is
 * connected to, who from that provider can get in, whether the assurance
 * evidence on file is still valid, and how risky the arrangement is. Almost
 * every institution assembles that from memory the week before. This class is
 * the standing version of it.
 *
 * THE ROW IS AN ENGAGEMENT, NOT A VENDOR. TRD §5.1 assesses risk at the
 * engagement, and a provider supplying core banking under one arrangement and
 * stationery under another has two different answers to every column here.
 * Collapsing them onto the vendor would have to pick one, and picking the
 * lower is how a register understates.
 *
 * THE POPULATION IS WIDER THAN `EngagementType::isIctArrangement()`. That
 * method answers "is this an ICT arrangement by contract type"; §1.4 also
 * catches a cloud service bought as ordinary supply, and a service from a
 * provider whose category is flagged ICT. All three limbs are OR'd, because
 * the failure this register exists to prevent is an omission, and every limb
 * left out is a provider the examiner finds and we did not list.
 *
 * DERIVED STATUSES SAY WHAT THEY MEAN, INCLUDING WHEN THEY MEAN "NOTHING ON
 * FILE". `connection_documentation` distinguishes an engagement with no
 * connections at all from one whose connections are undocumented; an
 * "undocumented: 0" against a vendor nobody recorded a tunnel for is a
 * comfortable lie. Same for evidence: `None held` is a distinct value from
 * `Current`.
 */
class CbnRegisterBuilder
{
    /**
     * A connection counts as documented when it has been approved AND carries
     * the four facts an examiner asks for: what it connects to, how it is
     * encrypted, how it authenticates, and the firewall rule that permits it.
     * Approval alone is a signature over an unspecified path.
     *
     * @var list<string>
     */
    private const DOCUMENTATION_FIELDS = ['endpoint', 'encryption', 'authentication_method', 'firewall_rule_ref'];

    /**
     * The column set, declared once. The screen renders these headings, the
     * spreadsheet writes them and the PDF prints them, so a column added here
     * appears in all three or in none of them — never in two.
     *
     * @return list<array{key: string, label: string, group: string}>
     */
    public function columns(): array
    {
        return [
            ['key' => 'provider', 'label' => 'Provider', 'group' => 'Provider'],
            ['key' => 'registration_number', 'label' => 'RC number', 'group' => 'Provider'],
            ['key' => 'lei', 'label' => 'LEI', 'group' => 'Provider'],
            ['key' => 'country_of_incorporation', 'label' => 'Country of incorporation', 'group' => 'Provider'],
            ['key' => 'category', 'label' => 'Category', 'group' => 'Provider'],

            ['key' => 'reference', 'label' => 'Engagement', 'group' => 'Arrangement'],
            ['key' => 'service', 'label' => 'Service', 'group' => 'Arrangement'],
            ['key' => 'engagement_type', 'label' => 'Arrangement type', 'group' => 'Arrangement'],
            ['key' => 'cloud_model', 'label' => 'Cloud model', 'group' => 'Arrangement'],
            ['key' => 'business_unit', 'label' => 'Business unit', 'group' => 'Arrangement'],
            ['key' => 'status', 'label' => 'Status', 'group' => 'Arrangement'],
            ['key' => 'supports_critical_function', 'label' => 'Supports a critical function', 'group' => 'Arrangement'],
            ['key' => 'is_material_outsourcing', 'label' => 'Material outsourcing', 'group' => 'Arrangement'],
            ['key' => 'start_date', 'label' => 'Start date', 'group' => 'Arrangement'],
            ['key' => 'end_date', 'label' => 'End date', 'group' => 'Arrangement'],
            ['key' => 'contract_reference', 'label' => 'Contract', 'group' => 'Arrangement'],
            ['key' => 'contract_expiry', 'label' => 'Contract expiry', 'group' => 'Arrangement'],

            ['key' => 'data_location_at_rest', 'label' => 'Data at rest', 'group' => 'Data'],
            ['key' => 'data_location_processing', 'label' => 'Data processed in', 'group' => 'Data'],
            ['key' => 'cross_border', 'label' => 'Cross-border transfer', 'group' => 'Data'],

            ['key' => 'connection_documentation', 'label' => 'Connection documentation', 'group' => 'Access'],
            ['key' => 'connections_open', 'label' => 'Open connections', 'group' => 'Access'],
            ['key' => 'access_grants_live', 'label' => 'Live access grants', 'group' => 'Access'],
            ['key' => 'access_grants_overdue', 'label' => 'Expired, not revoked', 'group' => 'Access'],

            ['key' => 'evidence_currency', 'label' => 'Assurance evidence', 'group' => 'Assurance'],
            ['key' => 'evidence_next_expiry', 'label' => 'Next evidence expiry', 'group' => 'Assurance'],

            ['key' => 'tier', 'label' => 'Tier', 'group' => 'Risk'],
            ['key' => 'residual_score', 'label' => 'Residual score', 'group' => 'Risk'],
            ['key' => 'residual_band', 'label' => 'Residual band', 'group' => 'Risk'],
            ['key' => 'next_assessment_due', 'label' => 'Next assessment due', 'group' => 'Risk'],
        ];
    }

    /**
     * The rows, in the order the screen shows them: worst tier first, so the
     * page and the first page of the PDF answer the question the examiner
     * actually asked.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(RegisterFilters $filters): Collection
    {
        $engagements = $this->query($filters)->get();

        $evidence = $this->evidenceCurrency($engagements);

        return $engagements
            ->map(fn (Engagement $engagement) => $this->row($engagement, $evidence))
            ->values();
    }

    /**
     * Headline counts for the screen. Derived from the SAME rows the table
     * renders — Phase 7 shipped a screen whose tile counted live engagements
     * and whose column counted all of them, and the two numbers contradicted
     * each other in public.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'critical_tier' => $rows->where('tier', 'Critical')->count(),
            'supports_critical_function' => $rows->where('supports_critical_function', 'Yes')->count(),
            'undocumented_connections' => $rows->where('connections_undocumented', '>', 0)->count(),
            'access_grants_overdue' => (int) $rows->sum('access_grants_overdue'),
            'evidence_expired' => $rows->where('evidence_currency', 'Expired')->count(),
            'evidence_none' => $rows->where('evidence_currency', 'None held')->count(),
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return Builder<Engagement>
     */
    private function query(RegisterFilters $filters): Builder
    {
        return Engagement::query()
            ->with([
                'thirdParty:id,uuid,legal_name,registration_number,lei,country_of_incorporation,category_id,slug',
                'thirdParty.category:id,name,is_ict',
                'businessUnit:id,name',
                'connections:id,engagement_id,status,approved_at,endpoint,encryption,authentication_method,firewall_rule_ref',
                'accessGrants:id,engagement_id,status,valid_to',
                'contracts' => fn ($query) => $query
                    ->select('id', 'engagement_id', 'reference', 'status', 'expiry_date')
                    ->orderByDesc('expiry_date'),
            ])
            ->where(fn (Builder $query) => $this->ictPopulation($query))
            ->when(! $filters->includeInactive, fn (Builder $query) => $query->whereIn(
                'status',
                array_map(fn (EngagementStatus $status) => $status->value, array_filter(
                    EngagementStatus::cases(),
                    fn (EngagementStatus $status) => $status->isLive()
                ))
            ))
            ->when($filters->tier, fn (Builder $query, $tier) => $query->where('effective_tier', $tier->value))
            ->when($filters->businessUnitId, fn (Builder $query, $id) => $query->where('business_unit_id', $id))
            ->when($filters->criticalOnly, fn (Builder $query) => $query->where('supports_critical_function', true))
            ->when($filters->search, fn (Builder $query, $search) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('tp_engagements.name', 'like', '%'.$search.'%')
                    ->orWhere('tp_engagements.reference', 'like', '%'.$search.'%')
                    ->orWhereHas('thirdParty', fn ($tp) => $tp->where('legal_name', 'like', '%'.$search.'%'))
            ))
            // Worst first, then stable by reference so two runs of the same
            // filter produce byte-identical files.
            ->orderByRaw($this->tierOrdering())
            ->orderBy('tp_engagements.reference');
    }

    /**
     * The three limbs of the §1.4 population. See the class comment.
     *
     * @param  Builder<Engagement>  $query
     */
    private function ictPopulation(Builder $query): void
    {
        $ictTypes = ['ict_service', 'outsourcing', 'intra_group'];

        $query->whereIn('engagement_type', $ictTypes)
            ->orWhereNotNull('cloud_model')
            ->orWhereHas('thirdParty.category', fn ($category) => $category->where('is_ict', true));
    }

    private function tierOrdering(): string
    {
        return "CASE effective_tier
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'moderate' THEN 3
            WHEN 'low' THEN 4
            ELSE 5 END";
    }

    /**
     * @param  array<int, array<string, mixed>>  $evidence
     * @return array<string, mixed>
     */
    private function row(Engagement $engagement, array $evidence): array
    {
        $thirdParty = $engagement->thirdParty;

        $connections = $engagement->connections;
        $open = $connections->filter(fn ($connection) => $connection->status->isOpen());
        $documented = $open->filter(fn ($connection) => $this->isDocumented($connection));

        $grants = $engagement->accessGrants;
        $live = $grants->filter(fn ($grant) => $grant->status->isLive());
        $overdue = $live->filter(fn ($grant) => $grant->status === AccessGrantStatus::Expired
            || ($grant->valid_to !== null && $grant->valid_to->isBefore(now()->startOfDay())));

        $contract = $engagement->contracts->firstWhere('status', 'executed') ?? $engagement->contracts->first();

        $currency = $evidence[$engagement->getKey()] ?? ['status' => 'None held', 'next_expiry' => null];

        return [
            'id' => $engagement->getKey(),
            'uuid' => $engagement->uuid,
            'url' => route('tprm.engagements.show', $engagement),

            'provider' => $thirdParty->legal_name,
            'registration_number' => $thirdParty->registration_number ?: '—',
            'lei' => $thirdParty->lei ?: '—',
            'country_of_incorporation' => $thirdParty->country_of_incorporation ?: '—',
            'category' => $thirdParty->category?->name ?? '—',

            'reference' => $engagement->reference,
            'service' => $engagement->name,
            'engagement_type' => $engagement->engagement_type?->label() ?? '—',
            'cloud_model' => $engagement->cloud_model ? strtoupper($engagement->cloud_model) : '—',
            'business_unit' => $engagement->businessUnit?->name ?? '—',
            'status' => $engagement->status->label(),
            'supports_critical_function' => $engagement->supports_critical_function ? 'Yes' : 'No',
            'is_material_outsourcing' => $engagement->is_material_outsourcing ? 'Yes' : 'No',
            'start_date' => $engagement->start_date?->toDateString() ?? '—',
            'end_date' => $engagement->end_date?->toDateString() ?? 'Open-ended',
            'contract_reference' => $contract?->reference ?? 'None recorded',
            'contract_expiry' => $contract?->expiry_date?->toDateString() ?? '—',

            'data_location_at_rest' => $engagement->data_location_at_rest ?: '—',
            'data_location_processing' => $engagement->data_location_processing ?: '—',
            'cross_border' => $engagement->cross_border ? 'Yes' : 'No',

            'connection_documentation' => $this->documentationStatus($open->count(), $documented->count()),
            'connections_open' => $open->count(),
            'connections_undocumented' => $open->count() - $documented->count(),
            'access_grants_live' => $live->count(),
            'access_grants_overdue' => $overdue->count(),

            'evidence_currency' => $currency['status'],
            'evidence_next_expiry' => $currency['next_expiry'] ?? '—',

            'tier' => $engagement->effective_tier?->label() ?? 'Not tiered',
            'residual_score' => $engagement->residual_score !== null ? (float) $engagement->residual_score : null,
            'residual_band' => $engagement->residual_band?->label() ?? 'Not scored',
            'next_assessment_due' => $engagement->next_assessment_due?->toDateString() ?? '—',
        ];
    }

    private function isDocumented(mixed $connection): bool
    {
        if ($connection->approved_at === null) {
            return false;
        }

        foreach (self::DOCUMENTATION_FIELDS as $field) {
            if (blank($connection->{$field})) {
                return false;
            }
        }

        return true;
    }

    private function documentationStatus(int $open, int $documented): string
    {
        if ($open === 0) {
            return 'No connections recorded';
        }

        if ($documented === $open) {
            return 'Fully documented';
        }

        return $documented === 0
            ? 'Undocumented'
            : sprintf('Partial (%d of %d)', $documented, $open);
    }

    /**
     * Assurance evidence currency per engagement, in one query rather than one
     * per row.
     *
     * Evidence attached to the PROVIDER counts for every engagement with it —
     * a SOC 2 is issued about a company, not about a purchase order — so both
     * owner kinds are read and merged. `is_assurance_evidence` is what makes a
     * document evidence at all: a signed NDA on file is not assurance.
     *
     * @param  EloquentCollection<int, Engagement>  $engagements
     * @return array<int, array{status: string, next_expiry: ?string}>
     */
    private function evidenceCurrency(Collection $engagements): array
    {
        if ($engagements->isEmpty()) {
            return [];
        }

        $engagementIds = $engagements->modelKeys();
        $thirdPartyIds = $engagements->pluck('third_party_id')->filter()->unique()->values()->all();

        $documents = Document::query()
            ->select('tp_documents.owner_type', 'tp_documents.owner_id', 'tp_documents.valid_to')
            ->join('tp_document_types', 'tp_document_types.id', '=', 'tp_documents.document_type_id')
            ->where('tp_document_types.is_assurance_evidence', true)
            ->where('tp_documents.is_superseded', false)
            ->where(function ($query) use ($engagementIds, $thirdPartyIds) {
                $query->where(fn ($q) => $q
                    ->where('tp_documents.owner_type', Document::OWNER_ENGAGEMENT)
                    ->whereIn('tp_documents.owner_id', $engagementIds));

                if ($thirdPartyIds !== []) {
                    $query->orWhere(fn ($q) => $q
                        ->where('tp_documents.owner_type', Document::OWNER_THIRD_PARTY)
                        ->whereIn('tp_documents.owner_id', $thirdPartyIds));
                }
            })
            ->get();

        $byOwner = $documents->groupBy(fn ($document) => $document->owner_type.':'.$document->owner_id);

        $today = now()->startOfDay();
        $result = [];

        foreach ($engagements as $engagement) {
            $held = collect()
                ->merge($byOwner->get(Document::OWNER_ENGAGEMENT.':'.$engagement->getKey(), collect()))
                ->merge($byOwner->get(Document::OWNER_THIRD_PARTY.':'.$engagement->third_party_id, collect()));

            if ($held->isEmpty()) {
                $result[$engagement->getKey()] = ['status' => 'None held', 'next_expiry' => null];

                continue;
            }

            $current = $held->filter(fn ($document) => $document->valid_to === null
                || ! $document->valid_to->isBefore($today));

            $nextExpiry = $current
                ->filter(fn ($document) => $document->valid_to !== null)
                ->min(fn ($document) => $document->valid_to->toDateString());

            $result[$engagement->getKey()] = [
                // Held-but-all-expired is "Expired", not "None held": the
                // difference between a vendor that never supplied a SOC 2 and
                // one whose SOC 2 lapsed is the whole conversation.
                'status' => $current->isEmpty() ? 'Expired' : 'Current',
                'next_expiry' => $nextExpiry,
            ];
        }

        return $result;
    }
}
