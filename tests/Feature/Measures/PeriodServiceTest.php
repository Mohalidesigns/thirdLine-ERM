<?php

namespace Tests\Feature\Measures;

use App\Models\MeasureValue;
use App\Models\Period;
use App\Models\PeriodCalendar;
use App\Models\User;
use App\Support\Periods\PeriodGenerator;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\TestCase;

class PeriodServiceTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The calendar horizon is relative to today, so the assertions below
        // name fiscal years. Freezing the clock keeps them meaningful in 2030.
        $this->freezeClock();

        $this->bootDomainFixtures();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    private function freezeClock(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-08-10 09:00:00'));
    }

    #[Test]
    public function it_provisions_a_calendar_and_the_horizon_on_first_use(): void
    {
        $this->assertSame(0, PeriodCalendar::count());

        $calendar = $this->periods()->ensureCalendar();

        $this->assertTrue($calendar->is_default);
        $this->assertSame(1, $calendar->fiscal_year_start_month);

        // Seven fiscal years: the current one plus three either side.
        $this->assertSame(7, Period::where('type', 'year')->count());
        $this->assertSame(7 * 12, Period::where('type', 'month')->count());
        $this->assertSame(7 * 4, Period::where('type', 'quarter')->count());
        $this->assertSame(7 * 2, Period::where('type', 'half')->count());
    }

    #[Test]
    public function months_roll_up_to_quarters_to_halves_to_the_year(): void
    {
        $this->periods()->ensureCalendar();

        $march = Period::where('code', 'FY2026-M03')->firstOrFail();
        $q1 = $march->parent;
        $h1 = $q1->parent;
        $year = $h1->parent;

        $this->assertSame('FY2026-Q1', $q1->code);
        $this->assertSame('FY2026-H1', $h1->code);
        $this->assertSame('FY2026', $year->code);
        $this->assertNull($year->parent_period_id);

        // A quarter's descendants are itself plus its three months.
        $this->assertCount(4, $q1->descendantIds());
    }

    #[Test]
    public function resolve_finds_the_period_containing_a_date(): void
    {
        $quarter = $this->periods()->resolve('2026-05-14', 'quarter');

        $this->assertSame('FY2026-Q2', $quarter->code);
        $this->assertSame('2026-04-01', $quarter->start_date->toDateString());
        $this->assertSame('2026-06-30', $quarter->end_date->toDateString());
    }

    #[Test]
    public function resolve_generates_a_fiscal_year_outside_the_horizon_rather_than_failing(): void
    {
        $this->periods()->ensureCalendar();
        $this->assertSame(0, Period::where('code', 'FY2015')->count());

        // Backfilling a decade-old assessment must not need a migration.
        $period = $this->periods()->resolve('2015-08-03', 'quarter');

        $this->assertSame('FY2015-Q3', $period->code);
    }

    #[Test]
    public function previous_and_next_walk_the_calendar_and_extend_it(): void
    {
        $q1 = $this->periods()->resolve('2026-02-01', 'quarter');

        $this->assertSame('FY2025-Q4', $this->periods()->previous($q1)->code);
        $this->assertSame('FY2026-Q2', $this->periods()->next($q1)->code);

        // Walking off the end of the horizon generates the next year.
        $last = Period::where('type', 'quarter')->orderByDesc('start_date')->firstOrFail();
        $beyond = $this->periods()->next($last);

        $this->assertNotNull($beyond);
        $this->assertTrue($beyond->start_date->gt($last->start_date));
    }

    #[Test]
    public function range_returns_the_periods_inside_the_window_in_order(): void
    {
        $months = $this->periods()->range('2026-01-01', '2026-06-30', 'month');

        $this->assertCount(6, $months);
        $this->assertSame('FY2026-M01', $months->first()->code);
        $this->assertSame('FY2026-M06', $months->last()->code);
    }

    #[Test]
    public function trailing_returns_the_last_n_periods_ending_with_the_anchor(): void
    {
        $anchor = $this->periods()->resolve('2026-06-30', 'month');
        $window = $this->periods()->trailing($anchor, 12);

        $this->assertCount(12, $window);
        $this->assertSame('FY2025-M07', $window->first()->code);
        $this->assertSame('FY2026-M06', $window->last()->code);
    }

    #[Test]
    public function closing_a_period_locks_its_values_and_those_of_its_children(): void
    {
        $this->bootMeasureEngine();
        $risk = $this->makeRisk();

        $march = $this->month('2026-03-15');
        $q1 = $march->parent;

        $this->measures()->record('risk.inherent_score', $risk, $march, 12, ['detect_breach' => false]);
        $this->measures()->record('risk.inherent_score', $risk, $q1, 12, ['detect_breach' => false]);

        $locked = $this->periods()->close($q1, $this->actor);

        $this->assertSame(2, $locked);
        $this->assertTrue($q1->fresh()->is_closed);
        $this->assertTrue($march->fresh()->is_closed, 'Closing a quarter must close the months inside it.');
        $this->assertSame(
            ['locked', 'locked'],
            MeasureValue::orderBy('id')->pluck('status')->all()
        );
    }

    #[Test]
    public function a_locked_value_cannot_be_edited_through_the_model(): void
    {
        $this->bootMeasureEngine();
        $risk = $this->makeRisk();
        $quarter = $this->quarter('2026-02-01');

        $value = $this->measures()->record('risk.inherent_score', $risk, $quarter, 9, ['detect_breach' => false]);
        $this->periods()->close($quarter, $this->actor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/locked/');

        $value->fresh()->update(['value' => 25]);
    }

    #[Test]
    public function recording_into_a_closed_period_is_refused(): void
    {
        $this->bootMeasureEngine();
        $risk = $this->makeRisk();
        $quarter = $this->quarter('2026-02-01');

        $this->periods()->close($quarter, $this->actor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/closed/');

        $this->measures()->record('risk.inherent_score', $risk, $quarter->fresh(), 9);
    }

    #[Test]
    public function reopening_requires_the_permission_and_a_reason(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootMeasureEngine();

        $risk = $this->makeRisk();
        $quarter = $this->quarter('2026-02-01');
        $this->measures()->record('risk.inherent_score', $risk, $quarter, 9, ['detect_breach' => false]);
        $this->periods()->close($quarter, $this->actor);

        $unprivileged = User::create([
            'name' => 'Analyst',
            'email' => 'analyst@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $unprivileged->assignRole('risk-analyst');

        try {
            $this->periods()->reopen($quarter, $unprivileged, 'Correcting a late submission');
            $this->fail('A user without period.reopen must not be able to reopen a closed period.');
        } catch (AuthorizationException) {
            $this->assertTrue($quarter->fresh()->is_closed);
        }

        $this->actor->assignRole('super-admin');
        $unlocked = $this->periods()->reopen($quarter->fresh(), $this->actor, 'Correcting a late submission');

        $this->assertSame(1, $unlocked);
        $this->assertFalse($quarter->fresh()->is_closed);
        $this->assertSame('approved', MeasureValue::first()->status);
    }

    #[Test]
    public function a_non_calendar_fiscal_year_is_labelled_by_the_year_it_starts_in(): void
    {
        $specs = PeriodGenerator::forFiscalYear(2026, 4);
        $year = collect($specs)->firstWhere('type', 'year');
        $firstMonth = collect($specs)->firstWhere('code', 'FY2026-M01');

        $this->assertSame('2026-04-01', $year['start_date']);
        $this->assertSame('2027-03-31', $year['end_date']);
        $this->assertSame('FY2026/27', $year['name']);
        $this->assertSame('Apr 2026', $firstMonth['name']);

        $this->assertSame(2026, PeriodGenerator::fiscalYearOf(CarbonImmutable::parse('2027-02-01'), 4));
        $this->assertSame(2025, PeriodGenerator::fiscalYearOf(CarbonImmutable::parse('2026-02-01'), 4));
    }

    #[Test]
    public function periods_are_scoped_to_their_organization(): void
    {
        $this->periods()->ensureCalendar();
        $ours = Period::count();

        $other = \App\Models\Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::actingAs($other->id, fn () => $this->periods()->ensureCalendar($other->id));

        // The tenant scope still shows only our own.
        $this->assertSame($ours, Period::count());
        $this->assertSame($ours * 2, Period::withoutGlobalScopes()->count());
    }
}
