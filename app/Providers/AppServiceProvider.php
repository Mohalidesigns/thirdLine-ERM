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

        // Migration Phase 3.8. The only policy registered by hand: RCSA has no
        // model for Laravel to discover one from — it is four screens over
        // Risk, Control and RiskControlMapping plus a write into the campaign
        // tables — so its abilities are authorised against a subject class.
        // See App\Support\Rcsa\RcsaProgramme.
        Gate::policy(\App\Support\Rcsa\RcsaProgramme::class, \App\Policies\RcsaPolicy::class);

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
