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
     * REBUILT in migration Phase 1 (app/Support/Auth/Totp.php,
     * app/Http/Controllers/Auth/Mfa*Controller.php). The three defects that
     * put the flow behind this flag are fixed and each is held in place by a
     * test:
     *
     *   1. Sign-in completes. An enrolled user's password is checked without
     *      establishing the session; MfaVerifyController logs them in once the
     *      code is right. Nothing is logged out on the way in
     *      (tests/Feature/Auth/MfaLoginFlowTest).
     *   2. The codes are RFC 6238 TOTP: eight-byte big-endian counter, verified
     *      against the RFC's Appendix B vectors (tests/Unit/Auth/TotpTest).
     *   3. The QR code is drawn in the browser from an otpauth:// URI this
     *      server generates; no secret leaves the deployment
     *      (tests/Feature/Auth/MfaSetupTest).
     *
     * STILL OFF BY DEFAULT, for one remaining reason: there is no self-service
     * recovery. A user who loses their authenticator needs an administrator to
     * clear mfa_secret / mfa_enabled on their record (Administration → User
     * Management), and backup codes are not issued. Turn the flag on per
     * environment once that operating procedure is in place. MfaFeatureGateTest
     * asserts the default; MfaEnforcementTest asserts the flag-on behaviour.
     */
    'mfa_totp' => env('FEATURE_MFA_TOTP', false),

    /*
     * The rewritten RCSA module (RCSA v2).
     *
     * The existing RCSA — four screens computed over Risk, Control and
     * RiskControlMapping — stays live and untouched behind this flag being off.
     * The rewrite is built alongside it on its own `rcsa_` tables, per §13 of
     * the implementation plan: build alongside, not on top. Nothing redirects,
     * nothing is deleted and no legacy table is dropped until a tenant has
     * completed a parallel run and signed off the reconciliation.
     *
     * OFF BY DEFAULT UNTIL THE MODULE IS COMPLETE. Turning it on exposes the
     * new universe, workspace and export surfaces alongside the old ones, which
     * is the intended state during the parallel run and a confusing one before
     * it. The flag is per environment; cutover is per tenant and is a separate
     * decision recorded against the tenant, not this file.
     */
    'rcsa_v2' => env('FEATURE_RCSA_V2', false),

];
