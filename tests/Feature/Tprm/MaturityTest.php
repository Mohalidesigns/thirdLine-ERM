<?php

namespace Tests\Feature\Tprm;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\User;
use App\Services\Tprm\Reporting\MaturityService;
use App\Support\Tprm\MaturityModel;
use Carbon\CarbonImmutable;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * FR-RPT-06 — programme maturity.
 *
 * THE ASSERTIONS ARE ABOUT THE DIFFERENCE BETWEEN ZERO AND UNKNOWN. Level 0
 * means a programme somebody assessed and found non-existent; null means
 * nobody looked. Every place the two could be collapsed — the score itself,
 * the mean, the gap, the trend line — is pinned here, because collapsing them
 * makes an unassessed programme report as either better or worse than it is
 * and nothing in the product would contradict the number.
 *
 * The second theme is that an approved assessment stops moving. A maturity
 * trend whose earlier points move is not a trend.
 */
class MaturityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $admin;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Kaduna Union Bank', 'short_name' => 'KUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->admin = $this->user('Programme Owner', 'owner@kub.test', ['tprm.report.view', 'tprm.admin']);
        $this->reader = $this->user('Reader', 'reader@kub.test', ['tprm.report.view', 'tprm.report.export']);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ================================================================== */

    #[Test]
    public function opening_a_period_seeds_every_category_unscored(): void
    {
        $assessment = app(MaturityService::class)->open('H1 2027', CarbonImmutable::now(), $this->admin->id);

        $scores = $assessment->scores;

        // Eight VRMMM categories and the ten GV.SC subcategories.
        $this->assertCount(8, $scores->where('framework', MaturityModel::FRAMEWORK_VRMMM));
        $this->assertCount(10, $scores->where('framework', MaturityModel::FRAMEWORK_NIST_CSF));

        // A form with eighteen rows, four still unscored at approval, says
        // something about the assessment as well as the programme.
        $this->assertCount(18, $scores->whereNull('current_level'));
        $this->assertSame(MaturityModel::VERSION, $assessment->framework_version);
    }

    #[Test]
    public function the_nist_subcategories_come_from_the_shipped_framework_library(): void
    {
        $assessment = app(MaturityService::class)->open('H1 2027', CarbonImmutable::now(), $this->admin->id);

        $csf = $assessment->scores->where('framework', MaturityModel::FRAMEWORK_NIST_CSF)->keyBy('category_code');

        $this->assertArrayHasKey('GV.SC-01', $csf->all());
        $this->assertArrayHasKey('GV.SC-10', $csf->all());

        // The framework's own words, not a second copy that would drift the
        // first time NIST revised one.
        $published = \Illuminate\Support\Facades\DB::table('tp_framework_controls')
            ->where('control_id', 'GV.SC-10')
            ->value('title');

        $this->assertSame($published, $csf['GV.SC-10']->category_name);
    }

    #[Test]
    public function not_assessed_is_a_different_value_from_level_zero(): void
    {
        $assessment = app(MaturityService::class)->open('H1 2027', CarbonImmutable::now(), $this->admin->id);

        $unscored = $assessment->scores->firstWhere('category_code', 'VRMMM-01');
        $zero = $assessment->scores->firstWhere('category_code', 'VRMMM-02');

        app(MaturityService::class)->score($zero, ['current_level' => 0], $this->admin->id);

        $this->assertSame('Not assessed', $unscored->currentLabel());
        // A real finding about a programme somebody looked at.
        $this->assertSame('Non-existent', $zero->refresh()->currentLabel());
        $this->assertSame(0, $zero->current_level);
    }

    #[Test]
    public function a_score_can_be_cleared_back_to_not_assessed(): void
    {
        $assessment = app(MaturityService::class)->open('H1 2027', CarbonImmutable::now(), $this->admin->id);
        $score = $assessment->scores->firstWhere('category_code', 'VRMMM-03');

        app(MaturityService::class)->score($score, ['current_level' => 4], $this->admin->id);
        $this->assertSame(4, $score->refresh()->current_level);

        // Somebody who realises they scored on the wrong evidence should be
        // able to take the number back out rather than leave one they no
        // longer stand behind.
        app(MaturityService::class)->score($score, ['current_level' => null], $this->admin->id);
        $this->assertNull($score->refresh()->current_level);
    }

    #[Test]
    public function a_gap_against_a_target_nobody_set_is_not_a_gap(): void
    {
        $assessment = app(MaturityService::class)->open('H1 2027', CarbonImmutable::now(), $this->admin->id);

        $noTarget = $assessment->scores->firstWhere('category_code', 'VRMMM-01');
        $short = $assessment->scores->firstWhere('category_code', 'VRMMM-02');

        app(MaturityService::class)->score($noTarget, ['current_level' => 2], $this->admin->id);
        app(MaturityService::class)->score($short, [
            'current_level' => 2, 'target_level' => 4, 'gap_actions' => 'Publish the policy.',
        ], $this->admin->id);

        $plan = app(MaturityService::class)->gapPlan($assessment->refresh());

        $this->assertCount(1, $plan['items']);
        $this->assertSame('VRMMM-02', $plan['items'][0]['category_code']);
        $this->assertSame(2, $plan['items'][0]['gap']);
        // Counted, not silently assumed to be aiming at level 5.
        $this->assertSame(1, $plan['no_target']);
        $this->assertSame(16, $plan['unscored']);
    }

    #[Test]
    public function a_gap_with_no_action_recorded_is_marked_as_such(): void
    {
        $assessment = app(MaturityService::class)->open('H1 2027', CarbonImmutable::now(), $this->admin->id);
        $score = $assessment->scores->firstWhere('category_code', 'VRMMM-04');

        app(MaturityService::class)->score($score, ['current_level' => 1, 'target_level' => 3], $this->admin->id);

        $plan = app(MaturityService::class)->gapPlan($assessment->refresh());

        // The gap plan's own gap.
        $this->assertFalse($plan['items'][0]['has_plan']);
    }

    #[Test]
    public function an_off_scale_level_is_refused(): void
    {
        $assessment = app(MaturityService::class)->open('H1 2027', CarbonImmutable::now(), $this->admin->id);
        $score = $assessment->scores->first();

        $this->expectException(\InvalidArgumentException::class);

        app(MaturityService::class)->score($score, ['current_level' => 7], $this->admin->id);
    }

    /* ================================================================== */
    /*  Approval and the trend */
    /* ================================================================== */

    #[Test]
    public function an_assessment_of_nothing_cannot_be_approved(): void
    {
        $assessment = app(MaturityService::class)->open('H1 2027', CarbonImmutable::now(), $this->admin->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no category carries a current level/');

        app(MaturityService::class)->approve($assessment, $this->admin->id);
    }

    #[Test]
    public function reopening_an_approved_period_is_refused(): void
    {
        $service = app(MaturityService::class);
        $assessment = $service->open('H1 2027', CarbonImmutable::now(), $this->admin->id);

        $service->score($assessment->scores->first(), ['current_level' => 3], $this->admin->id);
        $service->approve($assessment->refresh(), $this->admin->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        $service->open('H1 2027', CarbonImmutable::now(), $this->admin->id);
    }

    #[Test]
    public function an_approved_score_cannot_be_changed(): void
    {
        $service = app(MaturityService::class);
        $assessment = $service->open('H1 2027', CarbonImmutable::now(), $this->admin->id);
        $score = $assessment->scores->first();

        $service->score($score, ['current_level' => 3], $this->admin->id);
        $service->approve($assessment->refresh(), $this->admin->id);

        $this->expectException(\RuntimeException::class);

        $service->score($score->refresh(), ['current_level' => 5], $this->admin->id);
    }

    #[Test]
    public function the_trend_keeps_a_break_where_a_category_went_unassessed(): void
    {
        $service = app(MaturityService::class);

        $first = $service->open('H1 2027', CarbonImmutable::parse('2027-06-30'), $this->admin->id);
        $service->score($first->scores->firstWhere('category_code', 'VRMMM-01'), ['current_level' => 2], $this->admin->id);
        $service->approve($first->refresh(), $this->admin->id);

        $second = $service->open('H2 2027', CarbonImmutable::parse('2027-12-31'), $this->admin->id);
        // VRMMM-01 deliberately left unscored this period.
        $service->score($second->scores->firstWhere('category_code', 'VRMMM-02'), ['current_level' => 3], $this->admin->id);
        $service->approve($second->refresh(), $this->admin->id);

        $trend = $service->trend();

        $this->assertCount(2, $trend['periods']);

        $vrmmm01 = collect($trend['categories'])->firstWhere('category_code', 'VRMMM-01');

        // A break in the line, not a straight segment across the gap.
        $this->assertSame([2, null], $vrmmm01['levels']);
    }

    #[Test]
    public function the_two_lenses_are_averaged_separately(): void
    {
        $service = app(MaturityService::class);
        $assessment = $service->open('H1 2027', CarbonImmutable::now(), $this->admin->id);

        $service->score($assessment->scores->firstWhere('category_code', 'VRMMM-01'), ['current_level' => 4], $this->admin->id);
        $service->score($assessment->scores->firstWhere('category_code', 'GV.SC-01'), ['current_level' => 2], $this->admin->id);
        $service->approve($assessment->refresh(), $this->admin->id);

        $means = $service->trend()['means'];

        // Not 3. Programme maturity and control outcomes are different scales,
        // and one number over both would mean nothing.
        $this->assertSame(4.0, (float) $means[MaturityModel::FRAMEWORK_VRMMM][0]['mean']);
        $this->assertSame(2.0, (float) $means[MaturityModel::FRAMEWORK_NIST_CSF][0]['mean']);
        $this->assertSame(1, $means[MaturityModel::FRAMEWORK_VRMMM][0]['scored']);
    }

    #[Test]
    public function a_period_scoring_nothing_in_one_lens_reports_null_rather_than_zero(): void
    {
        $service = app(MaturityService::class);
        $assessment = $service->open('H1 2027', CarbonImmutable::now(), $this->admin->id);

        $service->score($assessment->scores->firstWhere('category_code', 'VRMMM-01'), ['current_level' => 4], $this->admin->id);
        $service->approve($assessment->refresh(), $this->admin->id);

        $means = $service->trend()['means'];

        $this->assertNull($means[MaturityModel::FRAMEWORK_NIST_CSF][0]['mean']);
    }

    /* ================================================================== */
    /*  The gate */
    /* ================================================================== */

    #[Test]
    public function scoring_the_programme_is_tprm_admin_and_not_a_report_permission(): void
    {
        $assessment = app(MaturityService::class)->open('H1 2027', CarbonImmutable::now(), $this->admin->id);
        $score = $assessment->scores->first();

        // The number recorded here is what a regulator is shown when it asks
        // how mature this institution's third-party management is.
        $this->actingAs($this->reader)->get(route('tprm.reports.maturity'))->assertOk();
        $this->actingAs($this->reader)
            ->put(route('tprm.reports.maturity.score', $score), ['current_level' => 5])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->put(route('tprm.reports.maturity.score', $score), ['current_level' => 5])
            ->assertRedirect();

        $this->assertSame(5, $score->refresh()->current_level);
    }

    #[Test]
    public function the_screens_hand_the_pages_the_props_they_read(): void
    {
        $assessment = app(MaturityService::class)->open('H1 2027', CarbonImmutable::now(), $this->admin->id);

        $this->actingAs($this->admin)
            ->get(route('tprm.reports.maturity'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Reports/Maturity')
                ->has('assessments', 1)
                ->has('levels')
                ->where('can.assess', true)
            );

        $this->actingAs($this->reader)
            ->get(route('tprm.reports.maturity.show', $assessment))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Reports/MaturityAssessment')
                ->has('scores', 18)
                ->has('gapPlan.items')
                ->where('can.assess', false)
            );
    }

    /* ================================================================== */

    /** @param  list<string>  $permissions */
    private function user(string $name, string $email, array $permissions): User
    {
        $user = User::create([
            'name' => $name, 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('role-'.Str::slug($email), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }
}
