<?php

namespace Tests\Feature\Scoring;

use App\Models\ObjectType;
use App\Models\Organization;
use App\Models\ScoringProfile;
use App\Support\Scoring\ScoringProfileTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Scoring profiles — the screen where an organisation decides what a score
 * means, and the four ways it could save a profile the platform cannot use.
 *
 * The one that matters most is the residual formula. It was
 * `nullable|string|max:500` — any string at all — and a formula that cannot be
 * evaluated does not fail loudly: RiskScoringService catches, logs a warning,
 * and falls back to the platform default. So a typo silently reverted **every
 * residual score in the register** to the default, and the only trace was a
 * line in a log nobody reads.
 */
class ScoringProfileBuilderTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $template = ScoringProfileTemplates::default('NGN');

        return array_merge([
            'name' => 'Our matrix',
            'code' => 'ours',
            'matrix_rows' => 5,
            'matrix_cols' => 5,
            'impact_aggregation' => 'max',
            'residual_formula' => ScoringProfileTemplates::DEFAULT_RESIDUAL_FORMULA,
            'likelihood_scale' => $template['likelihood_scale'],
            'impact_scale' => $template['impact_scale'],
            'rating_bands' => $template['rating_bands'],
            'impact_dimensions' => $template['impact_dimensions'],
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /*  The residual formula */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_formula_that_cannot_be_evaluated_is_refused(): void
    {
        $this->post(route('admin.scoring-profiles.store'), $this->payload([
            'residual_formula' => 'inherent * (1 - effectivness / 100',
        ]))->assertSessionHasErrors('residual_formula');

        $this->assertDatabaseMissing('scoring_profiles', ['code' => 'ours']);
    }

    #[Test]
    public function a_formula_naming_a_variable_that_is_not_in_scope_is_refused(): void
    {
        // `effectivness` parses fine; it is simply not a variable the scoring
        // service puts in scope, so every evaluation would throw and every
        // residual would quietly revert to the default.
        $this->post(route('admin.scoring-profiles.store'), $this->payload([
            'residual_formula' => 'inherent * (1 - effectivness / 100)',
        ]))->assertSessionHasErrors('residual_formula');
    }

    #[Test]
    public function a_workable_formula_is_accepted_and_stored(): void
    {
        $this->post(route('admin.scoring-profiles.store'), $this->payload([
            'residual_formula' => 'inherent * (1 - effectiveness / 100) + 1',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(
            'inherent * (1 - effectiveness / 100) + 1',
            ScoringProfile::where('code', 'ours')->firstOrFail()->residual_formula,
        );
    }

    #[Test]
    public function the_formula_can_be_checked_before_saving(): void
    {
        $this->postJson(route('admin.scoring-profiles.validate-formula'), [
            'formula' => 'inherent * (1 - effectiveness / 100)',
        ])->assertOk()->assertJson(['valid' => true]);

        $this->postJson(route('admin.scoring-profiles.validate-formula'), [
            'formula' => 'inherent * (((',
        ])->assertOk()->assertJson(['valid' => false]);
    }

    /* ------------------------------------------------------------------ */
    /*  Bands */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function bands_with_a_gap_are_refused(): void
    {
        // A score that falls into no band renders as a blank rating, which on a
        // dashboard is indistinguishable from "not assessed".
        $this->post(route('admin.scoring-profiles.store'), $this->payload([
            'rating_bands' => [
                ['code' => 'low', 'label' => 'Low', 'min' => 1, 'max' => 5],
                ['code' => 'high', 'label' => 'High', 'min' => 9, 'max' => 25],
            ],
        ]))->assertSessionHasErrors('rating_bands');
    }

    #[Test]
    public function bands_that_do_not_reach_the_top_score_are_refused(): void
    {
        $this->post(route('admin.scoring-profiles.store'), $this->payload([
            'rating_bands' => [
                ['code' => 'low', 'label' => 'Low', 'min' => 1, 'max' => 10],
            ],
        ]))->assertSessionHasErrors('rating_bands');
    }

    #[Test]
    public function the_rerating_preview_says_how_many_risks_move(): void
    {
        $this->makeRisk(['risk_code' => 'R-1', 'inherent_likelihood' => 5, 'inherent_impact' => 5,
            'inherent_rating' => 'Low', 'status' => 'active']);

        $response = $this->postJson(route('admin.scoring-profiles.preview'), [
            'matrix_rows' => 5,
            'matrix_cols' => 5,
            'rating_bands' => [
                ['code' => 'c', 'label' => 'Critical', 'min' => 1, 'max' => 25],
            ],
        ])->assertOk();

        // Score 25 lands in "Critical", the risk currently reads "Low", so it
        // moves — and the operator sees that before saving, not after.
        $response->assertJson(['moved' => 1, 'total' => 1]);
        $this->assertStringContainsString('R-1: Low → Critical', $response->json('examples.0'));
    }

    /* ------------------------------------------------------------------ */
    /*  The unvalidated fields */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_profile_cannot_be_scoped_to_another_institutions_object_type(): void
    {
        // `applies_to_object_type_ids` had no rule at all — it was read straight
        // off the component into the applies_to blob.
        $foreign = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB7',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $foreignType = ObjectType::withoutGlobalScopes()->create([
            'organization_id' => $foreign->id,
            'code' => 'TheirType',
            'name' => 'Their Type',
            'category' => 'governance',
            'is_system' => false,
        ]);

        $this->post(route('admin.scoring-profiles.store'), $this->payload([
            'applies_to_object_type_ids' => [$foreignType->id],
        ]))->assertSessionHasErrors('applies_to_object_type_ids.0');
    }

    #[Test]
    public function two_profiles_cannot_share_a_code(): void
    {
        $this->post(route('admin.scoring-profiles.store'), $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->post(route('admin.scoring-profiles.store'), $this->payload(['name' => 'Another']))
            ->assertSessionHasErrors('code');
    }

    #[Test]
    public function a_dimension_the_platform_does_not_score_is_refused(): void
    {
        // It would contribute nothing and be invisible — the aggregation simply
        // skips a dimension it has no value for.
        $this->post(route('admin.scoring-profiles.store'), $this->payload([
            'impact_dimensions' => ['financial', 'astrological'],
        ]))->assertSessionHasErrors('impact_dimensions.1');
    }

    /* ------------------------------------------------------------------ */
    /*  Defaults and the seeded profile */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function exactly_one_profile_is_the_default(): void
    {
        $this->post(route('admin.scoring-profiles.store'), $this->payload(['is_default' => true]))
            ->assertSessionHasNoErrors();

        $this->post(route('admin.scoring-profiles.store'), $this->payload([
            'code' => 'second', 'name' => 'Second', 'is_default' => true,
        ]))->assertSessionHasNoErrors();

        $defaults = ScoringProfile::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('is_default', true)
            ->pluck('code');

        $this->assertSame(['second'], $defaults->all());
    }

    #[Test]
    public function saving_over_the_seeded_profile_forks_it(): void
    {
        // The seeded 5×5 is what every organisation without one of their own
        // scores against. Editing it in place would move scores for everybody.
        $system = ScoringProfile::withoutGlobalScopes()->where('is_system', true)->firstOrFail();
        $originalBands = $system->rating_bands;

        $this->put(route('admin.scoring-profiles.update', $system->id), $this->payload([
            'name' => 'Our own version',
            'code' => 'our-own',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($originalBands, $system->fresh()->rating_bands, 'the seeded profile must be untouched');
        $this->assertTrue($system->fresh()->is_system);

        $fork = ScoringProfile::where('code', 'our-own')->first();

        $this->assertNotNull($fork);
        $this->assertFalse($fork->is_system);
        $this->assertSame($this->organization->id, $fork->organization_id);
        $this->assertSame($this->actor->id, $fork->approved_by, 'redefining a rating is a governance act');
    }

    #[Test]
    public function the_seeded_profile_cannot_be_deleted_by_anyone(): void
    {
        // Refused as a data invariant rather than an authorisation failure:
        // the actor here is a super-admin, and Gate::before answers every
        // ability true for one, so a policy could not have stopped this.
        $system = ScoringProfile::withoutGlobalScopes()->where('is_system', true)->firstOrFail();

        $this->delete(route('admin.scoring-profiles.destroy', $system->id))
            ->assertSessionHasErrors('profile');

        $this->assertNotNull($system->fresh());
    }

    #[Test]
    public function the_screen_needs_the_scoring_permission(): void
    {
        $analyst = \App\Models\User::create([
            'name' => 'Analyst',
            'email' => 'analyst-scoring@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $analyst->assignRole('risk-analyst');

        $this->actingAs($analyst)->get(route('admin.builder.scoring-profiles'))->assertForbidden();
    }
}
