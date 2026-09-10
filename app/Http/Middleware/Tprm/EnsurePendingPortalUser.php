<?php

namespace App\Http\Middleware\Tprm;

use App\Models\Tprm\PortalUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The half-authenticated state: password accepted, code outstanding.
 *
 * This is deliberately NOT a session on the portal guard. Until a code is
 * verified there is no authenticated user, only an id in the session saying
 * which account is midway through signing in — so a session stolen at this
 * point buys an attacker nothing but the same challenge screen.
 *
 * The pending id EXPIRES. Ten minutes is long enough to open an authenticator
 * and short enough that a shared machine left on the challenge screen is not a
 * standing invitation.
 */
class EnsurePendingPortalUser
{
    public const SESSION_KEY = 'tprm_portal_pending_user';

    public const STARTED_KEY = 'tprm_portal_pending_started';

    public const TTL_SECONDS = 600;

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get(self::SESSION_KEY);
        $started = (int) $request->session()->get(self::STARTED_KEY, 0);

        if ($id === null || $started === 0 || (time() - $started) > self::TTL_SECONDS) {
            $request->session()->forget([self::SESSION_KEY, self::STARTED_KEY]);

            return redirect()
                ->route('tprm-portal.entry')
                ->with('error', 'That took too long. Please sign in again.');
        }

        /** @var PortalUser|null $user */
        $user = PortalUser::query()->withoutGlobalScopes()->find($id);

        if ($user === null || ! $user->canAuthenticate()) {
            $request->session()->forget([self::SESSION_KEY, self::STARTED_KEY]);

            return redirect()
                ->route('tprm-portal.entry')
                ->with('error', 'This account is not active. Contact your client\'s risk team.');
        }

        // Bound so the MFA screens can read the client's name and branding.
        TenantContext::set((int) $user->organization_id);

        $request->attributes->set('portalPendingUser', $user);

        return $next($request);
    }
}
