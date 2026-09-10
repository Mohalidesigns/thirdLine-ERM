<?php

namespace Tests\Feature\Console;

use App\Enums\Tprm\AccessGrantStatus;
use App\Enums\Tprm\AccessLevel;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AccessGrant;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Access\AccessService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `tprm:reconcile-access` — FR-ACC-05, the daily expiry sweep.
 *
 * `AccessService::expireDueGrants()` and its idempotency are already covered
 * directly (GraphAndAccessTest); this file covers what belongs to the
 * wrapper: the `--organization` and `--dry-run` options, the per-tenant loop
 * (`TenantContext::actingAs()`, not `bypass()` — the docblock is explicit that
 * bypassing would let one tenant's bad data attribute findings to whichever
 * organisation happened to be current), and that a dry run writes nothing.
 */
class ReconcileTprmAccessCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('tprm.ai.enabled', false);

        $this->organization = Organization::create([
            'name' => 'Lagos Union Bank', 'short_name' => 'LUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->manager = User::create([
            'name' => 'Relationship Manager', 'email' => 'tprm@lub.test',
            'password' => bcrypt('secret'), 'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $this->engagement = $this->makeEngagement($this->makeVendor('Cloudspan Nigeria Limited'), 'Core banking hosting');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function it_expires_a_grant_past_its_end_date_and_raises_the_critical_finding_it_owes(): void
    {
        $grant = $this->liveGrant('Amina Sule', 'Payment Gateway', AccessLevel::Write);
        $grant->forceFill(['valid_to' => now()->subDays(10)->toDateString()])->save();

        $this->artisan('tprm:reconcile-access')
            ->expectsOutputToContain('1 grant(s) marked expired, 1 Critical finding(s) raised')
            ->assertSuccessful();

        TenantContext::set($this->organization->id);
        $status = $grant->fresh()->status;
        $finding = Finding::query()->where('engagement_id', $this->engagement->id)->latest('id')->first();
        TenantContext::clear();

        $this->assertSame(AccessGrantStatus::Expired, $status);
        $this->assertNotNull($finding, 'The wrapper did not raise the finding FR-ACC-05 requires.');
        $this->assertSame('critical', $finding->severity->value);
        $this->assertStringContainsString('Amina Sule', $finding->title);
    }

    #[Test]
    public function running_it_twice_raises_nothing_a_second_time(): void
    {
        $grant = $this->liveGrant('Amina Sule', 'Payment Gateway', AccessLevel::Write);
        $grant->forceFill(['valid_to' => now()->subDays(10)->toDateString()])->save();

        $this->artisan('tprm:reconcile-access')->assertSuccessful();

        $this->artisan('tprm:reconcile-access')
            ->expectsOutputToContain('0 grant(s) marked expired, 0 Critical finding(s) raised')
            ->assertSuccessful();

        TenantContext::set($this->organization->id);
        $findingCount = Finding::query()->where('source', 'monitoring')->count();
        TenantContext::clear();

        $this->assertSame(1, $findingCount, 'A re-run raised a second finding for the same grant.');
    }

    #[Test]
    public function it_no_ops_quietly_when_nothing_is_overdue(): void
    {
        $this->liveGrant('Amina Sule', 'Payment Gateway', AccessLevel::Read);

        $this->artisan('tprm:reconcile-access')
            ->expectsOutputToContain('0 grant(s) marked expired, 0 Critical finding(s) raised')
            ->assertSuccessful();
    }

    #[Test]
    public function it_no_ops_when_the_feature_is_switched_off(): void
    {
        config()->set('features.tprm', false);

        $grant = $this->liveGrant('Amina Sule', 'Payment Gateway', AccessLevel::Write);
        $grant->forceFill(['valid_to' => now()->subDays(10)->toDateString()])->save();

        $this->artisan('tprm:reconcile-access')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        TenantContext::set($this->organization->id);
        $status = $grant->fresh()->status;
        TenantContext::clear();

        $this->assertNotSame(AccessGrantStatus::Expired, $status);
    }

    #[Test]
    public function dry_run_reports_without_writing_anything(): void
    {
        $grant = $this->liveGrant('Amina Sule', 'Payment Gateway', AccessLevel::Write);
        $grant->forceFill(['valid_to' => now()->subDays(10)->toDateString()])->save();

        $this->artisan('tprm:reconcile-access', ['--dry-run' => true])
            ->expectsOutputToContain('1 overdue')
            ->assertSuccessful();

        TenantContext::set($this->organization->id);
        $status = $grant->fresh()->status;
        $findingCount = Finding::query()->where('engagement_id', $this->engagement->id)->count();
        TenantContext::clear();

        $this->assertNotSame(AccessGrantStatus::Expired, $status, 'A dry run wrote a status change.');
        $this->assertSame(0, $findingCount, 'A dry run raised a finding.');
    }

    #[Test]
    public function each_tenant_is_reconciled_under_its_own_context_not_a_bypass(): void
    {
        $grantA = $this->liveGrant('Amina Sule', 'Payment Gateway', AccessLevel::Write);
        $grantA->forceFill(['valid_to' => now()->subDays(10)->toDateString()])->save();
        TenantContext::clear();

        $orgB = Organization::create([
            'name' => 'Second Bank', 'short_name' => 'SB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        RiskCategory::create([
            'organization_id' => $orgB->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);
        TenantContext::set($orgB->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($orgB->id);

        $managerB = User::create([
            'name' => 'Manager B', 'email' => 'tprm@sb.test',
            'password' => bcrypt('secret'), 'organization_id' => $orgB->id, 'is_active' => true,
        ]);
        $vendorB = ThirdParty::create([
            'legal_name' => 'Second Vendor Ltd', 'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);
        $engagementB = Engagement::create([
            'third_party_id' => $vendorB->id, 'reference' => 'ENG-2026-0001', 'name' => 'Data hosting',
            'service_description' => 'Second tenant fixture.', 'engagement_type' => 'ict_service',
            'relationship_owner_id' => $managerB->id,
        ]);
        $engagementB->forceFill([
            'status' => \App\Enums\Tprm\EngagementStatus::Active->value,
            'inherent_score' => 70, 'inherent_tier' => RiskTier::High->value, 'effective_tier' => RiskTier::High->value,
        ])->save();
        InherentAssessment::create([
            'organization_id' => $orgB->id, 'engagement_id' => $engagementB->id,
            'version' => 1, 'ruleset_version' => '1.0.0', 'raw_score' => 70,
            'resulting_tier' => RiskTier::High->value, 'assessed_at' => now(), 'is_current' => true,
            'answers' => [], 'factor_scores' => [], 'weights' => [], 'explanation' => [],
        ]);
        $grantB = app(AccessService::class)->grantAccess($engagementB, [
            'grantee_name' => 'Bank B User', 'grantee_email' => 'bankb@vendor.test',
            'system_name' => 'System B', 'access_level' => AccessLevel::Write->value,
            'justification' => 'Fixture.', 'valid_from' => now()->subMonth()->toDateString(),
            'valid_to' => now()->subDays(5)->toDateString(), 'monitoring_method' => 'Reviewed weekly.',
        ], $managerB->id);
        app(AccessService::class)->approveGrant($grantB, $managerB->id);
        TenantContext::clear();

        $this->artisan('tprm:reconcile-access')
            ->expectsOutputToContain('2 grant(s) marked expired, 2 Critical finding(s) raised')
            ->assertSuccessful();

        TenantContext::set($this->organization->id);
        $statusA = $grantA->fresh()->status;
        TenantContext::clear();

        TenantContext::set($orgB->id);
        $statusB = $grantB->fresh()->status;
        TenantContext::clear();

        $this->assertSame(AccessGrantStatus::Expired, $statusA);
        $this->assertSame(AccessGrantStatus::Expired, $statusB);
    }

    private function makeVendor(string $name): ThirdParty
    {
        return ThirdParty::create([
            'legal_name' => $name,
            'slug' => Str::random(12),
            'entity_type' => 'company',
            'status' => 'active',
        ]);
    }

    private function makeEngagement(ThirdParty $vendor, string $name): Engagement
    {
        $engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-0001',
            'name' => $name,
            'service_description' => 'Recorded for the scheduled-command fixtures.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->manager->id,
        ]);

        $engagement->forceFill([
            'status' => \App\Enums\Tprm\EngagementStatus::Active->value,
            'inherent_score' => 70,
            'inherent_tier' => RiskTier::High->value,
            'effective_tier' => RiskTier::High->value,
        ])->save();

        InherentAssessment::create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $engagement->id,
            'version' => 1, 'ruleset_version' => '1.0.0',
            'raw_score' => 70, 'resulting_tier' => RiskTier::High->value,
            'assessed_at' => now(), 'is_current' => true,
            'answers' => [], 'factor_scores' => [], 'weights' => [], 'explanation' => [],
        ]);

        return $engagement->refresh();
    }

    private function liveGrant(string $name, string $system, AccessLevel $level): AccessGrant
    {
        $grant = app(AccessService::class)->grantAccess($this->engagement, [
            'grantee_name' => $name,
            'grantee_email' => Str::slug($name).'@vendor.test',
            'system_name' => $system,
            'access_level' => $level->value,
            'justification' => 'Support of the hosted platform under the master services agreement.',
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_to' => now()->addMonths(3)->toDateString(),
            'monitoring_method' => 'Session recording, reviewed weekly.',
        ], $this->manager->id);

        return app(AccessService::class)->approveGrant($grant, $this->manager->id);
    }
}
