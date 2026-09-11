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
             * 120 SECONDS IS BOUNDED BY THE REQUEST, NOT BY THE MODEL.
             * `ExtractionDispatcher` runs inline in the HTTP request from
             * `DocumentController`, and the document text is sent UNCAPPED —
             * so a real 90-page SOC 2 will exhaust PHP-FPM or the proxy long
             * before any timeout set here is reached. Raising this number
             * further would not fix that; moving extraction onto a queue
             * would, and that is the actual fix. This value makes the small
             * and medium documents work and leaves the large-document
             * problem visible instead of disguised as a timeout.
             */
            'extraction' => ['max_tokens' => 2048, 'timeout' => 120],
        ],
    ],

];
