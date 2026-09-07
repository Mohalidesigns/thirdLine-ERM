<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaMethodology;
use App\Services\Rcsa\RcsaCycleService;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The one screen behind §14's Q4, Q5 and Q10.
 *
 * The interesting half is the two different LIFETIMES it edits. Appetite lives
 * on the methodology and freezes when a cycle opens; override approval and
 * retention are tenant settings and never freeze. A screen that saved all three
 * the same way would look right and silently discard a third of itself the
 * first time a cycle was open.
 */
class SettingsScreenTest extends CycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->grant(['rcsa_settings.manage']);
    }

    /* ------------------------------------------------------------------ */
    /*  Reaching it */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_screen_renders_with_the_shipped_defaults(): void
    {
        $this->actingAs($this->actor)
            ->get(route('rcsa.settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('RcsaSettings/Index')
                ->where('methodology.appetite_mode', 'single')
                ->where('treatment_override_approval_required', false)
                ->where('retention.export_files_days', null)
                ->where('retention.closed_cycle_years', null)
                ->where('methodology.is_locked', false)
            );
    }

    #[Test]
    public function a_user_without_the_permission_is_refused(): void
    {
        $stranger = $this->userWith(['rcsa_cycle.view', 'rcsa_assessment.view']);

        $this->actingAs($stranger)->get(route('rcsa.settings.index'))->assertForbidden();
        $this->actingAs($stranger)->put(route('rcsa.settings.update'), $this->payload())->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  Saving */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function it_saves_all_three_answers(): void
    {
        $this->actingAs($this->actor)
            ->put(route('rcsa.settings.update'), $this->payload([
                'appetite_mode' => 'per_category',
                'category_appetites' => [
                    ['risk_category' => 'Operational', 'ceiling_level' => 'very_low', 'note' => 'Board decision.'],
                ],
                'treatment_override_approval_required' => true,
                'retention' => ['export_files_days' => 90, 'import_files_days' => 30, 'closed_cycle_years' => 7],
            ]))
            ->assertRedirect();

        $methodology = $this->methodology()->refresh();

        $this->assertSame('per_category', $methodology->appetite_mode);
        $this->assertSame('very_low', $methodology->categoryAppetites()->sole()->ceiling_level);

        $settings = $this->organization->refresh()->settings;

        $this->assertTrue($settings['rcsa']['treatment_override_approval_required']);
        $this->assertSame(90, $settings['rcsa']['retention']['export_files_days']);
        $this->assertSame(7, $settings['rcsa']['retention']['closed_cycle_years']);
    }

    /**
     * `settings` carries `board_pack`, `mfa_required_roles` and
     * `bu_approval_required`. Writing the array back wholesale would drop every
     * key this screen does not know about — including P5's BU-approval step.
     */
    #[Test]
    public function saving_does_not_drop_settings_this_screen_does_not_own(): void
    {
        $this->organization->forceFill(['settings' => array_replace_recursive(
            (array) $this->organization->settings,
            ['rcsa' => ['bu_approval_required' => true], 'board_pack' => ['title' => 'Board pack']],
        )])->save();

        $this->actingAs($this->actor)->put(route('rcsa.settings.update'), $this->payload());

        $settings = $this->organization->refresh()->settings;

        $this->assertTrue($settings['rcsa']['bu_approval_required']);
        $this->assertSame('Board pack', $settings['board_pack']['title']);
    }

    /** Blank means keep for ever, and has to survive a round trip as null. */
    #[Test]
    public function a_blank_period_is_stored_as_keep_for_ever(): void
    {
        $this->actingAs($this->actor)->put(route('rcsa.settings.update'), $this->payload([
            'retention' => ['export_files_days' => null, 'import_files_days' => null, 'closed_cycle_years' => null],
        ]));

        $this->actingAs($this->actor)
            ->get(route('rcsa.settings.index'))
            ->assertInertia(fn (Assert $page) => $page->where('retention.export_files_days', null));
    }

    /* ------------------------------------------------------------------ */
    /*  The refusals */
    /* ------------------------------------------------------------------ */

    /**
     * The service reads 0 as "keep for ever". A screen that swallowed the 0 and
     * then displayed "keep for ever" would be telling somebody their input was
     * accepted when it was discarded, so the form says no instead.
     */
    #[Test]
    public function a_retention_period_of_zero_is_refused_rather_than_reinterpreted(): void
    {
        $this->actingAs($this->actor)
            ->from(route('rcsa.settings.index'))
            ->put(route('rcsa.settings.update'), $this->payload([
                'retention' => ['export_files_days' => 0, 'import_files_days' => null, 'closed_cycle_years' => null],
            ]))
            ->assertSessionHasErrors('retention.export_files_days');
    }

    /** A mode that changes nothing reads as a broken switch. */
    #[Test]
    public function per_category_mode_with_no_ceilings_is_refused(): void
    {
        $this->actingAs($this->actor)
            ->from(route('rcsa.settings.index'))
            ->put(route('rcsa.settings.update'), $this->payload([
                'appetite_mode' => 'per_category',
                'category_appetites' => [],
            ]))
            ->assertSessionHasErrors('appetite_mode');

        $this->assertSame('single', $this->methodology()->refresh()->appetite_mode);
    }

    #[Test]
    public function the_same_category_cannot_be_given_two_ceilings(): void
    {
        $this->actingAs($this->actor)
            ->from(route('rcsa.settings.index'))
            ->put(route('rcsa.settings.update'), $this->payload([
                'appetite_mode' => 'per_category',
                'category_appetites' => [
                    ['risk_category' => 'Operational', 'ceiling_level' => 'very_low', 'note' => null],
                    ['risk_category' => 'Operational', 'ceiling_level' => 'high', 'note' => null],
                ],
            ]))
            ->assertSessionHasErrors('category_appetites');
    }

    #[Test]
    public function a_ceiling_must_name_a_band_the_methodology_owns(): void
    {
        $this->actingAs($this->actor)
            ->from(route('rcsa.settings.index'))
            ->put(route('rcsa.settings.update'), $this->payload(['appetite_ceiling_level' => 'catastrophic']))
            ->assertSessionHasErrors('appetite_ceiling_level');
    }

    /* ------------------------------------------------------------------ */
    /*  The lock */
    /* ------------------------------------------------------------------ */

    /**
     * THE ONE THAT MATTERS. Appetite freezes when a cycle opens, and the other
     * two settings do not — so a save during an open cycle must persist Q5 and
     * Q10 and leave Q4 exactly as it was.
     */
    #[Test]
    public function an_open_cycle_freezes_appetite_but_not_the_other_two(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        app(RcsaCycleService::class)->open($this->makeCycle(), $this->actor);

        $this->assertTrue($this->methodology()->refresh()->is_locked, 'Opening a cycle should lock the methodology.');

        $this->actingAs($this->actor)
            ->put(route('rcsa.settings.update'), $this->payload([
                'appetite_mode' => 'per_category',
                'appetite_ceiling_level' => 'high',
                'category_appetites' => [
                    ['risk_category' => 'Operational', 'ceiling_level' => 'very_low', 'note' => null],
                ],
                'treatment_override_approval_required' => true,
                'retention' => ['export_files_days' => 45, 'import_files_days' => null, 'closed_cycle_years' => null],
            ]))
            ->assertRedirect();

        $methodology = $this->methodology()->refresh();

        // Frozen.
        $this->assertSame('single', $methodology->appetite_mode);
        $this->assertSame('low', $methodology->appetite_ceiling_level);
        $this->assertSame(0, $methodology->categoryAppetites()->count());

        // Not frozen.
        $settings = $this->organization->refresh()->settings;
        $this->assertTrue($settings['rcsa']['treatment_override_approval_required']);
        $this->assertSame(45, $settings['rcsa']['retention']['export_files_days']);
    }

    #[Test]
    public function the_screen_says_why_appetite_is_frozen(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        app(RcsaCycleService::class)->open($this->makeCycle(['name' => 'RCSA 2026 H1']), $this->actor);

        $this->actingAs($this->actor)
            ->get(route('rcsa.settings.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('methodology.is_locked', true)
                ->where('open_cycles.0.name', 'RCSA 2026 H1')
                ->where('methodology.locked_reason', fn ($reason) => str_contains((string) $reason, 'locked'))
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'appetite_mode' => 'single',
            'appetite_ceiling_level' => 'low',
            'category_appetites' => [],
            'treatment_override_approval_required' => false,
            'retention' => [
                'export_files_days' => null,
                'import_files_days' => null,
                'closed_cycle_years' => null,
            ],
        ], $overrides);
    }

    /** The parent's, plus the appetite rows this screen edits. */
    protected function methodology(): RcsaMethodology
    {
        return RcsaMethodology::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->where('code', Template::CODE)
            ->with(['scaleItems', 'bands', 'categoryAppetites'])
            ->firstOrFail();
    }
}
