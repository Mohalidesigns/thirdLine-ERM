<?php

namespace App\Services;

use App\Models\FxRate;
use App\Models\Organization;
use App\Support\Periods\DateBounds;
use Carbon\CarbonImmutable;
use RuntimeException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Converts money between currencies at a rate that is recorded, dated and
 * attributable.
 *
 * WP-04 TASK 6. Two rules drive the whole design:
 *
 *   1. Money is stored in MINOR UNITS (kobo, cents) as an integer. A naira
 *      figure held as a float is a rounding error waiting for a reconciliation.
 *
 *   2. A converted figure is meaningless without the rate that produced it.
 *      In Nigeria the CBN official window, NAFEM and the parallel market can
 *      differ enough that the same USD loss event is above the CBN reporting
 *      threshold at one rate and below it at another. Every conversion returns
 *      the rate, its type and its date, and every caller persists them
 *      alongside the amount.
 */
class CurrencyService
{
    /** Where nothing else says otherwise. */
    public const DEFAULT_REPORTING_CURRENCY = 'NGN';

    public const DEFAULT_RATE_TYPE = 'cbn_official';

    /**
     * Currencies whose minor unit is not 1/100 of the major.
     *
     * Everything the platform ships with is 1/100; the map exists so that
     * adding a zero-decimal currency later is a data change rather than a hunt
     * through the call sites.
     *
     * @var array<string, int>
     */
    private const MINOR_UNIT_SCALE = [
        'JPY' => 1,
        'KRW' => 1,
        'XOF' => 1,
        'XAF' => 1,
    ];

