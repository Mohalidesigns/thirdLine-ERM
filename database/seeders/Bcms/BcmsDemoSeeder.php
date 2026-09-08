<?php

namespace Database\Seeders\Bcms;

use App\Enums\Bcms\ContactSource;
use App\Enums\Bcms\DistributionMode;
use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\ImpactCategory;
use App\Enums\Bcms\ImpactHorizon;
use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\LadderLevel;
use App\Enums\Bcms\PlanType;
use App\Enums\Bcms\RaciRole;
use App\Enums\Bcms\StrategyType;
use App\Models\Bcms\Application;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\BiaCampaign;
use App\Models\Bcms\BlackoutPeriod;
use App\Models\Bcms\Contact;
use App\Models\Bcms\DataSet;
use App\Models\Bcms\Equipment;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\Finding;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\Objective;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\ReadinessTask;
use App\Models\Bcms\Site;
use App\Models\Bcms\Strategy;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Bia\BiaAssessmentService;
use App\Services\Bcms\Bia\BiaCampaignService;
use App\Services\Bcms\Bia\DependencyService;
use App\Services\Bcms\Exercises\ExerciseDefinitionService;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
use App\Services\Bcms\Exercises\OccurrenceGenerator;
use App\Services\Bcms\Findings\CorrectiveActionService;
use App\Services\Bcms\Findings\FindingService;
use App\Services\Bcms\MaturityService;
use App\Services\Bcms\Plans\PlanAssembler;
use App\Services\Bcms\Plans\PlanService;
use App\Services\Bcms\PolicyService;
use App\Services\Bcms\ProgrammeService;
use App\Services\Bcms\RaciService;
use App\Services\Bcms\Reminders\ReadinessService;
use App\Services\Bcms\Reminders\ReminderScheduleBuilder;
use App\Services\Bcms\Strategy\StrategyService;
use App\Support\Bcms\AudienceRule;
use Illuminate\Database\Seeder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The demonstration estate — Blueprint Phase 0's "Kano Heritage Bank", grown
 * every phase.
 *
 * ONE DEVIATION FROM THE PHASE PROMPT, and it is deliberate. The prompt asks
 * for a demo tenant called "Kano Heritage Bank". This repository already has
 * exactly one demo organisation — `Demo Financial Institution`, created by
 * `RiskCategorySeeder` and populated by `DemoDataSeeder`, `TprmDemoSeeder` and
 * five others — and a second organisation would give the sales story a bank
 * with no risk register, no vendors and no KRIs, which is the opposite of the
 * point. The BCMS estate is therefore grown ON the existing demo tenant, and
 * "Kano Heritage Bank" is the trading name recorded on the programme. A
 * demonstration of an integrated suite has to be one tenant.
 *
 * WHAT THIS SEEDS AND WHAT IT DELIBERATELY DOES NOT. Sites, applications,
 * equipment, data sets, the BCM process overlay, the programme, the exercise
 * programme shell and a small contact roster — the substrate every later phase
 * builds on. It seeds NO occurrences, NO reminder schedules and NO call-tree
 * tests, because the generator that produces them is Phase 4's and hand-written
 * rows would be a fixture that the real generator then disagrees with.
 *
 * NO REAL PERSONAL DATA. Every contact here is invented and every phone number
 * is in the reserved 0800 range, which does not route. A seeder that put a
 * plausible Nigerian mobile number in a table an EMNS dispatcher reads is one
 * misconfiguration away from calling a stranger during a demo.
 */
class BcmsDemoSeeder extends Seeder
{
    private const TRADING_NAME = 'Kano Heritage Bank';

    /** The eight branches of Blueprint Phase 0's test data, plus head office. */
    private const BRANCHES = [
        ['KAN', 'Kano Branch', 'Kano', 'Kano', 12.0022, 8.5920],
        ['LAG', 'Lagos Branch', 'Lagos', 'Lagos', 6.4550, 3.4064],
        ['ABJ', 'Abuja Branch', 'Abuja', 'FCT', 9.0579, 7.4951],
        ['PHC', 'Port Harcourt Branch', 'Port Harcourt', 'Rivers', 4.8156, 7.0498],
        ['KAD', 'Kaduna Branch', 'Kaduna', 'Kaduna', 10.5222, 7.4383],
        ['IBA', 'Ibadan Branch', 'Ibadan', 'Oyo', 7.3775, 3.9470],
        ['ENU', 'Enugu Branch', 'Enugu', 'Enugu', 6.4584, 7.5464],
        ['MAI', 'Maiduguri Branch', 'Maiduguri', 'Borno', 11.8311, 13.1510],
    ];

    /** Primary, DR and cloud — Blueprint Phase 0's three data centres. */
    private const DATA_CENTRES = [
        ['DC-PRI', 'Primary Data Centre — Lagos', 'Lagos', 'Lagos', 6.4550, 3.4064, false],
        ['DC-DR', 'Disaster Recovery Site — Abuja', 'Abuja', 'FCT', 9.0579, 7.4951, true],
        ['DC-CLD', 'Cloud Region — af-south-1', 'Cape Town', 'Western Cape', -33.9249, 18.4241, true],
    ];

    /**
     * The fifty processes of the Phase 1 test data.
     *
     * Spread across the twelve departments and tiered the way a Nigerian
     * tier-2 bank would tier them: the payments, channels, core and card
     * services at tier 1, the things a customer notices within a day at tier
     * 2, and the back office below that. Ten are designated critical services
     * — the BOFIA/NDIC resolution-planning register — and every one of those
     * carries the justification the register needs.
     *
     * TIERS ARE SEEDED AND ARE NOT A CLAIM. A criticality tier is normally
     * written by the BIA approval path; these are a starting point so the
     * demo has a populated register on day one, and the first approved BIA
     * overwrites them.
     *
     * @var list<array{0:string, 1:string|null, 2:string, 3:int, 4:bool}>
     */
    private const PROCESSES = [
        ['BCP-PY', 'BP-PY', 'Payment processing and settlement (NIP, NEFT, RTGS)', 1, true],
        ['BCP-CHAN', null, 'Digital channels — mobile, internet and USSD banking', 1, true],
        ['BCP-CORE', null, 'Core banking transaction processing', 1, true],
        ['BCP-CARD', null, 'Card issuing, authorisation and settlement', 1, true],
        ['BCP-ATM', null, 'ATM network operation and cash replenishment', 2, true],
        ['BCP-CASH', null, 'Branch cash service and vault operations', 2, true],
        ['BCP-FX', 'BP-FX', 'FX trading and settlement', 2, true],
        ['BCP-RET', null, 'Regulatory returns and CBN reporting', 2, true],
        ['BCP-CLEAR', null, 'Cheque clearing and collections', 2, false],
        ['BCP-TREAS', null, 'Treasury and liquidity management', 2, false],
        ['BCP-LN', 'BP-LN', 'Loan origination and disbursement', 3, false],
        ['BCP-TF', 'BP-TF', 'Trade finance operations', 3, false],
        ['BCP-KY', 'BP-KY', 'Customer onboarding and KYC', 3, false],
        ['BCP-CREDIT', null, 'Credit assessment and approval', 3, false],
        ['BCP-COLL', null, 'Loan monitoring and debt recovery', 3, false],
        ['BCP-CUST', null, 'Customer service and complaints handling', 3, false],
        ['BCP-CALL', null, 'Contact centre operations', 3, false],
        ['BCP-BRANCH', null, 'Branch account services', 3, false],
        ['BCP-AGENT', null, 'Agent banking network support', 3, false],
        ['BCP-REMIT', null, 'International remittances', 3, false],
        ['BCP-CORP', null, 'Corporate banking relationship management', 3, false],
        ['BCP-PRIV', null, 'Private banking and wealth advisory', 4, false],
        ['BCP-SME', null, 'SME banking origination', 4, false],
        ['BCP-AML', null, 'AML transaction monitoring and reporting', 2, false],
        ['BCP-SANC', null, 'Sanctions and PEP screening', 2, false],
        ['BCP-FRAUD', null, 'Fraud detection and response', 2, false],
        ['BCP-COMP', null, 'Regulatory compliance monitoring', 3, false],
        ['BCP-AUDIT', null, 'Internal audit programme', 4, false],
        ['BCP-RISK', null, 'Enterprise risk management', 3, false],
        ['BCP-ORM', null, 'Operational risk and loss event management', 3, false],
        ['BCP-LEGAL', null, 'Legal advisory and litigation management', 4, false],
        ['BCP-ITOPS', null, 'IT operations and service desk', 2, false],
        ['BCP-ITSEC', null, 'Information security operations', 2, false],
        ['BCP-NET', null, 'Network and connectivity management', 2, true],
        ['BCP-DC', null, 'Data centre facilities management', 2, true],
        ['BCP-BACKUP', null, 'Backup and data protection', 2, false],
        ['BCP-CHANGE', null, 'IT change and release management', 3, false],
        ['BCP-DEV', null, 'Application development and support', 3, false],
        ['BCP-VEND', null, 'Third-party and vendor management', 3, false],
        ['BCP-PROC', null, 'Procurement and supplier payments', 4, false],
        ['BCP-FIN', null, 'Financial control and general ledger', 2, false],
        ['BCP-TAX', null, 'Tax computation and filing', 4, false],
        ['BCP-PAYROLL', null, 'Payroll processing', 3, false],
        ['BCP-HR', null, 'Human resources operations', 4, false],
        ['BCP-TRAIN', null, 'Learning and development', 4, false],
        ['BCP-FAC', null, 'Facilities and premises management', 3, false],
        ['BCP-SEC', null, 'Physical security and access control', 3, false],
        ['BCP-COMMS', null, 'Corporate communications and media relations', 3, false],
        ['BCP-MKT', null, 'Marketing and product management', 4, false],
        ['BCP-REC', null, 'Records management and archiving', 4, false],
    ];

