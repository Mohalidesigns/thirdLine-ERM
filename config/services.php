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
        ],
    ],

];
