<?php

namespace Tests\Feature\Tprm;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\TprmSetting;
use App\Models\User;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The screen that was missing for `tp_settings`.
 *
 * Phase 9 shipped `shareholders_funds_minor` with no way to enter it, so the
 * CBN 0.01% materiality test could not run on any tenant and every loss-only
 * incident reported as `undetermined` — honest, and useless. Phase 10 then
 * needed the institution's LEI, country and competent authority for DORA
 * RT.01.01 and hit the same wall. A column with no screen is a column that is
 * always null.
 *
 * THE TESTS HERE ARE MOSTLY ABOUT REFUSALS. The screen's value is that it
 * cannot be used to record a half-fact: a funds figure with no as-at date, a
 * twelve-character LEI, an amount in a currency nobody named. Each of those
 * would produce a threshold or a register field that looks authoritative and
 * is not.
 */
class ProgrammeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Port Harcourt Trust Bank', 'short_name' => 'PHTB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->admin = User::create([
            'name' => 'Programme Admin', 'email' => 'admin@phtb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-programme-admin', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.admin', 'web'));
        $this->admin->assignRole($role);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function the_screen_says_which_test_the_missing_figure_disables(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tprm.settings.programme'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Settings/Programme')
                ->where('consequences.materiality.available', false)
                ->where('consequences.materiality.threshold', null)
                // Four unset RT.01.01 fields, named.
                ->has('consequences.register_of_information.missing', 4)
            );
    }

    #[Test]
    public function saving_the_figure_makes_the_cbn_threshold_computable(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tprm.settings.programme.update'), [
                'shareholders_funds' => '250000000000.00',
                'shareholders_funds_currency' => 'ngn',
                'shareholders_funds_as_at' => now()->subMonths(3)->toDateString(),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $settings = TprmSetting::forOrganization($this->bank->id)->refresh();

        // Stored in minor units like every other money column in the module.
        $this->assertSame(25_000_000_000_000, $settings->shareholders_funds_minor);
        $this->assertSame('NGN', $settings->shareholders_funds_currency);
        $this->assertTrue($settings->hasMaterialityBasis());

        // 0.01% of ₦250bn is ₦25m.
        $this->assertSame(2_500_000_000, $settings->cbnMaterialityThresholdMinor());
    }

    #[Test]
    public function a_figure_with_no_as_at_date_is_refused(): void
    {
        // A threshold whose basis has no date shows on screen as current, and
        // shareholders' funds move with every audited account.
        $this->actingAs($this->admin)
            ->put(route('tprm.settings.programme.update'), [
                'shareholders_funds' => '100000000',
                'shareholders_funds_currency' => 'NGN',
            ])
            ->assertSessionHasErrors('shareholders_funds_as_at');

        $this->assertFalse(TprmSetting::forOrganization($this->bank->id)->hasMaterialityBasis());
    }

    #[Test]
    public function a_partial_lei_is_refused_rather_than_stored(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tprm.settings.programme.update'), ['lei' => '5493001KJTII'])
            ->assertSessionHasErrors('lei');

        $this->assertNull(TprmSetting::forOrganization($this->bank->id)->lei);
    }

    #[Test]
    public function a_wrong_figure_can_be_cleared_again(): void
    {
        TprmSetting::forOrganization($this->bank->id)->forceFill([
            'shareholders_funds_minor' => 999,
            'shareholders_funds_currency' => 'NGN',
            'shareholders_funds_as_at' => now()->subYears(4)->toDateString(),
        ])->save();

        $this->actingAs($this->admin)
            ->put(route('tprm.settings.programme.update'), ['shareholders_funds' => ''])
            ->assertRedirect();

        // Forcing a tenant to leave a wrong number in place is how a bad
        // threshold becomes permanent.
        $this->assertNull(TprmSetting::forOrganization($this->bank->id)->refresh()->shareholders_funds_minor);
    }

    #[Test]
    public function reading_a_register_does_not_let_you_move_the_reporting_threshold(): void
    {
        $reporter = User::create([
            'name' => 'Reporter', 'email' => 'reporter@phtb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-report-reader', 'web');
        foreach (['tprm.report.view', 'tprm.report.export'] as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $reporter->assignRole($role);

        $this->actingAs($reporter)->get(route('tprm.settings.programme'))->assertForbidden();
        $this->actingAs($reporter)
            ->put(route('tprm.settings.programme.update'), ['shareholders_funds' => '1'])
            ->assertForbidden();
    }

    #[Test]
    public function the_identity_saved_here_reaches_the_register_of_information(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tprm.settings.programme.update'), [
                'lei' => '5493001kjtiigc8y1r12',
                'country' => 'ng',
                'competent_authority' => 'Central Bank of Nigeria',
                'reporting_currency' => 'ngn',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $table = collect(app(\App\Services\Tprm\Reporting\DoraRegisterBuilder::class)->tables())
            ->firstWhere('code', 'RT.01.01');

        // Upper-cased on the way in: an LEI is defined as upper case, and two
        // spellings of one identifier is one identifier too many.
        $this->assertContains('5493001KJTIIGC8Y1R12', $table['rows'][0]);
        $this->assertContains('NG', $table['rows'][0]);
        $this->assertSame('complete', $table['coverage']);
    }
}
