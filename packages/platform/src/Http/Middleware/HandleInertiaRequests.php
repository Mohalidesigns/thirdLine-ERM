<?php

namespace ThirdLine\Platform\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;
use ThirdLine\Platform\Licensing\LicenseManager;

/**
 * The props EVERY ThirdLine Inertia page receives, in every product.
 *
 * THE RULE THIS EXISTS TO ENFORCE: `auth.user` is id, name and email — never
 * the model. Inertia serialises shared props into the HTML of every page, so
 * sharing the User object publishes its MFA secret columns, lockout state,
 * password reset tokens and whatever a later migration adds to the table, on
 * every page, to anyone who views source. It is the kind of leak that is
 * invisible in review because the offending line reads `'user' => $user`.
 *
 * Both products got this wrong independently — the risk product fixed it,
 * ThirdLine still shares the full User — which is exactly why the safe shape
 * belongs in a package rather than in a convention. A subclass cannot
 * accidentally widen it: share() is final, and what a product adds goes in
 * applicationProps().
 *
 * ORDERING. Register this after any middleware whose state the subclass reads
 * — a tenant resolver, a period resolver — because the closures here run at
 * render time but applicationProps() is assembled during the request.
 */
abstract class HandleInertiaRequests extends Middleware
{
    /**
     * The props this product adds on top of the platform's.
     *
     * Anything not shared by every ThirdLine product belongs here: the current
     * tenant, the selected reporting period, navigation, feature flags.
     *
     * @return array<string, mixed>
     */
    abstract protected function applicationProps(Request $request): array;

    /**
     * @return array<string, mixed>
     */
    final public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            ...$this->platformProps($request),
            ...$this->applicationProps($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function platformProps(Request $request): array
    {
        $user = $request->user();

        return [
            'auth' => [
                // id, name, email. NOT the model — see the class docblock.
                'user' => $user ? [
                    'id' => $user->getKey(),
                    'name' => $user->getAttribute('name'),
                    'email' => $user->getAttribute('email'),
                ] : null,
                'roles' => $user ? $user->getRoleNames()->values()->all() : [],
                'permissions' => $user ? $this->permissionsFor($user) : [],
            ],

            // Flashed input, for forms that post natively rather than through
            // Inertia. Laravel never flashes passwords.
            'old' => fn () => $request->session()->getOldInput(),

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],

            // Lazy and guarded: a licensing hiccup must never take a page down.
            // Null when the product has not opted into licensing at all.
            'license' => $user ? fn () => $this->licenseNotice() : null,
        ];
    }

    /**
     * Effective permissions.
     *
     * Spatie's Gate::before lets a super-admin through every check, and the
     * seeder grants that role every permission — but a role can be created by
     * hand, so the list is made explicit rather than trusting the grant table
     * for that one role. A screen that hides a button the server would allow is
     * a worse bug than one that shows a button the server refuses.
     *
     * @return list<string>
     */
    protected function permissionsFor(mixed $user): array
    {
        if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
            return Permission::query()->pluck('name')->values()->all();
        }

        return method_exists($user, 'getAllPermissions')
            ? $user->getAllPermissions()->pluck('name')->values()->all()
            : [];
    }

    /**
     * The licence notice, or null.
     *
     * Null — not a "blocked" notice — when the install has no licence file and
     * enforcement is off, which is the shipping default. Otherwise an
     * unlicensed development install would meet a full-screen block on every
     * page. Once a licence is activated, or enforcement is switched on, the
     * real state is shared.
     *
     * @return array<string, mixed>|null
     */
    protected function licenseNotice(): ?array
    {
        if (! class_exists(LicenseManager::class) || ! Auth::check()) {
            return null;
        }

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
