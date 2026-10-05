<?php

namespace Tests\Feature\Bcms;

use App\Models\ApiToken;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\DrSystem;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Dr\DrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `bcms_audit_logs.actor_label` is `varchar(150)`; a user's name or an API
 * token's name is not bounded by that. The old code passed both straight in, so
 * an over-long name made the insert fail in strict mode and the trait's
 * "auditing never fails the write" catch turned that into a missing row.
 */
class BcmsAuditHostileActorLabelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function an_actor_name_longer_than_the_label_column_still_writes_the_row(): void
    {
        $user = User::create([
            'name' => str_repeat('é', 255), 'email' => 'long@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
        $this->actingAs($user);

        Cache::forget(AuditLog::AUDIT_FAILURE_CACHE_KEY);

        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'Core banking']);

        $row = AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', DrSystem::class)
            ->where('auditable_id', $system->getKey())
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($row, 'the audit row must be written, not silently lost');
        $this->assertSame($user->getKey(), $row->actor_id);
        $this->assertTrue(mb_check_encoding($row->actor_label, 'UTF-8'));
        $this->assertSame(150, mb_strlen($row->actor_label, 'UTF-8'));
        $this->assertNull(Cache::get(AuditLog::AUDIT_FAILURE_CACHE_KEY), 'no audit failure may have been counted');
    }

    #[Test]
    public function a_machine_token_with_a_long_multibyte_name_still_writes_the_explicit_row(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'Core banking']);

        $token = ApiToken::create([
            'organization_id' => $this->organization->id,
            'tokenable_type' => null,
            'tokenable_id' => null,
            'name' => str_repeat('€', 240),
            'token_type' => ApiToken::TYPE_CLIENT,
            'client_id' => 'cid_'.Str::random(24),
            'token' => hash('sha256', Str::random(48)),
            'abilities' => ['bcms.dr.ingest'],
        ]);

        $test = app(DrService::class)->ingest($system, 'zerto', 'ext-hostile-label', ['rto_actual_minutes' => 10], $token);

        // Raised, not swallowed: this path writes the row directly, so a
        // refused insert would have thrown out of the transaction. Either way
        // the row has to be there.
        $row = AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', $test::class)
            ->where('auditable_id', $test->getKey())
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($row);
        $this->assertStringStartsWith('API token #'.$token->getKey().' (', $row->actor_label);
        $this->assertTrue(mb_check_encoding($row->actor_label, 'UTF-8'));
        $this->assertLessThanOrEqual(150, mb_strlen($row->actor_label, 'UTF-8'));
    }
}
