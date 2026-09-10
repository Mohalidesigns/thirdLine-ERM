<?php

namespace App\Services\Tprm\Reporting;

use App\Models\Entity;
use App\Models\Organization;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Contract;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\EngagementFunction;
use App\Models\Tprm\NthPartyEdge;
use App\Models\Tprm\ThirdParty;
use App\Models\Tprm\TprmSetting;
use App\Services\Tprm\Reporting\Concerns\StatesAbsence;
use Illuminate\Support\Collection;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Register of Information — FR-RPT-02, TRD Appendix D.
 *
 * WHAT IT IS. DORA Art. 28(3) requires a financial entity to maintain a
 * register of every contractual arrangement for ICT services, in fifteen
 * related tables (RT.01.01–RT.07.01). For an EU deployment this IS the
 * supervisory submission. For a Nigerian one it is the internal register
 * structure, which exceeds CBN Cyber Appendix II §1.4's minimum — the same
 * data, organised the way the more demanding regulator asks for it, so a
 * tenant that later needs the EU form is not rebuilding its register.
 *
 * EVERY TABLE DECLARES ITS OWN COVERAGE, and the cover sheet prints those
 * declarations first. This is the same discipline as `tp_frameworks
 * .catalogue_status` in Phase 2: a table that ships empty because this product
 * does not hold the data is indistinguishable, in a spreadsheet, from a table
 * that is empty because the institution has nothing to declare — and the
 * second is a statement to a regulator that the first has no right to make.
 *
 * WHERE A FIELD DOES NOT EXIST IN THIS PRODUCT IT IS NAMED AS ABSENT, NOT
 * FILLED. Per-entity LEIs are the recurring case: the platform's entity
 * register carries names, types and parents, not legal entity identifiers, so
 * the LEI columns in RT.01.02, RT.01.03 and RT.03.01 read "Not held" and the
 * table declares itself partial. Inventing an identifier — or quietly emitting
 * the maintaining entity's LEI for a subsidiary — is the one failure mode a
 * register of information cannot survive.
 *
 * THE CARDINALITY CAVEAT IS ON THE COVER SHEET, NOT IN THIS COMMENT. TRD
 * Appendix D requires it: the ESAs changed field cardinality in JC 2024 79 and
 * anyone freezing an EU deployment must check Commission Implementing
 * Regulation (EU) 2024/2956 Annexes I/II against this model. A reader of the
 * file needs that where they can see it.
 */
class DoraRegisterBuilder
{
    use StatesAbsence;

    public const COVERAGE_COMPLETE = 'complete';

    public const COVERAGE_PARTIAL = 'partial';

    /**
     * The whole register.
     *
     * @return list<array{code: string, title: string, coverage: string, note: ?string, headers: list<string>, rows: list<array<int, mixed>>}>
     */
    public function tables(): array
    {
        return [
            $this->rt0101(),
            $this->rt0102(),
            $this->rt0103(),
            $this->rt0201(),
            $this->rt0202(),
            $this->rt0203(),
            $this->rt0301(),
            $this->rt0302(),
            $this->rt0303(),
            $this->rt0401(),
            $this->rt0501(),
            $this->rt0502(),
            $this->rt0601(),
            $this->rt0701(),
        ];
    }

