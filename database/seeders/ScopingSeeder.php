<?php

namespace Database\Seeders;

use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ScopingSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::first();
        if (!$org) {
            $this->command->warn('No organization found. Skipping ScopingSeeder.');
            return;
        }

        $orgId = $org->id;
        $users = User::where('organization_id', $orgId)->get();
        $defaultUserId = $users->first()?->id;

        $this->command->info('Seeding Entity Types...');
        $types = $this->seedEntityTypes($orgId);

        $this->command->info('Seeding Entities (Nigerian Banking Hierarchy)...');
        $entities = $this->seedEntities($orgId, $types, $users, $defaultUserId);

        $this->command->info('Linking existing risks to entities...');
        $this->linkRisksToEntities($orgId, $entities);

        $this->command->info('Scoping module seeded successfully!');
        $this->command->info("  - {$types->count()} entity types");
        $this->command->info("  - " . count($entities) . " entities");
    }

    private function seedEntityTypes(int $orgId)
    {
        $typesData = [
            ['code' => 'GROUP',      'name' => 'Group',       'level' => 0, 'icon' => 'domain',          'color' => '#1A365D', 'sort_order' => 1],
            ['code' => 'SUBSIDIARY', 'name' => 'Subsidiary',  'level' => 1, 'icon' => 'account_balance', 'color' => '#2D7D46', 'sort_order' => 2],
            ['code' => 'DIVISION',   'name' => 'Division',    'level' => 2, 'icon' => 'store',           'color' => '#D4AF37', 'sort_order' => 3],
            ['code' => 'DEPARTMENT', 'name' => 'Department',  'level' => 2, 'icon' => 'groups',          'color' => '#DD6B20', 'sort_order' => 4],
            ['code' => 'REGION',     'name' => 'Region',      'level' => 3, 'icon' => 'location_on',     'color' => '#3182CE', 'sort_order' => 5],
            ['code' => 'BRANCH',     'name' => 'Branch',      'level' => 4, 'icon' => 'location_city',   'color' => '#718096', 'sort_order' => 6],
            ['code' => 'UNIT',       'name' => 'Unit',        'level' => 3, 'icon' => 'workspaces',      'color' => '#48BB78', 'sort_order' => 7],
            ['code' => 'PROCESS',    'name' => 'Process',     'level' => 3, 'icon' => 'sync_alt',        'color' => '#ED8936', 'sort_order' => 8],
            ['code' => 'SYSTEM',     'name' => 'System',      'level' => 3, 'icon' => 'computer',        'color' => '#9F7AEA', 'sort_order' => 9],
        ];

        foreach ($typesData as $td) {
            EntityType::updateOrCreate(
                ['organization_id' => $orgId, 'code' => $td['code']],
                array_merge($td, [
                    'organization_id' => $orgId,
                    'uuid'            => (string) Str::uuid(),
                    'is_active'       => true,
                ])
            );
        }

        return EntityType::where('organization_id', $orgId)->get()->keyBy('code');
    }

    private function seedEntities(int $orgId, $types, $users, ?int $defaultUserId): array
    {
        $entities = [];
        $codeCounter = 1;

        $genCode = function () use (&$codeCounter) {
            return sprintf('ENT-%04d', $codeCounter++);
        };

        $getUser = function ($index = 0) use ($users, $defaultUserId) {
            return $users[$index] ?? $users->first() ?? null;
        };

        // ──────────────────────────────────────────────────
        // L0: Group
        // ──────────────────────────────────────────────────
        $entities['holdings'] = Entity::updateOrCreate(
            ['organization_id' => $orgId, 'entity_code' => 'ENT-0001'],
            [
                'uuid' => (string) Str::uuid(),
                'organization_id' => $orgId,
                'entity_type_id' => $types['GROUP']->id,
                'parent_id' => null,
                'entity_code' => $genCode(),
                'name' => 'FirstBank Holdings PLC',
                'description' => 'Parent holding company for all FirstBank Group entities, overseeing strategic direction, group risk governance, and regulatory compliance across subsidiaries.',
                'owner_id' => $getUser(0)?->id,
                'status' => 'active',
                'level' => 0,
                'regulatory_frameworks' => ['CBN ORMS', 'Basel III', 'BOFIA', 'SEC Rules'],
                'risk_appetite_level' => 'cautious',
                'category_appetites' => ['credit' => 'cautious', 'operational' => 'minimal', 'market' => 'cautious', 'compliance' => 'averse', 'technology' => 'open'],
                'created_by' => $defaultUserId,
            ]
        );

        // ──────────────────────────────────────────────────
        // L1: Subsidiaries
        // ──────────────────────────────────────────────────
        $subsidiaries = [
            'fbn_nigeria' => ['name' => 'FirstBank Nigeria Ltd', 'desc' => 'Principal commercial banking subsidiary, providing retail, corporate, and investment banking services across Nigeria.', 'frameworks' => ['CBN ORMS', 'Basel III', 'NDPA', 'NFIU', 'BOFIA'], 'appetite' => 'cautious'],
            'fbn_insurance' => ['name' => 'FBN Insurance Ltd', 'desc' => 'Insurance and risk transfer subsidiary serving both retail and corporate clients.', 'frameworks' => ['NAICOM', 'CBN ORMS'], 'appetite' => 'minimal'],
            'fbn_capital' => ['name' => 'FBN Capital Ltd', 'desc' => 'Investment banking and asset management subsidiary.', 'frameworks' => ['SEC Rules', 'CBN ORMS'], 'appetite' => 'open'],
            'fbn_microfinance' => ['name' => 'FBN Microfinance Bank', 'desc' => 'Microfinance banking for underbanked populations and SMEs.', 'frameworks' => ['CBN ORMS', 'NDPA'], 'appetite' => 'cautious'],
        ];

        foreach ($subsidiaries as $key => $sub) {
            $entities[$key] = Entity::updateOrCreate(
                ['organization_id' => $orgId, 'name' => $sub['name']],
                [
                    'uuid' => (string) Str::uuid(),
                    'organization_id' => $orgId,
                    'entity_type_id' => $types['SUBSIDIARY']->id,
                    'parent_id' => $entities['holdings']->id,
                    'entity_code' => $genCode(),
                    'name' => $sub['name'],
                    'description' => $sub['desc'],
                    'owner_id' => $getUser(1)?->id,
                    'status' => 'active',
                    'level' => 1,
                    'regulatory_frameworks' => $sub['frameworks'],
                    'risk_appetite_level' => $sub['appetite'],
                    'created_by' => $defaultUserId,
                ]
            );
        }

        // ──────────────────────────────────────────────────
        // L2: Divisions & Departments (under FirstBank Nigeria)
        // ──────────────────────────────────────────────────
        $divisions = [
            'retail'     => ['name' => 'Retail Banking Division',            'type' => 'DIVISION',   'desc' => 'Consumer banking operations including deposits, personal lending, cards, and digital channels across all regions.', 'appetite' => 'cautious'],
            'corporate'  => ['name' => 'Corporate Banking Division',         'type' => 'DIVISION',   'desc' => 'Corporate relationship management, trade finance, structured lending, and cash management services.', 'appetite' => 'open'],
            'treasury'   => ['name' => 'Treasury & Investment Banking',      'type' => 'DIVISION',   'desc' => 'Treasury operations, foreign exchange, fixed income, equities, and investment advisory services.', 'appetite' => 'open'],
            'ops_tech'   => ['name' => 'Operations & Technology',            'type' => 'DIVISION',   'desc' => 'Core banking operations, IT infrastructure, digital transformation, and technology risk management.', 'appetite' => 'minimal'],
            'risk_mgmt'  => ['name' => 'Risk Management Department',         'type' => 'DEPARTMENT', 'desc' => 'Enterprise risk management, credit risk, market risk, and operational risk oversight functions.', 'appetite' => 'averse'],
            'compliance' => ['name' => 'Compliance Department',              'type' => 'DEPARTMENT', 'desc' => 'Regulatory compliance, AML/CFT, KYC, and sanctions screening operations.', 'appetite' => 'averse'],
        ];

        foreach ($divisions as $key => $div) {
            $entities[$key] = Entity::updateOrCreate(
                ['organization_id' => $orgId, 'name' => $div['name']],
                [
                    'uuid' => (string) Str::uuid(),
                    'organization_id' => $orgId,
                    'entity_type_id' => $types[$div['type']]->id,
                    'parent_id' => $entities['fbn_nigeria']->id,
                    'entity_code' => $genCode(),
                    'name' => $div['name'],
                    'description' => $div['desc'],
                    'owner_id' => $getUser(2)?->id,
                    'status' => 'active',
                    'level' => 2,
                    'regulatory_frameworks' => ['CBN ORMS', 'Basel III'],
                    'risk_appetite_level' => $div['appetite'],
                    'created_by' => $defaultUserId,
                ]
            );
        }

        // ──────────────────────────────────────────────────
        // L3: Regions (under Retail Banking Division)
        // ──────────────────────────────────────────────────
        $regions = [
            'lagos'  => ['name' => 'Lagos Region',          'desc' => 'Lagos metropolitan area branches covering Victoria Island, Ikeja, Lekki, and surrounding areas.'],
            'abuja'  => ['name' => 'Abuja Region',          'desc' => 'Federal Capital Territory branches covering Garki, Wuse, Maitama, and surrounding areas.'],
            'ph'     => ['name' => 'Port Harcourt Region',  'desc' => 'Rivers State branches covering the Oil & Gas corridor and surrounding Niger Delta areas.'],
        ];

        foreach ($regions as $key => $reg) {
            $entities[$key] = Entity::updateOrCreate(
                ['organization_id' => $orgId, 'name' => $reg['name']],
                [
                    'uuid' => (string) Str::uuid(),
                    'organization_id' => $orgId,
                    'entity_type_id' => $types['REGION']->id,
                    'parent_id' => $entities['retail']->id,
                    'entity_code' => $genCode(),
                    'name' => $reg['name'],
                    'description' => $reg['desc'],
                    'owner_id' => $getUser(3)?->id,
                    'status' => 'active',
                    'level' => 3,
                    'regulatory_frameworks' => ['CBN ORMS'],
                    'risk_appetite_level' => 'cautious',
                    'created_by' => $defaultUserId,
                ]
            );
        }

        // L3: System (under Ops & Tech)
        $entities['core_banking'] = Entity::updateOrCreate(
            ['organization_id' => $orgId, 'name' => 'Core Banking Systems'],
            [
                'uuid' => (string) Str::uuid(),
                'organization_id' => $orgId,
                'entity_type_id' => $types['SYSTEM']->id,
                'parent_id' => $entities['ops_tech']->id,
                'entity_code' => $genCode(),
                'name' => 'Core Banking Systems',
                'description' => 'Finacle core banking platform, payment gateway, and middleware infrastructure.',
                'owner_id' => $getUser(4)?->id,
                'status' => 'active',
                'level' => 3,
                'regulatory_frameworks' => ['CBN ORMS', 'NDPA'],
                'risk_appetite_level' => 'averse',
                'created_by' => $defaultUserId,
            ]
        );

        // ──────────────────────────────────────────────────
        // L4: Branches (under Lagos Region)
        // ──────────────────────────────────────────────────
        $branches = [
            'vi_branch'    => 'Victoria Island Branch',
            'ikeja_branch' => 'Ikeja Branch',
            'lekki_branch' => 'Lekki Branch',
        ];

        foreach ($branches as $key => $branchName) {
            $entities[$key] = Entity::updateOrCreate(
                ['organization_id' => $orgId, 'name' => $branchName],
                [
                    'uuid' => (string) Str::uuid(),
                    'organization_id' => $orgId,
                    'entity_type_id' => $types['BRANCH']->id,
                    'parent_id' => $entities['lagos']->id,
                    'entity_code' => $genCode(),
                    'name' => $branchName,
                    'description' => "{$branchName} — retail and SME banking services.",
                    'owner_id' => $getUser(3)?->id,
                    'status' => 'active',
                    'level' => 4,
                    'regulatory_frameworks' => ['CBN ORMS'],
                    'risk_appetite_level' => 'minimal',
                    'created_by' => $defaultUserId,
                ]
            );
        }

        return $entities;
    }

    private function linkRisksToEntities(int $orgId, array $entities): void
    {
        $risks = Risk::where('organization_id', $orgId)->get();

        if ($risks->isEmpty()) {
            $this->command->info('  - No existing risks to link.');
            return;
        }

        // Distribute risks among key entities
        $entityKeys = ['retail', 'corporate', 'treasury', 'ops_tech', 'risk_mgmt', 'compliance', 'lagos', 'fbn_nigeria'];
        $linkedCount = 0;

        foreach ($risks as $index => $risk) {
            $key = $entityKeys[$index % count($entityKeys)];
            if (isset($entities[$key])) {
                $risk->update(['entity_id' => $entities[$key]->id]);
                $linkedCount++;
            }
        }

        $this->command->info("  - {$linkedCount} risks linked to entities.");
    }
}
