<?php

namespace App\Http\Controllers\TprmPortal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Tprm\EnsurePendingPortalUser;
use App\Models\Organization;
use App\Services\Tprm\Portal\PortalAuthService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use RuntimeException;

/**
 * Accepting an invitation — FR-PRT-01.
 *
 * The token in the URL is the only credential, so it is exchanged for a row
 * and never echoed back into a form field: the acceptance POST carries it in
 * the path too, and nothing renders it into the page body where a referrer or
 * a screenshot could carry it away.
 *
 * A REFUSED INVITATION STILL RENDERS A PAGE, with the reason. Expired, already
 * used and withdrawn need three different next steps from the vendor, and a
 * flat 404 sends all three to the same dead end — usually an email to the
 * wrong person at the bank.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly PortalAuthService $auth) {}

    public function show(string $token)
    {
        $invitation = $this->auth->findInvitation($token);

        if ($invitation === null) {
            return Inertia::render('TprmPortal/Auth/InvitationRefused', [
                'reason' => 'This invitation link is not recognised. It may have been mistyped — check the link in '
                    .'your email, or ask your contact to reissue it.',
            ]);
        }

        $reason = $invitation->refusalReason();

        if ($reason !== null) {
            return Inertia::render('TprmPortal/Auth/InvitationRefused', ['reason' => $reason]);
        }

        $invitation->loadMissing('thirdParty');

        return Inertia::render('TprmPortal/Auth/InvitationAccept', [
            'token' => $token,
            'email' => $invitation->email,
            'vendor' => $invitation->thirdParty?->legal_name,
            'client' => Organization::query()->find($invitation->organization_id)?->name,
            'expires_at' => $invitation->expires_at->toDayDateTimeString(),
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $invitation = $this->auth->findInvitation($token);

        if ($invitation === null || $invitation->refusalReason() !== null) {
            return Inertia::render('TprmPortal/Auth/InvitationRefused', [
                'reason' => $invitation?->refusalReason()
                    ?? 'This invitation link is not recognised.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()->symbols()->uncompromised()],
        ], [
            'password.uncompromised' => 'That password has appeared in a public breach. Choose one you have not '
                .'used anywhere else — this account reaches your organisation\'s security evidence.',
        ]);

        try {
            $user = $this->auth->accept($invitation, $validated['name'], $validated['password']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()]);
        }

        /*
         * Straight into enrolment, in the same half-authenticated state a
         * password sign-in produces. The account exists and cannot do anything
         * until it has MFA, so there is no window in which a fresh account is
         * usable without it.
         */
        $request->session()->regenerate();
        $request->session()->put(EnsurePendingPortalUser::SESSION_KEY, $user->getKey());
        $request->session()->put(EnsurePendingPortalUser::STARTED_KEY, time());
        $request->session()->put('tprm_portal_pending_organization', $user->organization_id);

        return redirect()->route('tprm-portal.mfa.setup');
    }
}
