# "admin@risk.test can't log in" — 419 PAGE EXPIRED

Reported with a screenshot of `127.0.0.1:8000/login` showing Laravel's bare
`419 | PAGE EXPIRED`, plus a request to check that the seeded demo accounts can
all sign in.

## The accounts were fine

Every seeded account was checked in the database and then logged in for real —
a `GET /login`, the CSRF token read off the page, a `POST /login`, and a
follow-up authenticated request — against the running server on port 8000.

| Account | Role | Login | `/risk/rcsa/universe` |
|---|---|---|---|
| `admin@risk.test` | super-admin | 302 → dashboard | 200 |
| `adebayo.ogundimu@risk.test` | risk-manager | 302 → dashboard | 200 |
| `ngozi.adekunle@risk.test` | chief-risk-officer | 302 → dashboard | 200 |
| `emeka.nwosu@risk.test` | risk-analyst | 302 → dashboard | 200 |
| `fatima.bello@risk.test` | compliance-officer | 302 → dashboard | 200 |
| `oluwaseun.adeyemi@risk.test` | loss-event-manager | 302 → dashboard | **403** |

All six carry the password `password` (`DatabaseSeeder` and `DemoDataSeeder`),
all are active, none has MFA enabled, none has a non-zero `login_attempts` or a
`locked_until`. The single 403 is correct: `loss-event-manager` holds no
`rcsa_universe.*` permission, and no `rcsa.*` permission either.

`admin@risk.test` in particular was healthy the whole time.

## So what was the 419

An expired CSRF token — which is exactly, and only, what 419 means. The login
page had been open longer than `config('session.lifetime')` (120 minutes), so
the token embedded in it no longer matched the session when the password was
finally submitted.

Nothing was broken. But the recovery was: Laravel answers a stale token with a
bare `419 | PAGE EXPIRED` — no explanation, no link, no form. On the **login**
screen that is the worst place for it, because the person is not signed in, so
there is no navigation to escape through, and the back button returns the same
expired page. The only way out is knowing to hard-refresh.

## The fix

`bootstrap/app.php` now returns a guest whose token expired to the login form,
with their email preserved and a sentence saying what happened:

> That page had been open too long and the security token expired. Please sign
> in again.

Scoped to the four guest form paths — `login`, `register`, `forgot-password`,
`reset-password`. **Deliberately not broadened**: inside the application a 419
on a form somebody has filled in must not redirect somewhere that silently
discards their work, and the blunt page at least does not pretend the
submission succeeded.

## Three ways this fix can quietly do nothing

All three were hit while building it, and each produces code that looks right
and has no effect. They are why
`tests/Feature/Auth/ExpiredTokenOnLoginTest.php` exists.

1. **`POST /login` is unnamed.** `routes/auth.php` names only the `GET` (that
   is what redirects target). A handler matching on `$request->route()->getName()`
   never fires on the one request that fails this way. It matches on the path.

2. **The exception has already been converted.**
   `Handler::render()` calls `prepareException()` — which turns a
   `TokenMismatchException` into an `HttpException(419)` — **before** it runs
   any render callback. A callback type-hinted on `TokenMismatchException` is
   unreachable. It hints `HttpException` and checks `getStatusCode() === 419`.

3. **The test harness disables CSRF, and the class was renamed.**
   `ValidateCsrfToken::handle()` short-circuits when `runningUnitTests()` is
   true, so a test that posts a bad token passes while asserting nothing.
   `withMiddleware()` does not help. The suite rebinds a subclass that declines
   to skip — and it must rebind **`ValidateCsrfToken`**, not `VerifyCsrfToken`,
   which is the Laravel 10 name still shipped as a deprecated alias. Binding
   the alias has no effect whatsoever.

The first two were caught by posting a stale token at a running server rather
than by reading the code back; the third by noticing that an in-application POST
was answering 403 (the permission middleware) instead of 419, which meant CSRF
had never run.
