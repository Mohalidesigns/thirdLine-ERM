<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Presenters\NavPresenter;
use App\Services\Licensing\LicenseManager;
use App\Support\Periods\PeriodContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

/**
 * The props every Inertia page receives.
 *
 * Shape from ThirdLine's HandleInertiaRequests, with three additions this
 * product needs (`tenant`, `period`, `features`) and one deliberate
 * omission: `auth.user` is `id`, `name`, `email` — never the model. Serialising
 * the User would put its MFA columns, lockout state and organization internals
 * into every page's HTML (ThirdLine gotcha §11.13).
 *
 * Registered in the web group AFTER ResolveTenant and ResolvePeriod, because
 * both `tenant` and `period` read what those bind.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
                'roles' => $user ? $user->getRoleNames()->values()->all() : [],
                // Effective permissions. Gate::before lets super-admin through
                // every check, and the seeder grants it every permission, but a
                // role can be created by hand — so the list is made explicit
                // rather than trusting the grant table for that one role.
                'permissions' => $user ? $this->permissionsFor($user) : [],
            ],

            // organizationIdOrNull(), never organizationId(): the latter throws,
            // and this middleware runs on public pages too.
            'tenant' => fn () => $this->tenant(),

            'period' => fn () => $this->period(),

            'features' => fn () => array_map(
                fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                (array) config('features', []) + ['sso' => config('sso.enabled', false)]
            ),

            // Flashed input, for the forms that post natively because their
            // redirect lands on a Blade page (login, MFA) — see
            // resources/js/lib/nativeForm.jsx. Laravel never flashes passwords.
            'old' => fn () => $request->session()->getOldInput(),

            'navigation' => fn () => app(NavPresenter::class)->for($user),

            // Lazy + guarded so a licensing hiccup can never take a page down.
            'license' => $user ? fn () => $this->licenseNotice() : null,

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],

            // The same query the Blade topbar's View composer runs
            // (AppServiceProvider::boot); that composer goes in Phase 6.
            'unreadNotifications' => fn () => $user
                ? DB::table('notifications_log')->where('user_id', $user->id)->whereNull('read_at')->count()
                : 0,
        ];
    }

    /**
     * @return list<string>
     */
    private function permissionsFor(\App\Models\User $user): array
    {
        if ($user->hasRole('super-admin')) {
            return Permission::query()->pluck('name')->values()->all();
        }

        return $user->getAllPermissions()->pluck('name')->values()->all();
    }

    /**
     * @return array{id: int, name: string, code: string|null}|null
     */
    private function tenant(): ?array
    {
        $id = TenantContext::organizationIdOrNull();

        if ($id === null) {
            return null;
        }

        $organization = Organization::query()->find($id);

        if ($organization === null) {
            return null;
        }

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'code' => $organization->short_name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function period(): ?array
    {
        $period = PeriodContext::current();

        if ($period === null) {
            return null;
        }

        return [
            'id' => $period->id,
            'code' => $period->code,
            'name' => $period->name,
            'type' => $period->type,
            'start_date' => $period->start_date?->toDateString(),
            'end_date' => $period->end_date?->toDateString(),
            'is_closed' => (bool) $period->is_closed,
        ];
    }

    /**
     * The licence notice, or null.
     *
     * Null — not a "blocked" notice — when the install has no licence file and
     * enforcement is off (LICENSE_ENFORCE_VALID=false, the shipping default).
     * Otherwise an unlicensed development install would meet the full-screen
     * block on every page. Once a licence is activated, or enforcement is
     * switched on, the real state is shared.
     */
    private function licenseNotice(): ?array
    {
        try {
            $manager = app(LicenseManager::class);

            if (! $manager->isLicensed() && ! config('licensing.enforce_valid', false)) {
                return null;
            }

            return $manager->clientNotice();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
