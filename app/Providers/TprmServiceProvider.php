<?php

namespace App\Providers;

use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Category;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Services\Llm\ModuleAiPolicyRegistry;
use App\Services\Tprm\Ai\TprmAiPolicy;
use App\Support\Tprm\RuleEvaluator;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Third-Party Risk Management module.
 *
 * `config/tprm.php` is NOT published from here. This is a first-party module
 * inside the application, not a package: the file is in `config/` already, is
 * version-controlled with the code that reads it, and `engine_version` in it
 * is stamped onto every score run. A publishable copy would let a deployment
 * carry a scoring constant the code has never seen.
 *
 * Policies are registered by Laravel's own discovery, which looks for
 * `App\Policies\{Model}Policy`. The TPRM models live in `App\Models\Tprm`, so
 * discovery would look in `App\Policies\Tprm` — which is where they are put.
 * The mapping is stated below rather than left implicit, because development
 * standard §3 records two separate outages caused by a policy Laravel could
 * not find: an ability written on the wrong policy class returns false for
 * everybody, silently.
 */
class TprmServiceProvider extends ServiceProvider
{
    /**
     * Model to policy, for the guard test to assert against.
     *
     * @var array<class-string, class-string>
     */
    public const POLICIES = [
        ThirdParty::class => \App\Policies\Tprm\ThirdPartyPolicy::class,
        Engagement::class => \App\Policies\Tprm\EngagementPolicy::class,
        Category::class => \App\Policies\Tprm\CategoryPolicy::class,
        BusinessFunction::class => \App\Policies\Tprm\BusinessFunctionPolicy::class,
        \App\Models\Tprm\Assessment::class => \App\Policies\Tprm\AssessmentPolicy::class,
        \App\Models\Tprm\QuestionnaireTemplate::class => \App\Policies\Tprm\QuestionnaireTemplatePolicy::class,
        \App\Models\Tprm\Contract::class => \App\Policies\Tprm\ContractPolicy::class,
        \App\Models\Tprm\Obligation::class => \App\Policies\Tprm\ObligationPolicy::class,
        \App\Models\Tprm\Finding::class => \App\Policies\Tprm\FindingPolicy::class,
    ];

    public function register(): void
    {
        // The rule evaluator holds per-evaluation state (`unresolvedFacts`), so
        // it is bound as a transient rather than a singleton. A shared instance
        // would let one screen's unresolved facts leak into another's preview.
        $this->app->bind(RuleEvaluator::class, fn () => new RuleEvaluator);

        // Phase 11a Gate 2 fix: shared per request/job so its `tp_settings`
        // read is memoised across every consumer — see TprmAiPolicy's own
        // docblock for why `scoped()`, not `singleton()`, is what keeps a
        // long-running queue worker from serving one tenant's stale AI switch
        // state to the next job.
        $this->app->scoped(TprmAiPolicy::class);
    }

    public function boot(): void
    {
        // FR-ASM-05's publish gate. Registered as an observer rather than
        // enforced in a Form Request so that the seeder shipping the packs,
        // any clone-and-publish, and the API all meet the same rule.
        \App\Models\Tprm\QuestionnaireTemplate::observe(
            \App\Observers\Tprm\QuestionnaireTemplateObserver::class
        );

        foreach (self::POLICIES as $model => $policy) {
            if (class_exists($policy)) {
                \Illuminate\Support\Facades\Gate::policy($model, $policy);
            }
        }

        // Phase 5. One listener on one event, dispatched from everywhere a
        // scoring input changes — see EngagementScoreInvalidated for why it is
        // one event rather than the seven the phase prompt names. Registered
        // here rather than in an EventServiceProvider so that a reader looking
        // for what this module wires finds all of it in one file.
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\Tprm\EngagementScoreInvalidated::class,
            \App\Listeners\Tprm\RecomputeResidualScore::class,
        );

        $this->registerPortalRateLimiter();
        $this->registerPortalUploadLimiter();

