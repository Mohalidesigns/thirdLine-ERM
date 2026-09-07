<?php

namespace Database\Seeders\Tprm;

use App\Enums\Tprm\DisclosureSource;
use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Graph\NthPartyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * A demonstration portfolio with two REAL concentration clusters — the
 * fixture behind Phase 7's acceptance criterion.
 *
 * NOT PART OF `TprmReferenceSeeder`, and that separation matters. Reference
 * data is what a real client needs before they can do anything; this is
 * invented vendors with invented spend, and a client who found Interswitch
 * already in their register with a made-up contract value would be right to
 * stop trusting everything else the seeder put there.
 *
 * THE TWO CLUSTERS ARE DELIBERATELY DIFFERENT IN KIND, because they are the
 * two findings this analysis exists to produce and only one of them is
 * obvious:
 *
 *   DIRECT — three critical functions contracted straight to Interswitch. Any
 *   bank can see this one in a spreadsheet.
 *
 *   INDIRECT — three separate vendors, chosen independently, all running on
 *   Amazon Web Services. No engagement mentions AWS; the exposure exists only
 *   in the sub-processor graph, and no spreadsheet in any bank has it. This is
 *   the case the module was built for.
 */
class TprmDemoSeeder extends Seeder
{
    /** The vendors, and what each of them actually is. */
    private const VENDORS = [
        'interswitch' => ['Interswitch Limited', 'NG', 'payment_switch'],
        'unified' => ['Unified Payments Services Limited', 'NG', 'payment_processing'],
        'cloudspan' => ['Cloudspan Digital Limited', 'NG', 'software'],
        'kernel' => ['Kernel Analytics Limited', 'NG', 'analytics'],
        'ledgerworks' => ['Ledgerworks Systems Limited', 'NG', 'core_banking'],
        'aws' => ['Amazon Web Services', 'IE', 'cloud_infrastructure'],
        'rack' => ['Rack Centre Limited', 'NG', 'data_centre'],
    ];

    /**
     * engagement key => [vendor, name, function codes, spend (minor), tier,
     *                    substitutability, months to replace]
     */
    private const ENGAGEMENTS = [
        // Cluster one: three critical functions, one provider, contracted directly.
        ['interswitch', 'Card processing and issuing', ['BF-CARD-01'], 620_000_000, 'critical', 'none', 24],
        ['interswitch', 'ATM and POS switching', ['BF-ATM-01'], 410_000_000, 'critical', 'none', 18],
        ['interswitch', 'Clearing and settlement interface', ['BF-CLEAR-01'], 180_000_000, 'critical', 'difficult', 12],

        // Cluster two: three vendors chosen independently, one cloud underneath.
        ['cloudspan', 'Internet and mobile banking platform', ['BF-DIG-01'], 340_000_000, 'critical', 'difficult', 15],
        ['kernel', 'AML transaction monitoring', ['BF-AML-01'], 150_000_000, 'critical', 'moderate', 9],
        ['ledgerworks', 'Core banking hosting and operation', ['BF-CORE-01'], 890_000_000, 'critical', 'none', 30],

        // Unconcentrated, so the index has something to be a share OF.
        ['unified', 'Merchant acquiring support', ['BF-AGENT-01'], 70_000_000, 'high', 'moderate', 6],
    ];

    public function run(): void
    {
        if (! config('features.tprm')) {
            $this->command?->warn('TPRM is disabled; the demo portfolio was not seeded.');

            return;
        }

        foreach (Organization::query()->where('is_active', true)->get() as $organization) {
            TenantContext::actingAs($organization->id, fn () => $this->seedFor($organization));
        }
    }

    private function seedFor(Organization $organization): void
    {
        $owner = DB::table('users')->where('organization_id', $organization->id)->value('id');

        $vendors = collect(self::VENDORS)->mapWithKeys(
            fn (array $row, string $key): array => [$key => $this->vendor($organization, $row)],
        );

        $functions = DB::table('tp_business_functions')
            ->where('organization_id', $organization->id)
            ->pluck('id', 'function_code');

        foreach (self::ENGAGEMENTS as $index => [$vendorKey, $name, $codes, $spend, $tier, $substitutability, $months]) {
            $this->engagement(
                $organization,
                $vendors[$vendorKey],
                $name,
                $index + 1,
                $codes,
                $functions,
                $spend,
                $tier,
                $substitutability,
                $months,
                $owner === null ? null : (int) $owner,
            );
        }

        $this->subProcessorChains($vendors, $owner === null ? null : (int) $owner);
    }

