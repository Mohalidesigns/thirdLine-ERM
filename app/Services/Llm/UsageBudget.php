<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\DB;

/**
 * The monthly cap check — ADR 0015 §4.
 *
 * `SUM(total_tokens)` / `COUNT(*)` filtered by `organization_id` and the
 * stored `usage_month` column: an index-usable equality on the leftmost two
 * columns of `(organization_id, usage_month, service)`. No `DATE_FORMAT` in
 * the WHERE, no CTE, no window function — MariaDB 10.4, per contract §3.1.
 *
 * `DB::table()`, not the Eloquent model — see `UsageReporter`'s docblock for
 * why an aggregate `selectRaw()` against the model reports its aliases as
 * undefined properties.
 */
class UsageBudget
{
    public function check(int $organizationId, UsageCaps $caps): BudgetVerdict
    {
        $month = now()->format('Y-m');

        $row = DB::table('llm_usage_events')
            ->where('organization_id', $organizationId)
            ->where('usage_month', $month)
            ->selectRaw('COUNT(*) as call_count, SUM(total_tokens) as token_sum')
            ->first();

        $callsUsed = (int) ($row->call_count ?? 0);
        $tokensUsed = (int) ($row->token_sum ?? 0);

        if ($caps->isUncapped()) {
            return BudgetVerdict::ok($tokensUsed, $callsUsed);
        }

        if ($caps->callCap !== null && $callsUsed >= $caps->callCap) {
            return BudgetVerdict::exceeded(
                "The monthly call limit of {$caps->callCap} for {$month} has been reached.",
                $tokensUsed,
                $callsUsed,
            );
        }

        if ($caps->tokenCap !== null && $tokensUsed >= $caps->tokenCap) {
            return BudgetVerdict::exceeded(
                "The monthly token limit of {$caps->tokenCap} for {$month} has been reached.",
                $tokensUsed,
                $callsUsed,
            );
        }

        return BudgetVerdict::ok($tokensUsed, $callsUsed);
    }
}
