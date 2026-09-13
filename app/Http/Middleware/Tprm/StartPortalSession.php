<?php

namespace App\Http\Middleware\Tprm;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

/**
 * Give the portal its own session cookie — FR-PRT-01.
 *
 * IT EXTENDS `StartSession` AND REPLACES IT IN THE GROUP, rather than sitting
 * in front of it rewriting `session.cookie` in the container. The rewrite
 * approach was written first and was wrong in two ways that only showed up
 * under test:
 *
 *   `SessionManager` caches its built store, so the rewrite silently did
 *   nothing whenever anything had already touched the session. Dropping the
 *   cached drivers fixed that and broke something worse: with the `array`
 *   driver the storage IS the driver instance, so forgetting it wiped the
 *   portal's session between requests and no end-to-end sign-in could be
 *   tested at all.
 *
 *   A container-wide config mutation is process-wide, not request-wide. Under
 *   php-fpm that is invisible; under Octane or any long-lived worker an
 *   internal response would start carrying the portal cookie name.
 *
 * Overriding `getSession()` has neither problem. It touches no shared
 * configuration and leaves the manager's cache — and therefore the array
 * driver's storage — intact.
 *
 * The rename is UNDONE IN `handle()`. `SessionManager` hands out one cached
 * `Store` instance, so renaming it is a mutation of shared state that would
 * otherwise outlive the request: the next internal request in the same process
 * would find a store still called `…-tprm-portal`, fail to read the internal
 * cookie, and silently sign the user out. By the time the restore runs the
 * response cookie has already been written under the portal name, so nothing
 * is lost.
 *
 * WHY A SEPARATE COOKIE AT ALL, given the guards already keep the two
 * identities in different session keys. Two reasons, and the second is the
 * real one:
 *
 *   One browser has to hold both sessions. Our own staff open the portal to
 *   see what a vendor sees, and a shared cookie makes that a choice between
 *   the two rather than a tab each.
 *
 *   A stolen portal cookie presented to an internal route is not a session at
 *   all — the internal session is keyed by a cookie the attacker never had.
 *   With one cookie it would be the same session id, and only the guard's own
 *   bookkeeping would stand between them.
 */
class StartPortalSession extends StartSession
{
    /**
     * The suffix appended to the application's own session cookie name, so a
     * deployment running two copies on one domain does not collide.
     */
    public const COOKIE_SUFFIX = '-tprm-portal';

    public function handle($request, Closure $next)
    {
        $store = $this->manager->driver();
        $original = $store->getName();

        try {
            return parent::handle($request, $next);
        } finally {
            $store->setName($original);
        }
    }

    public function getSession(Request $request)
    {
        $session = $this->manager->driver();

        // Idempotent: `handle()` may be re-entered on an internal redirect
        // within one request, and appending the suffix twice would produce a
        // cookie name no browser ever sends.
        if (! str_ends_with($session->getName(), self::COOKIE_SUFFIX)) {
            $session->setName($session->getName().self::COOKIE_SUFFIX);
        }

        $session->setId($request->cookies->get($session->getName()));

        return $session;
    }
}