    /** Why each designated critical service is one. @var array<string, string> */
    private const CRITICAL_SERVICE_JUSTIFICATIONS = [
        'BCP-PY' => 'Payment settlement is a service the CBN treats as critical to the national payments system; an outage stops NIP and RTGS traffic for every customer of the bank and is reportable.',
        'BCP-CHAN' => 'Digital channels carry the majority of retail transaction volume. Loss of them removes service from customers who have no branch within reach, which is the case for most of the agent-banking base.',
        'BCP-CORE' => 'Every other banking service depends on the core. Its unavailability is by definition an outage of the whole institution.',
        'BCP-CARD' => 'Card authorisation failure declines transactions at the point of sale and is visible to customers and to the scheme within minutes.',
        'BCP-ATM' => 'ATMs are the cash channel for customers who cannot reach a branch during a disruption, and a network-wide failure is a public event.',
        'BCP-CASH' => 'Branch cash service is the fallback when every electronic channel is unavailable, so it cannot itself be treated as substitutable.',
        'BCP-FX' => 'FX settlement failure creates counterparty exposure and a CBN reporting obligation on the same day.',
        'BCP-RET' => 'Failure to file regulatory returns on time is a supervisory breach independent of any customer impact.',
        'BCP-NET' => 'Connectivity is the dependency every other critical service shares; it is designated separately so that its own continuity arrangements are governed rather than assumed.',
        'BCP-DC' => 'The primary data centre hosts every tier-1 system. It is designated in its own right because a facilities failure is a different scenario from an application failure.',
    ];

    /**
     * The four divisions the existing departments hang beneath.
     *
     * THE CODES ARE THE ONES THE DEMO ORGANISATION ACTUALLY HAS. Five of the
     * original entries — `BU-RB`, `BU-CB`, `BU-RM`, `BU-CO`, `BU-FN` — matched
     * nothing, so those departments were silently left at the top of the tree.
     * Nothing depended on the hierarchy until Phase 3, where a departmental
     * plan finds its processes by walking down from its own unit; an orphaned
     * department is a plan with an empty recovery-objectives table.
     */
    private const DIVISIONS = [
        ['DIV-RB', 'Retail Banking Division', ['BU-RT', 'BU-CX', 'BU-PB', 'BU-RES', 'BU-DB']],
        ['DIV-CB', 'Corporate Banking Division', ['BU-IB', 'BU-TR', 'BU-SB', 'BU-LMDR']],
        ['DIV-OPS', 'Operations and Technology Division', ['BU-OP', 'BU-IT']],
        ['DIV-CTL', 'Control Division', ['BU-ERM', 'BU-CIC', 'BU-IA', 'BU-LG', 'BU-FC', 'BU-HR', 'BU-SBP', 'BU-BM']],
    ];

    public function run(): void
    {
        $organization = Organization::query()->where('cbn_institution_code', 'NGN/COM/0001')->first();

        if ($organization === null) {
            $this->command?->warn('BCMS demo seeder: no demo organisation found; skipping.');

            return;
        }

        TenantContext::set($organization->id);

        try {
            $sites = $this->seedSites();
            $this->seedDivisions();
            $applications = $this->seedApplications($sites);
            $this->seedEquipment($sites);
            $this->seedDataSets($applications);
            $this->seedProcesses();
            $this->assignProcessOwners();
            $programme = $this->seedProgramme();
            $this->seedExerciseProgramme($programme);
            $this->seedContacts($sites);

            // Phase 1 governance: the policy, the scope set, the obligation
            // register, RACI, one management review, a worked finding, and a
            // maturity assessment over the lot.
            $this->seedPolicy($programme);
            $this->seedScopeAndObligations($programme);
            $this->seedRaci();
            $this->seedObjectives($programme);
            $this->seedManagementReview($programme);
            $this->seedWorkedFinding();
            $this->seedBiaCampaign();

            // Phase 3: the strategy register with three deliberate gaps, and
            // the plan estate those strategies feed.
            $this->assignProcessUnits();
            $this->seedStrategies();
            $this->seedPlans($sites);

            // Phase 4: next year's exercise programme, generated.
            $this->seedExerciseCalendar($sites);

            // Phase 5: participants, and one exercise close enough that the
            // countdown is live.
            $this->seedCountdownDemo();

            app(MaturityService::class)->assess($programme, 'scheduled');
        } finally {
            TenantContext::clear();
        }
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, Site> */
    private function seedSites(): array
    {
        $sites = [];

        $sites['HQ'] = Site::query()->updateOrCreate(
            ['code' => 'HQ'],
            [
                'name' => self::TRADING_NAME.' — Head Office, Kano',
                'site_type' => 'office',
                'city' => 'Kano', 'state' => 'Kano', 'country' => 'NG',
                'latitude' => 12.0022, 'longitude' => 8.5920,
                'headcount' => 420,
                'is_active' => true,
            ]
        );

        foreach (self::BRANCHES as $branch) {
            [$code, $name, $city, $state, $lat, $lng] = $branch;

            $sites[$code] = Site::query()->updateOrCreate(
                ['code' => 'BR-'.$code],
                [
                    'name' => $name,
                    'site_type' => 'branch',
                    'city' => $city, 'state' => $state, 'country' => 'NG',
                    'latitude' => $lat, 'longitude' => $lng,
                    'headcount' => 24,
                    'is_active' => true,
                ]
            );
        }

        foreach (self::DATA_CENTRES as $dc) {
            [$code, $name, $city, $state, $lat, $lng, $isRecovery] = $dc;

            $sites[$code] = Site::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'site_type' => 'data_centre',
                    'city' => $city, 'state' => $state,
                    // The cloud region is in South Africa. It is seeded that
                    // way rather than as Nigeria because af-south-1 residency
                    // is an NDPA question a demo should raise, not hide.
                    'country' => $code === 'DC-CLD' ? 'ZA' : 'NG',
                    'latitude' => $lat, 'longitude' => $lng,
                    'is_recovery_site' => $isRecovery,
                    'is_active' => true,
                ]
            );
        }

        // The DR site recovers the primary. Stated as an edge rather than left
        // to a reader to infer from the names.
        $sites['DC-PRI']->update(['recovery_site_id' => $sites['DC-DR']->id]);

