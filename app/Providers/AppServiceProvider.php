<?php

namespace App\Providers;

use App\Models\ControlTest;
use App\Models\LossEvent;
use App\Models\RiskAssessment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Observers\WebhookEventObserver;
use App\Observers\WorkflowTriggerObserver;
use App\Support\MorphTypes;
use App\Support\Tenancy\TenantContext;
use Dedoc\Scramble\Scramble;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant per request / job / command. Everything that needs the
        // current organization_id resolves this same instance.
        $this->app->singleton(TenantContext::class);

        // One selected reporting period per request / job / command, for the
        // same reason and with the same lifetime as the tenant above.
        $this->app->singleton(\App\Support\Periods\PeriodContext::class);

        // Livewire's runtime is compiled into resources/js/app.js so that the
        // application has exactly one Alpine (see the comment in that file).
        // Auto-injection would put a SECOND copy on the page, and the two fight
        // over Alpine's magics — the visible symptom is
        // "Cannot redefine property: $persist" and every x-data block on the
        // page going dead. The layout emits @livewireScriptConfig instead.
        config(['livewire.inject_assets' => false]);
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

        // Livewire's component RPC endpoint ships unguarded: `POST
        // livewire/update` is registered in the `web` group with nothing else.
        // That is a hole in WP-00 TASK 2's invariant — every web route carries
        // an authorization guard — because a Livewire component can read and
        // write anything the component's own code allows.
        //
        // dashboard.view is the platform's "may use this application"
        // permission: RolesAndPermissionsSeeder grants it to every role in its
        // $baseline, for exactly this reason. Object-level rules stay inside
        // the components, where they belong; this is the front door.
        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)
            ->middleware(['web', 'auth', 'permission:dashboard.view'])
            ->name('livewire.update'));

        // Share notification bell data with the topbar on every request.
        View::composer('layouts.partials.topbar', function ($view) {
            $userId = auth()->id();
            $recent = $userId
                ? DB::table('notifications_log')
                    ->where('user_id', $userId)
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get()
                : collect();
            $unreadCount = $userId
                ? DB::table('notifications_log')
                    ->where('user_id', $userId)
                    ->whereNull('read_at')
                    ->count()
                : 0;
            $view->with(compact('recent', 'unreadCount'));
        });

        // Super-admin bypass: any `can()` check short-circuits true.
        Gate::before(fn (?User $user, string $ability) => $user?->hasRole('super-admin') ? true : null);

        // Migration Phase 2: the data grid endpoints are guarded per grid.
        // `can:view-grid,grid` on the route hands the {grid} name here, and
        // the definition's own permission decides — the same check the
        // Livewire component made on every update.
        Gate::define('view-grid', function (User $user, string $grid) {
            try {
                return $user->can(\App\Grids\GridRegistry::resolve($grid)->permission());
            } catch (\InvalidArgumentException) {
                return false;
            }
        });
        // Control test review: the assigned reviewer OR a user with an
        // approver-class role can approve/reject the test.
        Gate::define('review-control-test', function (User $user, ControlTest $test) {
            if ($user->organization_id !== $test->organization_id) {
                return false;
            }
            if ($test->reviewer_id === $user->id) {
                return true;
            }

            return $user->hasAnyRole(['chief-risk-officer', 'risk-manager', 'compliance-officer']);
        });

        // Resubmit a rejected control test: the assigned tester or the
        // original creator.
        Gate::define('resubmit-control-test', function (User $user, ControlTest $test) {
            if ($user->organization_id !== $test->organization_id) {
                return false;
            }

            return in_array($user->id, array_filter([$test->tester_id, $test->created_by]));
        });

        // Treatment plan approval: role-based (no assigned reviewer column).
        Gate::define('approve-treatment-plan', function (User $user, TreatmentPlan $plan) {
            if ($user->organization_id !== $plan->organization_id) {
                return false;
            }

            return $user->hasAnyRole(['chief-risk-officer', 'risk-manager']);
        });

        // Treatment plan resubmit: owner or creator.
        Gate::define('resubmit-treatment-plan', function (User $user, TreatmentPlan $plan) {
            if ($user->organization_id !== $plan->organization_id) {
                return false;
            }

            return in_array($user->id, array_filter([
                $plan->owner_id ?? null,
                $plan->created_by ?? null,
            ]));
        });

        // Risk assessment approval: assigned reviewer_id OR approver role.
        Gate::define('approve-risk-assessment', function (User $user, RiskAssessment $assessment) {
            if ($user->organization_id !== $assessment->organization_id) {
                return false;
            }
            if ($assessment->reviewer_id === $user->id) {
                return true;
            }

            return $user->hasAnyRole(['chief-risk-officer', 'risk-manager']);
        });

        // Risk assessment resubmit: the assessor.
        Gate::define('resubmit-risk-assessment', function (User $user, RiskAssessment $assessment) {
            if ($user->organization_id !== $assessment->organization_id) {
                return false;
            }

            return $assessment->assessor_id === $user->id;
        });

        // Loss event approval: loss event manager or CRO.
        Gate::define('approve-loss-event', function (User $user, LossEvent $event) {
            if ($user->organization_id !== $event->organization_id) {
                return false;
            }
            if ($event->assigned_to_id === $user->id) {
                return true;
            }

            return $user->hasAnyRole(['chief-risk-officer', 'loss-event-manager', 'compliance-officer']);
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
