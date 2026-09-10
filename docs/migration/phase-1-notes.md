# Phase 1 — Auth pages, MFA fix, platform shell: implementation notes

Branch `migration/phase-1-auth-shell`, on `b511d61` (Phase 0). Prompt:
`05-phase-prompts/phase-1-auth-shell.md`.

## What landed

| Area | Delivered |
|---|---|
| Auth controllers | `AuthController` (680 lines) deleted. Breeze-shaped replacements under `app/Http/Controllers/Auth/`: `AuthenticatedSessionController`, `PasswordResetLinkController`, `NewPasswordController`, `PasswordController`, `MfaSetupController`, `MfaVerifyController`. `app/Http/Requests/Auth/LoginRequest` carries validation and the (email, IP) lockout; `throttle:login` stays the only rate limiter. |
| Routes | `routes/auth.php`, required from `web.php`; every route name unchanged. New: `notifications.unread-count` (JSON, `permission:notification.view`), `profile.edit` / `profile.update` / `profile.password` (`permission:dashboard.view`). |
| MFA fix | `app/Support/Auth/Totp.php`: eight-byte big-endian counter (`pack('J')`), RFC 6238 vectors in `tests/Unit/Auth/TotpTest`. Login checks an enrolled user's password with `Auth::validate` and holds them pending; `MfaVerifyController` calls `Auth::login` + `session()->regenerate()` on the right code. Nothing is logged out on the way in (`MfaLoginFlowTest` fakes the `Logout` event and asserts it never fires). QR code drawn in the browser by `Components/QrCode.jsx` (`qrcode` npm) from a server-generated `otpauth://` URI; `MfaSetupTest` asserts no host but `APP_URL` appears in the response. Session keys keep their existing names (`mfa_pending_user_id`, `mfa_verified`) because the limiter, `EnsureMfaVerified`, `SsoController` and the existing tests read them. |
| Pages | `Pages/Auth/{Login,ForgotPassword,ResetPassword,MfaSetup,MfaVerify}.jsx`, `Pages/Profile/Edit.jsx` (+ two partials), `Pages/Notifications/Index.jsx`, `Pages/Search/Index.jsx`; `Layouts/GuestLayout.jsx` rewritten with this product's branding. Blade `auth/*`, `notifications/index`, `search/index` deleted. |
| Topbar | `Components/SearchBox.jsx` (type-ahead over `search.suggest`, Enter opens the results page), `Components/PeriodSelector.jsx` (rewritten — ThirdLine's is a date-range picker for a different concept; this one drives the existing `risk.periods.select` route), bell polls `notifications.unread-count` every 30 s, user menu gains Profile and (flag on) 2FA Setup. |
| Cross-renderer links | `app/Support/Migration/Ported.php` lists the routes that render through Inertia. `NavPresenter` marks each entry `inertia`; the React layout renders `<Link>` only for those and a plain `<a>` otherwise. The Blade sidebar drops `wire:navigate` for ported paths. Login, SSO discovery and the two MFA forms post natively (`lib/nativeForm.jsx`) because their success redirect lands on a Blade page; validation errors and old input still arrive as Inertia props (`old` is now shared). |

## Deviations from the prompt, with reasons

- **`FEATURE_MFA_TOTP` default stays `false`.** The prompt says to flip it once `MfaLoginFlowTest` is green, but `MfaFeatureGateTest` (which the prompt also requires green and unchanged) asserts the default is off, and the config's own precondition list still has an unmet item: there is no self-service recovery (no backup codes). `.env.example` documents the key; the `config/features.php` comment now describes the rebuilt flow and the one remaining reason to leave it off.
- **`ConfirmablePasswordController` not added.** Nothing uses `password.confirm` middleware; it would be an unguarded route that `RouteAuthorizationTest` would have to allow-list for no consumer.
- **`MfaFeatureGateTest`** — one assertion adapted: `assertSee('Manual Entry')` against Blade markup became an `assertInertia` check that the enrolment page carries the secret. Everything else in the class is unchanged.
- **`AdminNavigationTest`** reads the Blade sidebar from `/risk/dashboard` (`dashboard.view`) now that `/notifications` is an Inertia page.
- **Profile**: email is read-only (it is the SSO/SCIM identity), and there is no "delete account" form — accounts on this platform are administered, not self-deleted. ThirdLine's `DeleteUserForm` was not copied.
- **Password reset** keeps the product's cache-token mechanism rather than switching to Laravel's password broker, so deployed reset links and mail wording do not change; the reset now also purges the user's other database sessions (from ThirdLine).
- **Phase 0 defect fixed here**: Phase 0's layout rendered every sidebar entry as an Inertia `<Link>`, which breaks the moment one is clicked into a Blade page. The `Ported` registry above is the fix; `AuthPagesTest` pins it.

## Verification

See the bottom of this file once the suite has run.

- `OrganizationSsoSettingsTest`: one assertion adapted from Blade copy ("Continue with single sign-on") to the `ssoAvailable` page prop.
- `Components/Dropdown.jsx`: the copied component's Headless UI `<Transition>` never reached its end state on this build (Headless UI 2.2 + Tailwind 4), leaving the user menu at opacity 0. The menu now renders without a transition. `Modal.jsx` uses the same pattern and has not been exercised yet — check it when Phase 2 first mounts a modal.

## Verification (2026-09-03)

- `php artisan test`: 1260 passed, 3 skipped, 0 failed after the SSO assertion adaptation.
- `vendor/bin/phpstan analyse`: no errors; baseline shrank from 822 to 819 (the deleted controller's entries).
- `npm run build`: clean.
- Browser: `/login` (guest), `/profile`, `/notifications`, `/search?q=risk` render through Inertia; the user menu opens; Log Out returns to the login page.
