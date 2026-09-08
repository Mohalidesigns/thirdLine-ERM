<?php

namespace App\Providers;

use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Category;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
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

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(10)
                    ->by('tprm-portal:'.$tenantKey.':'.$request->ip()),

                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)
                    ->by('tprm-portal-account:'.$tenantKey.':'.mb_strtolower((string) $request->input('email'))),
            ];
        });
    }
}