    /**
     * The sheets of the workbook: a cover, then one per table.
     *
     * @param  list<array<string, mixed>>  $tables
     * @return list<array{name: string, headers?: list<string>, rows: iterable<array-key, array<array-key, mixed>>, meta?: array<string,string>}>
     */
    public function sheets(array $tables, ReportProvenance $provenance): array
    {
        $sheets = [[
            'name' => 'Cover',
            'rows' => $this->coverRows($tables, $provenance),
        ]];

        foreach ($tables as $table) {
            $sheets[] = [
                // Excel truncates at 31 characters, so the template code leads:
                // "RT.02.02 Contractual arrangeme" still tells the reader which
                // table they are on, where a truncated title does not.
                'name' => $table['code'].' '.$table['title'],
                'headers' => $table['headers'],
                'rows' => $table['rows'],
                'meta' => array_filter([
                    'Template' => $table['code'].' — '.$table['title'],
                    'Coverage' => ucfirst($table['coverage']),
                    'Note' => $table['note'],
                ], fn (?string $value) => $value !== null && $value !== ''),
            ];
        }

        return $sheets;
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     * @return list<list<string>>
     */
    private function coverRows(array $tables, ReportProvenance $provenance): array
    {
        $rows = [
            ['Register of Information'],
            ['DORA Article 28(3) — templates RT.01.01 to RT.07.01'],
            [],
        ];

        foreach ($provenance->toMeta() as $label => $value) {
            $rows[] = [$label, $value];
        }

        $rows[] = [];
        $rows[] = ['VERIFY BEFORE AN EU SUBMISSION'];
        $rows[] = ['Field cardinality in this model has not been reconciled against Commission Implementing '
            .'Regulation (EU) 2024/2956 Annexes I/II. The ESAs issued field changes in JC 2024 79. For a '
            .'Nigerian deployment this workbook is the internal register structure and exceeds CBN Cyber '
            .'Framework Appendix II §1.4; it is not a submission to the CBN.'];
        $rows[] = [];
        $rows[] = ['Table', 'Title', 'Rows', 'Coverage', 'Note'];

        foreach ($tables as $table) {
            $rows[] = [
                $table['code'],
                $table['title'],
                (string) count($table['rows']),
                ucfirst($table['coverage']),
                $table['note'] ?? '',
            ];
        }

        return $rows;
    }

    /* ================================================================== */
    /*  RT.01 — the entity maintaining the register */
    /* ================================================================== */

    /**
     * @return array<string, mixed>
     */
    private function rt0101(): array
    {
        $organization = Organization::query()->find(TenantContext::organizationId());
        $settings = TprmSetting::forOrganization((int) TenantContext::organizationId());

        $missing = [];
        foreach (['lei' => 'LEI', 'country' => 'country', 'competent_authority' => 'competent authority'] as $column => $label) {
            if (blank($settings->{$column})) {
                $missing[] = $label;
            }
        }

        return [
            'code' => 'RT.01.01',
            'title' => 'Entity maintaining the register',
            'coverage' => $missing === [] ? self::COVERAGE_COMPLETE : self::COVERAGE_PARTIAL,
            'note' => $missing === []
                ? null
                : 'Unset in TPRM settings: '.implode(', ', $missing).'. Recorded as "Not set" rather than inferred.',
            'headers' => [
                'LEI', 'Name of the entity', 'Country', 'Type of entity', 'Competent authority',
                'Reporting currency', 'Value of total assets', 'National registration number',
            ],
            'rows' => [[
                $settings->lei ?: 'Not set',
                $this->labelOf($organization, 'name', 'Not set'),
                $settings->country ?: 'Not set',
                $organization?->institution_type
                    ? ucwords(str_replace('_', ' ', $organization->institution_type))
                    : 'Not set',
                $settings->competent_authority ?: 'Not set',
                $settings->reporting_currency ?: 'Not set',
                // DORA asks for total assets; this product holds shareholders'
                // funds, which is a different figure. Reporting one as the
                // other would be a false statement to a supervisor.
                'Not held — this product records shareholders\' funds, not total assets',
                $organization?->rc_number ?: 'Not set',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rt0102(): array
    {
        $entities = $this->entitiesOfType(['GROUP', 'SUBSIDIARY']);

        return [
            'code' => 'RT.01.02',
            'title' => 'Entities in scope of consolidation',
            'coverage' => self::COVERAGE_PARTIAL,
            'note' => 'Per-entity LEIs and countries are not held by the platform\'s entity register. '
                .'Names, types and the parent hierarchy are.',
            'headers' => ['LEI', 'Name of the entity', 'Country', 'Type of entity', 'Parent', 'Hierarchy path'],
            'rows' => $entities->map(fn (Entity $entity) => [
                'Not held',
                $entity->name,
                'Not held',
                $this->labelOf($entity->entityType, 'name', 'Not classified'),
                $this->labelOf($entity->parent, 'name', 'None — top of the hierarchy'),
                $entity->hierarchy_path ?: '',
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rt0103(): array
    {
        $branches = $this->entitiesOfType(['BRANCH']);

        return [
            'code' => 'RT.01.03',
            'title' => 'Branches',
            'coverage' => self::COVERAGE_PARTIAL,
            'note' => 'Branch identification codes and countries are not held by the platform\'s entity register.',
            'headers' => ['Identification code of the branch', 'LEI of the head office', 'Name of the branch', 'Country'],
            'rows' => $branches->map(fn (Entity $entity) => [
                $entity->entity_code ?: 'Not held',
                'Not held',
                $entity->name,
                'Not held',
            ])->all(),
        ];
    }

    /* ================================================================== */
    /*  RT.02 — contractual arrangements */
    /* ================================================================== */

    /**
     * @return array<string, mixed>
     */
    private function rt0201(): array
    {
        return [
            'code' => 'RT.02.01',
            'title' => 'Contractual arrangements — general',
            'coverage' => self::COVERAGE_COMPLETE,
            'note' => null,
            'headers' => [
                'Contractual arrangement reference', 'Type of contractual arrangement',
                'Overarching contractual arrangement', 'Currency', 'Annual expense or estimated cost',
                'Start date', 'End date', 'Status',
            ],
            'rows' => $this->contracts()->map(fn (Contract $contract) => [
                $contract->reference,
                ucwords(str_replace('_', ' ', (string) $contract->contract_type)),
                $this->labelOf($contract->parent, 'reference', 'None — standalone'),
                $contract->currency ?: ($contract->engagement?->currency ?: 'Not recorded'),
                $this->majorUnits($contract->value_minor ?? $contract->engagement?->annual_spend_minor),
                $contract->effective_date?->toDateString() ?? 'Not recorded',
                $contract->expiry_date?->toDateString() ?? 'Open-ended',
                ucwords(str_replace('_', ' ', (string) $contract->status)),
            ])->all(),
        ];
    }

    /**
     * RT.02.02 — the field set TRD Appendix D names explicitly.
     *
     * @return array<string, mixed>
     */
    private function rt0202(): array
    {
        return [
            'code' => 'RT.02.02',
            'title' => 'Contractual arrangements — specific',
            'coverage' => self::COVERAGE_PARTIAL,
            'note' => 'The LEI of the entity making use of the service is not held per business unit; '
                .'the maintaining entity is named instead, and the business unit is given alongside it.',
            'headers' => [
                'Contractual arrangement reference', 'LEI of the entity using the service',
                'Business unit using the service', 'Provider identification code', 'Type of code',
                'Function identifier', 'Type of ICT services', 'Start date', 'End date',
                'Reason of termination', 'Notice period for the entity (days)',
                'Notice period for the provider (days)', 'Country of governing law',
                'Country of provision', 'Storage of data', 'Location of data at rest',
                'Location of management or processing', 'Sensitiveness of data stored',
                'Level of reliance',
            ],
            'rows' => $this->contracts()->map(function (Contract $contract) {
                $engagement = $contract->engagement;
                $provider = $engagement->thirdParty;

                return [
                    $contract->reference,
                    $this->maintainingEntityLei(),
                    $this->labelOf($engagement->businessUnit, 'name', 'Not recorded'),
                    $this->providerCode($provider),
                    $this->providerCodeType($provider),
                    $this->functionIdentifiers($engagement),
                    $engagement?->engagement_type?->label() ?? 'Not recorded',
                    $engagement?->start_date?->toDateString() ?? $contract->effective_date?->toDateString() ?? 'Not recorded',
                    $engagement?->end_date?->toDateString() ?? $contract->expiry_date?->toDateString() ?? 'Open-ended',
                    $engagement?->termination_reason
                        ? ucwords(str_replace('_', ' ', $engagement->termination_reason))
                        : 'Not terminated',
                    $contract->notice_period_days_entity ?? 'Not recorded',
                    $contract->notice_period_days_provider ?? 'Not recorded',
                    $contract->governing_law_country ?: 'Not recorded',
                    $engagement?->deployment_location ?: 'Not recorded',
                    $engagement?->processes_personal_data ? 'Yes' : 'No',
                    $engagement?->data_location_at_rest ?: 'Not recorded',
                    $engagement?->data_location_processing ?: 'Not recorded',
                    $this->dataSensitivity($engagement),
                    $this->relianceLevel($engagement),
                ];
            })->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rt0203(): array
    {
        $rows = $this->contracts()
            ->filter(fn (Contract $contract) => (bool) $contract->engagement->thirdParty?->is_intra_group)
            ->map(fn (Contract $contract) => [
                $contract->reference,
                $this->labelOf($contract->engagement->thirdParty, 'legal_name', 'Not recorded'),
                $contract->engagement->reference,
                $contract->engagement->name,
                $contract->effective_date?->toDateString() ?? 'Not recorded',
                $contract->expiry_date?->toDateString() ?? 'Open-ended',
            ])
            ->values()
            ->all();

        return [
            'code' => 'RT.02.03',
            'title' => 'Intra-group contractual arrangements',
            'coverage' => self::COVERAGE_COMPLETE,
            'note' => 'Populated from providers flagged intra-group on the third-party register.',
            'headers' => [
                'Contractual arrangement reference', 'Intra-group provider', 'Engagement reference',
                'Service', 'Start date', 'End date',
            ],
            'rows' => $rows,
        ];
    }

    /* ================================================================== */
    /*  RT.03 — who signed */
    /* ================================================================== */

    /**
     * @return array<string, mixed>
     */
    private function rt0301(): array
    {
        $organization = Organization::query()->find(TenantContext::organizationId());

        return [
            'code' => 'RT.03.01',
            'title' => 'Entities signing to receive ICT services',
            'coverage' => self::COVERAGE_PARTIAL,
            'note' => 'This product records ONE contracting entity per tenant. Where a subsidiary signed in '
                .'its own name that is not distinguished here.',
            'headers' => ['Contractual arrangement reference', 'LEI of the signing entity', 'Name of the signing entity', 'Internal signatory'],
            'rows' => $this->contracts()->map(fn (Contract $contract) => [
                $contract->reference,
                $this->maintainingEntityLei(),
                $this->labelOf($organization, 'name', 'Not set'),
                $this->labelOf($contract->internalSignatory, 'name', 'Not recorded'),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rt0302(): array
    {
        return [
            'code' => 'RT.03.02',
            'title' => 'ICT third-party service providers signing',
            'coverage' => self::COVERAGE_COMPLETE,
            'note' => null,
            'headers' => [
                'Contractual arrangement reference', 'Provider identification code', 'Type of code',
                'Name of the provider', 'Counterparty signatory',
            ],
            'rows' => $this->contracts()->map(function (Contract $contract) {
                $provider = $contract->engagement->thirdParty;

                return [
                    $contract->reference,
                    $this->providerCode($provider),
                    $this->providerCodeType($provider),
                    $this->labelOf($provider, 'legal_name', 'Not recorded'),
                    $contract->counterparty_signatory ?: 'Not recorded',
                ];
            })->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rt0303(): array
    {
        $rows = $this->contracts()
            ->filter(fn (Contract $contract) => (bool) $contract->engagement->thirdParty?->is_intra_group)
            ->map(function (Contract $contract) {
                $provider = $contract->engagement->thirdParty;

                return [
                    $contract->reference,
                    $this->providerCode($provider),
                    $this->providerCodeType($provider),
                    $this->labelOf($provider, 'legal_name', 'Not recorded'),
                ];
            })
            ->values()
            ->all();

        return [
            'code' => 'RT.03.03',
            'title' => 'Entities signing to provide ICT services',
            'coverage' => self::COVERAGE_COMPLETE,
            'note' => 'Intra-group providers only, which is what this template covers.',
            'headers' => ['Contractual arrangement reference', 'Identification code', 'Type of code', 'Name'],
            'rows' => $rows,
        ];
    }

    /* ================================================================== */
    /*  RT.04 — who uses the service */
    /* ================================================================== */

    /**
     * @return array<string, mixed>
     */
    private function rt0401(): array
    {
        $engagements = $this->engagements()->keyBy(fn (Engagement $engagement) => $engagement->getKey());

        // `tp_engagement_functions` is read through its own model rather than
        // as a pivot bag, the way ConcentrationAnalyzer reads it: the link
        // carries a dependency level and a reliance level, which are facts
        // about the link and not decoration on the function.
        $links = EngagementFunction::query()
            ->with('businessFunction:id,function_code,name')
            ->whereIn('engagement_id', $engagements->keys())
            ->get();

        $rows = [];

        foreach ($links as $link) {
            $engagement = $engagements->get($link->engagement_id);

            if ($engagement === null) {
                continue;
            }

            $rows[] = [
                $this->firstContractReference($engagement),
                $engagement->reference,
                $this->maintainingEntityLei(),
                $this->labelOf($engagement->businessUnit, 'name', 'Not recorded'),
                $this->labelOf($link->businessFunction, 'function_code', 'Unknown function'),
                $this->labelOf($link->businessFunction, 'name', 'Unknown function'),
                ucwords(str_replace('_', ' ', (string) $link->dependency_level)),
                ucwords(str_replace('_', ' ', (string) $link->reliance_level)),
            ];
        }

        return [
            'code' => 'RT.04.01',
            'title' => 'Entities making use of the ICT services',
            'coverage' => self::COVERAGE_COMPLETE,
            'note' => 'One row per engagement and business function. An engagement linked to no function '
                .'produces no row here, which is itself a gap worth reading.',
            'headers' => [
                'Contractual arrangement reference', 'Engagement reference', 'LEI of the entity',
                'Business unit', 'Function identifier', 'Function name', 'Dependency level', 'Reliance level',
            ],
            'rows' => $rows,
        ];
    }

    /* ================================================================== */
    /*  RT.05 — providers and their supply chains */
    /* ================================================================== */

    /**
     * @return array<string, mixed>
     */
    private function rt0501(): array
    {
        $providers = ThirdParty::query()
            ->with(['category:id,name', 'ultimateParent:id,legal_name'])
            ->whereHas('engagements', fn ($query) => $query->whereIn(
                'engagement_type',
                ['ict_service', 'outsourcing', 'intra_group']
            ))
            ->orderBy('legal_name')
            ->get();

        return [
            'code' => 'RT.05.01',
            'title' => 'ICT third-party service providers',
            'coverage' => self::COVERAGE_COMPLETE,
            'note' => null,
            'headers' => [
                'Identification code', 'Type of code', 'Name of the provider', 'Type of person',
                'Country of the head office', 'Country of incorporation', 'Category',
                'Ultimate parent undertaking', 'Total annual expense', 'Currency',
            ],
            'rows' => $providers->map(fn (ThirdParty $provider) => [
                $this->providerCode($provider),
                $this->providerCodeType($provider),
                $provider->legal_name,
                $provider->entity_type ? ucwords(str_replace('_', ' ', $provider->entity_type)) : 'Not recorded',
                $provider->country_of_hq ?: 'Not recorded',
                $provider->country_of_incorporation ?: 'Not recorded',
                $this->labelOf($provider->category, 'name', 'Not classified'),
                $this->labelOf($provider->ultimateParent, 'legal_name', 'None recorded'),
                $this->majorUnits($this->annualSpendFor($provider)),
                $this->spendCurrencyFor($provider),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rt0502(): array
    {
        $edges = NthPartyEdge::query()
            ->with(['parent:id,legal_name,lei,registration_number', 'child:id,legal_name,lei,registration_number', 'engagement.contracts'])
            ->live()
            ->orderBy('rank')
            ->get();

        return [
            'code' => 'RT.05.02',
            'title' => 'ICT service supply chains',
            'coverage' => self::COVERAGE_PARTIAL,
            'note' => 'Proposed links are included and marked. A supply chain showing only confirmed links '
                .'understates what the institution has been told.',
            'headers' => [
                'Contractual arrangement reference', 'Type of ICT services', 'Rank',
                'Provider identification code', 'Type of code', 'Recipient identification code',
                'Type of code', 'Recipient name', 'Country of processing', 'Confirmation status',
            ],
            'rows' => $edges->map(fn (NthPartyEdge $edge) => [
                $edge->engagement === null
                    ? 'Not linked to a contract'
                    : $this->firstContractReference($edge->engagement),
                $edge->service_description ?: 'Not recorded',
                $edge->rank,
                $this->providerCode($edge->parent),
                $this->providerCodeType($edge->parent),
                $edge->child ? $this->providerCode($edge->child) : 'Not on the register',
                $edge->child ? $this->providerCodeType($edge->child) : 'None',
                $edge->displayName(),
                $edge->country_of_processing ?: 'Not recorded',
                ucfirst((string) $edge->confirmation_status),
            ])->all(),
        ];
    }

    /* ================================================================== */
    /*  RT.06 and RT.07 — functions and the assessments over them */
    /* ================================================================== */

    /**
     * @return array<string, mixed>
     */
    private function rt0601(): array
    {
        $functions = BusinessFunction::query()
            ->with('owningBusinessUnit:id,name')
            ->orderBy('function_code')
            ->get();

        return [
            'code' => 'RT.06.01',
            'title' => 'Functions identification',
            'coverage' => self::COVERAGE_PARTIAL,
            'note' => 'The LEI column names the maintaining entity; per-function entity LEIs are not held.',
            'headers' => [
                'Function identifier', 'Licensed activity', 'Function name', 'LEI',
                'Criticality assessment', 'Reasons for the classification', 'Date of the last assessment',
                'Recovery time objective (hours)', 'Recovery point objective (hours)',
                'Impact of discontinuation', 'Owning business unit',
            ],
            'rows' => $functions->map(fn (BusinessFunction $function) => [
                $function->function_code,
                $function->licensed_activity ?: 'Not recorded',
                $function->name,
                $this->maintainingEntityLei(),
                ucwords(str_replace('_', ' ', (string) $function->criticality)),
                $function->criticality_rationale ?: 'Not recorded',
                $function->criticality_assessed_at?->toDateString() ?? 'Never assessed',
                $function->rto_hours ?? 'Not set',
                $function->rpo_hours ?? 'Not set',
                $function->impact_of_discontinuation ?: 'Not recorded',
                $this->labelOf($function->owningBusinessUnit, 'name', 'Not assigned'),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rt0701(): array
    {
        $engagements = $this->engagements()
            ->filter(fn (Engagement $engagement) => (bool) $engagement->supports_critical_function);

        return [
            'code' => 'RT.07.01',
            'title' => 'Assessments of services supporting critical functions',
            'coverage' => self::COVERAGE_COMPLETE,
            'note' => 'Restricted to engagements flagged as supporting a critical or important function, '
                .'which is what this template covers.',
            'headers' => [
                'Contractual arrangement reference', 'Engagement reference', 'Substitutability',
                'Reasons if not substitutable', 'Time to replace (months)', 'Date of the last audit',
                'Existence of an exit plan', 'Exit plan last tested', 'Possibility of reintegration',
                'Impact of discontinuation', 'Effective tier', 'Residual score',
            ],
            'rows' => $engagements->map(function (Engagement $engagement) {
                $plan = $engagement->exitPlan;

                return [
                    $this->firstContractReference($engagement),
                    $engagement->reference,
                    $engagement->substitutability
                        ? ucwords(str_replace('_', ' ', $engagement->substitutability))
                        : 'Not assessed',
                    $engagement->substitutability === 'not_substitutable'
                        ? ($plan?->residual_risk_during_transition ?: 'Not recorded')
                        : '',
                    $engagement->time_to_replace_months ?? 'Not assessed',
                    $this->lastAuditDate($engagement),
                    $plan ? 'Yes' : 'No',
                    $plan?->last_tested_at?->toDateString() ?? ($plan ? 'Never tested' : ''),
                    $plan?->in_house_option ? 'In-house option recorded' : 'Not recorded',
                    $this->criticalFunctionImpact($engagement),
                    $engagement->effective_tier?->label() ?? 'Not tiered',
                    $engagement->residual_score !== null ? (float) $engagement->residual_score : 'Not scored',
                ];
            })->values()->all(),
        ];
    }

    /* ================================================================== */
    /*  Shared reads */
    /* ================================================================== */

    /** @var Collection<int, Contract>|null */
    private ?Collection $contractCache = null;

    /** @var Collection<int, Engagement>|null */
    private ?Collection $engagementCache = null;

    /**
     * @return Collection<int, Contract>
     */
    private function contracts(): Collection
    {
        return $this->contractCache ??= Contract::query()
            ->with([
                'parent:id,reference',
                'internalSignatory:id,name',
                'engagement.thirdParty:id,legal_name,lei,registration_number,is_intra_group',
                'engagement.businessUnit:id,name',
                'engagement.businessFunctions:id,function_code,name,criticality',
            ])
            ->whereHas('engagement', fn ($query) => $query->whereIn(
                'engagement_type',
                ['ict_service', 'outsourcing', 'intra_group']
            ))
            ->orderBy('reference')
            ->get();
    }

    /**
     * @return Collection<int, Engagement>
     */
    private function engagements(): Collection
    {
        return $this->engagementCache ??= Engagement::query()
            ->with([
                'thirdParty:id,legal_name,lei,registration_number,is_intra_group',
                'businessUnit:id,name',
                'businessFunctions',
                'contracts:id,engagement_id,reference',
                'exitPlan',
            ])
            ->whereIn('engagement_type', ['ict_service', 'outsourcing', 'intra_group'])
            ->orderBy('reference')
            ->get();
    }

    /**
     * @return Collection<int, Entity>
     */
    private function entitiesOfType(array $codes): Collection
    {
        return Entity::query()
            ->with(['entityType:id,code,name', 'parent:id,name'])
            ->whereHas('entityType', fn ($query) => $query->whereIn('code', $codes))
            ->orderBy('name')
            ->get();
    }

    /**
     * DORA identifies a provider by LEI where one exists and by another code
     * otherwise. Emitting a blank for a provider with no LEI would lose the
     * distinction between "no identifier held" and "identified by its national
     * registration number", which is the distinction the type column exists
     * to carry.
     */
    /**
     * The arrangement a DORA row hangs off.
     *
     * An engagement with no contract recorded is a real and reportable state —
     * a live ICT service with nothing signed — so it is named rather than left
     * blank.
     */
    private function firstContractReference(Engagement $engagement): string
    {
        $contract = $engagement->contracts->first();

        return $contract === null ? 'No contract recorded' : $contract->reference;
    }

    private function providerCode(?ThirdParty $provider): string
    {
        if ($provider === null) {
            return 'Not recorded';
        }

        return $provider->lei ?: ($provider->registration_number ?: 'Not held');
    }

    private function providerCodeType(?ThirdParty $provider): string
    {
        if ($provider === null) {
            return 'None';
        }

        if ($provider->lei) {
            return 'LEI';
        }

        return $provider->registration_number ? 'National registration number' : 'None held';
    }

    private function maintainingEntityLei(): string
    {
        return TprmSetting::forOrganization((int) TenantContext::organizationId())->lei ?: 'Not set';
    }

    private function functionIdentifiers(?Engagement $engagement): string
    {
        $codes = $engagement?->businessFunctions->pluck('function_code')->all() ?? [];

        return $codes === [] ? 'No function linked' : implode(', ', $codes);
    }

    /**
     * The level of reliance is the strongest one recorded across the linked
     * functions. Averaging them would report a comfortable middle for an
     * engagement holding up one critical function and three trivial ones.
     */
    private function relianceLevel(?Engagement $engagement): string
    {
        $order = ['low' => 1, 'medium' => 2, 'high' => 3, 'full' => 4];

        if ($engagement === null) {
            return 'Not recorded';
        }

        $highest = EngagementFunction::query()
            ->where('engagement_id', $engagement->getKey())
            ->pluck('reliance_level')
            ->sortByDesc(fn (string $level) => $order[$level] ?? 0)
            ->first();

        return $highest === null ? 'Not recorded' : ucfirst($highest);
    }

    private function dataSensitivity(?Engagement $engagement): string
    {
        if ($engagement === null || ! $engagement->processes_personal_data) {
            return 'No personal data';
        }

        $categories = (array) ($engagement->data_categories ?? []);

        return $categories === []
            ? 'Personal data, categories not recorded'
            : implode(', ', array_map(fn ($c) => ucwords(str_replace('_', ' ', (string) $c)), $categories));
    }

    private function criticalFunctionImpact(Engagement $engagement): string
    {
        $impacts = $engagement->businessFunctions
            ->filter(fn ($function) => filled($function->impact_of_discontinuation))
            ->pluck('impact_of_discontinuation')
            ->all();

        return $impacts === [] ? 'Not recorded' : implode(' | ', $impacts);
    }

    /**
     * "Date of the last audit" is read as the last VALIDATED assessment. A
     * submitted-but-unreviewed questionnaire is the vendor's own account of
     * itself, and reporting it as an audit date is the misstatement the
     * validate-before-score rule exists to prevent.
     */
    private function lastAuditDate(Engagement $engagement): string
    {
        $validated = $engagement->assessments()
            ->whereNotNull('validated_at')
            ->max('validated_at');

        return $validated ? substr((string) $validated, 0, 10) : 'No validated assessment';
    }

    private function annualSpendFor(ThirdParty $provider): ?int
    {
        $total = $this->engagements()
            ->where('third_party_id', $provider->getKey())
            ->sum(fn (Engagement $engagement) => (int) ($engagement->annual_spend_minor ?? 0));

        return $total > 0 ? (int) $total : null;
    }

    private function spendCurrencyFor(ThirdParty $provider): string
    {
        $currencies = $this->engagements()
            ->where('third_party_id', $provider->getKey())
            ->pluck('currency')
            ->filter()
            ->unique()
            ->values();

        if ($currencies->isEmpty()) {
            return 'Not recorded';
        }

        // Two currencies under one provider means the total above is a sum of
        // unlike things, and the cell says so rather than picking one.
        return $currencies->count() === 1 ? (string) $currencies->first() : 'Mixed: '.$currencies->implode(', ');
    }

    private function majorUnits(?int $minor): string
    {
        return $minor === null ? 'Not recorded' : number_format($minor / 100, 2, '.', '');
    }
}
