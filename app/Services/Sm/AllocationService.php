<?php

namespace App\Services\Sm;

use App\Models\Sm\BusinessLineAllocation;
use App\Models\Sm\Scenario;
use App\Services\AuditTrailService;
use Illuminate\Support\Collection;

/**
 * Business-line allocation: the rows, and the sum-to-100 invariant (ADR 0027
 * §A1.5).
 *
 * SUM-TO-100 IS A SERVICE INVARIANT, NOT A DB CHECK. A grep for `CHECK (` /
 * `->check(` across database/migrations returns NOTHING — there is no CHECK
 * precedent on either engine, and one here would fail a save with SQLSTATE[23000]
 * where the Form Request should have answered 422. So the comparison happens here,
 * and the answer is a 422 on key `allocations`.
 *
 * ENFORCED AT THE `in_design → designed` TRANSITION, not on every draft save. A
 * draft legitimately sums to 60% mid-entry, and refusing to save it pushes users
 * into one big form (spec 01 §6.1's per-tab save exists for the same reason).
 *
 * THE COMPARISON IS EXACT DECIMAL, NEVER FLOAT `==`. §A1.5 requires it "as decimals
 * at the column's precision (string/bccomp, never float ==)". Spec 03 §5.2 states
 * the same rule from the other side: `round(array_sum($percents), 2) === 100.00`,
 * so three lines of 33.33 are a GENUINE validation failure the user has to fix,
 * not a float artefact silently accepted — and a screen that says "Adds to 100%"
 * while the server returns "allocation must add to 100%" destroys trust in both.
 *
 * Implemented as INTEGER CENTS at the column's scale rather than through bcmath:
 * bcmath is installed in CI and locally but is NOT declared in composer.json's
 * `require` and nothing else in app/ uses it, so leaning on it here would add an
 * undeclared extension dependency to the production VPS for arithmetic that
 * integers do exactly. Summing 2dp values as integers is the same comparison at
 * the same precision, with no float anywhere in it.
 */
class AllocationService
{
    /**
     * Replace a scenario's allocation lines with the posted set.
     *
     * Lines are keyed by business unit, so a repeat is a data error rather than a
     * second row: `sm_allocations_scenario_unit_unique` would refuse it with a
     * 23000, and the Form Request answers 422 first with spec 03 §7's message.
     *
     * @param  array<int, array{business_unit_id: int, percent: string|float|int}>  $lines
     */
    public function sync(Scenario $scenario, array $lines, ?int $actorId = null): void
    {
        $before = $this->totalFor($scenario);

        $existing = $scenario->allocations()->get()->keyBy('business_unit_id');
        $wanted = [];

        foreach ($lines as $index => $line) {
            $unitId = (int) $line['business_unit_id'];
            $percent = self::normalisePercent($line['percent'] ?? null);

            $wanted[$unitId] = true;

            if ($existing->has($unitId)) {
                // fill() + save(): Eloquent issues an UPDATE only when the
                // percent actually changed, so an untouched line keeps its
                // `created_at` and "when was this allocation agreed" stays true.
                $existing->get($unitId)->fill(['percent' => $percent])->save();

                continue;
            }

            BusinessLineAllocation::create([
                'organization_id' => $scenario->organization_id,
                'sm_scenario_id' => $scenario->id,
                'business_unit_id' => $unitId,
                'percent' => $percent,
            ]);
        }

        // Deleting what is no longer wanted, rather than truncating and
        // re-inserting: an untouched line keeps its `created_at`, so the audit
        // trail and any later "when was this allocation agreed" question still
        // read true.
        $removed = $existing->keys()->diff(array_keys($wanted));

        if ($removed->isNotEmpty()) {
            BusinessLineAllocation::query()
                ->where('sm_scenario_id', $scenario->id)
                ->whereIn('business_unit_id', $removed->all())
                ->delete();
        }

        $scenario->unsetRelation('allocations');

        AuditTrailService::record(
            $scenario,
            'allocation_changed',
            'allocations',
            $before,
            $this->totalFor($scenario)
        );
    }

    /**
     * The saved lines' total as a DECIMAL STRING at the column's precision, or
     * NULL when there are none.
     *
     * NULL, never "0.00" (spec 03 §2, §5.4): "0% allocated" is a claim that the
     * scenario has been allocated and nothing landed anywhere — a different and
     * false statement, and exactly the class of figure the audit behind standard
     * §5 found rendered as a green tile. "Not allocated" is the true one.
     */
    public function totalFor(Scenario $scenario): ?string
    {
        return self::total($scenario->allocations);
    }

    /**
     * @param  Collection<int, BusinessLineAllocation>|array<int, BusinessLineAllocation>  $lines
     */
    public static function total(Collection|array $lines): ?string
    {
        $lines = $lines instanceof Collection ? $lines : collect($lines);

        if ($lines->isEmpty()) {
            return null;
        }

        $cents = 0;

        foreach ($lines as $line) {
            $cents += self::centsOf((string) $line->percent);
        }

        return self::formatCents($cents);
    }

    /** The posted lines' total, before anything is saved. Used by the gate. */
    public static function totalOfPercents(array $percents): ?string
    {
        if ($percents === []) {
            return null;
        }

        $cents = 0;

        foreach ($percents as $percent) {
            $cents += self::centsOf(self::normalisePercent($percent));
        }

        return self::formatCents($cents);
    }

    /**
     * Does $total add to exactly 100.00?
     *
     * Null is NOT 100 — an unallocated scenario has no total, and treating the
     * absence as a pass would let a scenario with no allocation lines through the
     * designed gate. Spec 03 §7 keeps the two cases apart with two messages.
     */
    public static function isExactly100(?string $total): bool
    {
        return $total !== null
            && self::centsOf($total) === self::centsOf(BusinessLineAllocation::REQUIRED_TOTAL);
    }

    /**
     * A percent at the column's precision, as a 2dp string.
     *
     * Rounds half away from zero to match what MySQL and MariaDB store into a
     * `decimal(5,2)` column, so the total the invariant checks is the total the
     * database holds.
     */
    public static function normalisePercent(string|float|int|null $percent): string
    {
        if ($percent === null || $percent === '') {
            return '0.00';
        }

        $value = is_string($percent) ? trim($percent) : $percent;

        if (! is_numeric($value)) {
            return '0.00';
        }

        $cents = (int) round(((float) $value) * 100);

        return self::formatCents($cents);
    }

    /** Integer hundredths of a 2dp decimal string, without touching floats. */
    public static function centsOf(string $decimal): int
    {
        $negative = str_starts_with($decimal, '-');
        $body = ltrim($decimal, '-+');

        [$whole, $fraction] = array_pad(explode('.', $body, 2), 2, '');

        // Pad or truncate to exactly PERCENT_SCALE digits: '40' → '4000',
        // '40.5' → '4050', '40.555' → '4055' (the stored column cannot hold more).
        $fraction = substr(str_pad($fraction, BusinessLineAllocation::PERCENT_SCALE, '0'), 0, BusinessLineAllocation::PERCENT_SCALE);

        $cents = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    /** Integer hundredths back to a 2dp decimal string. */
    public static function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf(
            '%s%d.%02d',
            $sign,
            intdiv($cents, 100),
            $cents % 100
        );
    }
}
