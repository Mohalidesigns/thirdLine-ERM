<?php

namespace App\Http\Controllers\TprmPortal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Tprm\EnsurePendingPortalUser;
use App\Models\Organization;
use App\Services\Tprm\Portal\PortalAuthService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Signing in and out of the vendor portal — FR-PRT-01.
 *
 * `store()` DOES NOT CREATE A SESSION. It checks the password and parks the
 * account id as pending; the session is minted only after a code, inside
 * `PortalAuthService::completeMfa()`. See that class for why the split is what
 * makes MFA mandatory rather than merely required by a middleware.
 */
class SessionController extends Controller
{
    public function __construct(private readonly PortalAuthService $auth) {}

    /**
     * The bare entry point, reached by someone who bookmarked `/vendor-portal`
     * without their client's path or whose session has lapsed.
     *
     * IT DOES NOT LIST THE CLIENTS. A page enumerating every bank running this
     * platform is a customer list, and it would be readable by anybody.
     */
    public function entry()
    {
        return Inertia::render('TprmPortal/Auth/Entry');
    }

    public function create(Request $request, Organization $client)
    {
        return Inertia::render('TprmPortal/Auth/Login', [
            'client' => ['name' => $client->name, 'uuid' => $client->uuid],
        ]);
    }

    public function store(Request $request, Organization $client)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->auth->attempt($client->getKey(), $validated['email'], $validated['password']);

        if ($result['user'] === null) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => $result['reason']]);
        }

        $user = $result['user'];

        /*
         * Regenerated BEFORE the pending id is written. A session fixed by an
         * attacker before the victim signs in would otherwise carry the
         * pending id into the challenge step.
         */
        $request->session()->regenerate();

        $request->session()->put(EnsurePendingPortalUser::SESSION_KEY, $user->getKey());
        $request->session()->put(EnsurePendingPortalUser::STARTED_KEY, time());
        $request->session()->put('tprm_portal_pending_organization', $user->organization_id);

        /*
         * Email accounts go straight to the challenge and the code is sent on
         * the way — there is nothing to enrol. Only a vendor who chose TOTP
         * and stopped halfway sees the enrolment screen.
         */
        if ($user->usesEmailCodes()) {
            $this->auth->sendEmailCode($user);

            return redirect()->route('tprm-portal.mfa.challenge');
        }

        return redirect()->route($user->mfa_enabled ? 'tprm-portal.mfa.challenge' : 'tprm-portal.mfa.setup');
    }

    public function destroy()
    {
        $this->auth->logout();

        return redirect()->route('tprm-portal.entry')->with('success', 'You have been signed out.');
    }
}
