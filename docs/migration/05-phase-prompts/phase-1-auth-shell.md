# Phase 1 — Auth pages, MFA fix, platform shell

> Claude Code implementation prompt. Branch `migration/phase-1-auth-shell`, based on Phase 0. Pattern source: `../thridLine/internalaudit/` (`routes/auth.php`, `app/Http/Controllers/Auth/*`, `resources/js/Pages/Auth/*`, `Pages/Profile/*`, `Pages/Notifications/*`).

## Context

`app/Http/Controllers/Auth/AuthController.php` (680 lines) handles login, logout, registration, password reset, MFA setup/verify and lockout in one class, rendering `resources/views/auth/*.blade.php`. MFA is feature-flagged off (`config/features.php mfa_totp`) because it is broken in three documented ways (`app/Http/Middleware/EnsureMfaVerified.php:17-49`): login logs the user out before redirecting to MFA and `verifyMfa` never logs them back in; the TOTP counter is packed with `pack('N')` (4 bytes) instead of 8; the QR code is fetched from a third-party URL, leaking the secret. Since every auth page is being rebuilt, fix these now. All rate limiters in `routes/web.php:86-200` and the SSO flow (`Auth/SsoController`, `app/Support/Sso/*`) are correct and must be preserved unchanged.

## Scope

### 1.1 Split `AuthController` into Breeze-shaped controllers (`app/Http/Controllers/Auth/`)
`AuthenticatedSessionController` (create/store/destroy), `PasswordResetLinkController`, `NewPasswordController`, `PasswordController` (update), `MfaSetupController` (show/enable), `MfaVerifyController` (show/verify), `ConfirmablePasswordController`. Move login validation into `app/Http/Requests/Auth/LoginRequest.php` (from ThirdLine, keeping this repo's `throttle:login` limiter rather than Breeze's `RateLimiter` in the request — one mechanism, not two). Delete `AuthController` and `auth/register.blade.php` (admin user creation lives in `Admin/UserManagementController`; the hard-coded role checkboxes at `register.blade.php:143-150` go away).

### 1.2 MFA fix (backend)
- `store()` on login: when the user has MFA enabled, **do not** call `Auth::logout()`; set `session('mfa.pending_user_id')` and `session('mfa.authenticated_at')`, redirect to `mfa.verify`. `MfaVerifyController@verify` must call `Auth::login($user, $remember)` and `session()->regenerate()` on success; `EnsureMfaVerified` checks `session('mfa.verified_at')`.
- TOTP: `pack('J', $counter)` (8-byte big-endian) per RFC 6238; add `tests/Unit/Auth/TotpTest.php` with the RFC 6238 test vectors (SHA-1, 30 s step, secrets `12345678901234567890`).
- QR: render locally — generate the `otpauth://` URI server-side and draw it in React with an inline SVG QR component (add `qrcode` npm package, render to SVG string; no external host). Assert no outbound URL in `MfaSetupController`.
- Keep the feature flag; flip `FEATURE_MFA_TOTP` default to `true` only after `MfaLoginFlowTest` is green, and add the key to `.env.example`.

### 1.3 Pages (`resources/js/Pages/Auth/`)
`Login.jsx`, `ForgotPassword.jsx`, `ResetPassword.jsx`, `MfaSetup.jsx`, `MfaVerify.jsx`, `ConfirmPassword.jsx` — copy ThirdLine's `Pages/Auth/*` shape (`GuestLayout`, `useForm`, `TextInput`, `InputError`, `PrimaryButton`). `Login.jsx` also shows the SSO "Continue with your organisation" entry (`sso.discover`) when `config('sso.enabled')` is shared as `features.sso`.

### 1.4 Platform shell
- `Pages/Profile/Edit.jsx` (+ `Partials/`) from ThirdLine, backed by `ProfileController` + `ProfileUpdateRequest`.
- `GET /` landing: keep the role-shaped redirect logic (`routes/web.php:219`) — no page.
- `Pages/Notifications/Index.jsx`; `NotificationController` returns Inertia; mark-read actions become `router.post`. Delete `resources/views/notifications/*`.
- Global search: `Pages/Search/Index.jsx`; `GlobalSearchController@suggest` stays JSON (the topbar's typeahead calls it with axios — port the Alpine in `layouts/partials/topbar.blade.php:55` into a `SearchBox` component in `resources/js/Components/`).
- Period selector: `PeriodSelector.jsx` exists in ThirdLine's components — wire it to `period` shared prop and `POST risk/periods/select` (existing route in `PeriodController`); tenant chip reads `tenant`.
- Topbar bell reads `unreadNotifications`; poll `notifications.unread-count` every 30 s as ThirdLine does (add that JSON route under `auth` + `permission:notification.view`).

## Files
`routes/web.php` (auth section only), `routes/auth.php` (new, `require`d from web.php, mirrors ThirdLine's), `app/Http/Controllers/Auth/*`, `app/Http/Controllers/{ProfileController,NotificationController,Risk/GlobalSearchController}.php`, `app/Http/Requests/{Auth/LoginRequest,ProfileUpdateRequest}.php`, `app/Http/Middleware/EnsureMfaVerified.php`, `app/Support/Auth/Totp.php` (extract from AuthController), `resources/js/Pages/{Auth,Profile,Notifications,Search}/**`, `resources/js/Components/{SearchBox,QrCode}.jsx`, `resources/js/Layouts/AuthenticatedLayout.jsx` (topbar), delete `resources/views/{auth,notifications,search,my}/**` and `layouts/partials/topbar.blade.php` only if no Blade page still includes it (it does until Phase 6 — leave it, mark deprecated).

## Acceptance criteria
1. Existing: `AuthenticationRateLimitTest`, `MfaEnforcementTest`, `MfaFeatureGateTest`, `SsoProvisioningTest`, `ScimProvisioningTest`, `SecurityConfigurationTest` green unchanged.
2. New `tests/Feature/Auth/MfaLoginFlowTest.php`: login with MFA-enabled user → redirected to `mfa.verify` while still holding a pending session → correct code logs in (`assertAuthenticatedAs`) → wrong code 5× hits `throttle:mfa-verify`; session regenerated on success; `Auth::logout` never called before verification (spy on the guard).
3. `tests/Unit/Auth/TotpTest.php` passes RFC 6238 vectors.
4. `tests/Feature/Auth/MfaSetupTest.php` asserts the setup response contains an `otpauth://` URI and **no** `http(s)://` host other than `APP_URL`.
5. `assertInertia` tests for every new page (component name + required props); `NavigationPermissionGateTest` still green (new `notification.view` JSON route is not a nav item).
6. `grep -rn "AuthController" app routes` returns nothing; `resources/views/auth` no longer exists.
7. `RouteAuthorizationTest` green — every new route carries `permission:` or is in the public allowlist (login, password reset, mfa verify, sso).
