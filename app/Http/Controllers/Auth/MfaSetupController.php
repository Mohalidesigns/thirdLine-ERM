<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmMfaEnrolmentRequest;
use App\Support\Auth\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Enrolment. The secret is generated here and kept in the session until the
 * user proves their authenticator has it; the otpauth:// URI is rendered as a
 * QR code in the browser (Components/QrCode.jsx). No part of the secret leaves
 * this deployment — MfaSetupTest asserts the response names no host but
 * APP_URL.
 */
class MfaSetupController extends Controller
{
    private const SESSION_SECRET = 'mfa_setup_secret';

    public function show(Request $request): Response
    {
        $user = $request->user();

        // A half-finished enrolment keeps its secret so the authenticator app
        // the user already scanned still works; a fresh one gets a new secret.
        $secret = $request->session()->get(self::SESSION_SECRET)
            ?? (($user->mfa_secret && ! $user->mfa_enabled) ? $user->mfa_secret : Totp::generateSecret());

        $request->session()->put(self::SESSION_SECRET, $secret);

        $issuer = (string) config('app.name', 'Atheris ERM');

        return Inertia::render('Auth/MfaSetup', [
            'secret' => $secret,
            'otpauthUri' => Totp::otpauthUri($issuer, $user->email, $secret),
            'issuer' => $issuer,
            'account' => $user->email,
            'alreadyEnabled' => (bool) $user->mfa_enabled,
        ]);
    }

    public function enable(ConfirmMfaEnrolmentRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $secret = $request->session()->get(self::SESSION_SECRET);

        if (! is_string($secret) || $secret === '') {
            return redirect()->route('mfa.setup')->with('error', 'Start the enrolment again — the setup key has expired.');
        }

        if (! Totp::verify($validated['code'], $secret)) {
            return back()->withErrors(['code' => 'Invalid verification code.']);
        }

        $request->user()->update([
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
        ]);

        $request->session()->forget(self::SESSION_SECRET);
        $request->session()->put([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);

        return redirect()->route('profile.edit')
            ->with('success', 'Two-factor authentication enabled successfully.');
    }
}
