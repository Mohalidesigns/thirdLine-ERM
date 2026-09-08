<?php

namespace App\Http\Controllers\TprmPortal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Tprm\EnsurePendingPortalUser;
use App\Models\Organization;
use App\Models\Tprm\PortalUser;
use App\Services\Tprm\Portal\PortalAuthService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Mandatory MFA on the vendor portal — FR-PRT-01.
 *
 * Every action here runs in the half-authenticated state: the password has
 * been accepted and no session exists on the portal guard. `EnsurePendingPortalUser`
 * put the account on the request; nothing here trusts an id from the client.
 *
 * TWO METHODS. An emailed code is the default and needs no enrolment; TOTP is
 * an upgrade a vendor can choose. `setup` and `enrol` only ever concern TOTP —
 * there is nothing to set up for email, which is exactly why it is the
 * default.
 *
 * THE ISSUER SHOWN IN THE AUTHENTICATOR IS THE CLIENT'S NAME, not ours. A
 * vendor answering questionnaires for four banks needs four entries they can
 * tell apart, and "Atheris" four times over is useless to them — as well as
 * telling each of those vendors which platform each bank runs.
 */
class MfaController extends Controller
{
    public function __construct(private readonly PortalAuthService $auth) {}

    public function setup(Request $request)
    {
        $user = $this->pending($request);

        if ($user->usesEmailCodes() || $user->mfa_enabled) {
            return redirect()->route('tprm-portal.mfa.challenge');
        }

        $enrolment = $this->auth->beginMfaEnrolment($user, $this->issuer($user));

        return Inertia::render('TprmPortal/Auth/MfaSetup', [
            'secret' => $enrolment['secret'],
            'uri' => $enrolment['uri'],
            'email' => $user->email,
        ]);
    }

    public function enrol(Request $request)
    {
        $user = $this->pending($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        if (! $this->auth->confirmMfaEnrolment($user, $validated['code'])) {
            return back()->withErrors([
                'code' => 'That code did not match. Check your device\'s clock is correct and try the current code.',
            ]);
        }

        // Enrolled and challenged in one act: the code just verified IS the
        // challenge. Asking for a second one immediately teaches people the
        // step is ceremonial.
        if (! $this->auth->completeMfa($user->refresh(), $validated['code'])) {
            return redirect()->route('tprm-portal.mfa.challenge');
        }

        $this->clearPending($request);

        return redirect()->intended(route('tprm-portal.dashboard'));
    }

    public function challenge(Request $request)
    {
        $user = $this->pending($request);

        if (! $user->hasSecondFactor()) {
            return redirect()->route('tprm-portal.mfa.setup');
        }

        /*
         * A code is issued on the way IN to the challenge only if none is
         * live. Re-issuing on every render would mean a browser refresh
         * silently invalidated the code the vendor is halfway through typing.
         */
        if ($user->usesEmailCodes() && ! $user->hasLiveCode()) {
            $this->auth->sendEmailCode($user);
            $user->refresh();
        }

        return Inertia::render('TprmPortal/Auth/MfaChallenge', [
            'email' => $user->email,
            'method' => $user->mfa_method,
            // Masked. The vendor needs to recognise the address; anybody
            // shoulder-surfing the screen does not need to read it.
            'sent_to' => $user->usesEmailCodes() ? $this->maskEmail($user->email) : null,
            'resend_in' => $user->secondsUntilResend(),
            'expires_in_minutes' => PortalUser::CODE_TTL_MINUTES,
        ]);
    }

    /** Ask for another code — FR-PRT-01, throttled in the model. */
    public function resend(Request $request)
    {
        $user = $this->pending($request);

        if (! $user->usesEmailCodes()) {
            return back();
        }

        $result = $this->auth->sendEmailCode($user);

        return $result['sent']
            ? back()->with('success', 'A new code is on its way.')
            : back()->withErrors(['code' => $result['reason']]);
    }

    /**
     * Show enough of the address to be recognised and not enough to be
     * harvested from a screenshot.
     */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $visible.str_repeat('•', max(3, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }

    public function verify(Request $request)
    {
        $user = $this->pending($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
            'remember' => ['boolean'],
        ]);

        if (! $this->auth->completeMfa($user, $validated['code'], (bool) ($validated['remember'] ?? false))) {
            $user->refresh();

            return back()->withErrors([
                'code' => match (true) {
                    $user->isLocked() => 'Too many failed codes. This account is locked for a short while.',
                    $user->usesEmailCodes() && ! $user->hasLiveCode() => 'That code has expired or been used. '
                        .'Ask for a new one.',
                    $user->usesEmailCodes() => 'That code did not match. Check the most recent email — an older '
                        .'code stops working as soon as a new one is sent.',
                    default => 'That code did not match. Try the current one from your authenticator.',
                },
            ]);
        }

        $this->clearPending($request);

        return redirect()->intended(route('tprm-portal.dashboard'));
    }

    /** Abandon a half-finished sign-in, e.g. on a shared machine. */
    public function abandon(Request $request)
    {
        $this->clearPending($request);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tprm-portal.entry');
    }

    private function pending(Request $request): PortalUser
    {
        /** @var PortalUser $user */
        $user = $request->attributes->get('portalPendingUser');

        return $user;
    }

    private function clearPending(Request $request): void
    {
        $request->session()->forget([
            EnsurePendingPortalUser::SESSION_KEY,
            EnsurePendingPortalUser::STARTED_KEY,
            'tprm_portal_pending_organization',
        ]);
    }

    private function issuer(PortalUser $user): string
    {
        return Organization::query()->find($user->organization_id)->name ?? config('app.name', 'Vendor Portal');
    }
}