    /**
     * The chains that make cluster two visible.
     *
     * CONFIRMED, NOT PROPOSED. A demo portfolio whose concentration analysis
     * showed nothing — because every edge in it was awaiting review — would
     * demonstrate the opposite of the feature. These stand for edges a person
     * has already accepted.
     *
     * @param  \Illuminate\Support\Collection<string, ThirdParty>  $vendors
     */
    private function subProcessorChains($vendors, ?int $userId): void
    {
        $service = app(NthPartyService::class);

        $chains = [
            // Three independent vendors, one cloud. Nobody contracted with AWS.
            ['cloudspan', 'aws', 'Application hosting and object storage', 'critical', 'IE'],
            ['kernel', 'aws', 'Model training and data lake', 'critical', 'IE'],
            ['ledgerworks', 'aws', 'Disaster recovery region', 'critical', 'IE'],

            // And a second-order shared dependency, to give the graph depth.
            ['cloudspan', 'rack', 'Primary colocation', 'important', 'NG'],
            ['interswitch', 'rack', 'Switch colocation', 'critical', 'NG'],
        ];

        foreach ($chains as [$parentKey, $childKey, $service_description, $criticality, $country]) {
            $edge = $service->record(
                $vendors[$parentKey],
                $vendors[$childKey]->legal_name,
                $vendors[$childKey],
                DisclosureSource::VendorDeclared,
                [
                    'service_description' => $service_description,
                    'criticality' => $criticality,
                    'country_of_processing' => $country,
                    'rank' => 1,
                    'disclosed_at' => now()->subMonths(2)->toDateString(),
                ],
                $userId,
            );

            $service->confirm($edge, $userId);
        }
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $row
     */
    private function vendor(Organization $organization, array $row): ThirdParty
    {
        [$name, $country, $type] = $row;

        $existing = ThirdParty::query()
            ->where('organization_id', $organization->id)
            ->where('legal_name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ThirdParty::create([
            'organization_id' => $organization->id,
            'legal_name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'entity_type' => 'company',
            'country_of_incorporation' => $country,
            'status' => 'active',
            'notes' => sprintf(
                'Demonstration record (%s). Not a real commercial relationship.',
                str_replace('_', ' ', $type),
            ),
        ]);
    }

    /**
     * @param  list<string>  $codes
     * @param  \Illuminate\Support\Collection<string, int>  $functions
     */
    private function engagement(
        Organization $organization,
        ThirdParty $vendor,
        string $name,
        int $sequence,
        array $codes,
        $functions,
        int $spend,
        string $tier,
        string $substitutability,
        int $months,
        ?int $ownerId,
    ): void {
        $reference = sprintf('ENG-DEMO-%04d', $sequence);

        if (Engagement::query()->where('organization_id', $organization->id)->where('reference', $reference)->exists()) {
            return;
        }

        $engagement = Engagement::create([
            'organization_id' => $organization->id,
            'third_party_id' => $vendor->getKey(),
            'reference' => $reference,
            'name' => $name,
            'service_description' => 'Demonstration engagement seeded to show concentration analysis.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $ownerId,
            'annual_spend_minor' => $spend,
            'currency' => 'NGN',
            'substitutability' => $substitutability,
            'time_to_replace_months' => $months,
        ]);

        $engagement->forceFill([
            'status' => EngagementStatus::Active->value,
            'inherent_tier' => $tier,
            'effective_tier' => $tier,
            'inherent_score' => $tier === RiskTier::Critical->value ? 88 : 72,
        ])->save();

        foreach ($codes as $code) {
            if (! isset($functions[$code])) {
                continue;
            }

            $engagement->businessFunctions()->attach($functions[$code], [
                'organization_id' => $organization->id,
                'dependency_level' => 'primary',
                'reliance_level' => 'high',
            ]);
        }
    }
}
