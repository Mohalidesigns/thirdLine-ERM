<?php

namespace App\Services\Llm;

use App\Enums\Llm\Outcome;
use App\Models\LlmUsageEvent;
use Illuminate\Support\Facades\Log;

/**
 * Writes one `llm_usage_events` row per gateway call — ADR 0015 §5:
 * "refusals are recorded." A usage report built only on successes cannot
 * tell a quiet month from a broken endpoint.
 *
 * `organization_id` IS NEVER NULL HERE. `LlmCall::$organizationId` is a
 * required `int`, so by the time a row reaches this class a tenant has
 * already been established; there is no branch in this class that writes
 * without one.
 */
class UsageRecorder
{
    /**
     * @param  array{
     *     model?: string, endpointProfile?: string, attempts?: int,
     *     promptTokens?: ?int, completionTokens?: ?int, durationMs?: int,
     *     unitCostMinor?: ?int, currency?: ?string,
     * }  $result
     */
    public function record(LlmCall $call, Outcome $outcome, array $result = []): LlmUsageEvent
    {
        $promptTokens = $result['promptTokens'] ?? null;
        $completionTokens = $result['completionTokens'] ?? null;

        return LlmUsageEvent::create([
            'organization_id' => $call->organizationId,
            'module' => $call->module,
            'service' => $call->service,
            'prompt_key' => $call->promptKey,
            'prompt_version' => $call->promptVersion,
            'endpoint_profile' => $result['endpointProfile'] ?? ($call->endpointProfile ?? (string) config('llm.default_profile')),
            'model' => $result['model'] ?? '',
            'outcome' => $outcome,
            'attempts' => $result['attempts'] ?? 1,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            // Null when either half is null — never a sum with a 0 standing
            // in for "not reported" (ADR 0015 §4).
            'total_tokens' => ($promptTokens === null || $completionTokens === null)
                ? null
                : $promptTokens + $completionTokens,
            'duration_ms' => $result['durationMs'] ?? 0,
            'unit_cost_minor' => $result['unitCostMinor'] ?? null,
            'currency' => $result['currency'] ?? null,
            'usage_month' => $this->currentMonth(),
            'subject_type' => $call->subjectType,
            'subject_id' => $call->subjectId,
            'user_id' => $call->userId,
            'created_at' => now(),
        ]);
    }

    /**
     * 'YYYY-MM', computed in PHP so the aggregate never needs
     * `DATE_FORMAT(created_at, ...)` in a WHERE clause on MariaDB 10.4 —
     * ADR 0015 §4.
     *
     * Uses the application's configured timezone. The platform has no
     * tenant-level timezone concept outside BCMS's own `bcms_settings`,
     * which this module-blind namespace may not read; `config('app.timezone')`
     * is therefore the one clock every module already shares.
     */
    private function currentMonth(): string
    {
        return now()->format('Y-m');
    }

    /**
     * Auditing-style failure isolation, matching `TprmAuditable`'s own rule:
     * a usage row that could not be written is logged, never allowed to fail
     * the caller's actual work. Used by callers that want the "never throws"
     * guarantee to extend all the way through recording.
     */
    public function recordSafely(LlmCall $call, Outcome $outcome, array $result = []): ?LlmUsageEvent
    {
        try {
            return $this->record($call, $outcome, $result);
        } catch (\Throwable $exception) {
            Log::error('LLM usage event write failed', [
                'organization_id' => $call->organizationId,
                'module' => $call->module,
                'service' => $call->service,
                'outcome' => $outcome->value,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
