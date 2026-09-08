<?php

use App\Enums\Bcms\ChannelKey;

return [

    /*
    |--------------------------------------------------------------------------
    | BCMS — Business Continuity Management
    |--------------------------------------------------------------------------
    |
    | DEPLOYMENT-WIDE SETTINGS ONLY. Anything that varies per customer lives on
    | `bcms_settings` and is read through App\Services\Bcms\BcmsSettings — see
    | Gate G0 criterion 4. The values under `defaults` here are what a tenant
    | starts with, not what it is stuck with, and nothing in the module reads
    | them directly at request time.
    |
    | The module as a whole is behind the `bcms` feature flag in
    | config/features.php, which aborts 404 rather than 403.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Four queues, four Horizon supervisors, sized separately because the jobs
    | have genuinely different shapes and one pool would let the slowest starve
    | the rest (ADR 0005). The names are here rather than as string literals in
    | jobs so that a deployment on a shared Redis can prefix them without
    | editing PHP.
    |
    | LIFE SAFETY IS NOT A PRIORITY LEVEL, IT IS A SEPARATE POOL. Standing rule
    | 6: never throttled, never subject to quiet hours, never behind routine
    | reminders. A "high priority" flag on a shared queue is still behind
    | whatever the worker is currently doing.
    |
    */
    'queues' => [
        'life_safety' => env('BCMS_QUEUE_LIFESAFETY', 'bcms-lifesafety'),
        'alerts' => env('BCMS_QUEUE_ALERTS', 'bcms-alerts'),
        'reminders' => env('BCMS_QUEUE_REMINDERS', 'bcms-reminders'),
        'sync' => env('BCMS_QUEUE_SYNC', 'bcms-sync'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification channels
    |--------------------------------------------------------------------------
    |
    | The swap point of ADR 0004. Every entry defaults to the Phase 0 recording
    | mock; Phase 7 replaces them ONE AT A TIME as each provider's paperwork
    | clears — Nigerian sender-ID registration, WhatsApp template approval and
    | USSD short codes run four to ten weeks (Orchestration §9), and they will
    | not all clear together.
    |
    | A null entry means "use the registry's default", which is the mock. The
    | EMNS console shows which channels are still mocked, because a crisis
    | manager who believes an SMS went out when the adapter recorded it and
    | dispatched nothing is the worst failure this module could have.
    |
    */
    'channels' => [
        ChannelKey::Sms->value => env('BCMS_CHANNEL_SMS'),
        ChannelKey::WhatsApp->value => env('BCMS_CHANNEL_WHATSAPP'),
        ChannelKey::Voice->value => env('BCMS_CHANNEL_VOICE'),
        ChannelKey::Email->value => env('BCMS_CHANNEL_EMAIL'),
        ChannelKey::Teams->value => env('BCMS_CHANNEL_TEAMS'),
        ChannelKey::Slack->value => env('BCMS_CHANNEL_SLACK'),
        ChannelKey::Push->value => env('BCMS_CHANNEL_PUSH'),
        ChannelKey::Ussd->value => env('BCMS_CHANNEL_USSD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant defaults
    |--------------------------------------------------------------------------
    |
    | What a new tenant's `bcms_settings` row is created with. Read through
    | BcmsSettings, never through config() at request time: config is
    | process-wide and a queue worker holds it across tenants.
    |
    */
    'defaults' => [
        'timezone' => env('BCMS_DEFAULT_TIMEZONE', 'Africa/Lagos'),

        // Ten days of daily countdown — Blueprint §5.4's headline requirement
        // and the thing no competitor ships.
        'lead_time_days' => 10,

        // Before the branch opens, after people are awake. A 06:00 reminder is
        // one people learn to swipe away, and alert fatigue is Blueprint §18's
        // biggest product risk.
        'reminder_send_time' => '07:30:00',

        // Standing rule 7 — one digest per user per day.
        'reminder_mode' => 'digest',

        // No quiet hours by default. A customer sets them deliberately, and
        // they never apply to critical or life-safety traffic.
        'quiet_hours_start' => null,
        'quiet_hours_end' => null,

        // T-2: late enough that people have had a chance to close a blocking
        // readiness task, early enough that a manager can still fix it.
        'escalation_day_offset' => -2,

        'channel_set' => ['email', 'sms'],

        // The offline-capable set. Blueprint §7.2: the network is the first
        // thing to fail.
        'life_safety_channel_set' => ['sms', 'voice', 'ussd'],

        'currency' => 'NGN',

        // Six months. Blueprint §6.4 — a number nobody has confirmed in
        // eighteen months is a broken branch waiting to happen.
        'contact_verification_days' => 180,
    ],

    /*
    |--------------------------------------------------------------------------
    | ERM integration
    |--------------------------------------------------------------------------
    |
    | The one-way bridge of ADR 0001. A BCMS finding is mirrored into the ERM
    | issue register so that a bank runs one remediation register rather than
    | two; closing either closes the other.
    |
    | BCMS deliberately creates no ERM RISKS. A missed fire drill is not a new
    | risk — the disruption it exercises is already in the register — and
    | "Fire drill overdue at Kano branch" as a risk row would fill the register
    | with programme-management noise. Continuity exposure reaches the register
    | through the KRIs the BC objectives are measured by.
    |
    */
    'integration' => [
        'mirror_findings_to_issues' => env('BCMS_MIRROR_FINDINGS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI capabilities (Blueprint §12)
    |--------------------------------------------------------------------------
    |
    | ADR 0010: BCMS AI runs on the product's own LlmService — a locally hosted
    | Ollama-compatible model — rather than on Laravel Prism, which this product
    | does not have. A BIA is a bank's complete map of what it depends on and
    | how long it survives without each piece; an architecture that can run
    | entirely inside the customer's estate is the one this can actually be sold
    | with.
    |
    | THREE SWITCHES GATE EVERY CALL and all three must be on: `services.llm.
    | enabled` for the deployment, `bcms_settings.ai_enabled` for the tenant, and
    | the per-capability flag below. Blueprint §12 lists eight capabilities, and
    | an institution may well trust a model to draft an impact narrative and not
    | to propose a recovery time objective.
    |
    | EVERY ONE DEFAULTS OFF. A module whose BIA cannot be filled in without a
    | model is a module a bank cannot buy.
    |
    */
    'ai' => [
        'capabilities' => [
            'bia_draft' => env('BCMS_AI_BIA_DRAFT', false),
            'scenario_generator' => env('BCMS_AI_SCENARIO_GENERATOR', false),
            'aar_synthesis' => env('BCMS_AI_AAR_SYNTHESIS', false),
            'alert_composer' => env('BCMS_AI_ALERT_COMPOSER', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Non-functional targets (Blueprint §14)
    |--------------------------------------------------------------------------
    |
    | Recorded as configuration rather than prose so that the Phase 12 load
    | tests assert against the same numbers the blueprint states, and a change
    | to a target is a visible diff rather than a forgotten paragraph.
    |
    */
    'nfr' => [
        'calendar_year_view_ms' => 1500,
        'dispatch_queue_seconds' => 30,
        'first_sms_seconds' => 60,
        'contacts_per_tenant' => 50000,
        'concurrent_checkins' => 200,
        'low_bandwidth_kbps' => 100,
    ],
];
