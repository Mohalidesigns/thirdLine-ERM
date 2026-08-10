<?php

namespace Tests\Feature\Measures;

use App\Models\FxRate;
use App\Models\MeasureValue;
use App\Models\Organization;
use App\Services\CurrencyService;
use App\Support\Measures\MeasureCatalog;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\TestCase;

/**
 * WP-04 TASK 6 acceptance: a loss event in USD rolls up into an NGN group
 * report at the recorded rate.
 */
class MultiCurrencyRollupTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 09:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-01-05 09:00:00'));

        $this->bootDomainFixtures();
        $this->bootMeasureEngine();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    private function currency(): CurrencyService
    {
        return app(CurrencyService::class);
    }

    /**
     * A platform-wide rate — what CBN published that day, visible to every
     * tenant.
     *
     * Written inside a tenancy bypass because that is the only way to leave
     * organization_id NULL: BelongsToOrganization stamps the current tenant
     * onto anything created without one, which is the behaviour that keeps a
     * tenant-scoped row from escaping its tenant by accident. FetchCbnRates
     * writes the same way, for the same reason.
     */
    private function recordRate(string $from, string $to, string $date, float $rate, string $type = 'cbn_official'): FxRate
    {
        return TenantContext::bypass(fn () => FxRate::create([
            'organization_id' => null,
            'from_currency' => $from,
            'to_currency' => $to,
            'rate_date' => $date,
            'rate_type' => $type,
            'rate' => $rate,
            'source' => 'test fixture',
            'captured_at' => now(),
        ]), 'test fixture: platform-wide FX rate');
    }

    #[Test]
    public function money_round_trips_through_minor_units(): void
    {
        $this->assertSame(150_075, CurrencyService::toMinor(1500.75, 'NGN'));
        $this->assertSame(1500.75, CurrencyService::toMajor(150_075, 'NGN'));
        $this->assertSame(100, CurrencyService::minorScale('USD'));
        $this->assertSame(1, CurrencyService::minorScale('JPY'), 'A zero-decimal currency has no minor unit.');
    }

    #[Test]
    public function a_conversion_returns_the_rate_it_used(): void
    {
        $this->recordRate('USD', 'NGN', '2026-03-31', 1650.25);

        // USD 250,000.00, held as 25,000,000 cents.
        $converted = $this->currency()->convert(25_000_000, 'USD', 'NGN', '2026-03-31');

        $this->assertSame(1650.25, $converted['rate']);
        $this->assertSame('cbn_official', $converted['rate_type']);
        $this->assertSame('2026-03-31', $converted['rate_date']);
        // 250,000 x 1650.25 = NGN 412,562,500.00, in kobo.
        $this->assertSame(41_256_250_000, $converted['amount_minor']);
    }

    #[Test]
    public function the_most_recent_rate_on_or_before_the_date_is_used(): void
    {
        $this->recordRate('USD', 'NGN', '2026-03-20', 1600.00);
        $this->recordRate('USD', 'NGN', '2026-03-28', 1650.25);
        $this->recordRate('USD', 'NGN', '2026-04-05', 1700.00);

        $this->assertSame(1650.25, $this->currency()->resolveRate('USD', 'NGN', '2026-03-31')['rate']);
        $this->assertSame('2026-03-28', $this->currency()->resolveRate('USD', 'NGN', '2026-03-31')['rate_date']);
    }

    #[Test]
    public function the_rate_type_changes_the_answer(): void
    {
        $this->recordRate('USD', 'NGN', '2026-03-31', 1650.25, 'cbn_official');
        $this->recordRate('USD', 'NGN', '2026-03-31', 1712.90, 'nafem');

        $this->assertSame(1650.25, $this->currency()->resolveRate('USD', 'NGN', '2026-03-31')['rate']);
        $this->assertSame(1712.90, $this->currency()->resolveRate('USD', 'NGN', '2026-03-31', 'nafem')['rate']);
    }

    #[Test]
    public function an_organisation_rate_wins_over_the_platform_rate_of_the_same_day(): void
    {
        $this->recordRate('USD', 'NGN', '2026-03-31', 1650.25);

        FxRate::create([
            'organization_id' => $this->organization->id,
            'from_currency' => 'USD',
            'to_currency' => 'NGN',
            'rate_date' => '2026-03-31',
            'rate_type' => 'cbn_official',
            'rate' => 1660.00,
            'source' => 'contracted rate',
            'captured_at' => now(),
        ]);

        $this->assertSame(1660.00, $this->currency()->resolveRate('USD', 'NGN', '2026-03-31')['rate']);
    }

    #[Test]
    public function an_inverse_rate_is_derived_when_only_one_direction_is_published(): void
    {
        // CBN publishes USD/NGN, not NGN/USD.
        $this->recordRate('USD', 'NGN', '2026-03-31', 1600.00);

        $resolved = $this->currency()->resolveRate('NGN', 'USD', '2026-03-31');

        $this->assertEqualsWithDelta(1 / 1600.00, $resolved['rate'], 1e-12);
    }

    #[Test]
    public function a_missing_rate_throws_rather_than_guessing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No cbn_official rate is recorded for USD\/NGN/');

        $this->currency()->convert(100, 'USD', 'NGN', '2026-03-31');
    }

    #[Test]
    public function the_reporting_currency_comes_from_organisation_settings(): void
    {
        $this->assertSame('NGN', $this->currency()->reportingCurrency());

        $this->organization->update(['settings' => ['reporting_currency' => 'GHS', 'default_fx_rate_type' => 'nafem']]);

        $this->assertSame('GHS', $this->currency()->reportingCurrency());
        $this->assertSame('nafem', $this->currency()->defaultRateType());
    }

    #[Test]
    public function a_usd_loss_event_rolls_up_into_an_ngn_group_report_at_the_recorded_rate(): void
    {
        $q1 = $this->quarter('2026-03-15');
        $rateDate = $q1->end_date->toDateString();

        $this->recordRate('USD', 'NGN', $rateDate, 1650.25);

        $nigerianRisk = $this->makeRisk(['title' => 'Domestic fraud exposure']);
        $offshoreRisk = $this->makeRisk(['title' => 'Correspondent banking exposure']);

        // NGN 500,000,000.00 recorded natively, in kobo.
        $this->measures()->record(
            MeasureCatalog::RISK_FINANCIAL_EXPOSURE, $nigerianRisk, $q1, 50_000_000_000,
            ['currency_code' => 'NGN', 'detect_breach' => false]
        );

        // USD 250,000.00 recorded natively, in cents.
        $this->measures()->record(
            MeasureCatalog::RISK_FINANCIAL_EXPOSURE, $offshoreRisk, $q1, 25_000_000,
            ['currency_code' => 'USD', 'detect_breach' => false]
        );

        /* ---- the group roll-up ---- */

        $reportingCurrency = $this->currency()->reportingCurrency();
        $total = 0;
        $ratesUsed = [];

        foreach (MeasureValue::forMeasureCode(MeasureCatalog::RISK_FINANCIAL_EXPOSURE)->where('period_id', $q1->id)->get() as $value) {
            $converted = $this->currency()->convert(
                (int) $value->value,
                $value->currency_code ?? $reportingCurrency,
                $reportingCurrency,
                // Period-end rate: a quarter's figures are translated at the
                // rate ruling at the close of the quarter, not at today's.
                $rateDate
            );

            $total += $converted['amount_minor'];
            $ratesUsed[$value->currency_code] = $converted['rate'];

            // The rate is recorded against the value it converted, so the
            // report can be reproduced after the rate has moved.
            $value->forceFill([
                'fx_rate_used' => $converted['rate'],
            ])->save();
        }

        // NGN 500,000,000 + (USD 250,000 x 1650.25 = NGN 412,562,500)
        $this->assertSame(50_000_000_000 + 41_256_250_000, $total);
        $this->assertSame(912_562_500.0, CurrencyService::toMajor($total, 'NGN'));

        $this->assertSame(1.0, $ratesUsed['NGN'], 'A native-currency figure converts at parity.');
        $this->assertSame(1650.25, $ratesUsed['USD']);

        $usdValue = MeasureValue::where('currency_code', 'USD')->firstOrFail();
        $this->assertEquals(1650.25, $usdValue->fx_rate_used);

        // Moving the rate afterwards must not change the report already filed.
        $this->recordRate('USD', 'NGN', '2026-04-30', 1900.00);
        $this->assertEquals(1650.25, $usdValue->fresh()->fx_rate_used);
    }

    #[Test]
    public function the_same_measure_may_hold_one_value_per_currency(): void
    {
        $q1 = $this->quarter('2026-03-15');
        $risk = $this->makeRisk();

        $this->measures()->record(MeasureCatalog::RISK_FINANCIAL_EXPOSURE, $risk, $q1, 50_000_000_000, ['currency_code' => 'NGN', 'detect_breach' => false]);
        $this->measures()->record(MeasureCatalog::RISK_FINANCIAL_EXPOSURE, $risk, $q1, 25_000_000, ['currency_code' => 'USD', 'detect_breach' => false]);

        $this->assertSame(2, MeasureValue::forMeasureCode(MeasureCatalog::RISK_FINANCIAL_EXPOSURE)->count());

        // ...and re-recording either one corrects rather than duplicating.
        $this->measures()->record(MeasureCatalog::RISK_FINANCIAL_EXPOSURE, $risk, $q1, 30_000_000, ['currency_code' => 'USD', 'detect_breach' => false]);

        $this->assertSame(2, MeasureValue::forMeasureCode(MeasureCatalog::RISK_FINANCIAL_EXPOSURE)->count());
        $this->assertEquals(30_000_000, MeasureValue::where('currency_code', 'USD')->firstOrFail()->value);
    }

    #[Test]
    public function a_non_monetary_value_is_still_unique_on_its_natural_key(): void
    {
        // currency_code is NULL for a score, and a NULL never equals a NULL in
        // a unique index — currency_key is what actually closes that hole.
        $q1 = $this->quarter('2026-03-15');
        $risk = $this->makeRisk();

        $this->measures()->record(MeasureCatalog::RISK_INHERENT_SCORE, $risk, $q1, 12, ['detect_breach' => false]);
        $this->measures()->record(MeasureCatalog::RISK_INHERENT_SCORE, $risk, $q1, 16, ['detect_breach' => false]);

        $rows = MeasureValue::forMeasureCode(MeasureCatalog::RISK_INHERENT_SCORE)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('XXX', $rows->first()->currency_key);
        $this->assertNull($rows->first()->currency_code);
        $this->assertEquals(16, $rows->first()->value);
    }

    #[Test]
    public function platform_rates_are_visible_to_every_tenant(): void
    {
        $this->recordRate('USD', 'NGN', '2026-03-31', 1650.25);

        $other = Organization::create([
            'name' => 'Second Bank PLC',
            'short_name' => 'SCND',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $rate = TenantContext::actingAs(
            $other->id,
            fn () => $this->currency()->resolveRate('USD', 'NGN', '2026-03-31', null, $other->id)['rate']
        );

        $this->assertSame(1650.25, $rate);
    }

    #[Test]
    public function the_rate_fetcher_records_a_manual_override_and_refuses_to_invent_one(): void
    {
        $this->artisan('fx:fetch-cbn-rates', [
            '--currency' => 'USD',
            '--rate' => '1650.25',
            '--date' => '2026-03-31',
            '--source' => 'CBN daily rates bulletin',
        ])->assertSuccessful();

        $this->assertDatabaseHas('fx_rates', [
            'from_currency' => 'USD',
            'to_currency' => 'NGN',
            'rate_type' => 'cbn_official',
            'source' => 'CBN daily rates bulletin',
        ]);

        // With no feed configured and no override, nothing is written.
        config(['measures.cbn_rate_url' => null]);
        $before = FxRate::withoutGlobalScopes()->count();

        $this->artisan('fx:fetch-cbn-rates', ['--date' => '2026-04-01'])
            ->expectsOutputToContain('No CBN rate feed is configured')
            ->assertSuccessful();

        $this->assertSame($before, FxRate::withoutGlobalScopes()->count());
    }
}
