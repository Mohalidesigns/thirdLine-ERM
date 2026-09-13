<?php

/*
|--------------------------------------------------------------------------
| The platform LLM gateway — phase-11a-ai-contract.md §4.1. FROZEN.
|--------------------------------------------------------------------------
|
| NOT PUBLISHABLE. Same reasoning as `config/tprm.php`: `endpoint_profile` is
| stamped onto every `llm_usage_events` row, and a published copy could carry
| a profile the code has never seen.
|
| A profile carries NO `timeout` and NO `max_tokens`. Those live in
| `config/services.php` -> `llm.budgets`, keyed by the shape of the answer
| being asked for, and that is their only home — ADR 0015 §6: two homes for
| one number is drift with extra steps.
|
*/

return [

    // The breaker is useless on a per-process store. The settings screen
    // reports whether the resolved store is shared.
    'cache_store' => env('LLM_CACHE_STORE', null),   // null = default store

    'retry' => [
        'max_attempts' => 2,
        'backoff_ms' => [1000, 3000],   // jittered
        'retry_on' => ['connection', 'timeout', 'http_5xx'],
    ],

    'breaker' => [
        'failure_threshold' => 3,    // consecutive
        'open_seconds' => 60,
        'half_open_probes' => 1,
    ],

    // ADR 0015 §6d. Declared, not assumed: the value the 2026-09-09 probe ran
    // under, stated here so `max_document_chars` can be derived from a number
    // this repository sets rather than from Ollama's default.
    //
    // NOT a budget key. A budget array is spread wholesale into LlmService by
    // Risk\AiToolsController, so anything put there reaches the grandfathered
    // ERM callers, which derive nothing from a window and only lose the
    // deployment's own tuning. Governance belongs in the gateway's config.
    'context' => [
        'num_ctx' => 4096,
    ],

    'default_profile' => env('LLM_PROFILE', 'local-ollama'),

    'profiles' => [
        'local-ollama' => [
            'label' => 'Local model (this deployment)',
            'endpoint' => env('LLM_ENDPOINT', 'http://localhost:11434'),
            'model' => env('LLM_MODEL', 'granite4:micro'),
            'keep_alive' => '30m',
            // Self-hosted. There is no price, so there is no figure.
            // NULL, not 0 — see ADR 0015 §4.
            'unit_cost_per_1k_tokens_minor' => null,
            'currency' => null,
        ],
    ],

    'retention_months' => 24,

];
