<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Flags gate surfaces that are not yet fit to be seen by a customer. A flag
    | defaults to FALSE so that a fresh environment — including production —
    | never exposes the surface by accident. Turning one on is a deliberate act
    | recorded in that environment's .env file.
    |
    | Routes are gated with the `feature:<key>` middleware, which aborts 404
    | (not 403) when the flag is off: a disabled surface should be
    | indistinguishable from one that does not exist.
    |
    */

    /*
     * AI Intelligence (Predictive / Radar / Regulatory Pulse).
     *
     * These screens previously generated user-facing figures with mt_rand() and
     * hardcoded model-performance metrics. They have since been rebuilt on the
     * organisation's own data, but the flag stays default-off so that each
     * environment opts in only once someone has looked at the output and
     * confirmed the underlying tables are populated enough for it to be
     * meaningful. Every number these screens now show is traceable to a query —
     * see docs/ai-number-provenance.md.
     */
    'ai_intelligence' => env('FEATURE_AI_INTELLIGENCE', false),

    /*
     * TOTP multi-factor authentication (enrolment, verification, enforcement).
     *
     * OFF BY DEFAULT BECAUSE THE IMPLEMENTATION IS BROKEN, NOT BECAUSE THE
     * CONTROL IS UNWANTED. Three separate defects, each sufficient on its own:
     *
     *   1. Sign-in cannot complete. AuthController::login() calls Auth::logout()
     *      before redirecting to mfa.verify, and verifyMfa() sets
     *      session('mfa_verified') without ever calling Auth::login(). A user
     *      with mfa_enabled = true is therefore locked out permanently — there
     *      is no code path that returns them to an authenticated session.
     *
     *   2. The TOTP codes are not TOTP. AuthController::verifyTotpCode() packs
     *      the time counter with pack('N', $time), which is FOUR bytes. RFC 6238
     *      requires an eight-byte big-endian counter, so the HMAC is computed
     *      over the wrong message and no authenticator app can ever produce a
     *      code this function accepts.
     *
     *   3. The shared secret is disclosed to a third party. The setup screen
     *      built its QR code with https://api.qrserver.com/..., which means the
     *      enrolling user's browser hands the TOTP seed AND their email address
     *      to an external service on every enrolment. For a platform sold to
     *      Nigerian banks that is an unreviewed cross-border disclosure of an
     *      authentication credential.
     *
     * DEFERRED, NOT FORGOTTEN. The product owner has scheduled the rebuild for
     * deployment readiness. None of the MFA code has been deleted — it is being
     * rebuilt, and the gate is what keeps the broken path unreachable until
     * then. While the flag is off, MFA is inert: the routes 404, the enrolment
     * entry point does not render, EnsureMfaVerified passes everyone through,
     * and organizations.settings->mfa_required_roles forces nobody into a flow
     * they cannot complete.
     *
     * BEFORE THIS FLAG IS TURNED ON, ALL OF THE FOLLOWING MUST BE TRUE:
     *   - verifyMfa() re-establishes the authenticated session (Auth::login),
     *     and a user with mfa_enabled = true can sign in end to end.
     *   - The counter is packed as eight bytes and verified against a
     *     known-answer vector from RFC 6238 Appendix B, in a test.
     *   - The QR code is rendered locally (an SVG/PNG generated in-process);
     *     no secret, label or issuer leaves this deployment.
     *   - Recovery exists: backup codes, or an audited administrator reset.
     *     The setup screen already promises backup codes it never issues, so
     *     today a lost authenticator is an unrecoverable lockout.
     *   - Verification attempts are counted and limited (routes/web.php already
     *     throttles mfa/verify; the controller should record the failures too).
     *   - verifyMfa() stops passing a null secret into verifyTotpCode(). A
     *     FOURTH defect, found while building the gate: the parameter is typed
     *     `string`, and a pending user with mfa_secret = null makes
     *     `POST mfa/verify` raise a TypeError — a 500 on an unauthenticated
     *     endpoint. Not exploitable beyond the error itself while the flag is
     *     off, because the route 404s.
     *   - MfaEnforcementTest and MfaFeatureGateTest both pass with
     *     FEATURE_MFA_TOTP=true.
     */
    'mfa_totp' => env('FEATURE_MFA_TOTP', false),

];
