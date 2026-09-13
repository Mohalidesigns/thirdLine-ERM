<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'llm' => [
        'enabled' => env('LLM_ENABLED', false),
        'driver' => env('LLM_DRIVER', 'ollama'),
        'endpoint' => rtrim(env('LLM_ENDPOINT', 'http://localhost:11434'), '/'),
        'model' => env('LLM_MODEL', 'granite4:micro'),
        'timeout' => (int) env('LLM_TIMEOUT_SECONDS', 20),
        'temperature' => (float) env('LLM_TEMPERATURE', 0.2),

        /*
        |----------------------------------------------------------------------
        | Per-call budgets
        |----------------------------------------------------------------------
        |
        | AiToolsController asks the model for seven different things, and each
        | one carried its own `max_tokens` / `timeout` pair as a literal at the
        | call site — 600/60, 1200/90, 900/90 and so on. The phase prompt named
        | two of them; there were seven.
        |
        | They are budgets, not settings a tenant configures, so they are named
        | for the SHAPE of the answer being asked for rather than for the method
        | asking. A tool that starts returning truncated JSON needs its budget
        | raised here, in one place, next to the others it should be compared
        | against.
        |
        */
        'budgets' => [
            // One paragraph, or a short object: a risk statement, a control or
            // treatment description.
            'short' => ['max_tokens' => 600, 'timeout' => 60],
            // A handful of structured suggestions: KRI descriptions.
            'medium' => ['max_tokens' => 700, 'timeout' => 60],
            // A narrative section for a report.
            'narrative' => ['max_tokens' => 900, 'timeout' => 90],
            // A list of recommendations with rationale for each, which is the
            // longest thing any of these tools asks for.
            'long' => ['max_tokens' => 1200, 'timeout' => 90],

            /*
             * TPRM evidence extraction — a WHOLE DOCUMENT, not a paragraph,
             * and the only budget here measured against a local model rather
             * than a hosted one.
             *
             * It was taking the bare `llm.timeout` of 20 seconds, which no
             * extraction has ever completed in: granite4:micro needed 26-69s
             * for a 1,100-word synthetic SOC 2 (2,272 prompt tokens, 931
             * completion) across five runs on 2026-09-09. Twenty seconds was
             * not a tight budget, it was a guaranteed failure, and it
             * presented as `cURL error 28` rather than as a configuration
             * problem.
             *
             * THIS COMMENT USED TO SAY "120 seconds is bounded by the request,
             * not by the model" and that `ExtractionDispatcher` runs inline in
             * the HTTP request with the document text sent UNCAPPED. Neither
             * is true any more: §6a moved extraction onto the queue for
             * exactly the PHP-FPM/proxy-exhaustion reason that paragraph
             * described, and §6b capped the document text per prompt
             * (`config/tprm_prompts.php` → `max_document_chars`, declared to
             * the confirmation screen via `_meta.document_truncated` rather
             * than silently discarded — TPRM Phase 11a Gate 2, defect 1). This
             * value still exists because a queued worker can still exceed it
             * on a large document; it now bounds the WORKER's call, not an
             * HTTP request nothing here still makes inline.
             *
             * `num_ctx` is NOT a budget key (ADR 0015 §6d deviation 9,
             * phase-11a-ai-contract.md §4.4). It lives in `config/llm.php`
             * -> `context.num_ctx` instead: `Risk\AiToolsController` spreads
             * a whole budget array into a direct `LlmService` call, so a key
             * placed here would leak the declared window to ERM's
             * grandfathered callers (ADR 0015 §1), which derive no cap
             * and have no usage row or `fitted` signal to report the loss
             * on. `config/llm.php` is read by `LlmGateway` and by no module
             * caller, which is what makes the declaration reach only the
             * calls it is meant for.
             */
            'extraction' => ['max_tokens' => 2048, 'timeout' => 120],
        ],
    ],

];
