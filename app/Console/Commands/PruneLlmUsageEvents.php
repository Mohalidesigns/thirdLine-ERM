<?php

namespace App\Console\Commands;

use App\Models\LlmUsageEvent;
use Illuminate\Console\Command;

/**
 * Retention for the platform LLM usage ledger — phase-11a-ai-contract.md
 * §7.4.
 *
 * `llm_usage_events` IS TELEMETRY, NOT AUDIT EVIDENCE. `tp_audit_logs` and
 * `bcms_audit_logs` hold the governance events (who turned AI on, and when);
 * this table holds the traffic, and traffic has no regulatory retention
 * requirement in this product. `config('llm.retention_months')` is 24 by
 * default.
 *
 * DELETES IN CHUNKS, across every organisation — this is a platform-owned,
 * unprefixed table read without tenant scoping deliberately, the same as any
 * other cross-tenant retention sweep.
 */
class PruneLlmUsageEvents extends Command
{
    protected $signature = 'llm:prune-usage {--chunk=1000 : Rows deleted per batch}';

    protected $description = 'Delete llm_usage_events rows older than the configured retention window';

    public function handle(): int
    {
        $months = (int) config('llm.retention_months', 24);
        $cutoff = now()->subMonthsNoOverflow($months);
        $chunkSize = max(1, (int) $this->option('chunk'));

        $deleted = 0;

        do {
            $count = LlmUsageEvent::withoutGlobalScopes()
                ->where('created_at', '<', $cutoff)
                ->limit($chunkSize)
                ->delete();

            $deleted += $count;
        } while ($count > 0);

        $this->info("Deleted {$deleted} llm_usage_events row(s) older than {$cutoff->toDateString()} ({$months} months).");

        return self::SUCCESS;
    }
}
