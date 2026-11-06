<?php

namespace Tests\Feature\Kri;

use App\Models\BusinessUnit;
use App\Models\KeyRiskIndicator;
use App\Models\MeasureThreshold;
use App\Models\Organization;
use App\Models\User;
use App\Services\Kri\KriLibraryImporter;
use App\Services\PeriodService;
use App\Support\Kri\KriBandRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `kri:import-library` and KriLibraryImporter: the bank's departmental KRI
 * library loaded as business units and banded KRIs.
 *
 * THE BANDS ARE CHECKED THROUGH THE ENGINE, not through the six columns: what
 * decides a breach is the measure engine's band set, so a boundary that is
 * right in the file but lands wrong in the engine must fail here.
 */
class KriLibraryImportTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private const LIBRARY = 'data/kri-library/bank-kri-library.json';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
    }

    /** @return array<string, mixed> */
    private function shipped(): array
    {
        return json_decode((string) file_get_contents(database_path(self::LIBRARY)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>>  $kris
     * @param  list<array<string, mixed>>|null  $units
     * @return array<string, mixed>
     */
    private function library(array $kris, ?array $units = null): array
    {
        return [
            'units' => $units ?? [
                ['code' => 'TRS', 'name' => 'Treasury', 'parent' => null],
                ['code' => 'ERM', 'name' => 'Enterprise Risk Management', 'parent' => null],
                ['code' => 'ERM-CR', 'name' => 'Credit Risk', 'parent' => 'ERM'],
            ],
            'kris' => $kris,
        ];
    }

    /** @return array<string, mixed> */
    private function row(string $code, string $unit = 'TRS', array $overrides = []): array
    {
        return array_merge([
            'code' => $code,
            'unit' => $unit,
            'name' => "Indicator {$code}",
            'description' => 'A test indicator.',
            'frequency' => 'monthly',
            'measurement_unit' => 'count',
            'direction' => 'higher_is_worse',
            'green_threshold' => 1,
            'red_threshold' => 1,
            'owner_title' => 'Chief Dealer',
        ], $overrides);
    }

    private function import(array $library, bool $dryRun = false): array
    {
        return app(KriLibraryImporter::class)->import($library, $this->actor->id, $dryRun);
    }

    private function band(string $code, float $value): ?string
    {
        $kri = KeyRiskIndicator::query()->where('kri_code', $code)->firstOrFail();
        $period = app(PeriodService::class)->resolve(now()->toDateString(), 'month', $this->organization->id);

        return app(KriBandRule::class)->bandForReading($kri, $period, $value);
    }

    private function user(string $name, ?string $jobTitle, ?Organization $organization = null): User
    {
        $organization ??= $this->organization;

        return TenantContext::bypass(fn () => User::create([
            'name' => $name,
            'email' => str($name)->slug().'-'.$organization->id.'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $organization->id,
            'job_title' => $jobTitle,
            'is_active' => true,
        ]));
    }

    /* ------------------------------------------------------------------ */
    /*  The shipped library */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_shipped_library_loads_every_department_and_every_kri(): void
    {
        $library = $this->shipped();

        $result = $this->import($library);

        $this->assertCount(count($library['units']), $result['units_created']);
        $this->assertSame(count($library['kris']), $result['kris_created']);
        $this->assertSame(327, KeyRiskIndicator::query()->count());

        // Every KRI sits on its department, and the sub-desks under theirs.
        $this->assertSame(0, KeyRiskIndicator::query()->whereNull('business_unit_id')->count());
        $ops = BusinessUnit::query()->where('code', 'OPS')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['OPS-DOM', 'OPS-TRO', 'OPS-TRD', 'OPS-CRD'],
            BusinessUnit::query()->where('parent_id', $ops->id)->pluck('code')->all(),
        );
        $this->assertSame(32, KeyRiskIndicator::query()->whereRelation('businessUnit', 'code', 'ERM-MR')->count());

        // The engine holds an open band set for every banded KRI and none for
        // the ones the bank left without levels.
        $banded = collect($library['kris'])->filter(fn ($k) => $k['green_threshold'] !== null || $k['red_threshold'] !== null)->count();
        $this->assertSame($banded, MeasureThreshold::withoutGlobalScopes()->whereNull('effective_to')->count());
    }

    /**
     * Each row is one way the sheet words its levels; the expectation is the
     * sheet's own reading of that value.
     *
     * @return array<string, array{string, float, ?string}>
     */
    public static function sheetReadings(): array
    {
        return [
            // 0 | 0 | >0 : any occurrence is a breach.
            'zero tolerance, none' => ['LIB-TRS-01', 0, 'green'],
            'zero tolerance, one' => ['LIB-TRS-01', 1, 'red'],
            // 0 | 1 | >1
            'one tolerated' => ['LIB-CCM-01', 1, 'amber'],
            'two intolerable' => ['LIB-CCM-01', 2, 'red'],
            // <=2% | >2%-5% | >5%
            'pct on the acceptable edge' => ['LIB-DIG-02', 2, 'green'],
            'pct inside tolerable' => ['LIB-DIG-02', 3.5, 'amber'],
            'pct on the tolerable edge' => ['LIB-DIG-02', 5, 'amber'],
            'pct past the tolerable edge' => ['LIB-DIG-02', 5.5, 'red'],
            // 100% and above | 99%-90% | <90%  (lower is worse)
            'budget met' => ['LIB-FIN-01', 100, 'green'],
            'budget at 90' => ['LIB-FIN-01', 90, 'amber'],
            'budget under 90' => ['LIB-FIN-01', 89.5, 'red'],
            // >=16% | 14-15.99% | <14%  (CAR)
            'CAR on the floor' => ['LIB-ERM-MR-30', 16, 'green'],
            'CAR at 14' => ['LIB-ERM-MR-30', 14, 'amber'],
            'CAR under 14' => ['LIB-ERM-MR-30', 13.9, 'red'],
            // 3 | 5 | >5
            'three overaged items' => ['LIB-ICT-01', 3, 'green'],
            'five overaged items' => ['LIB-ICT-01', 5, 'amber'],
            'six overaged items' => ['LIB-ICT-01', 6, 'red'],
            // 72hrs | 73-168hrs | >168hrs (the sheet's ">68hrs" typo read as >168)
            'MTTD 72h' => ['LIB-ISC-03', 72, 'green'],
            'MTTD 100h' => ['LIB-ISC-03', 100, 'amber'],
            'MTTD 169h' => ['LIB-ISC-03', 169, 'red'],
            // <350m | 350m-500m | >500m
            'judgement debt 500m' => ['LIB-LEG-03', 500, 'amber'],
            'judgement debt 501m' => ['LIB-LEG-03', 501, 'red'],
            // 1 day | 1-2 days | >2 days, days read as continuous
            'security pay delayed 1 day' => ['LIB-ADM-04', 1, 'green'],
            'security pay delayed 2 days' => ['LIB-ADM-04', 2, 'amber'],
            'security pay delayed 2.5 days' => ['LIB-ADM-04', 2.5, 'red'],
            // lower is worse, strict: >85% | <80-85% | <80%
            'lower strict, on green edge' => ['LIB-ISC-21', 85, 'amber'],
            'lower strict, above' => ['LIB-ISC-21', 85.5, 'green'],
            'lower strict, inside' => ['LIB-ISC-21', 82, 'amber'],
            'lower strict, under' => ['LIB-ISC-21', 79.5, 'red'],
            // lower is worse, sheet writes the 95% as a fraction: 0.95 | 85-95% | <85%
            'lower fraction, 95' => ['LIB-ISC-23', 95, 'green'],
            'lower fraction, 90' => ['LIB-ISC-23', 90, 'amber'],
            'lower fraction, 84' => ['LIB-ISC-23', 84, 'red'],
            // lower is worse, two edges meeting: >5% | - | -
            'single edge, above' => ['LIB-ERM-MR-10', 5.5, 'green'],
            'single edge, on it' => ['LIB-ERM-MR-10', 5, 'red'],
            // seconds: 1-30secs | 31secs-2mins59 | >3mins
            'seconds green' => ['LIB-CCT-07', 30, 'green'],
            'seconds amber' => ['LIB-CCT-07', 179, 'amber'],
            'seconds red' => ['LIB-CCT-07', 181, 'red'],
            // ratio: 1-2x | 3-5x | >5x
            'ratio 2x' => ['LIB-ERM-CR-16', 2, 'green'],
            'ratio 5x' => ['LIB-ERM-CR-16', 5, 'amber'],
            'ratio 5.5x' => ['LIB-ERM-CR-16', 5.5, 'red'],
            // money, zero tolerance: 0 | 0 | >0
            'money none' => ['LIB-FIN-11', 0, 'green'],
            'money a kobo' => ['LIB-FIN-11', 0.01, 'red'],
            // hours: <2hrs | 2hrs 30 | >2hrs 30
            'hours 2.5 amber' => ['LIB-ITD-07', 2.5, 'amber'],
            'hours 2.6 red' => ['LIB-ITD-07', 2.6, 'red'],
            // four-decimal percentage: 0 | 0.01-0.05% | >0.05%
            'fine pct 0.05 amber' => ['LIB-ISC-02', 0.05, 'amber'],
            'fine pct 0.0501 red' => ['LIB-ISC-02', 0.0501, 'red'],
            // 0 | 0 | >1 : one is neither acceptable nor intolerable
            'zero, zero, over one: one' => ['LIB-CCM-08', 1, 'amber'],
            'zero, zero, over one: two' => ['LIB-CCM-08', 2, 'red'],
            // sheet gap between tolerable and intolerable stays amber
            'CCT gap 5.5' => ['LIB-CCT-01', 5.5, 'amber'],
            // no levels in the source: no band at all, never an invented green
            'unbanded compliance KRI' => ['LIB-CMP-18', 3, null],
        ];
    }

    #[Test]
    #[DataProvider('sheetReadings')]
    public function the_engine_bands_a_reading_the_way_the_sheet_does(string $code, float $value, ?string $band): void
    {
        $library = $this->shipped();
        $only = collect($library['kris'])->firstWhere('code', $code);
        $unitCodes = [$only['unit']];
        $parent = collect($library['units'])->firstWhere('code', $only['unit'])['parent'];

        if ($parent !== null) {
            $unitCodes[] = $parent;
        }

        $this->import($this->library(
            [$only],
            collect($library['units'])->whereIn('code', $unitCodes)->values()->all(),
        ));

        $this->assertSame($band, $this->band($code, $value));
    }

    #[Test]
    public function the_semi_annual_kris_are_read_against_a_half_year(): void
    {
        $this->import($this->library([$this->row('LIB-TRS-01', overrides: ['frequency' => 'semi_annually'])]));

        $kri = KeyRiskIndicator::query()->where('kri_code', 'LIB-TRS-01')->firstOrFail();

        $this->assertSame('half', app(\App\Services\KriMeasureBridge::class)->periodTypeFor($kri));
        $this->assertContains('semi_annually', KeyRiskIndicator::FREQUENCIES, 'the KRI form accepts the frequency on edit');
    }

    /* ------------------------------------------------------------------ */
    /*  Re-runs and existing data */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_re_run_creates_nothing_and_keeps_a_tuned_threshold(): void
    {
        $library = $this->library([$this->row('LIB-TRS-01'), $this->row('LIB-TRS-02')]);
        $this->import($library);

        $tuned = KeyRiskIndicator::query()->where('kri_code', 'LIB-TRS-01')->firstOrFail();
        $tuned->update(['red_threshold_min' => 7, 'kri_name' => 'Renamed by the bank']);

        $result = $this->import($library);

        $this->assertSame(0, $result['kris_created']);
        $this->assertSame(2, $result['kris_existing']);
        $this->assertSame([], $result['units_created']);
        $this->assertSame(2, KeyRiskIndicator::query()->count());
        $this->assertSame(3, BusinessUnit::query()->count());
        $this->assertEquals(7, $tuned->fresh()->red_threshold_min);
        $this->assertSame('Renamed by the bank', $tuned->fresh()->kri_name);
    }

    #[Test]
    public function an_existing_department_is_matched_by_name_and_not_duplicated(): void
    {
        $existing = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'TREAS',
            'name' => '  treasury ',
            'is_active' => true,
        ]);

        $result = $this->import($this->library([$this->row('LIB-TRS-01')]));

        $this->assertSame(1, BusinessUnit::query()->where('name', 'like', '%reasury%')->count());
        $this->assertSame($existing->id, KeyRiskIndicator::query()->firstOrFail()->business_unit_id);
        $this->assertContains('TRS => '.$existing->name, $result['units_matched']);
    }

    #[Test]
    public function a_mapped_department_lands_on_the_banks_own_unit(): void
    {
        $legal = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-LG',
            'name' => 'Legal',
            'is_active' => true,
        ]);

        $this->artisan('kri:import-library', [
            '--organization' => $this->organization->id,
            '--map' => ['LEG=BU-LG', ' FIN = BU-LG '],
        ])->assertSuccessful();
        TenantContext::set($this->organization->id);

        $this->assertSame(0, BusinessUnit::query()->whereIn('code', ['LEG', 'FIN'])->count(), 'no duplicate department');
        $this->assertSame(13 + 13, KeyRiskIndicator::query()->where('business_unit_id', $legal->id)->count());
    }

    /** @return array<string, array{array<string, string>}> */
    public static function badMaps(): array
    {
        return [
            'target unit does not exist' => [['TRS' => 'BU-NOPE']],
            'library code does not exist' => [['NOPE' => 'TRS']],
        ];
    }

    #[Test]
    #[DataProvider('badMaps')]
    public function a_bad_map_entry_is_refused_and_writes_nothing(array $map): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            app(KriLibraryImporter::class)->import($this->library([$this->row('LIB-TRS-01'), $this->row('LIB-ERM-CR-01', 'ERM-CR')]), null, false, $map);
        } finally {
            $this->assertSame(0, KeyRiskIndicator::query()->count());
            $this->assertSame(0, BusinessUnit::query()->count());
        }
    }

    #[Test]
    public function a_malformed_map_option_fails_the_command(): void
    {
        $this->artisan('kri:import-library', ['--organization' => $this->organization->id, '--map' => ['LEG']])
            ->expectsOutputToContain('--map takes LIBCODE=UNITCODE')
            ->assertFailed();
    }

    #[Test]
    public function a_deleted_department_is_reported_and_its_kris_are_not_loaded(): void
    {
        BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'TRS',
            'name' => 'Old Treasury',
            'is_active' => true,
        ])->delete();

        $result = $this->import($this->library([$this->row('LIB-TRS-01'), $this->row('LIB-ERM-CR-01', 'ERM-CR')]));

        $this->assertCount(1, $result['units_refused']);
        $this->assertSame(['LIB-TRS-01'], $result['kris_skipped']);
        $this->assertSame(['LIB-ERM-CR-01'], KeyRiskIndicator::query()->pluck('kri_code')->all());
        $this->assertSame(1, BusinessUnit::onlyTrashed()->count(), 'the deleted unit stays deleted');
    }

    #[Test]
    public function a_dry_run_reports_the_import_and_writes_nothing(): void
    {
        $measures = \App\Models\Measure::withoutGlobalScopes()->count();

        $result = $this->import($this->library([$this->row('LIB-TRS-01'), $this->row('LIB-TRS-02')]), dryRun: true);

        $this->assertSame(2, $result['kris_created']);
        $this->assertCount(3, $result['units_created']);
        $this->assertSame(0, KeyRiskIndicator::query()->count());
        $this->assertSame(0, BusinessUnit::query()->count());
        $this->assertSame(0, MeasureThreshold::withoutGlobalScopes()->count());
        $this->assertSame($measures, \App\Models\Measure::withoutGlobalScopes()->count(), 'no measure definition written either');
    }

    /* ------------------------------------------------------------------ */
    /*  Owners */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_owner_is_the_one_user_holding_the_job_title(): void
    {
        $dealer = $this->user('Ade Dealer', 'chief  dealer');

        $result = $this->import($this->library([
            $this->row('LIB-TRS-01'),
            $this->row('LIB-TRS-02', overrides: ['owner_title' => 'Head , Legal']),
        ]));

        $this->assertSame($dealer->id, KeyRiskIndicator::query()->where('kri_code', 'LIB-TRS-01')->value('kri_owner_id'));
        $this->assertNull(KeyRiskIndicator::query()->where('kri_code', 'LIB-TRS-02')->value('kri_owner_id'));
        $this->assertSame(['Head , Legal' => 1], $result['owners_unresolved']);
    }

    #[Test]
    public function two_users_with_the_title_leave_the_kri_unowned_until_a_re_run(): void
    {
        $first = $this->user('First CFO', 'CFO');
        $this->user('Second CFO', 'CFO');
        $library = $this->library([$this->row('LIB-TRS-01', overrides: ['owner_title' => 'CFO'])]);

        $this->import($library);
        $this->assertNull(KeyRiskIndicator::query()->value('kri_owner_id'));

        // A user of ANOTHER tenant with the title does not count.
        TenantContext::bypass(fn () => User::query()->where('name', 'Second CFO')->update(['job_title' => 'Former CFO']));
        $this->user('Foreign CFO', 'CFO', TenantContext::bypass(fn () => Organization::create([
            'name' => 'Other Bank PLC', 'short_name' => 'OTHB', 'institution_type' => 'commercial_bank',
            'sector' => 'banking', 'is_active' => true,
        ])));

        $result = $this->import($library);

        $this->assertSame(1, $result['owners_assigned']);
        $this->assertSame($first->id, KeyRiskIndicator::query()->value('kri_owner_id'));
    }

    /* ------------------------------------------------------------------ */
    /*  The command and the file's guards */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_command_loads_into_the_named_organisation_only(): void
    {
        $other = TenantContext::bypass(fn () => Organization::create([
            'name' => 'Other Bank PLC', 'short_name' => 'OTHB', 'institution_type' => 'commercial_bank',
            'sector' => 'banking', 'is_active' => true,
        ]));
        TenantContext::clear();

        $this->artisan('kri:import-library')->assertFailed();

        $this->artisan('kri:import-library', ['--organization' => $other->id, '--dry-run' => true])
            ->expectsOutputToContain('[dry run - nothing written]')
            ->assertSuccessful();
        $this->assertSame(0, TenantContext::bypass(fn () => KeyRiskIndicator::query()->count()));

        $this->artisan('kri:import-library', ['--organization' => $other->id])
            ->expectsOutputToContain('KRIs: 327 created')
            ->assertSuccessful();

        TenantContext::bypass(function () use ($other) {
            $this->assertSame(327, KeyRiskIndicator::query()->where('organization_id', $other->id)->count());
            $this->assertSame(0, KeyRiskIndicator::query()->where('organization_id', $this->organization->id)->count());
            $this->assertSame(0, BusinessUnit::query()->where('organization_id', $this->organization->id)->count());
        });
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badRows(): array
    {
        return [
            'unknown frequency' => [['frequency' => 'fortnightly']],
            'unknown direction' => [['direction' => 'sideways']],
            'bounds reversed' => [['green_threshold' => 5, 'red_threshold' => 2]],
            'unknown unit' => [['unit' => 'NOPE']],
            'code too long for kri_code' => [['code' => 'LIB-A-VERY-LONG-CODE-01']],
        ];
    }

    #[Test]
    #[DataProvider('badRows')]
    public function a_malformed_library_is_refused_before_anything_is_written(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->import($this->library([$this->row('LIB-TRS-01'), $this->row('LIB-TRS-02', overrides: $override)]));
        } finally {
            $this->assertSame(0, KeyRiskIndicator::query()->count());
            $this->assertSame(0, BusinessUnit::query()->count());
        }
    }

    #[Test]
    public function every_shipped_row_traces_back_to_its_sheet_and_row(): void
    {
        foreach ($this->shipped()['kris'] as $row) {
            $this->assertNotEmpty($row['source']['sheet'], $row['code']);
            $this->assertIsInt($row['source']['row'], $row['code']);
            $this->assertMatchesRegularExpression('/^LIB-[A-Z-]+-\d{2}$/', $row['code']);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 fixes */
    /* ------------------------------------------------------------------ */

    /**
     * Every frequency the KRI form offers must be known to the two places that
     * turn a frequency into "days until the next reading is due"; an unknown
     * one falls to 30 days and a semi-annual KRI went overdue for five months
     * of every half-year.
     */
    #[Test]
    public function every_form_frequency_has_its_own_due_interval(): void
    {
        $expected = ['daily' => 1, 'weekly' => 7, 'monthly' => 30, 'quarterly' => 91, 'semi_annually' => 182];
        $this->assertEqualsCanonicalizing(array_keys($expected), KeyRiskIndicator::FREQUENCIES, 'a new frequency needs a row here');

        $matrix = new \ReflectionMethod(\App\Services\Bcms\Compliance\ClauseComplianceMatrixService::class, 'kriFrequencyDays');
        $responsibilities = new \ReflectionMethod(\App\Services\MyResponsibilitiesService::class, 'kriDueDate');
        $read = now()->subDays(60)->startOfDay();

        foreach ($expected as $frequency => $days) {
            $this->assertSame($days, $matrix->invoke(app(\App\Services\Bcms\Compliance\ClauseComplianceMatrixService::class), $frequency), $frequency);

            $kri = new KeyRiskIndicator(['measurement_frequency' => $frequency]);
            $kri->last_measurement_at = $read;
            $this->assertSame(
                $read->copy()->addDays($days)->toDateString(),
                $responsibilities->invoke(app(\App\Services\MyResponsibilitiesService::class), $kri)->toDateString(),
                $frequency,
            );
        }
    }

    #[Test]
    public function the_importer_refuses_to_run_without_a_tenant(): void
    {
        TenantContext::clear();

        try {
            app(KriLibraryImporter::class)->import($this->library([$this->row('LIB-TRS-01')]));
            $this->fail('an import with no tenant must not run');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('--map', $e->getMessage());
        }

        $this->assertSame(0, TenantContext::bypass(fn () => KeyRiskIndicator::query()->count()));
        $this->assertSame(0, TenantContext::bypass(fn () => BusinessUnit::query()->count()));
    }

    #[Test]
    public function a_deleted_library_kri_is_reported_not_recreated(): void
    {
        $library = $this->library([$this->row('LIB-TRS-01'), $this->row('LIB-TRS-02')]);
        $this->import($library);
        KeyRiskIndicator::query()->where('kri_code', 'LIB-TRS-01')->firstOrFail()->delete();

        $result = $this->import($library);

        $this->assertSame(['LIB-TRS-01'], $result['kris_deleted']);
        $this->assertSame(1, $result['kris_existing']);
        $this->assertSame(0, $result['kris_created']);
        $this->assertSame(1, KeyRiskIndicator::onlyTrashed()->count(), 'still deleted');
    }

    #[Test]
    public function an_inactive_user_with_the_title_is_not_counted(): void
    {
        $active = $this->user('Active Dealer', 'Chief Dealer');
        $this->user('Departed Dealer', 'Chief Dealer')->update(['is_active' => false]);

        $this->import($this->library([$this->row('LIB-TRS-01')]));

        $this->assertSame($active->id, KeyRiskIndicator::query()->value('kri_owner_id'));
    }

    #[Test]
    public function a_hand_set_owner_is_not_reported_as_missing_on_a_re_run(): void
    {
        $library = $this->library([$this->row('LIB-TRS-01'), $this->row('LIB-TRS-02')]);
        $first = $this->import($library);
        $this->assertSame(['Chief Dealer' => 2], $first['owners_unresolved']);

        KeyRiskIndicator::query()->where('kri_code', 'LIB-TRS-01')->update(['kri_owner_id' => $this->actor->id]);

        $this->assertSame(['Chief Dealer' => 1], $this->import($library)['owners_unresolved']);
    }
}
