<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\CurrencyService;
use App\Support\RiskCalculationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Organisation settings — the panels that saved into a void.
 *
 * The Blade screen had five panels. Two of them wrote ten keys that nothing on
 * the platform read: `settings.risk_thresholds` (four rating boundaries and a
 * capital requirement percentage) and `settings.notification_prefs` (four
 * switches and an address). A user set them, saw "updated successfully", and
 * nothing anywhere behaved differently.
 *
 * Meanwhile five keys with real readers had no interface at all. This file
 * asserts the swap in the only way that means anything: it saves through the
 * screen and then asks the consuming service what it now believes.
 */
class OrganizationSettingsTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('admin.settings');
        Role::findOrCreate('risk-analyst');
        Role::findOrCreate(UserPolicy::SUPER_ADMIN);

        $this->actor->givePermissionTo('admin.settings');
        RiskCalculationSettings::flush();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'control_effectiveness' => [
                'effective' => 90,
                'mostly_effective' => 75,
                'partially_effective' => 55,
                'ineffective' => 30,
                'not_operating' => 5,
            ],
            'regulatory_reportable_threshold_ngn' => 25000000,
            'reporting_currency' => 'USD',
            'default_fx_rate_type' => 'nafem',
            'mfa_required_roles' => ['risk-analyst'],
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /*  What is saved is what is read */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function saving_the_settings_changes_what_the_calculations_believe(): void
    {
        $this->actingAs($this->actor)
            ->put(route('admin.settings.organization'), $this->payload())
            ->assertSessionHasNoErrors();

        RiskCalculationSettings::flush();

        $orgId = $this->organization->id;

        // Not "the row contains what I posted" — the services that consume
        // these keys are asked directly, because the nesting is the part that
        // goes wrong and a database assertion would not notice.
        $this->assertSame(90.0, (float) RiskCalculationSettings::effectivenessMap($orgId)['effective']);
        $this->assertSame(5.0, (float) RiskCalculationSettings::effectivenessMap($orgId)['not_operating']);
        $this->assertSame(25000000.0, RiskCalculationSettings::regulatoryReportableThresholdNgn($orgId));

        $currency = app(CurrencyService::class);
        $this->assertSame('USD', $currency->reportingCurrency($orgId));
        $this->assertSame('nafem', $currency->defaultRateType($orgId));

        $this->assertSame(
            ['risk-analyst'],
            array_values((array) $this->organization->fresh()->settings['mfa_required_roles']),
        );
    }

    #[Test]
    public function an_untouched_band_keeps_the_platform_default(): void
    {
        // Overrides merge over config/risk.php rather than replacing it, so a
        // band added in a later release still has a value for every tenant.
        $orgId = $this->organization->id;
        $default = config('risk.control_effectiveness.mostly_effective');

        $this->actingAs($this->actor)
            ->put(route('admin.settings.organization'), $this->payload([
                'control_effectiveness' => array_merge($this->payload()['control_effectiveness'], [
                    'mostly_effective' => $default,
                ]),
            ]))
            ->assertSessionHasNoErrors();

        RiskCalculationSettings::flush();

        $this->assertSame((float) $default, (float) RiskCalculationSettings::effectivenessMap($orgId)['mostly_effective']);
    }

    #[Test]
    public function the_screen_shows_the_values_the_calculations_are_using(): void
    {
        $this->actingAs($this->actor)
            ->get(route('admin.settings'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Settings/General')
                ->where('calculation.control_effectiveness.effective', config('risk.control_effectiveness.effective'))
                ->where('platform.reporting_currency', CurrencyService::DEFAULT_REPORTING_CURRENCY)
                ->has('options.roles')
                ->has('matrix.rows')
            );
    }

    /* ------------------------------------------------------------------ */
    /*  The rules */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_effectiveness_band_outside_zero_to_one_hundred_is_refused(): void
    {
        $this->actingAs($this->actor)
            ->put(route('admin.settings.organization'), $this->payload([
                'control_effectiveness' => array_merge($this->payload()['control_effectiveness'], [
                    'effective' => 140,
                ]),
            ]))
            ->assertSessionHasErrors('control_effectiveness.effective');
    }

    #[Test]
    public function a_role_that_does_not_exist_cannot_be_required_to_use_mfa(): void
    {
        $this->actingAs($this->actor)
            ->put(route('admin.settings.organization'), $this->payload([
                'mfa_required_roles' => ['not-a-real-role'],
            ]))
            ->assertSessionHasErrors('mfa_required_roles.0');
    }

    #[Test]
    public function the_matrix_bounds_are_still_three_to_ten(): void
    {
        $this->actingAs($this->actor)
            ->put(route('admin.settings.risk'), [
                'scoring_methodology' => 'qualitative',
                'probability_scale' => 2,
                'impact_scale' => 5,
                'calculation_method' => 'max',
                'review_frequency' => 'quarterly',
            ])
            ->assertSessionHasErrors('probability_scale');
    }

    /* ------------------------------------------------------------------ */
    /*  The panels that are gone */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_routes_that_saved_into_a_void_are_gone(): void
    {
        // Phase 6.2 removed `admin.settings.thresholds` and
        // `admin.settings.notifications`. Between them they wrote ten keys no
        // service, job, command or template ever read. If either name comes
        // back, so has the lie.
        foreach (['admin.settings.thresholds', 'admin.settings.notifications'] as $name) {
            $this->assertNull(
                app('router')->getRoutes()->getByName($name),
                "Route [{$name}] is back; it writes settings nothing reads.",
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Tenancy */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_administrator_edits_only_their_own_institution(): void
    {
        $foreign = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB4',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $theirs = User::withoutGlobalScopes()->create([
            'name' => 'Their Admin',
            'email' => 'their-admin@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $foreign->id,
            'is_active' => true,
        ]);

        $this->assertFalse($theirs->can('updateSettings', $this->organization));
        $this->assertTrue($this->actor->can('updateSettings', $this->organization));
    }

    #[Test]
    public function the_screen_needs_its_permission(): void
    {
        $stranger = User::create([
            'name' => 'No Access',
            'email' => 'no-access@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->actingAs($stranger)->get(route('admin.settings'))->assertForbidden();
    }
}
