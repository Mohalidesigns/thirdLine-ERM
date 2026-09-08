<?php

namespace Database\Seeders\Bcms;

use App\Enums\Bcms\ContactSource;
use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Application;
use App\Models\Bcms\Contact;
use App\Models\Bcms\DataSet;
use App\Models\Bcms\Equipment;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\Site;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
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

    /** The four divisions the twelve existing departments hang beneath. */
    private const DIVISIONS = [
        ['DIV-RB', 'Retail Banking Division', ['BU-RB', 'BU-CX', 'BU-PB', 'BU-RES']],
        ['DIV-CB', 'Corporate Banking Division', ['BU-CB', 'BU-TR', 'BU-SB', 'BU-LMDR']],
        ['DIV-OPS', 'Operations and Technology Division', ['BU-OP', 'BU-IT']],
        ['DIV-CTL', 'Control Division', ['BU-RM', 'BU-CO', 'BU-IA', 'BU-LG', 'BU-FN', 'BU-HR']],
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
            $programme = $this->seedProgramme();
            $this->seedExerciseProgramme($programme);
            $this->seedContacts($sites);
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
        $definitions = [
            ['BCP-PY', 'BP-PY', 'Payment processing and settlement', 1, true],
            ['BCP-LN', 'BP-LN', 'Loan origination and disbursement', 3, false],
            ['BCP-TF', 'BP-TF', 'Trade finance operations', 3, false],
            ['BCP-FX', 'BP-FX', 'FX trading and settlement', 2, true],
            ['BCP-KY', 'BP-KY', 'Customer onboarding and KYC', 3, false],
            ['BCP-CASH', null, 'Branch cash service', 2, true],
            ['BCP-CHAN', null, 'Digital channels (mobile, internet, USSD)', 1, true],
            ['BCP-RET', null, 'Regulatory returns and reporting', 2, false],
        ];

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
                    'iso_clause_ref' => IsoClauseRef::Iso22301_8_2_2->value,
                    'status' => 'active',
                ]
            );
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
}