        // Phase 11a. This is the ONLY place TPRM tells the platform gateway
        // how to read this module's own settings — ADR 0015 §2:
        // "the gateway takes resolved settings as an argument and reads no
        // settings table itself." App\Services\Llm\ never imports
        // App\Models\Tprm directly; it asks this registry for a policy
        // instead, and TprmAiPolicy is the class that actually does.
        ModuleAiPolicyRegistry::register('tprm', TprmAiPolicy::class);
    }

    /**
     * The upload endpoint's own limit — Phase 8, part 11.
     *
     * SEPARATE FROM THE LOGIN LIMITER because the two protect different
     * things. Login throttling is about guessing a credential; this is about a
     * signed-in vendor filling the disk, deliberately or through a retry loop
     * in their own script. Twenty 25MB files a minute is generous for a person
     * and inconvenient for a machine.
     */
    private function registerPortalUploadLimiter(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('tprm-portal-upload', function (\Illuminate\Http\Request $request) {
            $user = $request->user('tprm-portal');

            return \Illuminate\Cache\RateLimiting\Limit::perMinute(20)
                ->by('tprm-portal-upload:'.($user?->getKey() ?? $request->ip()));
        });
    }

    /**
     * Per-organisation rate limiting on the vendor portal — FR-PRT-01.
     *
     * KEYED ON (organisation, ip, email), not on ip alone. Vendors of one bank
     * routinely share an office and therefore an egress address; a plain
     * per-ip limit would let one careless vendor lock out every other vendor
     * of that client, which is a denial of service a competitor could arrange
     * for the price of a wrong password typed slowly.
     *
     * Including the ORGANISATION means noise from one tenant's portal cannot
     * spill into another's, and including the EMAIL means the limit bites the
     * account under attack rather than the address it is attacked from.
     *
     * This is the outer bound. The per-account lockout in `PortalAuthService`
     * is the sharper instrument; this one exists so that an attacker who
     * rotates addresses to avoid tripping the lockout still meets a wall.
     */
    private function registerPortalRateLimiter(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('tprm-portal-login', function (\Illuminate\Http\Request $request) {
            /*
             * The RAW route value, not a bound model. Throttle middleware runs
             * before SubstituteBindings, so `route('client')` is still the
             * uuid string here — asking it for `getKey()` is a fatal on the
             * first login attempt, which is how this was found.
             */
            $client = $request->route('client');

            $tenantKey = is_object($client)
                ? $client->getKey()
                : ($client ?? $request->session()->get('tprm_portal_pending_organization') ?? 'none');

            /*
             * The account half of the key. Which request field may name the
             * account depends on the route, and the kinds carry distinct
             * prefixes so one can never collide with another:
             *
             *  - mfa.*  : the half-authenticated user parked in the session.
             *             The request's `email` is IGNORED here. These routes
             *             carry none, and honouring one would let a vendor of
             *             the same client post `email=pending:42` to fill
             *             victim 42's bucket, or add a random email to every
             *             request to escape their own.
             *  - invitation.accept : the route token (raw, as above), so two
             *             invitations do not share one bucket across tenants.
             *  - anything else (login.attempt): the form's email. A stale
             *             pending session left by an abandoned attempt must
             *             not turn a login for another address into someone
             *             else's bucket.
             */
            $routeName = (string) $request->route()?->getName();
            $pending = $request->hasSession()
                ? $request->session()->get(\App\Http\Middleware\Tprm\EnsurePendingPortalUser::SESSION_KEY)
                : null;

            if (str_starts_with($routeName, 'tprm-portal.mfa.') && $pending !== null) {
                $account = 'pending:'.$pending;
            } elseif ($routeName === 'tprm-portal.invitation.accept') {
                $account = 'invite:'.hash('sha256', (string) $request->route('token'));
            } else {
                $account = 'email:'.mb_strtolower((string) $request->input('email'));
            }

            $throttled = fn (\Illuminate\Http\Request $request, array $headers) => $this->portalThrottledResponse($request, $headers);

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(10)
                    ->by('tprm-portal:'.$tenantKey.':'.$request->ip())
                    ->response($throttled),

                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)
                    ->by('tprm-portal-account:'.$tenantKey.':'.$account)
                    ->response($throttled),
            ];
        });
    }

    /**
     * What a tripped portal limit answers.
     *
     * Laravel's default is a bare 429 page, which Inertia shows as a modal
     * over the sign-in form. A redirect back carrying the error under the key
     * the screen already renders puts the message where the vendor is
     * looking: `code` on the MFA screens, `email` on the login and
     * invitation forms. A client that asked for JSON keeps the real 429.
     */
    private function portalThrottledResponse(\Illuminate\Http\Request $request, array $headers): \Symfony\Component\HttpFoundation\Response
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);
        $wait = $seconds > 60
            ? 'about '.(int) ceil($seconds / 60).' minutes'
            : max(1, $seconds).' seconds';
        $message = "Too many attempts. Please wait {$wait} and try again.";

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 429, $headers);
        }

        $key = str_starts_with((string) $request->route()?->getName(), 'tprm-portal.mfa.') ? 'code' : 'email';

        return back()->withErrors([$key => $message]);
    }
}
