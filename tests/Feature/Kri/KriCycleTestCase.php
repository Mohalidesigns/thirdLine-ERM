<?php

namespace Tests\Feature\Kri;

use App\Enums\KriContactRole;
use App\Enums\KriReturnStatus;
use App\Models\BusinessUnit;
use App\Models\KeyRiskIndicator;
use App\Models\KriDepartmentContact;
use App\Models\KriReturn;
use App\Models\KriReturnLine;
use App\Models\Period;
use App\Models\User;
use App\Services\KriMeasureBridge;
use App\Services\PeriodService;
use App\Support\Kri\KriWorkingCalendar;
use App\Support\Measures\MeasureCatalog;
use App\Support\Measures\UnitRegistry;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Fixtures for the departmental KRI cycle (ADR 0026).
 *
 * THE CLOCK. The suite is frozen at 06:00 UTC on Monday 2 November 2026, which
 * is 07:00 in Lagos and the FIRST WORKING DAY of November (1 Nov is a Sunday).
 * The reporting month is therefore October 2026. App time stays UTC; every
 * decision under test reads the Lagos day through KriWorkingCalendar::today().
 *
 * KRIs are back-dated to 15 Jan 2026 before their bands are synced: the first
 * band set takes effect from the KRI's created_at, and a reading for October
 * recorded against a KRI "created" on 2 Nov would otherwise find no threshold
 * in force and come back unbanded - a test passing for the wrong reason.
 */
abstract class KriCycleTestCase extends KriTestCase
{
    protected BusinessUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt('2026-11-02 06:00:00');

        foreach ([
            'kri.submit_return', 'kri.send_reminder', 'kri.manage_department_contacts',
            'kri.all_departments', 'holiday.manage',
        ] as $permission) {
            Permission::findOrCreate($permission);
        }

        UnitRegistry::install();
        app(PeriodService::class)->ensureCalendar($this->organization->id);
        MeasureCatalog::install($this->organization->id);

        $this->unit = $this->makeUnit('Treasury', 'TRS');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** Freeze "now" at a UTC wall-clock time. */
    protected function freezeAt(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
    }

    protected function makeUnit(string $name, string $code, ?int $organizationId = null, ?int $parentId = null, bool $active = true): BusinessUnit
    {
        $organizationId ??= $this->organization->id;

        return TenantContext::bypass(fn () => BusinessUnit::create([
            'organization_id' => $organizationId,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $name,
            'is_active' => $active,
        ]), 'test fixture');
    }

    /**
     * A KRI reported by a department, with its measure and bands synced.
     * Defaults (KriTestCase): monthly, higher is worse, green below 10, red from 50.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function deptKri(?BusinessUnit $unit = null, array $attributes = []): KeyRiskIndicator
    {
        $unit ??= $this->unit;
        $organizationId = $attributes['organization_id'] ?? $this->organization->id;

        $kri = TenantContext::bypass(function () use ($unit, $attributes, $organizationId) {
            $kri = $this->makeKri(array_merge(['business_unit_id' => $unit->id, 'organization_id' => $organizationId], $attributes));
            $kri->forceFill(['created_at' => '2026-01-15 00:00:00'])->saveQuietly();

            return $kri->fresh();
        }, 'test fixture');

        return TenantContext::actingAs($organizationId, function () use ($kri) {
            app(KriMeasureBridge::class)->syncDefinition($kri);

            return KeyRiskIndicator::query()->findOrFail($kri->id);
        });
    }

    protected function makeContact(BusinessUnit $unit, User $user, KriContactRole $role = KriContactRole::RiskChampion): KriDepartmentContact
    {
        return TenantContext::bypass(fn () => KriDepartmentContact::create([
            'organization_id' => $unit->organization_id,
            'business_unit_id' => $unit->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'assigned_by' => $this->actor->id,
        ]), 'test fixture');
    }

    /** A user (champion by default) of this tenant. */
    protected function makeStaff(string $name, array $permissions = [], bool $active = true, ?string $email = null): User
    {
        $email ??= strtolower(str_replace(' ', '.', $name)).'@example.test';

        $user = $this->userWith($permissions, $email);
        $user->forceFill(['name' => $name, 'is_active' => $active])->save();

        return $user->fresh();
    }

    /** The October 2026 reporting month (the month just ended on the frozen clock). */
    protected function reportingMonth(?int $organizationId = null): Period
    {
        return app(PeriodService::class)->resolve('2026-10-31', 'month', $organizationId ?? $this->organization->id);
    }

    /** Open the cycle through the real command at the frozen first working day. */
    protected function openCycle(array $options = []): void
    {
        $this->artisan('kri:open-cycle', $options)->assertExitCode(0);
    }

    protected function theReturn(?BusinessUnit $unit = null): KriReturn
    {
        return KriReturn::query()
            ->where('business_unit_id', ($unit ?? $this->unit)->id)
            ->where('period_id', $this->reportingMonth()->id)
            ->firstOrFail();
    }

    /** A return opened directly (no command, no mail), with its lines synced. */
    protected function makeReturn(?BusinessUnit $unit = null, array $attributes = [], bool $syncLines = true): KriReturn
    {
        $unit ??= $this->unit;
        $period = $this->reportingMonth($unit->organization_id);

        $return = TenantContext::bypass(fn () => KriReturn::create(array_merge([
            'organization_id' => $unit->organization_id,
            'business_unit_id' => $unit->id,
            'period_id' => $period->id,
            'status' => KriReturnStatus::Draft->value,
            'requested_on' => '2026-11-02',
            'due_on' => '2026-11-09',
            'opened_at' => now(),
            'lock_version' => 0,
        ], $attributes)), 'test fixture');

        if ($syncLines) {
            TenantContext::actingAs($unit->organization_id, fn () => app(\App\Services\Kri\KriCycleService::class)->syncLines($return->fresh()));
        }

        return $return->fresh();
    }

    protected function lineFor(KriReturn $return, KeyRiskIndicator $kri): KriReturnLine
    {
        return KriReturnLine::query()
            ->where('kri_return_id', $return->id)
            ->where('key_risk_indicator_id', $kri->id)
            ->firstOrFail();
    }

    /**
     * The body the champion screen posts.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    protected function body(KriReturn $return, array $lines): array
    {
        return ['lock_version' => (int) $return->fresh()->lock_version, 'lines' => $lines];
    }

    /** @return array{root_cause: string, action_point: string, timeline: string} */
    protected function explanation(string $timeline = '2026-12-15'): array
    {
        return [
            'root_cause' => 'Settlement queue backed up after the core banking upgrade.',
            'action_point' => 'Add a second reconciliation run before cut-off.',
            'timeline' => $timeline,
        ];
    }

    protected function todayInLagos(): string
    {
        return KriWorkingCalendar::today()->toDateString();
    }

    /** A second tenant's user, department and KRI, for cross-tenant assertions. */
    protected function foreignDepartment(string $name = 'Their Treasury'): BusinessUnit
    {
        return $this->makeUnit($name, 'FTR', $this->otherOrg->id);
    }

    protected function foreignUser(string $email = 'foreign.champion@example.test'): User
    {
        return TenantContext::bypass(fn () => User::create([
            'name' => 'Foreign Champion',
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $this->otherOrg->id,
            'is_active' => true,
        ]), 'test fixture');
    }
}
