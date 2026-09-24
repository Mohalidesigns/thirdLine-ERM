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
    | Dual approval (Phase 7)
    |--------------------------------------------------------------------------
    |
    | The two thresholds above which a live alert needs a second authoriser.
    | EITHER trips it: a critical alert to nine people and an advisory to nine
    | thousand both deserve a second pair of eyes, for different reasons, and a
    | rule that only looked at severity would wave through the accidental
    | all-staff "ACTIVE FIRE" this control exists to prevent.
    |
    | DEPLOYMENT-WIDE, NOT PER TENANT, and that is deliberate. "How loud is too
    | loud to send unchecked" is a property of the product's duty of care, not a
    | number a customer should be able to raise to nine thousand on a Friday
    | afternoon. What a tenant controls is the switch itself —
    | `bcms_settings.require_dual_approval_for_live`. Making the thresholds
    | per-tenant would take two columns and an ADR.
    |
    | A SIMULATION NEVER NEEDS APPROVAL: it reaches nobody outside the sandbox,
    | and requiring a signature to run a training exercise is how operators
    | learn to route around the control.
    |
    */
    'dual_approval' => [
        'severity' => env('BCMS_DUAL_APPROVAL_SEVERITY', 'critical'),
        'recipients' => env('BCMS_DUAL_APPROVAL_RECIPIENTS', 500),
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
    | Identity sync (Phase 2C, ADR 0018) — the Microsoft host allowlist
    |--------------------------------------------------------------------------
    |
    | NOT TENANT-EDITABLE, DELIBERATELY. `token_base_url`/`graph_base_url` are
    | connector fields a `bcms.identity.manage` holder types into a form — a
    | role that may WRITE a client secret but never READ one back (ADR 0018
    | §5). Without this list, that holder could point `token_base_url` at
    | their own host and recover the plaintext secret the moment "Test
    | connection" POSTs it, or point `graph_base_url` anywhere to make the
    | server fetch on their behalf. `App\Support\Http\OutboundUrlGuard`'s
    | general SSRF check (scheme, no credentials-in-URL, no private/internal
    | address) does not stop this: an attacker's own server is a perfectly
    | public https host. This list is the closed answer underneath it — the
    | six FQDNs Microsoft's identity platform and Graph actually run on,
    | across the commercial, US Government and China clouds ADR 0018 §3.1
    | names as in scope. A request to any other host, including a redirect —
    | `EntraGraphClient` disables Guzzle's own redirect-following entirely
    | (`withoutRedirecting()` on both the token POST and every Graph GET), so
    | this list is never bypassed by a 3xx it never had a chance to see — an
    | `@odata.nextLink` page or a stored `delta_link` that has drifted off
    | this list, fails the run with a bounded error code rather than being
    | followed.
    |
    */
    'identity' => [
        'allowed_hosts' => [
            'login.microsoftonline.com',
            'login.microsoftonline.us',
            'graph.microsoft.com',
            'graph.microsoft.us',
            'login.chinacloudapi.cn',
            'microsoftgraph.chinacloudapi.cn',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Incident & crisis management (Phase 10, ADR 0020)
    |--------------------------------------------------------------------------
    |
    | THE REGULATORY WINDOWS ARE LAW, NOT A TENANT SETTING (ADR 0020 §2 point
    | 3) — same reasoning as the dual-approval thresholds above: a customer
    | must not be able to set their own reporting deadline to something
    | roomier. 24 hours (CBN cyber incident reporting) and 72 hours (NDPA
    | s.40 personal-data breach) run from AWARENESS, never from declaration
    | or from when a report is drafted.
    |
    | DR CADENCE is inherited from `bcms_processes.regulatory_flags`
    | (open_banking), resolved in PHP over a scoped fetch per the clause
    | map's own MariaDB warning — never a raw JSON function. Expressed here
    | in days because `next_test_due` is a date, not a timestamp.
    |
    */
    'incident' => [
        'notification_windows' => [
            'cbn_hours' => env('BCMS_INCIDENT_CBN_WINDOW_HOURS', 24),
            'ndpa_hours' => env('BCMS_INCIDENT_NDPA_WINDOW_HOURS', 72),
        ],

        // How far past `due_at` an obligation must be before the watchdog's
        // "approaching" warning fires ahead of the deadline — a chance to
        // act before the countdown tile turns rose, not just after.
        'notification_approaching_hours' => env('BCMS_INCIDENT_NOTIFICATION_APPROACHING_HOURS', 6),
    ],

    'dr' => [
        // CBN Open Banking cadence (Phase 0/9 corroborated): quarterly
        // failover, six-monthly DR test, 30-minute failover/failback
        // threshold. Days, because `next_test_due` is a date column.
        'open_banking_failover_cadence_days' => env('BCMS_DR_OPEN_BANKING_FAILOVER_DAYS', 91),
        'open_banking_test_cadence_days' => env('BCMS_DR_OPEN_BANKING_TEST_DAYS', 182),
        'open_banking_threshold_minutes' => env('BCMS_DR_OPEN_BANKING_THRESHOLD_MINUTES', 30),

        // A system with no open-banking exposure still gets a default review
        // cadence, or a DR register with nothing typed in `next_test_due`
        // never surfaces as overdue at all.
        'default_test_cadence_days' => env('BCMS_DR_DEFAULT_TEST_CADENCE_DAYS', 365),

        // Same shape as `defaults.contact_verification_days` above — a
        // backup attested six months ago is not a backup you can currently
        // trust.
        'backup_currency_days' => env('BCMS_DR_BACKUP_CURRENCY_DAYS', 30),
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
            'plan_draft' => env('BCMS_AI_PLAN_DRAFT', false),
            'programme_advisor' => env('BCMS_AI_PROGRAMME_ADVISOR', false),
            // Phase 10, Blueprint §12(8): compares a real incident's timeline
            // against the plan and prior exercises. Draft only, human
            // reviewed, never sets severity/is_reportable/status and never
            // raises a finding itself (clause map §6.10).
            'post_incident_learning' => env('BCMS_AI_POST_INCIDENT_LEARNING', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline bundles
    |--------------------------------------------------------------------------
    |
    | Where the PWA's offline plan bundles are written. A separate setting from
    | the default disk because a bundle carries mobile numbers and assembly
    | points — Blueprint §1.2's whole point is that it leaves the platform — and
    | a bank that keeps its evidence on S3 may well want these on local disk
    | inside its own perimeter, or the reverse.
    |
    */
    'offline_disk' => env('BCMS_OFFLINE_DISK', env('FILESYSTEM_DISK', 'local')),

    /*
    |--------------------------------------------------------------------------
    | Unauthenticated route rate limits
    |--------------------------------------------------------------------------
    |
    | `bcms/check-in` and `bcms/check-in/{token}` (Phase 9, Gate 2 defect 4)
    | carry no session and no permission to check — the credential is the
    | per-participant HMAC token/short code itself (`CheckInController`'s own
    | docblock). Registered as TWO limiters in `AppServiceProvider::
    | registerBcmsCheckInRateLimiters()` (Gate 2 review #2), not one, because
    | the two routes are different traffic shapes:
    |
    | `check_in_rate_limit_per_minute` guards the short-code FORM
    | (`bcms/check-in`, no token in the URL), keyed on ip alone — an 8-hex
    | code typed by hand is a genuine guessing surface, and this is a small
    | number of human fingers, not a provider's server, so there is no
    | per-provider key to add.
    |
    | `check_in_token_rate_limit_per_minute` and `check_in_ip_rate_limit_
    | per_minute` together guard the per-participant token routes
    | (`bcms/check-in/{token}`): the FIRST is keyed per token (a tight
    | ceiling, because a token is a credential and hammering one specific
    | token is a guessing/abuse pattern), the SECOND is keyed per ip (a
    | generous ceiling, sized for an evacuation drill's worth of people
    | behind one office NAT scanning their own, distinct tokens within the
    | same minute — the scenario a single shared ip-keyed bucket used to
    | lock out at request 61, defeating the exact feature it protects).
    | Both apply together; either can trip first.
    |
    | The ip ceiling was 600 through Gate 1 of this pass and is 1200 as of
    | Gate 1 review #3: the concurrent-checkins NFR (`nfr.concurrent_
    | checkins` below) is 200, and each participant's own check-in is up to
    | THREE requests against this route — GET the link, POST "I'm here",
    | the redirect-GET back to the same page that follows a successful
    | POST — so 200 × 3 is exactly 600, and one nervous double-tap from
    | anyone in the drill trips the old ceiling on the feature it exists to
    | protect. 1200 leaves that headroom without weakening the ceiling as a
    | guessing-rate control — a script hammering distinct tokens from one
    | ip still needs 1200 requests inside a minute to clear it, and each of
    | those tokens is still separately capped at
    | `check_in_token_rate_limit_per_minute` regardless.
    |
    | All three keys are read from INSIDE their `RateLimiter::for()`
    | closures, at the moment each request is checked, not captured by
    | `use(...)` at boot (Advisory 2, Gate 2 review #3) — so a value
    | changed here (or by `config()->set()` in a test) takes effect on the
    | very next request, on a long-lived worker as much as in a test run.
    |
    */
    'check_in_rate_limit_per_minute' => (int) env('BCMS_CHECK_IN_RATE_LIMIT_PER_MINUTE', 60),
    'check_in_token_rate_limit_per_minute' => (int) env('BCMS_CHECK_IN_TOKEN_RATE_LIMIT_PER_MINUTE', 10),
    'check_in_ip_rate_limit_per_minute' => (int) env('BCMS_CHECK_IN_IP_RATE_LIMIT_PER_MINUTE', 1200),

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
