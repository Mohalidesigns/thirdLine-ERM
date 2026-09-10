<?php

namespace Tests\Feature\Scoring;

use App\Models\Organization;
use App\Models\ScoringProfile;
use App\Services\RiskScoringService;
use App\Services\Scoring\ScoringProfileProvisioner;
use App\Support\Scoring\ScoringProfileTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-05 TASK 3 — scoring profiles.
 *
 * The first group is the one that matters on upgrade day: an organisation on
 * the seeded 5×5 must produce byte-for-byte the same scores and ratings it
 * produced before this work package existed. The oracle is the pre-WP-05
 * implementation, written out literally below rather than referenced, so the
 * test still fails if somebody "tidies up" the service into agreement with
 * itself.
 */
class ScoringProfileTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private RiskScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        ScoringProfile::flushResolutionCache();
        $this->service = new RiskScoringService;
    }

    protected function tearDown(): void
    {
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Parity with the hardcoded 5×5 this work package deleted */
    /* ------------------------------------------------------------------ */

    /**
     * RiskScoringService::calculateRating() exactly as it read before WP-05.
     * Do not refactor this to call the service — it is the control, not the
     * subject.
     */
    private function legacyRating(int $score): string
    {
        if ($score >= 20) {
            return 'Critical';
        }
        if ($score >= 12) {
            return 'High';
        }
        if ($score >= 5) {
            return 'Medium';
        }

        return 'Low';
    }

    #[Test]
    public function every_cell_of_the_five_by_five_matrix_scores_and_rates_exactly_as_it_did_before(): void
    {
        $checked = 0;

        for ($likelihood = 1; $likelihood <= 5; $likelihood++) {
            for ($impact = 1; $impact <= 5; $impact++) {
                $score = $this->service->calculateScore($likelihood, $impact);

                $this->assertSame(
                    $likelihood * $impact,
                    $score,
                    "score moved at likelihood {$likelihood}, impact {$impact}"
                );

                $this->assertSame(
                    $this->legacyRating($score),
                    $this->service->calculateRating($score),
                    "rating moved at likelihood {$likelihood}, impact {$impact} (score {$score})"
                );

                $checked++;
            }
        }

        $this->assertSame(25, $checked, 'every cell of the matrix should have been checked');
    }

    #[Test]
    public function the_rating_is_unchanged_for_every_score_the_matrix_can_produce_and_beyond(): void
    {
        // 0 and negatives are not reachable through the UI but were reachable
        // through the old code path, and the migration must not change what
        // they produced either.
        for ($score = -5; $score <= 40; $score++) {
            $this->assertSame(
                $this->legacyRating($score),
                $this->service->calculateRating($score),
                "rating moved at score {$score}"
            );
        }
    }

    #[Test]
    public function the_seeded_system_profile_is_the_five_by_five_and_belongs_to_every_tenant(): void
    {
        $profile = $this->service->profileFor(organizationId: $this->organization->id);

        $this->assertTrue($profile->exists, 'the seed migration should have written a resolvable profile');
        $this->assertNull($profile->organization_id, 'a tenant with no profile of its own resolves the system one');
        $this->assertSame(5, $profile->matrix_rows);
        $this->assertSame(5, $profile->matrix_cols);
        $this->assertSame(ScoringProfileTemplates::DEFAULT_RATING_BANDS, $profile->rating_bands);
    }

    #[Test]
    public function residual_scores_are_unchanged_by_the_default_profiles_formula(): void
    {
        foreach ([[20, 50.0, 10], [20, 75.0, 5], [20, 0.0, 20], [20, 100.0, 1], [25, 37.0, 16]] as [$inherent, $effectiveness, $expected]) {
            $this->assertSame(
                $expected,
                $this->service->calculateResidualScore($inherent, $effectiveness),
                "residual moved for inherent {$inherent} at {$effectiveness}% effectiveness"
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Matrices that are not 5×5 */
    /* ------------------------------------------------------------------ */

    #[Test]
    #[DataProvider('matrixSizes')]
    public function a_resized_matrix_produces_a_coherent_grid(int $rows, int $cols): void
    {
        app(ScoringProfileProvisioner::class)->resize($this->organization, $rows, $cols);

        $matrix = $this->service->getRiskMatrix($this->organization->id);

        $this->assertCount($rows, $matrix, 'the grid should have one row per likelihood point');

        foreach ($matrix as $likelihood => $row) {
            $this->assertCount($cols, $row, "row {$likelihood} should have one cell per impact point");

            foreach ($row as $impact => $cell) {
                $this->assertSame($likelihood * $impact, $cell['score']);

                // Every attainable score must fall inside a band. A gap means
                // a blank rating on a dashboard, which reads as "not assessed".
                $this->assertNotSame(
                    '',
                    $cell['rating'],
                    "score {$cell['score']} ({$likelihood}×{$impact}) falls into no band on a {$rows}×{$cols} matrix"
                );
                $this->assertNotNull($cell['color'], "score {$cell['score']} has no colour to render");
            }
        }
    }

    public static function matrixSizes(): array
    {
        return [
            '3x3' => [3, 3],
            '4x4' => [4, 4],
            '5x5' => [5, 5],
            '6x6' => [6, 6],
            '10x10' => [10, 10],
            'asymmetric 4x6' => [4, 6],
        ];
    }

    #[Test]
    #[DataProvider('matrixSizes')]
    public function the_rating_bands_of_a_resized_matrix_are_contiguous_and_cover_the_whole_range(int $rows, int $cols): void
    {
        $bands = ScoringProfileTemplates::ratingBandsFor($rows, $cols);

        $this->assertSame(1, $bands[0]['min'], 'the lowest band must start at 1');
        $this->assertSame($rows * $cols, $bands[count($bands) - 1]['max'], 'the highest band must close on the maximum score');

        foreach ($bands as $index => $band) {
            $this->assertLessThanOrEqual($band['max'], $band['min'], "band {$band['code']} is inverted");

            if ($index > 0) {
                $this->assertSame(
                    $bands[$index - 1]['max'] + 1,
                    $band['min'],
                    "there is a gap or an overlap between {$bands[$index - 1]['code']} and {$band['code']}"
                );
            }
        }
    }

    #[Test]
    public function moving_to_a_four_by_four_re_rates_a_risk_that_was_critical_on_five_by_five(): void
    {
        // The whole point of the acceptance criterion: the same stored
        // likelihood and impact mean something different under a different
        // profile, and every surface must agree about what.
        $this->assertSame('Critical', $this->service->calculateRating(20));

        app(ScoringProfileProvisioner::class)->resize($this->organization, 4, 4);
        ScoringProfile::flushResolutionCache();

        $profile = $this->service->profileFor(organizationId: $this->organization->id);

        $this->assertSame(4, $profile->matrix_rows);
        // A 4×4 tops out at 16, so a stored 5 is clamped to 4 and 5×5 becomes
        // 4×4 = 16, which is that matrix's maximum and therefore Critical.
        $this->assertSame(16, $this->service->calculateScore(5, 5, $profile));
        $this->assertSame('Critical', $this->service->calculateRating(16, $profile));
    }

    #[Test]
    public function a_resize_keeps_the_level_definitions_the_tenant_wrote(): void
    {
        $provisioner = app(ScoringProfileProvisioner::class);
        $profile = $provisioner->ensureFor($this->organization);

        $scale = $profile->impact_scale;
        $scale[3]['definition'] = 'Loss above ₦500m or a CBN sanction';
        $profile->impact_scale = $scale;
        $profile->save();

        $resized = $provisioner->resize($this->organization, 6, 6);

        $this->assertSame(
            'Loss above ₦500m or a CBN sanction',
            collect($resized->impact_scale)->firstWhere('value', 4)['definition'],
            'a resize must not discard the definitions somebody wrote'
        );
        $this->assertCount(6, $resized->impact_scale);
    }

    /* ------------------------------------------------------------------ */
    /*  Resolution */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_tenants_own_profile_beats_the_system_profile(): void
    {
        $own = ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'house-matrix',
            'is_system' => false,
        ]));

        ScoringProfile::flushResolutionCache();

        $this->assertSame($own->id, $this->service->profileFor(organizationId: $this->organization->id)->id);
    }

    #[Test]
    public function a_risk_type_specific_profile_beats_the_general_one(): void
    {
        ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'general',
            'is_system' => false,
        ]));

        $credit = ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'credit',
            'applies_to' => ['risk_types' => ['credit']],
            'is_default' => false,
            'is_system' => false,
        ]));

        ScoringProfile::flushResolutionCache();

        $this->assertSame(
            $credit->id,
            $this->service->profileFor(organizationId: $this->organization->id, riskType: 'credit')->id
        );

        $this->assertNotSame(
            $credit->id,
            $this->service->profileFor(organizationId: $this->organization->id, riskType: 'operational')->id,
            'a credit-risk profile must never score an operational risk'
        );
    }

    #[Test]
    public function a_profile_that_is_not_yet_effective_does_not_score_anything(): void
    {
        ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'next-year',
            'name' => 'Next year 6×6',
            'matrix_rows' => 6,
            'matrix_cols' => 6,
            'rating_bands' => ScoringProfileTemplates::ratingBandsFor(6, 6),
            'effective_from' => now()->addMonth()->toDateString(),
            'is_system' => false,
        ]));

        ScoringProfile::flushResolutionCache();

        $this->assertSame(
            5,
            $this->service->profileFor(organizationId: $this->organization->id)->matrix_rows,
            'staging next year\'s matrix must not re-rate this year\'s register'
        );
    }

    #[Test]
    public function another_organizations_profile_never_resolves(): void
    {
        $other = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $other->id,
            'code' => 'theirs',
            'matrix_rows' => 3,
            'matrix_cols' => 3,
            'rating_bands' => ScoringProfileTemplates::ratingBandsFor(3, 3),
            'is_system' => false,
        ]));

        ScoringProfile::flushResolutionCache();

        $this->assertSame(
            5,
            $this->service->profileFor(organizationId: $this->organization->id)->matrix_rows
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Provisioning from the previously dead settings */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function provisioning_honours_the_matrix_size_the_settings_screen_collected(): void
    {
        // settings->risk_settings->impact_scale has been written by the admin
        // screen since WP-00 and read by nothing. Provisioning is the first
        // code path that acts on it.
        $this->organization->update(['settings' => [
            'risk_settings' => ['probability_scale' => 4, 'impact_scale' => 4],
        ]]);

        $profile = app(ScoringProfileProvisioner::class)->ensureFor($this->organization->fresh());

        $this->assertSame(4, $profile->matrix_rows);
        $this->assertSame(4, $profile->matrix_cols);
        $this->assertSame(16, $profile->rating_bands[3]['max']);
    }

    #[Test]
    public function provisioning_is_idempotent(): void
    {
        $provisioner = app(ScoringProfileProvisioner::class);

        $first = $provisioner->ensureFor($this->organization);
        $second = $provisioner->ensureFor($this->organization->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ScoringProfile::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)->count());
    }

    #[Test]
    public function a_custom_residual_formula_is_evaluated_rather_than_the_default(): void
    {
        $profile = ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'halving',
            // Deliberately not the default expression, so we know the
            // evaluator ran rather than the fallback.
            'residual_formula' => 'inherent * (1 - effectiveness / 200)',
            'is_system' => false,
        ]));

        ScoringProfile::flushResolutionCache();

        // 20 × (1 − 100/200) = 10
        $this->assertSame(10, $this->service->calculateResidualScore(20, 100.0, $profile));
    }

    #[Test]
    public function a_broken_residual_formula_falls_back_instead_of_blanking_the_register(): void
    {
        $profile = ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'broken',
            'residual_formula' => 'inherent * (((',
            'is_system' => false,
        ]));

        ScoringProfile::flushResolutionCache();

        $this->assertSame(10, $this->service->calculateResidualScore(20, 50.0, $profile));
    }
}
