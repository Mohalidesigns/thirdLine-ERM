<?php

namespace ThirdLine\Platform\Licensing;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The application-specific fact the licensing cluster needs: what a seat is.
 *
 * Exactly one read in ten service classes named the application —
 * `App\Models\User::count()`, the seats-in-use figure reported to the licence
 * server. Everything else in the cluster is about JWTs, grace periods, clock
 * drift and tamper detection, none of which care what product they are in.
 *
 * NO DEFAULT, for the same reason as TenancyConfig: a package that guesses
 * `App\Models\User` works until it meets a consumer that calls it something
 * else, and then under-reports seat usage to a licence server rather than
 * failing — which is a licence compliance problem discovered by an auditor
 * rather than by a test.
 */
final class LicensingConfig
{
    /**
     * The route an unlicensed or locked user is sent to, and the prefix whose
     * routes stay reachable so they are not trapped there.
     *
     * A SHARED PACKAGE MUST NOT NAME A CONSUMER'S ROUTE. This was
     * `route('admin.license')`, which is what the risk product calls it;
     * ThirdLine calls the same screen `license`, so adopting the package threw
     * RouteNotFoundException on every unlicensed request — six failures the
     * moment a second consumer tried it. The rule is the same one the UI
     * package follows: a component that hardcodes a route name cannot be
     * shared, and it applies to PHP exactly as it does to JSX.
     */
    public static function recoveryRoute(): string
    {
        $route = config('licensing.recovery_route');

        if (! is_string($route) || $route === '') {
            throw new RuntimeException(
                'licensing.recovery_route is not configured. Set it to the route name that '
                .'serves this application\'s licence screen — the package cannot guess it, and '
                .'an unlicensed user redirected at a route that does not exist sees a 500 '
                .'instead of the page that would let them recover.'
            );
        }

        return $route;
    }

    /**
     * Where a user is sent when a FEATURE is not licensed — they still have a
     * working deployment, just not that module, so they go home rather than to
     * the licence screen.
     *
     * Same rule as recoveryRoute(): this was `route('risk.dashboard')`, which
     * is the risk product's name for it.
     */
    public static function homeRoute(): string
    {
        $route = config('licensing.home_route');

        if (! is_string($route) || $route === '') {
            throw new RuntimeException(
                'licensing.home_route is not configured. Set it to the route name a user should '
                .'land on when a module is not licensed.'
            );
        }

        return $route;
    }

    /**
     * Send the user home, or refuse legibly if that route does not exist.
     */
    public static function redirectHome(string $message): mixed
    {
        $route = self::homeRoute();

        if (! app('router')->has($route)) {
            abort(403, "That module is not licensed, and licensing.home_route names \"{$route}\", which is not a registered route.");
        }

        return redirect()->route($route)->with('error', $message);
    }

    /**
     * Send the user to the recovery screen, or refuse legibly if there is none.
     */
    public static function redirectToRecovery(string $message): mixed
    {
        $route = self::recoveryRoute();

        if (! app('router')->has($route)) {
            abort(403, "This deployment is not licensed, and licensing.recovery_route names \"{$route}\", which is not a registered route.");
        }

        return redirect()->route($route)->with('error', $message);
    }

    /**
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        $model = config('licensing.user_model');

        if (! is_string($model) || $model === '') {
            throw new RuntimeException(
                'licensing.user_model is not configured. Set it to the class a licence seat '
                .'is counted from — the licence server is told how many are in use, so this '
                .'cannot be guessed.'
            );
        }

        if (! is_subclass_of($model, Model::class)) {
            throw new RuntimeException(
                "licensing.user_model is set to \"{$model}\", which is not an Eloquent model."
            );
        }

        return $model;
    }
}
