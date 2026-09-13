<?php

namespace Tests\Feature\Bcms;

use App\Models\Bcms\AuditLog;
use App\Models\Bcms\Setting;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\BcmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Set A defect 3 (Gate 1 retrospective): `Setting` carried no `BcmsAuditable`
 * and `BcmsSettings::update()` never set `updated_by`, so disabling
 * `require_dual_approval_for_live` — the second-person control on a system
 * that can put a live evacuation order on ten thousand handsets — left no
 * trace at all. Separately, `defaultAttributes()` was filled unconditionally
 * on every save, so a caller sending a partial payload silently reset the
 * other thirteen fields (including `ai_enabled` and
 * `require_dual_approval_for_live`) back to their defaults.
 */
class BcmsSettingsAuditTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);

        $this->admin = User::create([
            'organization_id' => $this->organization->id, 'name' => 'BC Admin',
            'email' => 'bcadmin@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /**
     * MUTATION: remove `BcmsAuditable` from `Setting` (or revert to the old
     * model) and this assertion finds no row at all — a dual-approval control
     * disabled with nobody able to say who did it or when.
     */
    #[Test]
    public function disabling_dual_approval_for_live_writes_an_audited_before_and_after(): void
    {
        $this->actingAs($this->admin);

        $settings = app(BcmsSettings::class);

        // Create the row first (defaults, including the true we are about to
        // flip), exactly as an administrator opening the settings screen for
        // the first time would.
        $settings->update([], organizationId: $this->organization->id, userId: $this->admin->id);
        $settings->forget();

        $this->assertTrue((bool) $settings->for()->require_dual_approval_for_live);

        $settings->update(
            ['require_dual_approval_for_live' => false],
            organizationId: $this->organization->id,
            userId: $this->admin->id,
        );

        $row = Setting::query()->where('organization_id', $this->organization->id)->firstOrFail();

        $this->assertFalse((bool) $row->require_dual_approval_for_live);
        $this->assertSame($this->admin->id, $row->updated_by);

        $audit = AuditLog::query()
            ->where('organization_id', $this->organization->id)
            ->where('auditable_type', Setting::class)
            ->where('auditable_id', $row->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'No audit row was written for disabling dual approval on a live dispatch.');
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertArrayHasKey('require_dual_approval_for_live', $audit->after);
        $this->assertFalse((bool) $audit->after['require_dual_approval_for_live']);
        $this->assertArrayHasKey('require_dual_approval_for_live', $audit->before);
        $this->assertTrue((bool) $audit->before['require_dual_approval_for_live']);
    }

    /**
     * MUTATION: fill `defaultAttributes()` unconditionally (drop the
     * `$setting->exists` guard) and the second `update()` call below resets
     * `ai_enabled` and `require_dual_approval_for_live` back to their
     * defaults even though neither was named in that call's payload.
     */
    #[Test]
    public function a_partial_update_does_not_reset_the_fields_it_did_not_name(): void
    {
        $settings = app(BcmsSettings::class);

        // First save deliberately deviates from every boolean default.
        $settings->update([
            'ai_enabled' => true,
            'require_dual_approval_for_live' => false,
            'exercise_simulation_default' => false,
        ], organizationId: $this->organization->id, userId: $this->admin->id);
        $settings->forget();

        $afterFirst = $settings->for();
        $this->assertTrue((bool) $afterFirst->ai_enabled);
        $this->assertFalse((bool) $afterFirst->require_dual_approval_for_live);
        $this->assertFalse((bool) $afterFirst->exercise_simulation_default);

        // Second save touches an unrelated field only.
        $settings->update([
            'default_lead_time_days' => 21,
        ], organizationId: $this->organization->id, userId: $this->admin->id);
        $settings->forget();

        $afterSecond = $settings->for();
        $this->assertSame(21, (int) $afterSecond->default_lead_time_days);

        // The three booleans set on the first save must still hold — a
        // partial update behaves like every other partial update: it changes
        // what it names and leaves the rest alone.
        $this->assertTrue(
            (bool) $afterSecond->ai_enabled,
            'ai_enabled was silently reset to its default by an unrelated partial update.'
        );
        $this->assertFalse(
            (bool) $afterSecond->require_dual_approval_for_live,
            'require_dual_approval_for_live was silently reset to its default by an unrelated partial update.'
        );
        $this->assertFalse((bool) $afterSecond->exercise_simulation_default);
    }
}