    /**
     * Convert an amount held in minor units.
     *
     * @param  int  $amountMinor  e.g. kobo for NGN, cents for USD
     * @return array{amount_minor:int, rate:float, rate_type:string, rate_date:string, from:string, to:string}
     */
    public function convert(
        int $amountMinor,
        string $from,
        string $to,
        string|CarbonImmutable|null $date = null,
        ?string $rateType = null,
        ?int $organizationId = null
    ): array {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $date = $this->normaliseDate($date);
        $rateType = $rateType ?? $this->defaultRateType($organizationId);

        if ($from === $to) {
            return [
                'amount_minor' => $amountMinor,
                'rate' => 1.0,
                'rate_type' => $rateType,
                'rate_date' => $date,
                'from' => $from,
                'to' => $to,
            ];
        }

        $resolved = $this->resolveRate($from, $to, $date, $rateType, $organizationId);

        // Minor -> major -> convert -> minor, because the two currencies need
        // not share a minor-unit scale.
        $major = $amountMinor / self::minorScale($from);
        $convertedMajor = $major * $resolved['rate'];

        return [
            'amount_minor' => (int) round($convertedMajor * self::minorScale($to)),
            'rate' => $resolved['rate'],
            'rate_type' => $resolved['rate_type'],
            'rate_date' => $resolved['rate_date'],
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * The rate to multiply a $from amount by to get a $to amount.
     *
     * @return array{rate:float, rate_type:string, rate_date:string}
     */
    public function resolveRate(
        string $from,
        string $to,
        string|CarbonImmutable|null $date = null,
        ?string $rateType = null,
        ?int $organizationId = null
    ): array {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $date = $this->normaliseDate($date);
        $rateType = $rateType ?? $this->defaultRateType($organizationId);

        if ($from === $to) {
            return ['rate' => 1.0, 'rate_type' => $rateType, 'rate_date' => $date];
        }

        // Direct.
        if ($row = $this->lookup($from, $to, $date, $rateType, $organizationId)) {
            return ['rate' => (float) $row->rate, 'rate_type' => $row->rate_type, 'rate_date' => $row->rate_date->toDateString()];
        }

        // Inverse. Publishing USD/NGN and expecting NGN/USD to work is the
        // normal case, not an edge one — CBN publishes one direction.
        if ($row = $this->lookup($to, $from, $date, $rateType, $organizationId)) {
            if ((float) $row->rate == 0.0) {
                throw new RuntimeException("Recorded {$to}/{$from} rate on {$row->rate_date->toDateString()} is zero and cannot be inverted.");
            }

            return [
                'rate' => 1 / (float) $row->rate,
                'rate_type' => $row->rate_type,
                'rate_date' => $row->rate_date->toDateString(),
            ];
        }

        // Triangulate through the reporting currency. USD -> GBP with only
        // USD/NGN and GBP/NGN recorded is the common shape for a Nigerian
        // group holding foreign-currency exposures.
        $pivot = $this->reportingCurrency($organizationId);

        if ($from !== $pivot && $to !== $pivot) {
            $legOne = $this->tryResolve($from, $pivot, $date, $rateType, $organizationId);
            $legTwo = $this->tryResolve($pivot, $to, $date, $rateType, $organizationId);

            if ($legOne !== null && $legTwo !== null) {
                return [
                    'rate' => $legOne['rate'] * $legTwo['rate'],
                    'rate_type' => $rateType,
                    // The older of the two legs: a rate is only as current as
                    // its stalest input.
                    'rate_date' => min($legOne['rate_date'], $legTwo['rate_date']),
                ];
            }
        }

        throw new RuntimeException(
            "No {$rateType} rate is recorded for {$from}/{$to} on or before {$date}. "
            .'Record one via the FX rate register or run fx:fetch-cbn-rates.'
        );
    }

    /** @return array{rate:float, rate_type:string, rate_date:string}|null */
    private function tryResolve(string $from, string $to, string $date, string $rateType, ?int $organizationId): ?array
    {
        try {
            return $this->resolveRate($from, $to, $date, $rateType, $organizationId);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * The most recent rate on or before $date, preferring the tenant's own
     * recorded rate over the platform-wide one.
     */
    private function lookup(string $from, string $to, string $date, string $rateType, ?int $organizationId): ?FxRate
    {
        $organizationId = $organizationId ?? TenantContext::organizationIdOrNull();

        return FxRate::withoutGlobalScopes()
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('rate_type', $rateType)
            // The whole of the day, not the instant midnight: a DATE column
            // written through Eloquent's date cast carries a time component,
            // so a bare date string would exclude the day itself. See
            // App\Support\Periods\DateBounds.
            ->where('rate_date', '<=', DateBounds::endOfDay($date))
            ->where(function ($query) use ($organizationId) {
                $query->whereNull('organization_id');

                if ($organizationId !== null) {
                    $query->orWhere('organization_id', $organizationId);
                }
            })
            // A tenant's own rate wins over the platform rate of the same date.
            ->orderByDesc('rate_date')
            ->orderByRaw('CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('id')
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /*  Organisation settings */
    /* ------------------------------------------------------------------ */

    /**
     * The currency this organisation reports in. Group roll-ups convert into
     * it and record the rate used.
     */
    public function reportingCurrency(?int $organizationId = null): string
    {
        return strtoupper((string) (
            $this->setting($organizationId, 'reporting_currency') ?? self::DEFAULT_REPORTING_CURRENCY
        ));
    }

    /**
     * The rate type conversions default to. CBN official, unless the
     * organisation has said otherwise — a fintech marking to NAFEM is a
     * legitimate and common choice.
     */
    public function defaultRateType(?int $organizationId = null): string
    {
        $configured = $this->setting($organizationId, 'default_fx_rate_type');

        return in_array($configured, ['cbn_official', 'nafem', 'parallel', 'internal', 'custom'], true)
            ? $configured
            : self::DEFAULT_RATE_TYPE;
    }

    private function setting(?int $organizationId, string $key): mixed
    {
        $organizationId = $organizationId ?? TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            return null;
        }

        $settings = Organization::query()->whereKey($organizationId)->value('settings');

        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        return is_array($settings) ? ($settings[$key] ?? null) : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Minor units */
    /* ------------------------------------------------------------------ */

    public static function minorScale(string $currency): int
    {
        return self::MINOR_UNIT_SCALE[strtoupper($currency)] ?? 100;
    }

    /** Major units (naira) to minor units (kobo). */
    public static function toMinor(float|string $major, string $currency = 'NGN'): int
    {
        return (int) round((float) $major * self::minorScale($currency));
    }

    /** Minor units (kobo) to major units (naira). */
    public static function toMajor(int $minor, string $currency = 'NGN'): float
    {
        return $minor / self::minorScale($currency);
    }

    private function normaliseDate(string|CarbonImmutable|null $date): string
    {
        if ($date === null) {
            return CarbonImmutable::now()->toDateString();
        }

        return $date instanceof CarbonImmutable ? $date->toDateString() : CarbonImmutable::parse($date)->toDateString();
    }
}