        return $sites;
    }

    private function seedDivisions(): void
    {
        foreach (self::DIVISIONS as $index => [$code, $name, $childCodes]) {
            $division = BusinessUnit::query()->updateOrCreate(
                ['organization_id' => TenantContext::organizationIdOrNull(), 'code' => $code],
                ['name' => $name, 'is_active' => true, 'sort_order' => 100 + $index]
            );

            // Reparent the existing departments beneath their division. Only
            // where a department has no parent already — a seeder that
            // rearranged a hierarchy somebody had edited would be destroying
            // their work to tidy a demo.
            BusinessUnit::query()
                ->whereIn('code', $childCodes)
                ->whereNull('parent_id')
                ->update(['parent_id' => $division->id]);
        }
    }

    /** @param array<string, Site> $sites @return array<string, Application> */
    private function seedApplications(array $sites): array
    {
        $definitions = [
            ['APP-CORE', 'Core Banking Platform', 'on_premise', 'DC-PRI'],
            ['APP-SWITCH', 'Payments Switch', 'on_premise', 'DC-PRI'],
            ['APP-MOBILE', 'Mobile and Internet Banking', 'saas', 'DC-CLD'],
            ['APP-USSD', 'USSD Banking Channel', 'on_premise', 'DC-PRI'],
            ['APP-CARD', 'Card Management System', 'on_premise', 'DC-PRI'],
            ['APP-TREAS', 'Treasury and FX System', 'on_premise', 'DC-PRI'],
            ['APP-AML', 'AML and Transaction Monitoring', 'saas', 'DC-CLD'],
            ['APP-EMAIL', 'Corporate Email and Collaboration', 'saas', 'DC-CLD'],
            ['APP-HR', 'HR and Payroll', 'saas', 'DC-CLD'],
            ['APP-GL', 'General Ledger and Reporting', 'on_premise', 'DC-PRI'],
            ['APP-ATM', 'ATM Switch and Monitoring', 'on_premise', 'DC-PRI'],
            ['APP-AGENT', 'Agent Banking Platform', 'saas', 'DC-CLD'],
            ['APP-LOAN', 'Loan Origination System', 'on_premise', 'DC-PRI'],
            ['APP-COLL', 'Collections and Recovery', 'on_premise', 'DC-PRI'],
            ['APP-TRADE', 'Trade Finance System', 'on_premise', 'DC-PRI'],
            ['APP-CRM', 'Customer Relationship Management', 'saas', 'DC-CLD'],
            ['APP-CONTACT', 'Contact Centre Platform', 'saas', 'DC-CLD'],
            ['APP-DMS', 'Document Management System', 'on_premise', 'DC-PRI'],
            ['APP-SIEM', 'Security Monitoring (SIEM)', 'on_premise', 'DC-PRI'],
            ['APP-BACKUP', 'Backup and Replication', 'on_premise', 'DC-DR'],
            ['APP-AD', 'Directory Services', 'on_premise', 'DC-PRI'],
            ['APP-NET', 'Network Management', 'on_premise', 'DC-PRI'],
            ['APP-RETURNS', 'Regulatory Returns Engine', 'on_premise', 'DC-PRI'],
            ['APP-RECON', 'Reconciliation Engine', 'on_premise', 'DC-PRI'],
            ['APP-SWIFT', 'SWIFT Gateway', 'on_premise', 'DC-PRI'],
        ];

        $applications = [];

        foreach ($definitions as [$code, $name, $hosting, $siteCode]) {
            $applications[$code] = Application::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'hosting_model' => $hosting,
                    'hosting_location' => $siteCode === 'DC-CLD' ? 'af-south-1' : 'Lagos',
                    'primary_site_id' => $sites[$siteCode]->id ?? null,
                    'is_active' => true,
                ]
            );
        }

        return $applications;
    }

    /** @param array<string, Site> $sites */
    private function seedEquipment(array $sites): void
    {
        foreach (['HQ', 'DC-PRI', 'DC-DR'] as $siteCode) {
            $site = $sites[$siteCode] ?? null;

            if ($site === null) {
                continue;
            }

            foreach ([
                ['GEN', 'Standby generator', 'generator', 2],
                ['UPS', 'Uninterruptible power supply', 'ups', 2],
                ['VSAT', 'VSAT backup link', 'vsat', 1],
            ] as [$suffix, $name, $type, $quantity]) {
                Equipment::query()->updateOrCreate(
                    ['code' => $siteCode.'-'.$suffix],
                    [
                        'name' => $name.' — '.$site->name,
                        'equipment_type' => $type,
                        'site_id' => $site->id,
                        'quantity' => $quantity,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    /** @param array<string, Application> $applications */
    private function seedDataSets(array $applications): void
    {
        $definitions = [
            ['DS-CUST', 'Customer master data', 'restricted', true, 'NG', 'APP-CORE'],
            ['DS-TXN', 'Transaction history', 'restricted', true, 'NG', 'APP-CORE'],
            ['DS-STAFF', 'Staff records and emergency contacts', 'confidential', true, 'NG', 'APP-HR'],
            ['DS-GL', 'General ledger and regulatory returns', 'confidential', false, 'NG', 'APP-GL'],
        ];

        foreach ($definitions as [$code, $name, $classification, $personal, $residency, $appCode]) {
            DataSet::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'classification' => $classification,
                    'contains_personal_data' => $personal,
                    'residency_country' => $residency,
                    'primary_application_id' => $applications[$appCode]->id ?? null,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * The BCM overlay on the org's existing process catalogue.
     *
     * Every row links to a `business_processes` row where one exists — that is
     * the reuse rule of ADR 0001 made visible in the demo rather than only
     * stated in a document.
     */
    private function seedProcesses(): void
    {
        $definitions = self::PROCESSES;

        foreach ($definitions as [$code, $sourceCode, $name, $tier, $criticalService]) {
            $source = $sourceCode === null
                ? null
                : BusinessProcess::query()->where('code', $sourceCode)->first();

            Process::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'business_process_id' => $source?->id,
                    'business_unit_id' => $source?->business_unit_id,
                    'owner_id' => $source?->owner_id,
                    // A tier is normally written by the BIA approval path. It
                    // is seeded here so the demo has a populated register on
                    // day one, and every row is overwritten the first time a
                    // BIA is approved against it.
                    'criticality_tier' => $tier,
                    'is_critical_service' => $criticalService,
                    // A regulatory designation with no justification is one
                    // nobody can defend at an examination, and the importer
                    // refuses one — so the demo must not ship one either.
                    'critical_service_justification' => $criticalService
                        ? self::CRITICAL_SERVICE_JUSTIFICATIONS[$code] ?? null
                        : null,
                    'iso_clause_ref' => IsoClauseRef::Iso22301_8_2_2->value,
                    'status' => 'active',
                ]
            );
        }
    }

    /**
     * Give every process an owner.
     *
     * The eight linked to the org's own catalogue inherit its owner; the rest
     * are spread across the active users. Without this a campaign distributes
     * fifty assessments and asks five people, which is exactly the failure
     * `distribute()` reports rather than hides.
     */
    private function assignProcessOwners(): void
    {
        $users = User::query()->where('is_active', true)->orderBy('id')->get();

        if ($users->isEmpty()) {
            return;
        }

        $unowned = Process::query()->whereNull('owner_id')->orderBy('code')->get();

        foreach ($unowned as $index => $process) {
            $process->update(['owner_id' => $users[$index % $users->count()]->id]);
        }
    }

    private function seedProgramme(): Programme
    {
        return Programme::query()->updateOrCreate(
            ['year' => (int) now()->year, 'name' => self::TRADING_NAME.' BCMS Programme'],
            [
                'scope_statement' => 'The business continuity management system covers '.self::TRADING_NAME
                    ."'s head office, eight branches and three data centres, and the prioritised activities "
                    .'that deliver payments, digital channels, branch cash service and regulatory reporting.',
                'out_of_scope_statement' => 'Subsidiary insurance brokerage operations, which maintain their own arrangements.',
                'owner_id' => User::query()->where('email', 'admin@risk.test')->value('id'),
                'status' => 'active',
                'iso_clause_ref' => IsoClauseRef::Iso22301_4_3->value,
            ]
        );
    }

    private function seedExerciseProgramme(Programme $programme): void
    {
        ExerciseProgramme::query()->updateOrCreate(
            ['year' => (int) now()->year, 'name' => 'Annual exercise programme '.now()->year],
            [
                'programme_id' => $programme->id,
                'status' => 'draft',
                'iso_clause_ref' => IsoClauseRef::Iso22301_8_5_programme->value,
            ]
        );

        // No definitions and no occurrences: the generator is Phase 4's, and a
        // hand-written occurrence is a fixture the real generator will disagree
        // with.
    }

    /**
     * A small invented roster.
     *
     * EVERY NUMBER IS IN THE RESERVED 0800 RANGE AND DOES NOT ROUTE. A seeder
     * that wrote a plausible Nigerian mobile number into a table an EMNS
     * dispatcher reads is one misconfiguration away from calling a stranger
     * during a demo.
     *
     * @param  array<string, Site>  $sites
     */
    private function seedContacts(array $sites): void
    {
        $users = User::query()->where('is_active', true)->orderBy('id')->limit(8)->get();

        foreach ($users as $index => $user) {
            Contact::query()->updateOrCreate(
                ['employee_id' => 'KHB-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT)],
                [
                    'user_id' => $user->id,
                    'source' => ContactSource::Manual->value,
                    'full_name' => $user->name,
                    'business_unit_id' => $user->business_unit_id,
                    'site_id' => $sites['HQ']->id ?? null,
                    'title' => $user->job_title,
                    'email' => $user->email,
                    'mobile_primary' => '+2348000'.str_pad((string) (100 + $index), 6, '0', STR_PAD_LEFT),
                    'preferred_language' => 'en',
                    // Not granted. A demo roster that shipped with consent
                    // already recorded would teach the wrong lesson about the
                    // one control the NDPA actually requires here.
                    'consent_status' => 'not_requested',
                    'verification_status' => 'unverified',
                    'is_active' => true,
                ]
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Phase 1 governance */
    /* ------------------------------------------------------------------ */

    private function seedPolicy(Programme $programme): void
    {
        $policies = app(PolicyService::class);

        if ($policies->history()->isNotEmpty()) {
            return;
        }

        $author = User::query()->where('email', 'admin@risk.test')->first();
        $approver = User::query()->where('id', '!=', $author?->id)->where('is_active', true)->first();

        $policy = $policies->draft(self::TRADING_NAME.' Business Continuity Policy', [
            'owner_id' => $author?->id,
            'next_review_date' => now()->addYear()->toDateString(),
            'content' => [
                'purpose' => 'To establish, maintain and continually improve a business continuity management '
                    .'system that protects the delivery of '.self::TRADING_NAME."'s prioritised activities through "
                    .'a disruption.',
                'commitment' => 'The board commits the resources needed to maintain the BCMS, and requires that '
                    .'every prioritised activity is exercised on a stated rhythm rather than documented and left.',
                'scope' => $programme->scope_statement,
            ],
        ], $author?->id);

        if ($approver === null) {
            // Nothing to approve with. Leaving the policy as a draft is the
            // honest outcome; inventing a second user to sign it would put a
            // fabricated approval into a compliance artefact.
            return;
        }

        $policies->approve($policy, $approver);
        $policies->attest($policy, $approver, (int) now()->year, 'board');

        $programme->update(['policy_plan_id' => $policy->getKey()]);
    }

    private function seedScopeAndObligations(Programme $programme): void
    {
        // Every division is in scope; the demo records one deliberate exclusion
        // so the screen shows what a justified exclusion looks like, which is
        // what clause 4.3 actually asks for.
        foreach (self::DIVISIONS as [$code, $name, $children]) {
            $division = BusinessUnit::query()->where('code', $code)->first();

            if ($division !== null) {
                app(ProgrammeService::class)->setScope($programme, $division, true);
            }
        }

        $marketing = Process::query()->where('code', 'BCP-MKT')->first();

        if ($marketing !== null) {
            app(ProgrammeService::class)->setScope(
                $programme,
                $marketing,
                false,
                'Marketing and product management has no recovery time objective inside the maximum tolerable '
                .'period of disruption for any prioritised activity, and is resumed after the BCMS scope is restored.'
            );
        }

        $programme->update([
            'interested_parties' => [
                'Central Bank of Nigeria', 'Nigeria Deposit Insurance Corporation',
                'Nigeria Data Protection Commission', 'Customers and depositors',
                'The board and its risk committee', 'Staff', 'Critical service providers',
            ],
        ]);

        app(ProgrammeService::class)->seedObligations($programme);
    }

    /**
     * RACI over most of the catalogue, and deliberately not all of it.
     *
     * Six processes are left with nobody accountable, because the gap report is
     * a headline feature and a demo where it reads "0 gaps" demonstrates
     * nothing. They are mid-tier on purpose: leaving a tier-1 critical service
     * unowned would look like a defect rather than a finding.
     */
    private function seedRaci(): void
    {
        $raci = app(RaciService::class);
        $users = User::query()->where('is_active', true)->orderBy('id')->get();

        if ($users->isEmpty()) {
            return;
        }

        $processes = Process::query()->orderBy('code')->get();
        $withoutOwner = ['BCP-TAX', 'BCP-TRAIN', 'BCP-REC', 'BCP-MKT', 'BCP-SME', 'BCP-PRIV'];

        foreach ($processes as $index => $process) {
            $responsible = $users[$index % $users->count()];
            $raci->assign($process, $responsible->id, RaciRole::Responsible);

            if (in_array($process->code, $withoutOwner, true)) {
                continue;
            }

            $accountable = $users[($index + 1) % $users->count()];
            $raci->assign($process, $accountable->id, RaciRole::Accountable);
        }
    }

    private function seedObjectives(Programme $programme): void
    {
        if (Objective::query()->where('programme_id', $programme->getKey())->exists()) {
            return;
        }

        $owner = User::query()->where('email', 'admin@risk.test')->value('id');

        $objectives = [
            ['Every tier-1 and tier-2 process has an approved BIA', 'Approved BIA coverage of tier 1-2 processes', 100, '%', 0],
            ['Every approved plan is inside its review date', 'Plans within review date', 95, '%', 0],
            ['The annual exercise programme is delivered in full', 'Exercises completed against planned', 100, '%', 0],
            ['No corrective action is more than 30 days overdue', 'Corrective actions over 30 days overdue', 0, 'actions', 0],
        ];

        foreach ($objectives as [$title, $measure, $target, $unit, $baseline]) {
            Objective::query()->create([
                'programme_id' => $programme->getKey(),
                'title' => $title,
                'measure_description' => $measure,
                'target_value' => $target,
                'target_unit' => $unit,
                // A baseline of zero is a real measurement at the start of a
                // programme, not a placeholder — and clause 6.2 needs one or
                // the objective cannot show movement.
                'baseline_value' => $baseline,
                'baseline_captured_at' => now(),
                'target_date' => now()->endOfYear()->toDateString(),
                'owner_id' => $owner,
                'status' => 'open',
                'iso_clause_ref' => IsoClauseRef::Iso22301_6_2->value,
            ]);
        }
    }

    private function seedManagementReview(Programme $programme): void
    {
        if (ManagementReview::query()->where('programme_id', $programme->getKey())->exists()) {
            return;
        }

        $chair = User::query()->where('is_active', true)->value('id');

        $review = app(ProgrammeService::class)->openManagementReview($programme, 'Annual management review of the BCMS', [
            'held_on' => now()->startOfYear()->addMonths(2)->toDateString(),
            'chaired_by' => $chair,
            'attendees' => ['Chief Risk Officer', 'Chief Operating Officer', 'Head of IT', 'Head of Internal Audit', 'Company Secretary'],
            'discussion' => 'The committee reviewed the state of the BCMS against clause 9.3 and noted that the '
                .'exercise programme for the year has not yet been approved.',
            'decisions' => [
                'Approve the annual exercise programme before the end of the quarter.',
                'Confirm accountable owners for the six processes the RACI gap report identifies.',
            ],
        ]);

        app(ProgrammeService::class)->captureReviewInputs($review);
    }

    /**
     * One worked finding, end to end.
     *
     * The register's whole pipeline — raise, assign, complete, verify — is what
     * three other tracks will consume, and a demo tenant where it has never
     * been exercised is a demo where the first click is the first test.
     */
    private function seedWorkedFinding(): void
    {
        if (Finding::query()->exists()) {
            return;
        }

        $users = User::query()->where('is_active', true)->orderBy('id')->take(3)->get();

        if ($users->count() < 2) {
            return;
        }

        $process = Process::query()->where('code', 'BCP-NET')->first();

        $finding = app(FindingService::class)->raise(
            FindingSource::GapAnalysis,
            FindingClassification::Nonconformity,
            'The two terrestrial links to the primary data centre share their last two kilometres of physical '
            .'path, so the documented diverse routing does not exist.',
            null,
            [
                'severity' => 'high',
                'root_cause' => 'The diversity was specified in the contract and never verified against the '
                    .'provider\'s as-built records.',
                'iso_clause_ref' => IsoClauseRef::Iso22301_8_3->value,
                'affected_process_id' => $process?->getKey(),
            ],
            $users[0]->id,
        );

        $actions = app(CorrectiveActionService::class);

        $action = $actions->create($finding, 'Obtain as-built path records from both carriers and procure a '
            .'genuinely diverse second path', [
                'owner_id' => $users[1]->id,
                'due_date' => now()->addMonths(3)->toDateString(),
                'priority' => 'high',
            ], $users[0]->id);

        $actions->assign($action, $users[1]->id, now()->addMonths(3)->toDateString());

        // Left open on purpose. A demo whose only finding is already closed
        // shows the register at rest rather than at work.
    }

    /* ------------------------------------------------------------------ */
    /*  Phase 2 — the BIA estate */
    /* ------------------------------------------------------------------ */

    /**
     * The recovery objectives and dependencies for the twelve processes the
     * demo actually walks through.
     *
     * Tier, RTO in hours, RPO in minutes, and what the process rests on.
     * FOUR DELIBERATE SINGLE POINTS OF FAILURE and one dependency four
     * processes share, because a dependency screen with neither shows a feature
     * working and demonstrates nothing.
     *
     * @var array<string, array<string, mixed>>
     */
    private const BIA_PROFILE = [
        'BCP-PY' => ['tier' => 1, 'rto' => 0.5, 'rpo' => 0, 'mtpd' => 4,
            'mbco' => 'NIP inbound and outbound settlement for retail values, with corporate payments queued.',
            'deps' => [['applications', 'APP-SWITCH', 'critical', true], ['applications', 'APP-CORE', 'critical', false], ['applications', 'APP-NET', 'critical', true], ['sites', 'DC-PRI', 'critical', false]]],
        'BCP-CHAN' => ['tier' => 1, 'rto' => 1, 'rpo' => 15, 'mtpd' => 8,
            'mbco' => 'Balance enquiry and intra-bank transfer on USSD; full mobile app service may follow.',
            'deps' => [['applications', 'APP-MOBILE', 'critical', false], ['applications', 'APP-USSD', 'high', false], ['applications', 'APP-CORE', 'critical', false], ['applications', 'APP-NET', 'critical', true]]],
        'BCP-CORE' => ['tier' => 1, 'rto' => 2, 'rpo' => 0, 'mtpd' => 8,
            'mbco' => 'Deposit, withdrawal and balance enquiry against the current ledger.',
            'deps' => [['applications', 'APP-CORE', 'critical', true], ['sites', 'DC-PRI', 'critical', false], ['applications', 'APP-AD', 'high', false], ['applications', 'APP-NET', 'critical', true]]],
        'BCP-CARD' => ['tier' => 1, 'rto' => 1, 'rpo' => 5, 'mtpd' => 6,
            'mbco' => 'Card authorisation at point of sale; issuing and personalisation can wait.',
            'deps' => [['applications', 'APP-CARD', 'critical', false], ['applications', 'APP-SWITCH', 'critical', true], ['applications', 'APP-NET', 'critical', true]]],
        'BCP-ATM' => ['tier' => 2, 'rto' => 4, 'rpo' => 30, 'mtpd' => 24,
            'mbco' => 'Cash withdrawal at branch-attached ATMs.',
            'deps' => [['applications', 'APP-ATM', 'high', false], ['applications', 'APP-SWITCH', 'critical', true], ['equipment', 'HQ-GEN', 'medium', false]]],
        'BCP-CASH' => ['tier' => 2, 'rto' => 4, 'rpo' => 60, 'mtpd' => 24,
            'mbco' => 'Counter cash service at head office and the four largest branches.',
            'deps' => [['applications', 'APP-CORE', 'critical', false], ['sites', 'BR-KAN', 'high', false], ['equipment', 'HQ-GEN', 'high', false]]],
        'BCP-FX' => ['tier' => 2, 'rto' => 4, 'rpo' => 15, 'mtpd' => 24,
            'mbco' => 'Settlement of trades already struck; new dealing may be suspended.',
            'deps' => [['applications', 'APP-TREAS', 'critical', false], ['applications', 'APP-SWIFT', 'critical', true], ['applications', 'APP-NET', 'high', true]]],
        'BCP-RET' => ['tier' => 2, 'rto' => 8, 'rpo' => 240, 'mtpd' => 48,
            'mbco' => 'The returns falling due inside the next five working days.',
            'deps' => [['applications', 'APP-RETURNS', 'high', false], ['applications', 'APP-GL', 'high', false], ['data_sets', 'DS-GL', 'critical', false]]],
        'BCP-NET' => ['tier' => 2, 'rto' => 2, 'rpo' => 0, 'mtpd' => 8,
            'mbco' => 'One working path between head office, the primary data centre and the switch.',
            'deps' => [['applications', 'APP-NET', 'critical', true], ['sites', 'DC-PRI', 'critical', false]]],
        'BCP-LN' => ['tier' => 3, 'rto' => 24, 'rpo' => 480, 'mtpd' => 72,
            'mbco' => 'Disbursement of loans already approved; new applications may queue.',
            'deps' => [['applications', 'APP-LOAN', 'high', false], ['applications', 'APP-CORE', 'high', false]]],
        'BCP-KY' => ['tier' => 3, 'rto' => 24, 'rpo' => 480, 'mtpd' => 72,
            'mbco' => 'Account opening for walk-in customers on a manual form, keyed later.',
            'deps' => [['applications', 'APP-CRM', 'medium', false], ['applications', 'APP-DMS', 'medium', false]]],
        'BCP-AML' => ['tier' => 2, 'rto' => 8, 'rpo' => 60, 'mtpd' => 48,
            'mbco' => 'Screening of transactions above the reporting threshold.',
            'deps' => [['applications', 'APP-AML', 'critical', false], ['data_sets', 'DS-TXN', 'critical', false]]],
    ];

    /**
     * A distributed, part-completed BIA campaign.
     *
     * DELIBERATELY NOT ALL APPROVED. Twelve processes are assessed and approved;
     * the other thirty-eight are outstanding, so the report's coverage figure
     * and its gap list have something to say. A demo tenant at 100% coverage
     * shows a screen that never has to be honest.
     */
    private function seedBiaCampaign(): void
    {
        if (BiaCampaign::query()->exists()) {
            return;
        }

        $campaigns = app(BiaCampaignService::class);
        $assessments = app(BiaAssessmentService::class);
        $dependencies = app(DependencyService::class);

        $campaign = $campaigns->create([
            'name' => 'Annual business impact analysis '.now()->year,
            'cycle' => 'annual',
            'opens_at' => now()->subWeeks(6),
            'closes_at' => now()->subWeek(),
        ]);

        $campaigns->distribute($campaign);

        $approver = User::query()->where('email', 'admin@risk.test')->first()
            ?? User::query()->where('is_active', true)->first();

        foreach (self::BIA_PROFILE as $code => $profile) {
            $process = Process::query()->where('code', $code)->first();

            if ($process === null) {
                continue;
            }

            $assessment = BiaAssessment::query()
                ->where('campaign_id', $campaign->getKey())
                ->where('process_id', $process->getKey())
                ->first();

            if ($assessment === null) {
                continue;
            }

            $this->scoreGrid($assessments, $assessment, $profile['mtpd']);

            $assessments->save($assessment->refresh(), [
                'mtpd_hours' => $profile['mtpd'],
                'rto_hours' => $profile['rto'],
                'rpo_minutes' => $profile['rpo'],
                'mbco_description' => $profile['mbco'],
                'min_staff_required' => max(2, (int) round($profile['rto'])),
                // Month-end, salary week and the festive period matter enormously
                // in Nigerian banking, and a BIA that ignores them plans for a
                // quiet Tuesday.
                'peak_periods' => ['Month-end (last three working days)', 'Salary week (25th–28th)', 'December festive period'],
                'workaround_available' => $profile['tier'] > 1,
                'workaround_max_duration_hours' => $profile['tier'] > 1 ? $profile['rto'] * 2 : null,
            ]);

            foreach ($profile['deps'] as [$type, $targetCode, $criticality, $spof]) {
                $target = $this->dependencyTarget($type, $targetCode);

                if ($target === null) {
                    continue;
                }

                $dependencies->attach($assessment->refresh(), $target, [
                    'dependency_type' => 'upstream',
                    'criticality' => $criticality,
                    'single_point_of_failure' => $spof,
                    // A single point of failure cannot also have an alternative;
                    // the form request refuses that pairing and the demo must
                    // not ship it.
                    'alternative_available' => ! $spof && $criticality !== 'critical',
                    'recovery_notes' => $spof
                        ? 'No diverse alternative is in place. This is the gap, not a note.'
                        : null,
                ]);
            }

            $assessments->submit($assessment->refresh());

            if ($approver !== null && (int) $approver->getKey() !== (int) $assessment->assessor_id) {
                $assessments->approve($assessment->refresh(), (int) $approver->getKey(), $profile['tier']);
            }
        }
    }

    /**
     * Fill the impact grid so the derived MTPD lands on the profile's figure.
     *
     * Impact ramps with time and crosses the intolerable score exactly at the
     * intended horizon, which is what makes the derived proposal agree with the
     * recorded answer on a demo tenant — and lets a demo show the two differing
     * by editing one cell.
     */
    private function scoreGrid(BiaAssessmentService $assessments, BiaAssessment $assessment, float $mtpdHours): void
    {
        foreach (ImpactHorizon::cases() as $horizon) {
            $hours = $horizon->hours();

            $severity = match (true) {
                $hours < $mtpdHours / 4 => 1,
                $hours < $mtpdHours / 2 => 2,
                $hours < $mtpdHours => 3,
                $hours == $mtpdHours => 4,
                default => 5,
            };

            foreach ([ImpactCategory::Regulatory, ImpactCategory::Customer, ImpactCategory::Financial] as $category) {
                $assessments->scoreImpact(
                    $assessment,
                    $category,
                    $horizon,
                    $category === ImpactCategory::Regulatory ? $severity : max(1, $severity - 1),
                    $category === ImpactCategory::Financial && $severity >= 4 ? 50_000_000_00 * $severity : null,
                    sprintf('At %s the %s impact is rated %d of 5.', $horizon->value, $category->value, $severity),
                );
            }
        }
    }

    private function dependencyTarget(string $type, string $code): ?\Illuminate\Database\Eloquent\Model
    {
        return match ($type) {
            'applications' => Application::query()->where('code', $code)->first(),
            'sites' => Site::query()->where('code', $code)->first(),
            'equipment' => Equipment::query()->where('code', $code)->first(),
            'data_sets' => DataSet::query()->where('code', $code)->first(),
            'processes' => Process::query()->where('code', $code)->first(),
            default => null,
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Phase 3 — strategy and plans */
    /* ------------------------------------------------------------------ */

    /**
     * Which department owns each process.
     *
     * ONLY THE EIGHT LINKED TO THE ORG'S OWN CATALOGUE INHERITED A UNIT, and
     * the other forty were left at organisation level. That is fine for a
     * register and useless for a departmental plan: a plan scoped to IT
     * Operations finds its processes through `business_unit_id`, and a
     * catalogue where every process is organisation-level produces a
     * departmental BCP with an empty recovery-objectives table. Assigning them
     * is what makes the demo show the binding working rather than the empty
     * state.
     *
     * @var array<string, string> process code prefix or code => business unit code
     */
    private const PROCESS_UNITS = [
        'BCP-PY' => 'BU-OP', 'BCP-CORE' => 'BU-OP', 'BCP-CLEAR' => 'BU-OP', 'BCP-CASH' => 'BU-OP',
        'BCP-RET' => 'BU-FC', 'BCP-FIN' => 'BU-FC', 'BCP-TAX' => 'BU-FC', 'BCP-PROC' => 'BU-FC',
        'BCP-CHAN' => 'BU-IT', 'BCP-CARD' => 'BU-IT', 'BCP-ATM' => 'BU-IT', 'BCP-ITOPS' => 'BU-IT',
        'BCP-ITSEC' => 'BU-IT', 'BCP-NET' => 'BU-IT', 'BCP-DC' => 'BU-IT', 'BCP-BACKUP' => 'BU-IT',
        'BCP-CHANGE' => 'BU-IT', 'BCP-DEV' => 'BU-IT',
        'BCP-FX' => 'BU-TR', 'BCP-TREAS' => 'BU-TR',
        'BCP-LN' => 'BU-RT', 'BCP-KY' => 'BU-RT', 'BCP-CREDIT' => 'BU-RT', 'BCP-COLL' => 'BU-LMDR',
        'BCP-BRANCH' => 'BU-RT', 'BCP-AGENT' => 'BU-RT', 'BCP-SME' => 'BU-RT', 'BCP-REMIT' => 'BU-RT',
        'BCP-CUST' => 'BU-CX', 'BCP-CALL' => 'BU-CX',
        'BCP-CORP' => 'BU-IB', 'BCP-TF' => 'BU-IB',
        'BCP-PRIV' => 'BU-PB',
        'BCP-AML' => 'BU-CIC', 'BCP-SANC' => 'BU-CIC', 'BCP-COMP' => 'BU-CIC',
        'BCP-FRAUD' => 'BU-ERM', 'BCP-RISK' => 'BU-ERM', 'BCP-ORM' => 'BU-ERM', 'BCP-VEND' => 'BU-ERM',
        'BCP-AUDIT' => 'BU-IA',
        'BCP-LEGAL' => 'BU-LG',
        'BCP-HR' => 'BU-HR', 'BCP-PAYROLL' => 'BU-HR', 'BCP-TRAIN' => 'BU-HR',
    ];

    /**
     * The strategy register — ISO 22331, with three deliberate gaps.
     *
     * THE GAPS ARE THE POINT OF THE DEMO. Payments cannot be recovered in the
     * thirty minutes the BIA demands by the strategy the bank has actually
     * funded; cards and regulatory returns are the same story at different
     * scales. A demo estate where every strategy meets its objective would show
     * the gap-analysis screen as an empty table, which is the one screen a
     * prospect asks to see.
     *
     * @var array<string, list<array{0:string, 1:string, 2:int, 3:float, 4:bool}>>
     *                                                                             process code => [type, title, cost in whole naira, achievable RTO hours, selected]
     */
    private const STRATEGIES = [
        'BCP-PY' => [
            ['recover', 'Restore the switch interface at the primary data centre', 18_000_000, 1.5, true],
            ['relocate', 'Fail the switch interface over to the Abuja DR site', 240_000_000, 0.5, false],
            ['manual_workaround', 'Queue outbound NIP and release on restoration', 0, 6.0, false],
        ],
        'BCP-CHAN' => [
            ['recover', 'Restart channel middleware on standby capacity', 42_000_000, 1.0, true],
            ['manual_workaround', 'USSD-only service with balance and intra-bank transfer', 3_000_000, 4.0, false],
        ],
        'BCP-CORE' => [
            ['relocate', 'Warm standby of the core at the Abuja DR site', 310_000_000, 2.0, true],
            ['recover', 'Rebuild the core in place from the last verified backup', 12_000_000, 26.0, false],
        ],
        'BCP-CARD' => [
            ['recover', 'Restore card authorisation on the primary platform', 22_000_000, 2.0, true],
            ['outsource', 'Stand-in authorisation by the scheme, with limits', 95_000_000, 0.75, false],
        ],
        'BCP-ATM' => [
            ['recover', 'Restore the ATM controller and reload cassettes', 8_000_000, 3.0, true],
            ['accept', 'Direct customers to branches and agent locations', 0, 24.0, false],
        ],
        'BCP-CASH' => [
            ['relocate', 'Move counter service to the four largest branches', 6_500_000, 3.0, true],
        ],
        'BCP-FX' => [
            ['remote', 'Dealers and settlement staff work from home on the treasury VPN', 4_200_000, 3.0, true],
        ],
        'BCP-RET' => [
            ['manual_workaround', 'Compile returns from the general ledger extract by hand', 1_200_000, 12.0, true],
            ['recover', 'Restore the returns platform from backup', 14_000_000, 6.0, false],
        ],
        'BCP-NET' => [
            ['recover', 'Fail over to the secondary carrier on the diverse path', 36_000_000, 1.5, true],
        ],
        'BCP-DC' => [
            ['relocate', 'Transfer production to the Abuja DR site', 310_000_000, 6.0, true],
        ],
        'BCP-ITSEC' => [
            ['remote', 'Security operations run remotely on the out-of-band channel', 5_400_000, 2.0, true],
        ],
    ];

    /**
     * Where the deliberate gaps are, and why each one is realistic.
     *
     * @var array<string, string>
     */
    private const STRATEGY_GAP_RATIONALE = [
        'BCP-PY' => 'Full switch failover to Abuja meets the thirty-minute objective and costs ₦240m. '
            .'The funded strategy is an in-place restore at ninety minutes. The gap is a funding decision, '
            .'not an oversight, and it is recorded here so that it is one somebody has taken rather than one '
            .'nobody has seen.',
        'BCP-CARD' => 'Scheme stand-in authorisation would meet the one-hour objective. It is priced per '
            .'transaction and was not approved for this cycle, so the funded strategy recovers in two hours.',
        'BCP-RET' => 'Manual compilation from the general ledger takes twelve hours against an eight-hour '
            .'objective. Restoring the returns platform would meet it; the platform is scheduled for '
            .'replacement and the spend was deferred.',
    ];

    private function assignProcessUnits(): void
    {
        $units = BusinessUnit::query()
            ->whereIn('code', array_values(array_unique(self::PROCESS_UNITS)))
            ->get()
            ->keyBy('code');

        foreach (self::PROCESS_UNITS as $processCode => $unitCode) {
            $unit = $units->get($unitCode);

            if ($unit === null) {
                continue;
            }

            // Only where none is set. A process linked to the organisation's own
            // catalogue already inherited that catalogue's unit, and overwriting
            // it here would make the BCMS overlay disagree with the register it
            // overlays (ADR 0001).
            Process::query()
                ->where('code', $processCode)
                ->whereNull('business_unit_id')
                ->update(['business_unit_id' => $unit->getKey()]);
        }
    }

    private function seedStrategies(): void
    {
        if (Strategy::query()->exists()) {
            return;
        }

        $service = app(StrategyService::class);

        $approver = User::query()->where('email', 'admin@risk.test')->first()
            ?? User::query()->where('is_active', true)->first();

        foreach (self::STRATEGIES as $processCode => $options) {
            $process = Process::query()->where('code', $processCode)->first();

            if ($process === null) {
                continue;
            }

            foreach ($options as [$type, $title, $costNaira, $achievable, $selected]) {
                $strategyType = StrategyType::from($type);

                $strategy = $service->propose($process, $strategyType, [
                    'title' => $title,
                    'description' => $strategyType->description(),
                    // Minor units throughout. ₦42,000,000 stored as 42000000
                    // is either forty-two million or four hundred and twenty
                    // thousand, and a register that mixes the two cannot be
                    // totalled.
                    'cost_estimate_minor' => $costNaira * 100,
                    'currency' => 'NGN',
                    'rto_achievable_hours' => $achievable,
                    'resource_requirements' => $this->resourcesFor($strategyType),
                    'selection_rationale' => $strategyType->requiresRationale()
                        ? 'Recorded deliberately rather than by omission: this is what the organisation has decided to do.'
                        : null,
                ]);

                if (! $selected) {
                    continue;
                }

                $service->select(
                    $strategy,
                    self::STRATEGY_GAP_RATIONALE[$processCode]
                        ?? 'Selected as the best balance of cost and achievable recovery time for this process.',
                );

                if ($approver !== null) {
                    $service->approve($strategy->refresh(), $approver);
                }
            }
        }
    }

    /** @return array<string, list<string>> */
    private function resourcesFor(StrategyType $type): array
    {
        return match ($type) {
            StrategyType::Relocate => [
                'people' => ['Recovery team, 12 seats', 'Site coordinator'],
                'facilities' => ['DR site — Abuja, 40 seats'],
                'technology' => ['Warm standby infrastructure', 'Replicated storage'],
                'funding' => ['Annual DR site retainer'],
            ],
            StrategyType::Remote => [
                'people' => ['All affected staff with laptops'],
                'technology' => ['VPN concentrator capacity', 'Softphone licences'],
                'information' => ['Access to the transaction ledger'],
            ],
            StrategyType::ManualWorkaround => [
                'people' => ['Two additional operators per shift'],
                'information' => ['Printed ledger extract', 'Manual register forms'],
            ],
            StrategyType::Outsource => [
                'suppliers' => ['Scheme stand-in service'],
                'funding' => ['Per-transaction stand-in fee'],
            ],
            default => [
                'people' => ['On-call engineering team'],
                'technology' => ['Spare capacity at the primary site'],
            ],
        };
    }

    /**
     * The plan estate: one group BCP, four departmental, one crisis management
     * plan, one IT DRP, eight branch plans and a pandemic plan.
     *
     * THE GROUP BCP IS APPROVED AND THE REST ARE NOT. A demo where everything
     * is approved shows nothing about document control; one where nothing is
     * shows nothing about the frozen render. One of each is what lets somebody
     * see both the immutable version and the editable one in the same estate.
     *
     * @param  array<string, Site>  $sites
     */
    private function seedPlans(array $sites): void
    {
        if (Plan::query()->where('plan_type', '!=', PlanType::Policy->value)->exists()) {
            return;
        }

        $plans = app(PlanService::class);
        $assembler = app(PlanAssembler::class);

        $author = User::query()->where('is_active', true)->orderBy('id')->first();
        $approver = User::query()->where('email', 'admin@risk.test')->first();

        if ($approver !== null && $author !== null && (int) $approver->getKey() === (int) $author->getKey()) {
            $author = User::query()->where('is_active', true)->whereKeyNot($approver->getKey())->first();
        }

        $units = BusinessUnit::query()->whereIn('code', ['BU-OP', 'BU-IT', 'BU-RT', 'BU-TR'])->get()->keyBy('code');

        // --- The group plan, approved -----------------------------------
        $group = $plans->create(
            PlanType::Bcp,
            self::TRADING_NAME.' Group Business Continuity Plan',
            [
                'owner_id' => $author?->getKey(),
                'review_frequency_months' => 12,
                // Everybody with a role in the group plan. The grammar is the
                // one every other targeting feature uses (ADR 0003).
                'distribution_rule' => AudienceRule::make('role', ['names' => ['bcm-coordinator', 'risk-champion']])->toArray(),
            ],
            'bcp_group',
            $author?->getKey(),
        );

        $assembler->assemble($group, $author?->getKey());

        if ($approver !== null) {
            $plans->submitForReview($group, $author?->getKey());
            $plans->approve($group, $approver, now()->subMonths(2)->toDateString(), 12);
        }

        // --- Four departmental plans ------------------------------------
        foreach ([
            'BU-OP' => 'Operations Division Business Continuity Plan',
            'BU-IT' => 'Information Technology Business Continuity Plan',
            'BU-RT' => 'Retail Banking Business Continuity Plan',
            'BU-TR' => 'Treasury Business Continuity Plan',
        ] as $unitCode => $title) {
            $unit = $units->get($unitCode);

            if ($unit === null) {
                continue;
            }

            $plan = $plans->create(
                PlanType::Department,
                $title,
                [
                    'business_unit_id' => $unit->getKey(),
                    'owner_id' => $author?->getKey(),
                    'review_frequency_months' => 12,
                ],
                'bcp_department',
                $author?->getKey(),
            );

            $assembler->assemble($plan, $author?->getKey());
        }

        // --- Crisis management, IT DR and pandemic -----------------------
        foreach ([
            [PlanType::Cmp, 'Crisis Management Plan', 'cmp_crisis', 12],
            [PlanType::Drp, 'IT Disaster Recovery Plan', 'drp_it', 6],
            [PlanType::Pandemic, 'Pandemic and Infectious Disease Plan', 'pandemic', 24],
        ] as [$type, $title, $template, $months]) {
            $plan = $plans->create(
                $type,
                $title,
                ['owner_id' => $author?->getKey(), 'review_frequency_months' => $months],
                $template,
                $author?->getKey(),
            );

            $assembler->assemble($plan, $author?->getKey());
        }

        // --- Eight branch site plans ------------------------------------
        foreach (self::BRANCHES as [$code, $name]) {
            $site = $sites[$code] ?? null;

            if ($site === null) {
                continue;
            }

            $plan = $plans->create(
                PlanType::Site,
                $name.' Continuity Plan',
                [
                    'site_id' => $site->getKey(),
                    'owner_id' => $author?->getKey(),
                    'review_frequency_months' => 12,
                ],
                'site_branch',
                $author?->getKey(),
            );

            $assembler->assemble($plan, $author?->getKey());
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Phase 4 — the resilience calendar */
    /* ------------------------------------------------------------------ */

    /**
     * Eighteen exercises across nine types and six sites, generated for next
     * year.
     *
     * THREE OF THEM ARE DELIBERATELY WRONG, and each demonstrates one of the
     * engine's answers rather than its happy path:
     *
     *   · **Two exercises competing for the same people on the same day.** The
     *     second is shifted forward inside its own segment and the shift is in
     *     the generation log — never into the next segment, because that is how
     *     four quarterly exercises silently become three in Q4.
     *   · **A month that is entirely blacked out.** A core banking migration
     *     freeze covers the whole of July, and the exercise told to run in July
     *     comes out `needs_scheduling` rather than being dropped or booked into
     *     the freeze. It also demonstrates ADR 0012's claim that a change-freeze
     *     window IS a blackout period rather than a table of its own.
     *   · **A full-scale exercise on a process nobody has tabletopped.** The
     *     ladder advisor says so, and does not block it — the exercise is
     *     booked, the regulator is watching, and a system that refused to record
     *     it is a system people work around.
     *
     * @param  array<string, Site>  $sites
     */
    private function seedExerciseCalendar(array $sites): void
    {
        if (ExerciseDefinition::query()->exists()) {
            return;
        }

        $year = (int) now()->addYear()->year;
        $approver = User::query()->where('email', 'admin@risk.test')->first();

        // A DIFFERENT PERSON FROM THE APPROVER. Clause 8.5's programme is
        // approved by somebody other than its author, the service enforces it,
        // and a demo whose programme silently stayed in draft because both
        // roles landed on the same account would be demonstrating nothing.
        $author = User::query()->where('is_active', true)
            ->when($approver !== null, fn ($q) => $q->whereKeyNot($approver->getKey()))
            ->orderBy('id')
            ->first() ?? $approver;

        $programme = app(ExerciseProgrammeService::class)->create(
            $year,
            self::TRADING_NAME.' exercise programme '.$year,
            ['programme_id' => Programme::query()->value('id')],
            $author?->getKey(),
        );

        $this->seedChangeFreeze($year);
        $this->giveContactsADepartment();

        $units = BusinessUnit::query()->whereIn('code', ['BU-IT', 'BU-OP', 'BU-TR', 'BU-RT', 'BU-CX', 'BU-ERM'])
            ->get()->keyBy('code');
        $types = ExerciseType::query()->get()->keyBy('code');
        $processes = Process::query()->get()->keyBy('code');

        $definitions = app(ExerciseDefinitionService::class);
        $generator = app(OccurrenceGenerator::class);

        foreach ($this->exerciseDefinitions() as $spec) {
            $type = $types->get($spec['type']);

            if ($type === null) {
                continue;
            }

            $definition = $definitions->create(
                $programme,
                $type,
                $spec['name'],
                array_filter([
                    'business_unit_id' => isset($spec['unit']) ? $units->get($spec['unit'])?->id : null,
                    'site_id' => isset($spec['site']) ? ($sites[$spec['site']]->id ?? null) : null,
                    'process_ids' => isset($spec['processes'])
                        ? array_values(array_filter(array_map(
                            fn (string $code) => $processes->get($code)?->id,
                            $spec['processes'],
                        )))
                        : null,
                    'frequency_per_year' => $spec['frequency'],
                    'distribution_mode' => $spec['mode'] ?? DistributionMode::Even->value,
                    'preferred_window' => $spec['window'] ?? null,
                    'min_notice_days' => $spec['min_notice'] ?? 5,
                    'mandatory' => $spec['mandatory'] ?? false,
                    'unannounced' => $spec['unannounced'] ?? false,
                    // An unannounced exercise cannot also send participants a
                    // countdown — the form request refuses that pairing and the
                    // demo must not ship it.
                    'daily_reminder_enabled' => ! ($spec['unannounced'] ?? false),
                    'default_audience_rule' => isset($spec['unit']) && $units->get($spec['unit']) !== null
                        ? AudienceRule::make('org_node', [
                            'id' => $units->get($spec['unit'])->id,
                            'include_descendants' => true,
                        ])->toArray()
                        : null,
                    'owner_id' => $author?->getKey(),
                    'facilitator_id' => $author?->getKey(),
                    'status' => 'active',
                ], fn ($v) => $v !== null),
                $author?->getKey(),
            );

            $generator->generate($definition->refresh(), $author?->getKey());
        }

        app(ExerciseProgrammeService::class)->refreshCounts($programme->refresh());

        if ($approver !== null && (int) $approver->getKey() !== (int) $author?->getKey()) {
            app(ExerciseProgrammeService::class)->approve($programme->refresh(), $approver);
        }
    }

    /**
     * The change-freeze window that blacks out a whole month.
     *
     * A CHANGE FREEZE IS A BLACKOUT PERIOD (ADR 0012). The phase prompt lists
     * "system change-freeze windows" as a conflict source of its own; they are
     * expressible exactly as this, and a separate table would be the blackout
     * calendar under a different name.
     */
    private function seedChangeFreeze(int $year): void
    {
        BlackoutPeriod::query()->updateOrCreate(
            ['organization_id' => TenantContext::organizationIdOrNull(), 'name' => 'Core banking migration freeze'],
            [
                'category' => 'custom',
                'is_hard_block' => true,
                'starts_on' => $year.'-07-01',
                'ends_on' => $year.'-07-31',
                'is_system_default' => false,
                'is_active' => true,
            ]
        );
    }

    /**
     * Put the emergency roster into departments.
     *
     * Contacts inherit their unit from the user record, and the demo's users
     * sit at organisation level — so every `org_node` audience resolved to
     * nobody, and a conflict detector that works on shared PEOPLE had no people
     * to share. Phase 2C owns the contact roster properly; this is the minimum
     * that makes the calendar's conflict detection demonstrable.
     */
    private function giveContactsADepartment(): void
    {
        $units = BusinessUnit::query()->whereIn('code', ['BU-IT', 'BU-OP', 'BU-TR'])->pluck('id')->all();

        if ($units === []) {
            return;
        }

        foreach (Contact::query()->whereNull('business_unit_id')->orderBy('id')->get() as $index => $contact) {
            $contact->update(['business_unit_id' => $units[$index % count($units)]]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exerciseDefinitions(): array
    {
        return [
            // --- The regulator's cadences ------------------------------
            [
                'type' => 'DRFAILOVER', 'name' => 'CBN open banking failover test', 'unit' => 'BU-IT',
                'processes' => ['BCP-CHAN', 'BCP-PY'], 'frequency' => 4, 'mandatory' => true,
                'window' => ['weekdays' => ['tue', 'wed'], 'start_time' => '08:00'], 'min_notice' => 10,
            ],
            [
                'type' => 'DRTEST', 'name' => 'Full disaster recovery test', 'unit' => 'BU-IT', 'site' => 'DC-PRI',
                'processes' => ['BCP-CORE', 'BCP-DC'], 'frequency' => 2, 'mandatory' => true, 'min_notice' => 14,
            ],
            [
                'type' => 'BACKUP', 'name' => 'Backup restore verification', 'unit' => 'BU-IT',
                'processes' => ['BCP-BACKUP'], 'frequency' => 4,
            ],
            [
                'type' => 'CALLTREE', 'name' => 'Group call tree test', 'frequency' => 4, 'unannounced' => true,
                'window' => ['start_time' => '07:30'],
            ],
            // --- The deliberate participant conflict -------------------
            //
            // Two IT exercises, same audience, same `even` spread and the same
            // frequency, so they want the same midpoint. The second is shifted
            // inside its own segment and the shift is in its generation log.
            [
                'type' => 'CYBER', 'name' => 'Cyber incident exercise', 'unit' => 'BU-IT',
                'processes' => ['BCP-ITSEC'], 'frequency' => 2,
                'window' => ['weekdays' => ['wed'], 'start_time' => '09:00'],
            ],
            [
                'type' => 'CRISISSIM', 'name' => 'Crisis management simulation', 'unit' => 'BU-IT',
                'processes' => ['BCP-CORE'], 'frequency' => 2,
                'window' => ['weekdays' => ['wed'], 'start_time' => '09:00'],
            ],
            // --- The fully blacked-out segment -------------------------
            [
                'type' => 'WALKTHRU', 'name' => 'Treasury plan walkthrough (July)', 'unit' => 'BU-TR',
                'processes' => ['BCP-FX', 'BCP-TREAS'], 'frequency' => 1,
                'mode' => DistributionMode::MonthSpecific->value,
                'window' => ['months' => [7]],
            ],
            // --- The ladder warning ------------------------------------
            [
                'type' => 'FULLSCALE', 'name' => 'Full-scale payments failure exercise', 'unit' => 'BU-OP',
                'processes' => ['BCP-PY'], 'frequency' => 1, 'min_notice' => 14,
            ],
            // --- The rest of the year ----------------------------------
            ['type' => 'FIREDRILL', 'name' => 'Head office fire drill', 'site' => 'HQ', 'frequency' => 2],
            ['type' => 'FIREDRILL', 'name' => 'Kano branch fire drill', 'site' => 'KAN', 'frequency' => 2],
            ['type' => 'FIREDRILL', 'name' => 'Lagos branch fire drill', 'site' => 'LAG', 'frequency' => 2],
            ['type' => 'EVAC', 'name' => 'Abuja branch evacuation test', 'site' => 'ABJ', 'frequency' => 1],
            ['type' => 'EVAC', 'name' => 'Port Harcourt evacuation test', 'site' => 'PHC', 'frequency' => 1],
            [
                'type' => 'TABLETOP', 'name' => 'Retail operations tabletop', 'unit' => 'BU-RT',
                'processes' => ['BCP-BRANCH', 'BCP-CASH'], 'frequency' => 2,
            ],
            [
                'type' => 'TABLETOP', 'name' => 'Contact centre tabletop', 'unit' => 'BU-CX',
                'processes' => ['BCP-CALL', 'BCP-CUST'], 'frequency' => 2,
            ],
            [
                'type' => 'ORIENT', 'name' => 'New joiner BC orientation', 'frequency' => 12,
                'mode' => DistributionMode::Even->value,
            ],
            [
                'type' => 'PANDEMIC', 'name' => 'Mass absence exercise', 'unit' => 'BU-ERM',
                'processes' => ['BCP-HR'], 'frequency' => 1,
            ],
            [
                'type' => 'SUPPLIER', 'name' => 'Critical supplier continuity test', 'unit' => 'BU-ERM',
                'processes' => ['BCP-VEND'], 'frequency' => 1,
                'mode' => DistributionMode::QuarterEnd->value,
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Phase 5 — the countdown */
    /* ------------------------------------------------------------------ */

    /**
     * Participants, and one exercise ten days out so the ladder is live.
     *
     * A CALENDAR WITH NO PARTICIPANTS DEMONSTRATES NOTHING. The whole of Phase 5
     * is "one alert per person per day", and the demo estate had exercises with
     * nobody in them — every rung would resolve to nobody and be recorded as
     * sent-to-nobody, which is honest and useless to show.
     *
     * ONE EXERCISE IS PULLED FORWARD TO T-10 DELIBERATELY. Next year's programme
     * is the right shape for a calendar and the wrong shape for a countdown; a
     * demo of the T-10 ladder needs an exercise whose ladder is actually
     * running. Two of its readiness owners are left behind on purpose, which is
     * what the T-2 escalation is for.
     */
    private function seedCountdownDemo(): void
    {
        if (ExerciseParticipant::query()->exists()) {
            return;
        }

        $contacts = Contact::query()->where('is_active', true)->get();

        if ($contacts->isEmpty()) {
            return;
        }

        $occurrences = ExerciseOccurrence::query()
            ->whereNotNull('scheduled_date')
            ->with('definition')
            ->orderBy('scheduled_date')
            ->get();

        foreach ($occurrences as $occurrence) {
            foreach ($contacts as $index => $contact) {
                if ($contact->user_id === null) {
                    continue;
                }

                ExerciseParticipant::query()->updateOrCreate(
                    ['occurrence_id' => $occurrence->getKey(), 'user_id' => $contact->user_id],
                    [
                        'organization_id' => $occurrence->organization_id,
                        'contact_id' => $contact->getKey(),
                        // One facilitator, one observer, the rest taking part.
                        'role' => match ($index) {
                            0 => 'facilitator',
                            1 => 'observer',
                            default => 'participant',
                        },
                        'business_unit_id' => $contact->business_unit_id,
                        'invitation_status' => 'pending',
                    ]
                );
            }
        }

        // The live countdown. A tabletop is the right one to pull forward: it
        // is the exercise type with the most human readiness items, so the
        // daily digest has something to say.
        $live = $occurrences->first(
            fn (ExerciseOccurrence $o) => $o->definition?->exerciseType?->ladder_level === LadderLevel::Tabletop
        ) ?? $occurrences->first();

        if ($live === null) {
            return;
        }

        $live->update([
            'scheduled_date' => now()->addDays(10)->toDateString(),
            'scheduled_start' => now()->addDays(10)->setTime(9, 0),
            'scheduled_end' => now()->addDays(10)->setTime(12, 0),
        ]);

        app(ReadinessService::class)->materialise($live->refresh());
        app(ReminderScheduleBuilder::class)->build($live->refresh());

        // Spread the checklist across the people who are actually in the
        // exercise, then close most of it — two owners left behind is what the
        // T-2 escalation exists to catch, and a checklist that is entirely open
        // shows the escalation without showing the progress.
        $owners = $contacts->pluck('user_id')->filter()->values();
        $tasks = ReadinessTask::query()->where('occurrence_id', $live->getKey())->orderBy('id')->get();

        foreach ($tasks as $index => $task) {
            $task->update(['owner_id' => $owners[$index % max(1, $owners->count())] ?? null]);
        }

        $tasks->take(max(0, $tasks->count() - 2))->each(fn (ReadinessTask $t) => $t->update([
            'status' => 'complete',
            'completed_at' => now(),
            'completed_by' => $t->owner_id,
        ]));

        app(ReadinessService::class)->refreshCounts($live->refresh());
    }
}
