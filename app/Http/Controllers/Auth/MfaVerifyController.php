<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second factor, mid sign-in.
 *
 * Two callers reach this screen: a password-verified user whose session holds
 * `mfa_pending_user_id` (AuthenticatedSessionController), and an
 * already-authenticated user whose session is not yet marked verified — a
 * federated user, or one whose EnsureMfaVerified check fired. Both complete
 * here; only the first still needs Auth::login().
 *
 * Session keys keep the names the rest of the codebase reads
 * (`mfa_pending_user_id` for the mfa-verify limiter, `mfa_verified` for
 * EnsureMfaVerified and SsoController).
 */
class MfaVerifyController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $this->subject($request);

        if ($user === null) {
            return redirect('/login');
        }

        if (Auth::check() && $request->session()->get('mfa_verified')) {
            return redirect()->intended('/risk/dashboard');
        }

        return Inertia::render('Auth/MfaVerify', [
            'account' => $user->email,
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $this->subject($request);

        if ($user === null) {
            return redirect('/login');
        }

        // A pending user with no secret can happen if an administrator cleared
        // it between the password and the code. Refuse cleanly rather than
        // raising a TypeError on an unauthenticated endpoint.
        if (empty($user->mfa_secret) || ! Totp::verify($validated['code'], $user->mfa_secret)) {
            return back()->withErrors(['code' => 'Invalid verification code.']);
        }

        if (! Auth::check()) {
            Auth::guard('web')->login($user, (bool) $request->session()->get('mfa_pending_remember', false));

            $user->update([
                'login_attempts' => 0,
                'locked_until' => null,
                'last_login_at' => now(),
                'last_activity_at' => now(),
            ]);
        }

        $request->session()->regenerate();
        $request->session()->forget(['mfa_pending_user_id', 'mfa_pending_remember', 'mfa_pending_at']);
        $request->session()->put([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);

        return redirect()->intended('/risk/dashboard');
    }

    private function subject(Request $request): ?User
    {
        $pendingId = $request->session()->get('mfa_pending_user_id');

        if ($pendingId !== null) {
            return User::query()->find($pendingId);
        }

        // An already-authenticated session only has something to verify if
        // the account is enrolled; anyone else has no business here.
        $user = $request->user();

        return $user?->mfa_enabled ? $user : null;
    }
}
