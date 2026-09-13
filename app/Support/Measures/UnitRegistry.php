<?php

namespace App\Support\Measures;

use App\Models\Unit;

/**
 * The units the platform ships with.
 *
 * Shared across tenants — a percentage does not vary by organisation — and
 * installed idempotently, so adding a unit here and re-running the seeder
 * updates an existing deployment without a new migration.
 *
 * The currency entries are units, not exchange rates. NGN and USD both have a
 * conversion_factor of 1 against themselves; converting between them is
 * fx_rates' job, because that answer depends on a date and a rate type.
 */
class UnitRegistry
{
    /** Money is stored in minor units. This is the naira's. */
    public const MINOR_NGN = 'kobo';

    /**
     * @return list<array{code:string,name:string,symbol:?string,category:string,base:?string,factor:float}>
     */
    public static function all(): array
    {
        return [
            // Currency. Base units first: a minor unit references its major.
            ['code' => 'NGN', 'name' => 'Nigerian Naira', 'symbol' => '₦', 'category' => 'currency', 'base' => null, 'factor' => 1],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'category' => 'currency', 'base' => null, 'factor' => 1],
            ['code' => 'GBP', 'name' => 'Pound Sterling', 'symbol' => '£', 'category' => 'currency', 'base' => null, 'factor' => 1],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'category' => 'currency', 'base' => null, 'factor' => 1],
            ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R', 'category' => 'currency', 'base' => null, 'factor' => 1],
            ['code' => 'GHS', 'name' => 'Ghanaian Cedi', 'symbol' => '₵', 'category' => 'currency', 'base' => null, 'factor' => 1],
            ['code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'category' => 'currency', 'base' => null, 'factor' => 1],
            ['code' => 'kobo', 'name' => 'Kobo', 'symbol' => 'k', 'category' => 'currency', 'base' => 'NGN', 'factor' => 0.01],
            ['code' => 'cent', 'name' => 'Cent', 'symbol' => 'c', 'category' => 'currency', 'base' => 'USD', 'factor' => 0.01],

            // Counts.
            ['code' => 'count', 'name' => 'Count', 'symbol' => null, 'category' => 'count', 'base' => null, 'factor' => 1],
            ['code' => 'events', 'name' => 'Events', 'symbol' => null, 'category' => 'count', 'base' => 'count', 'factor' => 1],
            ['code' => 'incidents', 'name' => 'Incidents', 'symbol' => null, 'category' => 'count', 'base' => 'count', 'factor' => 1],

            // Proportions.
            ['code' => 'pct', 'name' => 'Percent', 'symbol' => '%', 'category' => 'pct', 'base' => null, 'factor' => 1],
            ['code' => 'bps', 'name' => 'Basis points', 'symbol' => 'bps', 'category' => 'pct', 'base' => 'pct', 'factor' => 0.01],
            ['code' => 'ratio', 'name' => 'Ratio', 'symbol' => null, 'category' => 'ratio', 'base' => null, 'factor' => 1],

            // Time. Hours is the base so that a sub-day SLA is expressible.
            ['code' => 'hours', 'name' => 'Hours', 'symbol' => 'h', 'category' => 'time', 'base' => null, 'factor' => 1],
            ['code' => 'days', 'name' => 'Days', 'symbol' => 'd', 'category' => 'time', 'base' => 'hours', 'factor' => 24],
            ['code' => 'weeks', 'name' => 'Weeks', 'symbol' => 'w', 'category' => 'time', 'base' => 'hours', 'factor' => 168],

            // The unit a 1-5 likelihood, impact or risk score is expressed in.
            ['code' => 'score', 'name' => 'Score', 'symbol' => null, 'category' => 'custom', 'base' => null, 'factor' => 1],
            ['code' => 'index', 'name' => 'Index', 'symbol' => null, 'category' => 'custom', 'base' => null, 'factor' => 1],
        ];
    }

    /**
     * Create or update every registered unit. Safe to re-run.
     */
    public static function install(): void
    {
        foreach (self::all() as $spec) {
            Unit::updateOrCreate(
                ['code' => $spec['code']],
                [
                    'name' => $spec['name'],
                    'symbol' => $spec['symbol'],
                    'category' => $spec['category'],
                    // Resolved after the base rows exist — the list is ordered
                    // so a base always precedes anything referencing it.
                    'base_unit_id' => $spec['base'] === null
                        ? null
                        : Unit::where('code', $spec['base'])->value('id'),
                    'conversion_factor' => $spec['factor'],
                ]
            );
        }
    }

    public static function idFor(string $code): ?int
    {
        return Unit::where('code', $code)->value('id');
    }
}
