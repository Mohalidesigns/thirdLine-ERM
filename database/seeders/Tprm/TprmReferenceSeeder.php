<?php

namespace Database\Seeders\Tprm;

use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\DocumentExtractor;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Support\Tprm\DefaultRuleset;
use Database\Seeders\Tprm\Reference\ClauseLibrary;
use Database\Seeders\Tprm\Reference\CountryRisk;
use Database\Seeders\Tprm\Reference\FrameworkLibraries;
use Database\Seeders\Tprm\Reference\Iso27002Library;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Seeds everything the TPRM module needs before a user can do anything.
 *
 * Two halves, and they are different in kind.
 *
 * THE SYSTEM HALF — frameworks, control libraries, document types, the clause
 * library — carries `organization_id = null`. It is shipped with the product,
 * readable by every tenant and editable by none. It is idempotent by natural
 * key, so re-running the seeder after a framework is extended adds the new
 * rows without duplicating or resetting the old ones.
 *
 * THE TENANT HALF — categories, business functions, tier policies — is a
 * STARTING POINT, not a fact. A Nigerian bank's function catalogue and vendor
 * taxonomy are the ones we ship because a client staring at an empty universe
 * abandons the module in week one; every row is editable, and the criticality
 * ratings in particular are a placeholder for the client's own business impact
 * analysis rather than our opinion of their business.
 *
 * The prohibited-outsourcing flags are the exception: those are not a starting
 * point, they are the law, and AC-01 blocks an intake against them.
 */
class TprmReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedFrameworks();
        $this->seedDocumentTypes();
        $this->seedClauseLibrary();
        $this->seedCountryRisk();

        // Tenant reference data for every organisation that exists. A tenant
        // created later gets it from the same method, called from wherever
        // organisations are provisioned.
        Organization::query()->get()->each(fn (Organization $org) => $this->seedForOrganization($org));
    }

    /* ------------------------------------------------------------------ */
    /*  System libraries */
    /* ------------------------------------------------------------------ */

    private function seedFrameworks(): void
    {
        $iso = [
            'code' => Iso27002Library::CODE,
            'name' => 'ISO/IEC 27002:2022',
            'version' => Iso27002Library::VERSION,
            'publisher' => 'ISO/IEC',
            'has_stable_keys' => true,
            'declared_control_count' => Iso27002Library::DECLARED_COUNT,
            'catalogue_status' => 'complete',
            'catalogue_note' => 'All 93 controls by clause number. Control names only; the standard\'s control text is copyright ISO and is not reproduced.',
            'controls' => array_map(
                fn (array $row) => $row + [
                    'supplier_relevant' => in_array($row['control_id'], Iso27002Library::SUPPLIER_RELEVANT, true),
                ],
                Iso27002Library::controls()
            ),
        ];

        foreach (array_merge([$iso], FrameworkLibraries::all()) as $framework) {
            $controls = $framework['controls'];
            unset($framework['controls']);

            $frameworkId = DB::table('tp_frameworks')
                ->where('code', $framework['code'])
                ->where('version', $framework['version'])
                ->value('id');

            $attributes = $framework + ['is_active' => true, 'updated_at' => now()];

            if ($frameworkId === null) {
                $frameworkId = DB::table('tp_frameworks')->insertGetId($attributes + ['created_at' => now()]);
            } else {
                DB::table('tp_frameworks')->where('id', $frameworkId)->update($attributes);
            }

            foreach ($controls as $control) {
                DB::table('tp_framework_controls')->updateOrInsert(
                    ['framework_id' => $frameworkId, 'control_id' => $control['control_id']],
                    [
                        'title' => $control['title'],
                        'domain' => $control['domain'] ?? null,
                        'supplier_relevant' => $control['supplier_relevant'] ?? false,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    /**
     * The document type catalogue, with the extractor that reads each type and
     * the assurance level a confirmed one can support.
     *
     * `default_validity_months` is what drives the expiry monitor. A SOC 2
     * covers a stated period rather than expiring, so twelve months is the
     * point at which it stops being current evidence rather than a date on the
     * document — which is why `has_expiry` is true for it even though no SOC 2
     * says "expires".
     */
    private function seedDocumentTypes(): void
    {
        $types = [
            ['soc2_type2', 'SOC 2 Type II report', 'assurance', true, 12, DocumentExtractor::Soc2, true, AssuranceLevel::IndependentlyAssured],
            ['soc2_type1', 'SOC 2 Type I report', 'assurance', true, 12, DocumentExtractor::Soc2, true, AssuranceLevel::Documented],
            ['soc1', 'SOC 1 report', 'assurance', true, 12, DocumentExtractor::Soc2, true, AssuranceLevel::IndependentlyAssured],
            ['bridge_letter', 'Bridge letter', 'assurance', true, 6, DocumentExtractor::Generic, true, AssuranceLevel::Documented],
            ['iso27001_cert', 'ISO/IEC 27001 certificate', 'certification', true, 36, DocumentExtractor::IsoCert, true, AssuranceLevel::IndependentlyAssured],
            ['iso22301_cert', 'ISO 22301 certificate', 'certification', true, 36, DocumentExtractor::IsoCert, true, AssuranceLevel::IndependentlyAssured],
            ['iso20000_cert', 'ISO/IEC 20000 certificate', 'certification', true, 36, DocumentExtractor::IsoCert, true, AssuranceLevel::IndependentlyAssured],
            ['iso9001_cert', 'ISO 9001 certificate', 'certification', true, 36, DocumentExtractor::IsoCert, false, null],
            ['pci_aoc', 'PCI DSS Attestation of Compliance', 'certification', true, 12, DocumentExtractor::PciAoc, true, AssuranceLevel::IndependentlyAssured],
            ['pentest_report', 'Penetration test report', 'assurance', true, 12, DocumentExtractor::Pentest, true, AssuranceLevel::IndependentlyAssured],
            ['bcp_test_report', 'BCP / DR test report', 'resilience', true, 12, DocumentExtractor::BcpTest, true, AssuranceLevel::IndependentlyAssured],
            ['insurance_cert', 'Insurance certificate', 'financial', true, 12, DocumentExtractor::Insurance, false, AssuranceLevel::Documented],
            ['audited_financials', 'Audited financial statements', 'financial', true, 12, DocumentExtractor::Financials, false, AssuranceLevel::Documented],
            ['dpa', 'Data processing agreement', 'legal', false, null, DocumentExtractor::Dpa, true, AssuranceLevel::Documented],
            ['contract', 'Contract or master services agreement', 'legal', false, null, DocumentExtractor::Generic, false, null],
            ['ndpc_registration', 'NDPC registration certificate', 'certification', true, 12, DocumentExtractor::Generic, false, AssuranceLevel::Documented],
            ['regulator_licence', 'Regulatory licence', 'certification', true, 12, DocumentExtractor::Generic, false, AssuranceLevel::Documented],
            ['cac_certificate', 'CAC incorporation certificate', 'corporate', false, null, DocumentExtractor::Generic, false, null],
            ['security_policy', 'Information security policy', 'policy', true, 24, DocumentExtractor::Generic, true, AssuranceLevel::Documented],
            ['bcp_plan', 'Business continuity plan', 'resilience', true, 12, DocumentExtractor::Generic, true, AssuranceLevel::Documented],
            ['questionnaire_response', 'Completed questionnaire response', 'assurance', false, null, DocumentExtractor::Generic, true, AssuranceLevel::SelfAttested],
            ['site_visit_report', 'Site visit report', 'assurance', true, 24, DocumentExtractor::Generic, true, AssuranceLevel::Validated],
            ['other', 'Other', 'other', false, null, DocumentExtractor::Generic, false, null],
        ];

        foreach ($types as [$code, $name, $category, $hasExpiry, $months, $extractor, $isAssurance, $level]) {
            DB::table('tp_document_types')->updateOrInsert(
                ['organization_id' => null, 'code' => $code],
                [
                    'name' => $name,
                    'category' => $category,
                    'has_expiry' => $hasExpiry,
                    'default_validity_months' => $months,
                    'extractor' => $extractor->value,
                    'is_assurance_evidence' => $isAssurance,
                    'default_assurance_level' => $level?->value,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function seedClauseLibrary(): void
    {
        foreach (ClauseLibrary::clauses() as $clause) {
            DB::table('tp_clause_library')->updateOrInsert(
                ['organization_id' => null, 'code' => $clause['code'], 'version' => '1.0'],
                [
                    'title' => $clause['title'],
                    'category' => $clause['category'],
                    'regulatory_source' => $clause['regulatory_source'],
                    'citation' => $clause['citation'],
                    'applicability_rule' => $clause['applicability_rule'] === null
                        ? null
                        : json_encode($clause['applicability_rule']),
                    'is_blocking' => $clause['is_blocking'],
                    // Model text is deliberately absent — see the note on
                    // ClauseLibrary. A plausible clause a client pastes into a
                    // real contract is a liability, not a feature.
                    'model_text' => null,
                    'guidance' => $clause['guidance'],
                    'framework_maps' => null,
                    'status' => 'published',
                    'is_system_owned' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * Country reference data for the GEO factor.
     *
     * `supervisory_access_impeded` is NEVER written here. Asserting that a
     * jurisdiction obstructs a Nigerian supervisor is a policy determination
     * with diplomatic weight; the column exists and the scoring reads it, and
     * a tenant's risk function is what fills it in. Re-running the seeder must
     * therefore not reset a tenant's assessment, which is why the update below
     * touches region, name and score only.
     */
    private function seedCountryRisk(): void
    {
        foreach (CountryRisk::countries() as $country) {
            DB::table('tp_country_risk')->updateOrInsert(
                ['organization_id' => null, 'country_code' => $country['code']],
                [
                    'name' => $country['name'],
                    'region' => $country['region'],
                    'geo_score' => CountryRisk::REGION_SCORES[$country['region']] ?? null,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Per-tenant reference data */
    /* ------------------------------------------------------------------ */

    public function seedForOrganization(Organization $organization): void
    {
        TenantContext::set($organization->id);

        try {
            $riskAreaId = $this->ensureThirdPartyRiskArea($organization);

            $this->seedCategories($organization, $riskAreaId);
            $this->seedBusinessFunctions($organization);
            $this->seedTierPolicies($organization);
            $this->seedRuleset($organization);
        } finally {
            TenantContext::clear();
        }
    }

    /**
     * The ERM key risk area third-party exposure rolls up into.
     *
     * The taxonomy this product ships already carries `TR-TP` — "Third Party
     * Tech Risk" — under Technology Risk, and that node stays exactly where it
     * is. It is not enough on its own: it describes technology vendors, and
     * this module also covers agent networks, debt collectors, cash-in-transit,
     * outsourced processing and professional services, none of which are a
     * technology risk. So a sibling area is added under Operational Risk for
     * third-party and outsourcing exposure generally, and the ICT categories
     * still point at `TR-TP` where it exists.
     *
     * Created only if absent, and never modified if a tenant has already
     * defined its own. A tenant's taxonomy is its own; the module maps onto it
     * rather than reshaping it.
     */
    private function ensureThirdPartyRiskArea(Organization $organization): ?int
    {
        $existing = RiskCategory::query()
            ->where('organization_id', $organization->id)
            ->where('code', 'OR-TP')
            ->value('id');

        if ($existing !== null) {
            return $existing;
        }

        $operational = RiskCategory::query()
            ->where('organization_id', $organization->id)
            ->where('code', 'OR')
            ->first();

        // No operational risk area means the tenant has not seeded a taxonomy
        // at all. The module still works — engagements simply report as
        // uncategorised in the roll-up, which is a true statement rather than
        // a guess at where they belong.
        if ($operational === null) {
            return null;
        }

        return RiskCategory::create([
            'organization_id' => $organization->id,
            'parent_id' => $operational->id,
            'code' => 'OR-TP',
            'name' => 'Third-Party and Outsourcing Risk',
            'description' => 'Risk arising from reliance on third parties for services, processing and functions — '
                .'including ICT providers, outsourced processing, agents, and professional services. '
                .'Populated from the third-party risk module; residual scores sync one way, TPRM to the register.',
            'level' => ($operational->level ?? 1) + 1,
            'cbn_mapping_code' => 'CBN-OR-TP',
            'basel_category' => 'Operational Risk',
            'is_active' => true,
            'sort_order' => 90,
            'icon' => 'handshake',
            'color' => '#8D6E63',
        ])->id;
    }

    /**
     * The vendor taxonomy, mapped onto the ERM key risk areas.
     *
     * `is_prohibited_outsourcing` on a category is a second net; the binding
     * prohibition is on the business FUNCTION and is what AC-01 enforces.
     */
    private function seedCategories(Organization $organization, ?int $riskAreaId): void
    {
        // ICT categories prefer the taxonomy's existing technology-vendor node
        // where the tenant has one, so that a core banking provider reports
        // under technology risk rather than being pulled out of it.
        $technologyAreaId = RiskCategory::query()
            ->where('organization_id', $organization->id)
            ->where('code', 'TR-TP')
            ->value('id') ?? $riskAreaId;

        $categories = [
            // code, name, is_ict, tier floor, ERM area
            ['CORE-BANKING', 'Core banking and switching', true, RiskTier::Critical, $technologyAreaId],
            ['CLOUD', 'Cloud service providers', true, RiskTier::High, $technologyAreaId],
            ['PAYMENTS', 'Payment processing and switching', true, RiskTier::Critical, $technologyAreaId],
            ['CARD-PERSO', 'Card production and personalisation', true, RiskTier::High, $technologyAreaId],
            ['ATM-SERVICES', 'ATM deployment and servicing', true, RiskTier::High, $technologyAreaId],
            ['ICT-OTHER', 'Other ICT services and software', true, null, $technologyAreaId],
            ['AGENT-NETWORK', 'Agent banking network', false, RiskTier::High, $riskAreaId],
            ['DEBT-COLLECTION', 'Debt collection and recovery', false, RiskTier::High, $riskAreaId],
            ['BPO-CALL', 'BPO and call centre', false, RiskTier::Moderate, $riskAreaId],
            ['PROF-AUDIT', 'Professional services — audit', false, null, $riskAreaId],
            ['PROF-LEGAL', 'Professional services — legal', false, null, $riskAreaId],
            ['PROF-CONSULT', 'Professional services — consulting', false, null, $riskAreaId],
            ['FACILITIES', 'Facilities management', false, null, $riskAreaId],
            ['SECURITY-SVC', 'Physical security services', false, null, $riskAreaId],
            ['CIT', 'Cash-in-transit and logistics', false, RiskTier::High, $riskAreaId],
            ['HR-RECRUIT', 'HR and recruitment', false, null, $riskAreaId],
            ['MARKETING', 'Marketing and communications', false, null, $riskAreaId],
            ['TRAINING', 'Training providers', false, null, $riskAreaId],
            ['INSURANCE-BROKER', 'Insurance brokerage', false, null, $riskAreaId],
            ['CORRESPONDENT', 'Correspondent banking', false, RiskTier::High, $riskAreaId],
        ];

        foreach ($categories as $index => [$code, $name, $isIct, $floor, $ermId]) {
            DB::table('tp_categories')->updateOrInsert(
                ['organization_id' => $organization->id, 'code' => $code],
                [
                    'name' => $name,
                    'is_ict' => $isIct,
                    'default_tier_floor' => $floor?->value,
                    'erm_risk_category_id' => $ermId,
                    'is_active' => true,
                    'sort_order' => $index,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * A Nigerian bank business-function starter catalogue.
     *
     * The criticality and RTO values are A STARTING POINT for the client's own
     * business impact analysis, not our assessment of their business — a bank
     * that accepts these unchanged has not done a BIA, and the universe screen
     * says so.
     *
     * THE PROHIBITION FLAGS ARE NOT A STARTING POINT. Internal audit,
     * compliance and company secretarial may not be outsourced by a Nigerian
     * bank, the citation travels with the row, and AC-01 blocks an intake
     * naming one of them.
     */
    private function seedBusinessFunctions(Organization $organization): void
    {
        $prohibitionCitation = 'CBN Corporate Governance Guidelines 2023 §13.1, §3.6.2';

        $functions = [
            // code, name, criticality, rto hours, prohibited
            ['BF-CORE-01', 'Core banking transaction processing', 'critical', 2, false],
            ['BF-PAY-01', 'Electronic payments and funds transfer', 'critical', 2, false],
            ['BF-CARD-01', 'Card issuing and acquiring', 'critical', 4, false],
            ['BF-ATM-01', 'ATM and POS channel operation', 'critical', 4, false],
            ['BF-DIG-01', 'Internet and mobile banking channels', 'critical', 4, false],
            ['BF-AGENT-01', 'Agent banking services', 'important', 8, false],
            ['BF-TREAS-01', 'Treasury and investment operations', 'critical', 4, false],
            ['BF-CLEAR-01', 'Clearing and settlement', 'critical', 4, false],
            ['BF-CREDIT-01', 'Credit origination and administration', 'important', 24, false],
            ['BF-CUST-01', 'Customer onboarding and KYC', 'important', 24, false],
            ['BF-CS-01', 'Customer service and complaints handling', 'important', 24, false],
            ['BF-AML-01', 'AML/CFT transaction monitoring and reporting', 'critical', 8, false],
            ['BF-FIN-01', 'Financial reporting and regulatory returns', 'important', 48, false],
            ['BF-HR-01', 'Human resources administration', 'standard', 72, false],
            ['BF-PROC-01', 'Procurement and vendor administration', 'standard', 72, false],
            ['BF-FAC-01', 'Facilities and branch operations', 'standard', 48, false],
            ['BF-ICT-01', 'ICT infrastructure and network operations', 'critical', 2, false],
            ['BF-SEC-01', 'Information security operations', 'critical', 4, false],
            ['BF-BCM-01', 'Business continuity management', 'important', 24, false],

            // The three a bank may not outsource.
            ['BF-AUD-01', 'Internal audit', 'important', 72, true],
            ['BF-COMP-01', 'Compliance function', 'important', 48, true],
            ['BF-CSEC-01', 'Company secretarial', 'standard', 72, true],
        ];

        foreach ($functions as [$code, $name, $criticality, $rto, $prohibited]) {
            DB::table('tp_business_functions')->updateOrInsert(
                ['organization_id' => $organization->id, 'function_code' => $code],
                [
                    'name' => $name,
                    'criticality' => $criticality,
                    'rto_hours' => $rto,
                    'is_prohibited_outsourcing' => $prohibited,
                    'prohibition_citation' => $prohibited ? $prohibitionCitation : null,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * The shipped tiering ruleset, published as version 1.0.
     *
     * Written only when the tenant has no published ruleset at all. Once a
     * tenant publishes its own, re-running the seeder must not reintroduce
     * ours beside it — a second published ruleset would make "which rules
     * produced this score" ambiguous, which is the one thing the version stamp
     * exists to prevent.
     */
    private function seedRuleset(Organization $organization): void
    {
        $alreadyPublished = DB::table('tp_rulesets')
            ->where('organization_id', $organization->id)
            ->where('status', 'published')
            ->exists();

        if ($alreadyPublished) {
            return;
        }

        DB::table('tp_rulesets')->updateOrInsert(
            ['organization_id' => $organization->id, 'version' => DefaultRuleset::VERSION],
            [
                'name' => 'Shipped default ruleset',
                'notes' => 'The factor model of TRD §7.2 and the ten knockout rules of §7.3, as shipped. '
                    .'The REG severities and the FIN spend bands are this product\'s defaults rather than '
                    .'requirements — edit them to your own risk function\'s view and publish a new version.',
                'status' => 'published',
                'factors' => json_encode(DefaultRuleset::factors()),
                'knockouts' => json_encode(DefaultRuleset::knockouts()),
                'band_edges' => json_encode(DefaultRuleset::bandEdges()),
                'published_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * Default tier policies — TRD §6.4, FR-TIER-07.
     *
     * These four rows are what make a tier mean something. Everything
     * downstream reads them: how often the vendor is reassessed and rescreened,
     * who approves the intake, whether an exit plan is mandatory and how often
     * it must be tested, whether the engagement reaches the board.
     */
    private function seedTierPolicies(Organization $organization): void
    {
        $slaDefaults = config('tprm.defaults.remediation_sla_days');

        $policies = [
            [
                'tier' => RiskTier::Critical,
                'assessment_frequency_months' => 12,
                'screening_frequency_months' => 6,
                'monitoring_intensity' => 'continuous',
                'approval_chain' => ['tprm.intake.approve', 'tprm.tier.override'],
                'exit_plan_required' => true,
                'exit_test_frequency_months' => 12,
                'site_visit_required' => true,
                'board_reportable' => true,
            ],
            [
                'tier' => RiskTier::High,
                'assessment_frequency_months' => 12,
                'screening_frequency_months' => 12,
                'monitoring_intensity' => 'active',
                'approval_chain' => ['tprm.intake.approve'],
                'exit_plan_required' => true,
                'exit_test_frequency_months' => 24,
                'site_visit_required' => true,
                'board_reportable' => true,
            ],
            [
                'tier' => RiskTier::Moderate,
                'assessment_frequency_months' => 24,
                'screening_frequency_months' => 24,
                'monitoring_intensity' => 'passive',
                'approval_chain' => ['tprm.intake.approve'],
                'exit_plan_required' => false,
                'exit_test_frequency_months' => null,
                'site_visit_required' => false,
                'board_reportable' => false,
            ],
            [
                'tier' => RiskTier::Low,
                'assessment_frequency_months' => 36,
                'screening_frequency_months' => 36,
                'monitoring_intensity' => 'passive',
                'approval_chain' => ['tprm.intake.approve'],
                'exit_plan_required' => false,
                'exit_test_frequency_months' => null,
                'site_visit_required' => false,
                'board_reportable' => false,
            ],
        ];

        foreach ($policies as $policy) {
            $tier = $policy['tier'];
            unset($policy['tier']);

            DB::table('tp_tier_policies')->updateOrInsert(
                ['organization_id' => $organization->id, 'tier' => $tier->value],
                array_merge($policy, [
                    'approval_chain' => json_encode($policy['approval_chain']),
                    'remediation_sla' => json_encode($slaDefaults),
                    'assessment_template_ids' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
            );
        }
    }
}
