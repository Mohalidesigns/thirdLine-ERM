<?php

use App\Models\Organization;
use App\Services\PeriodService;
use App\Support\Measures\UnitRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-04 — install the unit registry and give every existing organisation a
 * default calendar with the current fiscal year +/- 3 generated.
 *
 * This is a migration rather than a seeder because the two migrations that
 * follow it (the KRI move and the risk-score backfill) need periods to resolve
 * measurement dates against. A backfill that silently produces nothing because
 * an optional seeder was skipped is worse than one that fails loudly.
 *
 * Organisations created after this point do not depend on it:
 * PeriodService::ensureCalendar() provisions lazily on first read, which is
 * what a tenant created by SSO just-in-time provisioning gets.
 */
return new class extends Migration
{
    public function up(): void
    {
        UnitRegistry::install();

        // Provisioning walks every tenant, so tenancy is explicitly bypassed
        // and then re-bound per organisation rather than left ambient.
        TenantContext::bypass(function () {
            $periods = app(PeriodService::class);

            Organization::query()->orderBy('id')->each(function (Organization $organization) use ($periods) {
                TenantContext::actingAs($organization->id, fn () => $periods->ensureCalendar($organization->id));
            });
        }, 'WP-04 period calendar provisioning');
    }

    public function down(): void
    {
        // Periods and calendars are dropped with their tables by 120001's
        // rollback. Units are shared reference data with no tenant rows of
        // their own; removing the seeded rows here keeps a rollback of this
        // migration alone from leaving a half-installed registry.
        DB::table('units')->whereIn('code', array_column(UnitRegistry::all(), 'code'))
            ->update(['base_unit_id' => null]);
        DB::table('units')->whereIn('code', array_column(UnitRegistry::all(), 'code'))->delete();
    }
};
