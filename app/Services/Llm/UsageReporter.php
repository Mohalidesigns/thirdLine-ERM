<?php

namespace App\Services\Llm;

use App\Enums\Llm\Outcome;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The read side of the usage ledger — phase-11a-ai-contract.md §7.3.
 *
 * EVERY QUERY HERE IS A PLAIN `GROUP BY` ON THE COMPOSITE INDEX. No CTE, no
 * window function, no raw JSON function, no `DATE_FORMAT` in a `WHERE` —
 * contract §8.12, MariaDB 10.4.
 *
 * `DB::table()`, NOT THE ELOQUENT MODEL. An aggregate `selectRaw()` against
 * `App\Models\LlmUsageEvent::query()` hydrates rows as that model, and
 * larastan then reports every aliased aggregate column (`calls`, `tokens`,
 * `succeeded`, …) as an undefined property — the column exists on the query,
 * not on the model's schema. The query builder returns plain `stdClass` rows
 * instead, which is both what every other aggregate report in this product
 * already does (`KriService::class` is the precedent) and avoids the global
 * tenant scope for a query that already filters `organization_id` explicitly
 * on every call.
 */
class UsageReporter
{
    /**
     * @return Collection<int, array{
     *     service: string, calls: int, succeeded: int, refused: int,
     *     tokens: int|null, calls_missing_tokens: int, avg_duration_ms: float,
     * }>
     */
    public function byService(int $organizationId, string $usageMonth): Collection
    {
        return DB::table('llm_usage_events')
            ->where('organization_id', $organizationId)
            ->where('usage_month', $usageMonth)
            ->selectRaw(
                'service, COUNT(*) as calls, SUM(outcome = ?) as succeeded, '.
                'SUM(total_tokens) as tokens, COUNT(total_tokens) as calls_with_tokens, '.
                'AVG(duration_ms) as avg_duration_ms',
                [Outcome::Succeeded->value]
            )
            ->groupBy('service')
            ->get()
            ->map(fn ($row) => $this->mapServiceRow($row));
    }

    /**
     * @return array{service: string, calls: int, succeeded: int, refused: int, tokens: int|null, calls_missing_tokens: int, avg_duration_ms: float}
     */
    private function mapServiceRow(object $row): array
    {
        return [
            'service' => (string) $row->service,
            'calls' => (int) $row->calls,
            'succeeded' => (int) $row->succeeded,
            'refused' => (int) $row->calls - (int) $row->succeeded,
            'tokens' => $row->tokens === null ? null : (int) $row->tokens,
            'calls_missing_tokens' => (int) $row->calls - (int) $row->calls_with_tokens,
            'avg_duration_ms' => $row->avg_duration_ms === null ? 0.0 : round((float) $row->avg_duration_ms, 0),
        ];
    }

    /**
     * `share` lives HERE, not in the controller (ruled 2026-09-11, frontend
     * deviation 2) — so an export built on this same method can never print
     * a different percentage from the screen. **Null when the month's total
     * call count is 0, never `0`** — ADR 0015 §4: a share of nothing is
     * undefined, not zero, and a `0%` bar would be a claim about a month
     * this method has no calls to divide.
     *
     * @return Collection<int, array{outcome: string, label: string, count: int, share: float|null}>
     */
    public function byOutcome(int $organizationId, string $usageMonth): Collection
    {
        $rows = DB::table('llm_usage_events')
            ->where('organization_id', $organizationId)
            ->where('usage_month', $usageMonth)
            ->selectRaw('outcome, COUNT(*) as count')
            ->groupBy('outcome')
            ->get();

        $totalCalls = (int) $rows->sum('count');

        return $rows->map(fn ($row) => $this->mapOutcomeRow($row, $totalCalls));
    }

    /**
     * @return array{outcome: string, label: string, count: int, share: float|null}
     */
    private function mapOutcomeRow(object $row, int $totalCalls): array
    {
        $outcome = Outcome::tryFrom((string) $row->outcome);
        $count = (int) $row->count;

        return [
            'outcome' => (string) $row->outcome,
            'label' => $outcome?->label() ?? (string) $row->outcome,
            'count' => $count,
            'share' => $totalCalls > 0 ? round($count / $totalCalls, 4) : null,
        ];
    }

    /**
     * @return array{usage_month: string, total_tokens: int|null, call_count: int, calls_missing_tokens: int}
     */
    public function monthSummary(int $organizationId, string $usageMonth): array
    {
        $row = DB::table('llm_usage_events')
            ->where('organization_id', $organizationId)
            ->where('usage_month', $usageMonth)
            ->selectRaw('COUNT(*) as call_count, SUM(total_tokens) as total_tokens, COUNT(total_tokens) as calls_with_tokens')
            ->first();

        $callCount = (int) ($row->call_count ?? 0);
        $callsWithTokens = (int) ($row->calls_with_tokens ?? 0);

        return [
            'usage_month' => $usageMonth,
            // Null when NOTHING in the month reported tokens — never a 0
            // standing in for "not reported" (ADR 0015 §4).
            'total_tokens' => $callsWithTokens === 0 ? null : (int) ($row->total_tokens ?? 0),
            'call_count' => $callCount,
            'calls_missing_tokens' => $callCount - $callsWithTokens,
        ];
    }

    /**
     * The months still inside the retention window, most recent first —
     * for the report's month selector. Not gated by whether a month actually
     * has rows: an empty retained month is still a legitimate selection (it
     * is the "quiet month" the report exists to distinguish from a broken
     * one).
     *
     * @return list<string>
     */
    public function retainedMonths(): array
    {
        $months = (int) config('llm.retention_months', 24);
        $out = [];

        for ($i = 0; $i < $months; $i++) {
            $out[] = now()->subMonthsNoOverflow($i)->format('Y-m');
        }

        return $out;
    }

    public function currentMonth(): string
    {
        return now()->format('Y-m');
    }

    /**
     * How much of a monthly cap is left — the one place this arithmetic
     * lives, so the settings screen and the usage report read the same
     * answer rather than each subtracting on its own.
     *
     * Null when the cap itself is unset (uncapped, nothing to count down
     * from) or when the figure being subtracted was never reported — a
     * remaining count built on an unreported total would be a fabricated
     * number wearing a subtraction sign.
     *
     * @return array{tokens: int|null, calls: int|null}
     */
    public function remaining(?int $tokenCap, ?int $callCap, ?int $totalTokens, int $callCount): array
    {
        return [
            'tokens' => ($tokenCap === null || $totalTokens === null) ? null : max(0, $tokenCap - $totalTokens),
            'calls' => $callCap === null ? null : max(0, $callCap - $callCount),
        ];
    }

    /**
     * The judgement a cap tile renders — `critical`/`warn`/`null` (neutral)
     * — computed once, server-side, so a browser-side copy of the 90 %
     * threshold cannot drift from this one (ruled 2026-09-11, frontend
     * deviation 2).
     *
     * NULL, NEVER A FOURTH STRING, for "no tone": a tile with no cap set
     * carries no tone at all (uncapped is not a warning state), and neither
     * does a tile whose figure was never reported — an unmeasurable thing
     * cannot be flagged as high or low.
     */
    public function tone(?int $cap, ?int $used): ?string
    {
        if ($cap === null || $used === null) {
            return null;
        }

        if ($cap - $used <= 0) {
            return 'critical';
        }

        if ($cap > 0 && ($used / $cap) >= 0.9) {
            return 'warn';
        }

        return null;
    }
}
