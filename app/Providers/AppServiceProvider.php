<?php

namespace App\Providers;

use App\Models\ControlTest;
use App\Models\LossEvent;
use App\Models\RiskAssessment;
use App\Models\TreatmentPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register middleware aliases
        $this->app->make('router')->middlewareGroup('auth', [
            \App\Http\Middleware\EnsureAuthenticated::class,
        ]);

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
                $plan->treatment_owner_id ?? null,
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
}
