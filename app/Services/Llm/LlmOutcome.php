<?php

namespace App\Services\Llm;

use App\Enums\Llm\Outcome;

/**
 * What the gateway handed back — phase-11a-ai-contract.md §2.2. FROZEN SHAPE.
 *
 * `$promptTokens`/`$completionTokens`/`$totalTokens` are `null`, never `0`,
 * when the backend did not report them — ADR 0015 §4's rule that a count of
 * zero is a claim and "we were not told" is the truth. `$data` is `[]` on
 * anything but `Succeeded`, so a caller never has to check `$outcome` before
 * indexing into it.
 *
 * `$contextWindow` — ADR 0015 §6d, phase-11a-ai-contract.md §2.2/§2.5. The
 * `num_ctx` ACTUALLY SENT to the model on this call, or null when none was
 * sent. The gateway is the only thing that knows what it sent, so it is the
 * only thing that may report it — a caller must never re-derive `num_ctx`
 * from config, because the gateway clamps and defaults and a caller reading
 * config directly would report a number the box never received. A null
 * value here forbids the affirmative completeness statement downstream,
 * because nothing was declared to compare `prompt_eval_count` against.
 */
final class LlmOutcome
{
    /**
     * @param  array<mixed>  $data
     */
    public function __construct(
        public readonly Outcome $outcome,
        public readonly array $data,
        public readonly ?string $reason,
        public readonly string $model,
        public readonly string $endpointProfile,
        public readonly ?int $promptTokens,
        public readonly ?int $completionTokens,
        public readonly ?int $totalTokens,
        public readonly int $durationMs,
        public readonly int $attempts,
        public readonly ?int $contextWindow,
        public readonly ?int $usageEventId,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === Outcome::Succeeded;
    }
}
