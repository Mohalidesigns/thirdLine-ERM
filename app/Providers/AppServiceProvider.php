<?php

namespace App\Providers;

use App\Models\ControlTest;
use App\Models\LossEvent;
use App\Models\RiskAssessment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Observers\WorkflowTriggerObserver;
use App\Support\MorphTypes;
use App\Support\Tenancy\TenantContext;
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

        // One naming authority for every polymorphic entity_type column.
        // enforceMorphMap (rather than plain morphMap) makes an unmapped model
        // fail loudly instead of silently writing an FQCN that no later query
        // will match — which is precisely how the audit trail came to return
        // nothing. See App\Support\MorphTypes.
        Relation::enforceMorphMap(MorphTypes::map());

        $this->registerWorkflowTriggers();

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
}
