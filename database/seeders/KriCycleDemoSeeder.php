<?php

namespace Database\Seeders;

use App\Enums\KriContactRole;
use App\Models\BusinessUnit;
use App\Models\KeyRiskIndicator;
use App\Models\KriDepartmentContact;
use App\Models\Organization;
use App\Models\OrganizationHoliday;
use App\Models\User;
use Illuminate\Database\Seeder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Demo data for the departmental monthly KRI cycle (ADR 0026) on the Kano
 * Heritage Bank demo tenant: department KRI contacts and an organisation
 * holiday list. Idempotent — every row is firstOrCreate'd on its unique key.
 *
 * It does NOT open a cycle: `kri:open-cycle --force` does that, so the demo
 * exercises the same path production does.
 *
 * No real person's address is seeded. The live email test (brief §5) is
 * configured by hand on a staging tenant, never here.
 */
class KriCycleDemoSeeder extends Seeder
{
    /**
     * Fixed-date Nigerian public holidays plus Easter for 2026–2027. Islamic
     * holidays move with the moon sighting and are declared late by the
     * Federal Government, so a Risk Officer adds them on the Holidays screen.
     *
     * @var array<string, string>
     */
    private const HOLIDAYS = [
        '2026-01-01' => "New Year's Day",
        '2026-04-03' => 'Good Friday',
        '2026-04-06' => 'Easter Monday',
        '2026-05-01' => "Workers' Day",
        '2026-06-12' => 'Democracy Day',
        '2026-10-01' => 'Independence Day',
        '2026-12-25' => 'Christmas Day',
        '2026-12-26' => 'Boxing Day',
        '2027-01-01' => "New Year's Day",
        '2027-03-26' => 'Good Friday',
        '2027-03-29' => 'Easter Monday',
        '2027-05-01' => "Workers' Day",
        '2027-06-12' => 'Democracy Day',
        '2027-10-01' => 'Independence Day',
        '2027-12-25' => 'Christmas Day',
        '2027-12-26' => 'Boxing Day',
    ];

    public function run(): void
    {
        $org = Organization::where('cbn_institution_code', 'NGN/COM/0001')->first();
        if ($org === null) {
            return;
        }

        TenantContext::actingAs($org->id, function () use ($org) {
            foreach (self::HOLIDAYS as $date => $name) {
                OrganizationHoliday::firstOrCreate(
                    ['organization_id' => $org->id, 'holiday_date' => $date],
                    ['name' => $name],
                );
            }

            $this->assignDepartments($org->id);
            $this->seedContacts($org->id);
        });
    }

    /**
     * Demo KRIs are seeded after migration 100001's backfill ran, so they
     * carry no department. Apply the backfill's second rule (the KRI's risk's
     * unit) here; a KRI whose risk has no unit stays Unassigned.
     */
    private function assignDepartments(int $orgId): void
    {
        KeyRiskIndicator::query()
            ->where('organization_id', $orgId)
            ->whereNull('business_unit_id')
            ->whereNotNull('risk_id')
            ->with('risk:id,business_unit_id')
            ->get()
            ->each(function (KeyRiskIndicator $kri) {
                $unitId = $kri->risk?->business_unit_id;
                if ($unitId !== null) {
                    $kri->forceFill(['business_unit_id' => $unitId])->saveQuietly();
                }
            });
    }

    /**
     * For every department that holds at least one active KRI: the first
     * active member of the unit becomes its risk champion and the unit head
     * its unit head. Departments without a member are left unconfigured, so
     * the dashboard's "no champion" gap is visible in the demo too.
     */
    private function seedContacts(int $orgId): void
    {
        $unitIds = KeyRiskIndicator::query()
            ->where('organization_id', $orgId)
            ->whereNotNull('business_unit_id')
            ->distinct()
            ->pluck('business_unit_id');

        $units = BusinessUnit::query()
            ->where('organization_id', $orgId)
            ->whereIn('id', $unitIds)
            ->get();

        foreach ($units as $unit) {
            $champion = User::query()
                ->where('organization_id', $orgId)
                ->where('business_unit_id', $unit->id)
                ->where('is_active', true)
                ->whereNotNull('email')
                ->orderBy('id')
                ->first();

            if ($champion !== null) {
                $this->contact($orgId, $unit->id, $champion->id, KriContactRole::RiskChampion);
            }

            if ($unit->head_id !== null) {
                $this->contact($orgId, $unit->id, (int) $unit->head_id, KriContactRole::UnitHead);
            }
        }
    }

    private function contact(int $orgId, int $unitId, int $userId, KriContactRole $role): void
    {
        KriDepartmentContact::firstOrCreate([
            'organization_id' => $orgId,
            'business_unit_id' => $unitId,
            'user_id' => $userId,
            'role' => $role->value,
        ]);
    }
}
