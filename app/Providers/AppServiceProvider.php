<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\WebhookEventObserver;
use App\Observers\WorkflowTriggerObserver;
use App\Support\MorphTypes;
use Dedoc\Scramble\Scramble;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use ThirdLine\Platform\Tenancy\TenantContext;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // TenantContext's singleton binding moved to the platform package's
        // TenancyServiceProvider in Phase 7.1 — bound in one place, so an
        // application that opts into tenancy cannot get a second instance and
        // a request that resolves one tenant while a job resolves another.

        // One selected reporting period per request / job / command, with the
        // same lifetime as the tenant. Stays here: a reporting period is this
        // product's idea, not a platform primitive.
        $this->app->singleton(\App\Support\Periods\PeriodContext::class);

        /*
         * BCMS tenant settings — a SINGLETON, because the service memoises the
         * row per organisation and two instances would hold two caches. A
         * settings change saved through one and read through another is a stale
         * read inside a single request: the AI kill switch flipped on and the
         * client still refusing, the RTO ceiling raised and the validator still
         * blocking. Both are silent.
         */
        $this->app->singleton(\App\Services\Bcms\BcmsSettings::class);

        /*
         * The channel registry, for the same reason and with a sharper edge.
         *
         * It resolves and CACHES one adapter instance per channel. Not a
         * singleton, every caller got its own registry and its own adapters —
         * which is wasteful, defeats `swap()` entirely (a test's replacement
         * went to a throwaway instance while the dispatcher used a fresh mock),
         * and would silently duplicate any adapter that ever holds state: a
         * connection, a rate limiter, a batch buffer. Phase 7 ships exactly
         * that kind of adapter.
         *
         * Found by a Phase 5 test asserting on a swapped channel and getting
         * nothing. The same defect as BcmsSettings above, one phase later.
         */
        $this->app->singleton(\App\Services\Bcms\Notification\ChannelRegistry::class);

        /*
         * Phase 11a — ADR 0015 §8. `LlmGateway` is bound TRANSIENT, not a
         * singleton: it carries per-call state (the resolved endpoint, the
         * last transport error) and a singleton in a queue worker would leak
         * one tenant's endpoint and error into the next tenant's job. The
         * circuit breaker's own state lives in the shared cache store
         * precisely so a transient gateway still shares it — the same
         * reasoning as `RuleEvaluator` in `TprmServiceProvider`.
         */
        $this->app->bind(\App\Services\Llm\LlmGateway::class);

        /*
         * BCMS Phase 2C (ADR 0018 §4). `DirectoryClient` is bound here rather
         * than in a `BcmsServiceProvider` — BCMS still has none (ADR 0007
         * deviation 2), and there is no new wiring here that would justify
         * one: no observer, no listener, no rate limiter, no asserted policy
         * map, just one interface→concrete binding exactly like every other
         * BCMS binding on this file.
         *
         * BOUND TRANSIENT, NOT A SINGLETON. `EntraGraphClient` carries
         * per-call paging state (`$pagesFetched`/`$objectsRead`, read back
         * through `ReportsDirectoryFetchStats`) the same way `RuleEvaluator`
         * carries `unresolvedFacts` in `TprmServiceProvider` — a singleton
         * would leak one sync run's paging counters into the next tenant's
         * run on a long-lived worker. `bind()` with no third argument is
         * already transient; this is explicit for the same reason the TPRM
         * binding is explicit: so the choice is a decision on the record, not
         * an accident of Laravel's default.
         */
        $this->app->bind(
            \App\Contracts\Bcms\DirectoryClient::class,
            \App\Services\Bcms\Identity\EntraGraphClient::class,
        );

        // How this product names the owner of a rendered document. The
        // renderer lives in thirdline/reporting and deliberately does not know
        // what an organisation is — see OrganizationBranding for why a Central
        // Bank of Nigeria code has no place in a shared PDF renderer.
        $this->app->bind(
            \ThirdLine\Reporting\Contracts\ResolvesDocumentBranding::class,
            \App\Services\Reporting\OrganizationBranding::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertDebugModeIsOff();
        $this->assertSessionCookieIsHardened();

        // One naming authority for every polymorphic entity_type column.
        // enforceMorphMap (rather than plain morphMap) makes an unmapped model
        // fail loudly instead of silently writing an FQCN that no later query
        // will match — which is precisely how the audit trail came to return
        // nothing. See App\Support\MorphTypes.
        Relation::enforceMorphMap(MorphTypes::map());

        $this->registerWorkflowTriggers();
        $this->registerWebhookEvents();

        // WP-07. The spec lives at /api/docs, not Scramble's default /docs/api,
        // because that is where the work package says it is and where an
        // integrator looks. Registration happens in app->booted(), so setting
        // it here is early enough.
        Scramble::configure()->expose(ui: 'api/docs', document: 'api/docs.json');

        // The `auth` group: authenticated, then past the second factor. MFA
        // sits in the group rather than on individual routes so a new screen
        // is covered by default instead of by remembering to annotate it.
        $this->app->make('router')->middlewareGroup('auth', [
            \App\Http\Middleware\EnsureAuthenticated::class,
            \App\Http\Middleware\EnsureMfaVerified::class,
        ]);

        // Super-admin bypass: any `can()` check short-circuits true.
        Gate::before(fn (?User $user, string $ability) => $user?->hasRole('super-admin') ? true : null);

        $this->registerBcmsWebhookRateLimiters();
        $this->registerBcmsCheckInRateLimiters();
        $this->registerAuthRateLimiters();
        $this->registerApiRateLimiters();

        // Migration Phase 3.8. The only policy registered by hand: RCSA has no
        // model for Laravel to discover one from — it is four screens over
        // Risk, Control and RiskControlMapping plus a write into the campaign
        // tables — so its abilities are authorised against a subject class.
        // See App\Support\Rcsa\RcsaProgramme.
        Gate::policy(\App\Support\Rcsa\RcsaProgramme::class, \App\Policies\RcsaPolicy::class);

        // Phase 11a. BCMS has no service provider of its own (ADR 0007
        // deviation 2) — its routes are in routes/web.php, its morph map is
        // above, and its AI policy registration is here for the same reason:
        // App\Services\Llm\ must not import App\Models\Bcms, so BCMS tells the
        // platform gateway how to read its own settings through this
        // registry rather than the gateway reading bcms_settings directly.
        \App\Services\Llm\ModuleAiPolicyRegistry::register('bcms', \App\Services\Bcms\Ai\BcmsAiPolicy::class);

        // Migration Phase 2: the data grid endpoints are guarded per grid.
        // `can:view-grid,grid` on the route hands the {grid} name here, and
        // the definition's own permission decides.
        Gate::define('view-grid', function (User $user, string $grid) {
            try {
                return $user->can(\App\Grids\GridRegistry::resolve($grid)->permission());
            } catch (\InvalidArgumentException) {
                return false;
            }
        });
        // Control test review and resubmission moved into
        // App\Policies\ControlTestPolicy in migration Phase 3.4. The abilities
        // keep their hyphenated names — WorkflowEngine::canAct() asks
        // `can('review-control-test', $test)` through ControlTestBinding::gate()
        // — because Laravel resolves a hyphenated ability on a model to the
        // camel-cased policy method, reviewControlTest().

        // Treatment plan approval and resubmission moved into
        // App\Policies\TreatmentPlanPolicy in migration Phase 3.5. Both keep
        // their hyphenated names — WorkflowEngine::canAct() asks
        // `can('approve-treatment-plan', $plan)` through
        // TreatmentPlanBinding::gate() — because Laravel resolves a hyphenated
        // ability on a model to the camel-cased policy method,
        // approveTreatmentPlan(). They also pick up the node scope every other
        // treatment ability applies.

        // Risk assessment approval and resubmission moved into
        // App\Policies\RiskAssessmentPolicy in migration Phase 3.3 — `approve`,
        // `reject` and `resubmit` there — where they also pick up the node
        // scope every other assessment ability applies.

        // Loss event approval moved into App\Policies\LossEventPolicy in
        // migration Phase 4.3 — the LAST risk-module closure. It keeps its
        // hyphenated name because WorkflowEngine::canAct() asks
        // `can('approve-loss-event', $event)` through LossEventBinding::gate(),
        // and Laravel resolves that to the camel-cased approveLossEvent().
    }

    /**
     * GATE 2 DEFECT 5 — the EMNS webhook routes' rate limiters, registered
     * here because BCMS has no service provider of its own (ADR 0007
     * deviation 2; see the morph-map and AI-policy registrations above for
     * the same pattern) and a named `RateLimiter::for()` closure has to run at
     * boot, not from `routes/web.php` — a route-cached deployment never
     * re-executes the routes file, so registering it there would work in
     * `php artisan serve` and silently vanish the moment `route:cache` runs.
     *
     * `bcms/alert-reply/{provider}` (the roll-call) and
     * `bcms/provider-status/{provider}` (delivery receipts) previously used
     * the bare `throttle:600,1` / `throttle:3000,1` middleware, bucketed on IP
     * alone. Gateways post from a small, stable set of source IPs, so that
     * was effectively one shared bucket per provider ACROSS EVERY TENANT —
     * and the roll-call, a person's life-safety acknowledgement, got the
     * *tighter* of the two limits. A 429 on that route is a dropped
     * acknowledgement, not a retried request.
     *
     * ADR 0016 §4 / phase-7-inbound-token-contract.md §3 replaced the single
     * shared `webhook_rate_limit_per_minute` with two keys — one per route —
     * because the two routes serve different acceptance criteria and the
     * invariant that actually matters is not "the two numbers are equal" but
     * "the life-safety route's ceiling is never below criterion 1's demand".
     * Both are INTERIM values (600/min each) until ADR 0016 §1 lands and its
     * tests pass; the raise to 2,000 / 10,000 is a separate, later commit.
     * Both stay keyed on {provider}+ip — not ip alone — so one provider's
     * volume cannot exhaust another provider's bucket, the same reasoning
     * TPRM's portal limiters use
     * (`TprmServiceProvider::registerPortalRateLimiter()`).
     */
    private function registerBcmsWebhookRateLimiters(): void
    {
        $alertReplyPerMinute = (int) config('bcms-gateways.alert_reply_rate_limit_per_minute', 600);
        $providerStatusPerMinute = (int) config('bcms-gateways.provider_status_rate_limit_per_minute', 600);

        \Illuminate\Support\Facades\RateLimiter::for('bcms-alert-reply', function (\Illuminate\Http\Request $request) use ($alertReplyPerMinute) {
            $provider = (string) ($request->route('provider') ?? 'unknown');

            return \Illuminate\Cache\RateLimiting\Limit::perMinute($alertReplyPerMinute)
                ->by('bcms-alert-reply:'.$provider.':'.$request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('bcms-provider-status', function (\Illuminate\Http\Request $request) use ($providerStatusPerMinute) {
            $provider = (string) ($request->route('provider') ?? 'unknown');

            return \Illuminate\Cache\RateLimiting\Limit::perMinute($providerStatusPerMinute)
                ->by('bcms-provider-status:'.$provider.':'.$request->ip());
        });

    }

    /**
     * Gate 2 defect 4 (BCMS Phase 9), and Gate 2 review #2's finding that the
     * defect 4 fix over-corrected. `bcms/check-in` (the short-code form) and
     * `bcms/check-in/{token}` (the QR link) carry no session and no
     * permission — the credential is the per-participant HMAC itself
     * (`CheckInController`'s own docblock) — so, like the EMNS webhook routes
     * above, named limiters have to be registered at boot rather than in
     * `routes/web.php`, which a route-cached deployment never re-executes.
     *
     * TWO LIMITERS, NOT ONE, because the two routes are different attack
     * surfaces answering to different traffic shapes:
     *
     * `bcms-check-in-code` guards the short-code FORM (`bcms/check-in`, no
     * token in the URL) — an 8-hex-character code typed by hand is a genuine
     * guessing surface, so this stays keyed on ip alone, as the single
     * `bcms-check-in` limiter always was.
     *
     * `bcms-check-in-token` guards the per-participant QR/link routes
     * (`bcms/check-in/{token}`). A single shared ip-keyed bucket here was the
     * defect: 200 people behind one office NAT, each scanning their OWN
     * token once during an evacuation drill, exhausted the same 60/min
     * bucket a token-guessing attacker would — request 61 was locked out
     * regardless of whose token it was, defeating the exact scenario the
     * feature exists for. This limiter returns TWO `Limit`s (Laravel accepts
     * an array from a `RateLimiter::for()` closure and enforces every one):
     * a tight per-TOKEN ceiling, because a token is a credential and
     * hammering one specific token is still a guessing/abuse pattern worth
     * stopping; and a generous per-ip ceiling, so a NAT'd crowd passes but a
     * single scanner cannot still hammer the route without limit.
     *
     * ADVISORY 2 (Gate 2 review #3): both `config(...)` reads used to happen
     * ONCE, here, at boot, and be captured into the closures by `use(...)`
     * — so nothing short of restarting the process (or re-registering the
     * limiter) could ever change the ceiling a running request is checked
     * against. That made the per-ip `Limit` on `bcms-check-in-token`
     * untestable from a plain `config()->set()` in a test, which is exactly
     * how every other ceiling in this file is proven — a test lowering it
     * silently did nothing, so deleting that `Limit` from the array above
     * would have left the suite green. `config(...)` is now read INSIDE
     * each closure, at CALL time, one lookup per request rather than once
     * per process: negligible cost against a cached config array, and it
     * makes `config()->set()` in a test — or an operator's runtime config
     * change on a long-lived worker — take effect on the very next request,
     * exactly as every other closure-based limiter in this file already
     * behaves. The stale `600` fallback on the per-ip `Limit` (half of
     * `config/bcms.php`'s own `1200` default, and derived from the same
     * 200-participants-times-three-requests arithmetic that default's own
     * comment explains) is corrected to match here too, so the two defaults
     * cannot drift again.
     */
    private function registerBcmsCheckInRateLimiters(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('bcms-check-in-code', function (\Illuminate\Http\Request $request) {
            $codePerMinute = (int) config('bcms.check_in_rate_limit_per_minute', 60);

            return \Illuminate\Cache\RateLimiting\Limit::perMinute($codePerMinute)->by('bcms-check-in-code:'.$request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('bcms-check-in-token', function (\Illuminate\Http\Request $request) {
            $token = (string) ($request->route('token') ?? 'unknown');
            $tokenPerMinute = (int) config('bcms.check_in_token_rate_limit_per_minute', 10);
            $ipPerMinute = (int) config('bcms.check_in_ip_rate_limit_per_minute', 1200);

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute($tokenPerMinute)->by('bcms-check-in-token:'.$token),
                \Illuminate\Cache\RateLimiting\Limit::perMinute($ipPerMinute)->by('bcms-check-in-token-ip:'.$request->ip()),
            ];
        });
    }

    /**
     * Rate limits on the authentication surface — registered here, at boot, and
     * NOT from `routes/web.php`.
     *
     * A named `RateLimiter::for()` closure has to run at boot: `route:cache`
     * (which `scripts/deploy.sh` runs) means a cached deployment never
     * re-executes the routes files, so a limiter defined there works under
     * `php artisan serve` and in the test suite and is simply never registered
     * in production. Every `throttle:<name>` route then answers 500 "Rate
     * limiter [login] is not defined" — login, MFA, password reset, SSO
     * discovery and licence activation included. This is the same reason
     * `registerBcmsWebhookRateLimiters()` lives here.
     *
     * `RateLimitersSurviveRouteCacheTest` fails the build if a limiter is ever
     * defined in a routes file again.
     *
     * PREVIOUS BEHAVIOUR: nothing in the routes was throttled. `POST login`,
     * `POST mfa/verify`, `POST forgot-password` and `POST auth/sso/discover` all
     * accepted unlimited attempts from anyone who could reach the host. On a
     * platform holding a bank's risk register that is the cheapest attack available:
     * an offline-quality password guessing rate against a live login form.
     *
     * CHOOSING THE KEY IS THE WHOLE DESIGN. These deployments sit inside banks,
     * where several hundred staff share one or two NAT egress addresses. A purely
     * per-IP limit on `login` would mean the head office throttling itself every
     * Monday morning, and an operator whose first experience of a security control
     * is a self-inflicted outage turns it off. So each limiter below uses the
     * narrowest key that still bounds the attack:
     *
     *   login            per (email, IP) primarily; a loose per-IP ceiling second
     *   mfa-verify       per (account, IP) — the brute-force target, tightest limit
     *   password-reset   per email primarily, because the abuse is mail-bombing one
     *                    named person; a loose per-IP ceiling second
     *   sso-discover     per IP, because there is no account involved — the abuse is
     *                    enumerating which customer domains are federated
     *
     * The SCIM group is limited too; its limiter lives beside the API's own in
     * `registerApiRateLimiters()` below, which is where the rest of the
     * machine-to-machine surface is configured.
     *
     * Every ceiling below is stated with the normal-use figure it has to clear, so
     * the next person can tell whether a change is safe.
     */
    private function registerAuthRateLimiters(): void
    {
        /*
         * LOGIN.
         *
         * Two limits, and both apply:
         *
         *   5 per minute per (email, IP) — one person, at one keyboard, getting their
         *   own password wrong. Five tries a minute is more than a human needs and far
         *   below what guessing needs. Keyed on the PAIR rather than on the email alone
         *   so that an attacker cannot consume a colleague's budget; keyed on the email
         *   as well as the IP so that one machine cannot grind a single account.
         *
         *   60 per minute per IP — the anti-spray ceiling: one source trying many
         *   different accounts. Deliberately generous, because in these deployments one
         *   IP is an entire office. A 200-person branch signing in over a ten-minute
         *   window is ~20/min, and this leaves 3x headroom on top. It bounds spraying
         *   at 86,400 attempts/day from a single address, which is not zero — the
         *   honest statement is that a shared-egress deployment cannot have a tight
         *   per-IP login limit, and that detection (repeated failures across many
         *   distinct accounts from one address) is the control that closes the rest.
         *
         * Both are counted per REQUEST, not per failure, because ThrottleRequests runs
         * before the controller. A user who signs in successfully consumes one of the
         * five, which does not matter at these numbers.
         */
        \Illuminate\Support\Facades\RateLimiter::for('login', function (\Illuminate\Http\Request $request) {
            $email = \Illuminate\Support\Str::lower(trim((string) $request->input('email')));

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('login:'.sha1($email.'|'.$request->ip())),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('login-ip:'.$request->ip()),
            ];
        });

        /*
         * MFA VERIFICATION AND ENROLMENT CONFIRMATION.
         *
         * A six-digit code checked against a plus/minus-one-step window is one guess in
         * ~333,333 per attempt. The verification controller keeps no attempt counter
         * of its own, so before this limiter existed the expected number of requests to
         * walk in was well inside what a script does over a lunch break.
         *
         * 5 attempts per 15 minutes per (account, IP) reduces that to roughly 20 codes
         * an hour, i.e. centuries of expected guessing, while still letting a user who
         * fat-fingers a code or whose phone clock has drifted try again shortly. The
         * account part of the key is the pending user id during sign-in verification and
         * the authenticated user id during enrolment confirmation, so the same limiter
         * serves mfa/verify and mfa/enable.
         *
         * The 30-per-hour per-IP ceiling catches somebody cycling sessions to reset the
         * narrower key.
         *
         * NOTE: mfa/verify and mfa/enable are behind `feature:mfa_totp` and return 404
         * while the flag is off. The limiter is applied anyway so that the route is not
         * left unthrottled for whoever turns the flag on.
         */
        \Illuminate\Support\Facades\RateLimiter::for('mfa-verify', function (\Illuminate\Http\Request $request) {
            $account = $request->session()->get('mfa_pending_user_id')
                ?? $request->user()?->getAuthIdentifier()
                ?? 'anonymous';

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinutes(15, 5)->by('mfa:'.sha1($account.'|'.$request->ip())),
                \Illuminate\Cache\RateLimiting\Limit::perMinutes(60, 30)->by('mfa-ip:'.$request->ip()),
            ];
        });

        /*
         * PASSWORD RESET REQUESTS.
         *
         * The abuse here is not guessing, it is mail-bombing: `POST forgot-password`
         * sends an email to an address the caller names, so an unthrottled endpoint is a
         * free outbound mailer pointed at a named member of staff, and it also burns the
         * deployment's SMTP reputation.
         *
         *   5 per hour per email — keyed on the EMAIL rather than the pair, because the
         *   victim is the mailbox and a botnet would otherwise get one send per source.
         *   Nobody legitimately needs a sixth reset link in an hour; the link is valid
         *   for an hour and reusable.
         *
         *   60 per hour per IP — the office-NAT allowance. High enough that a shared
         *   egress address cannot exhaust it during a normal morning.
         *
         * Keying on the email does mean an attacker can stop one person from requesting
         * a reset for an hour. That is a real, bounded nuisance and it is the lesser
         * evil: the alternative key lets the same attacker deliver hundreds of reset
         * emails to that person instead. It expires on its own, and an administrator can
         * reset the password directly from the user screen in the meantime.
         */
        \Illuminate\Support\Facades\RateLimiter::for('password-reset', function (\Illuminate\Http\Request $request) {
            $email = \Illuminate\Support\Str::lower(trim((string) $request->input('email')));

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinutes(60, 5)->by('pwreset:'.sha1($email)),
                \Illuminate\Cache\RateLimiting\Limit::perMinutes(60, 60)->by('pwreset-ip:'.$request->ip()),
            ];
        });

        /*
         * HOME-REALM DISCOVERY.
         *
         * `POST auth/sso/discover` turns an email address into a sign-in URL, which
         * makes it an oracle for "is this company a customer, and is their domain
         * federated". SsoController already answers vaguely for unknown domains; the
         * limit is what stops the vague answer being ground down by enumerating a
         * dictionary of domains.
         *
         * 30 per minute per IP. No account exists at this point in the flow, so the IP
         * is the only key available. A real user hits this once per sign-in, so 30 a
         * minute clears normal office use by a wide margin while making domain
         * enumeration slow enough to be visible in the logs.
         */
        \Illuminate\Support\Facades\RateLimiter::for('sso-discover', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('sso-discover:'.$request->ip());
        });

        /*
         * LICENCE ACTIVATION.
         *
         * `POST admin/settings/license/activate` forwards the supplied key to the
         * LicensingServer with retries, which makes an unthrottled endpoint a
         * licence-key brute-forcer with somebody else's server as the oracle. The
         * caller is always an authenticated licence manager, so the key is the user;
         * five attempts a minute is more than a person pasting a key needs.
         */
        \Illuminate\Support\Facades\RateLimiter::for('license-activate', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('license:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }

    /**
     * Machine-to-machine rate limits (the REST API and SCIM) — registered here,
     * at boot, and NOT from `routes/api.php`, for the reason given on
     * `registerAuthRateLimiters()`: a route-cached deployment never re-executes
     * the routes files, so a limiter defined there is never registered and
     * every `throttle:api-token` / `throttle:scim` route answers 500.
     */
    private function registerApiRateLimiters(): void
    {
        /*
         * WP-07 — the rate limit is PER TOKEN, and each token carries its own ceiling.
         *
         * Per IP would be wrong in both directions here: several integrations behind
         * one corporate NAT would throttle each other, and one runaway script would be
         * indistinguishable from the rest of the building. Per token also means a
         * misbehaving integration can be given a lower ceiling without touching anyone
         * else's.
         */
        \Illuminate\Support\Facades\RateLimiter::for('api-token', function (\Illuminate\Http\Request $request) {
            /** @var \App\Models\ApiToken|null $token */
            $token = $request->attributes->get('api_token');

            if ($token === null) {
                // Unauthenticated attempts, keyed by IP. Deliberately tight: the only
                // thing an unauthenticated caller can be doing here is guessing tokens.
                return \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by('api-anon:'.$request->ip());
            }

            return \Illuminate\Cache\RateLimiting\Limit::perMinute(max(1, (int) $token->rate_limit_per_minute))->by('api-token:'.$token->id);
        });

        /*
         * SCIM 2.0 PROVISIONING.
         *
         * Keyed on the PRESENTED BEARER TOKEN, not the IP: the callers are directory
         * services (Entra ID, Okta), one per customer, and several customers may egress
         * through the same cloud address. Keying on the credential also means the limit
         * applies before AuthenticateScim resolves it, so token guessing is throttled
         * too — which is why `throttle:scim` is listed BEFORE `scim.auth` on the group.
         *
         *   300 per minute per token — Entra ID sends one HTTP request per user or group
         *   change and bursts hard on the first full sync of a directory. A 5,000-staff
         *   bank's initial import is a long tail of requests, not a spike, but the
         *   ceiling has to clear the burst or provisioning fails silently at the
         *   customer end and nobody hears about it for a week.
         *
         *   20 per minute per IP when NO token is presented at all. The only thing an
         *   unauthenticated caller can be doing on these endpoints is probing, and this
         *   mirrors the `api-anon` limit already used by `api-token` above.
         */
        \Illuminate\Support\Facades\RateLimiter::for('scim', function (\Illuminate\Http\Request $request) {
            $bearer = $request->bearerToken();

            if ($bearer === null || $bearer === '') {
                return \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by('scim-anon:'.$request->ip());
            }

            return \Illuminate\Cache\RateLimiting\Limit::perMinute(300)->by('scim:'.hash('sha256', $bearer));
        });
    }

    /**
     * Watch every model that can be the subject of a workflow, so a definition
     * published with trigger = on_create or on_transition actually starts.
     *
     * WP-06 exists partly because workflow_definitions.escalation_rules was
     * written by the designer and read by nothing. Shipping a `trigger` column
     * with the same property would be the same mistake in a new table.
     *
     * Costs nothing until used: the observer's first act is a query for
     * PUBLISHED definitions with that trigger, and none of the ten shipped
     * processes has one.
     */
    private function registerWorkflowTriggers(): void
    {
        foreach (array_keys((array) config('workflow.subjects', [])) as $alias) {
            $class = Relation::getMorphedModel($alias);

            if ($class !== null && class_exists($class)) {
                $class::observe(WorkflowTriggerObserver::class);
            }
        }
    }

    /**
     * WP-07 TASK 3 — publish created / updated / deleted for everything the API
     * publishes, so webhook event names and API resource names describe the
     * same things and an integrator learns one vocabulary rather than two.
     *
     * Costs a cached existence check per organization when nobody is
     * subscribed, which is the common case.
     */
    private function registerWebhookEvents(): void
    {
        foreach (\App\Http\Api\ApiResourceRegistry::all() as $definition) {
            $model = $definition['model'];

            if (class_exists($model)) {
                $model::observe(WebhookEventObserver::class);
            }
        }
    }

    /**
     * Refuse to boot with debug output enabled outside development.
     *
     * Laravel's debug page renders the stack trace, the environment and the
     * values of configuration variables. On a platform holding regulatory
     * filings that is a data breach, not an inconvenience — so fail loudly at
     * boot rather than on whichever exception happens to surface first.
     */
    private function assertDebugModeIsOff(): void
    {
        if (in_array($this->app->environment(), ['local', 'testing'], true)) {
            return;
        }

        if (config('app.debug')) {
            throw new RuntimeException(
                'APP_DEBUG is enabled in the "'.$this->app->environment().'" environment. '
                .'Set APP_DEBUG=false: debug output exposes configuration, credentials and record contents.'
            );
        }
    }

    /**
     * Refuse to boot with an unprotected session cookie outside development.
     *
     * config/session.php already forces both settings on outside local and
     * testing, so in an ordinary deployment this check never fires. It exists
     * for the one case that file cannot cover: A CACHED CONFIGURATION BUILT
     * SOMEWHERE ELSE. `php artisan config:cache` freezes the resolved array,
     * env() stops being consulted at runtime, and a cache built on a developer's
     * machine or in a CI image with APP_ENV=local carries `secure => null` and
     * `encrypt => false` into production unnoticed. Nothing else in the request
     * would complain — the application would work perfectly, and the session
     * cookie would simply travel unmarked.
     *
     * PREVIOUS BEHAVIOUR: 'secure' was env('SESSION_SECURE_COOKIE') with no
     * default (null — the cookie was not marked Secure) and 'encrypt' was
     * env('SESSION_ENCRYPT', false), so a deployment where nobody had set those
     * two variables — which is every deployment, since no .env.example documents
     * them — sent an unencrypted session identifier that a plain HTTP request
     * would disclose.
     *
     * Modelled on assertDebugModeIsOff() above, and for the same reason: a
     * configuration mistake that silently weakens a control should stop the
     * application at boot, where somebody is watching, rather than surface as an
     * incident months later.
     */
    private function assertSessionCookieIsHardened(): void
    {
        if (in_array($this->app->environment(), ['local', 'testing'], true)) {
            return;
        }

        $problems = [];

        if (! config('session.secure')) {
            $problems[] = 'session.secure is off, so the session cookie is not marked Secure and will be sent over plain HTTP';
        }

        if (! config('session.encrypt')) {
            $problems[] = 'session.encrypt is off, so session payloads — including the resolved tenant — are stored in the clear';
        }

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(
            'Insecure session configuration in the "'.$this->app->environment().'" environment: '
            .implode('; ', $problems).'. config/session.php forces both on outside local and testing, '
            .'so seeing this almost certainly means a configuration cache was built in a different '
            .'environment — run `php artisan config:clear` and rebuild it here.'
        );
    }
}
