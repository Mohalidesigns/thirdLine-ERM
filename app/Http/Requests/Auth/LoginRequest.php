<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Validation and the (email, source) lockout for POST /login.
 *
 * ONE MECHANISM, NOT TWO. Breeze's LoginRequest keeps its own five-per-minute
 * RateLimiter; this repository already throttles the route with
 * `throttle:login` (routes/web.php), which is the anti-spray ceiling. What
 * lives here is the OTHER control — the per-account lockout that the retired
 * AuthController carried, keyed on (email, IP) so that it cannot be used to
 * lock a named member of staff out of their own account. The reasoning is
 * reproduced in lockoutKey(); AuthenticationRateLimitTest holds it in place.
 */
class LoginRequest extends FormRequest
{
    /**
     * Failed sign-ins tolerated from one place, for one account, before that
     * pair is shut out for LOCKOUT_MINUTES.
     */
    public const LOCKOUT_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 30;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => (string) $this->input('email'),
            'password' => (string) $this->input('password'),
        ];
    }

    /**
     * Too many recent failures for this account FROM THIS SOURCE? Checked
     * before the credentials are tested, and deliberately without loading or
     * touching the user row: the answer must not differ between an account
     * that exists and one that does not, or the lockout becomes a
     * user-enumeration oracle.
     */
    public function isLockedOut(): bool
    {
        return RateLimiter::tooManyAttempts($this->lockoutKey(), self::LOCKOUT_ATTEMPTS);
    }

    /** Minutes until the lockout for this pair lifts. */
    public function lockoutMinutes(): int
    {
        return max(1, (int) ceil(RateLimiter::availableIn($this->lockoutKey()) / 60));
    }

    /**
     * Count a failure against (account, source). Recorded for EVERY failed
     * attempt, including ones naming an address with no account behind it, so
     * that the endpoint behaves identically either way.
     *
     * users.login_attempts is still incremented, but as a REPORTING signal
     * rather than the lock itself — it is what the administrator sees on the
     * user screen, and a number that climbs while the person insists they have
     * not been trying is the first evidence of an attack on that account.
     * users.locked_until is deliberately not written here; see lockoutKey().
     *
     * @return int the number of failures now recorded for the pair
     */
    public function recordFailure(?User $user): int
    {
        $failures = RateLimiter::hit($this->lockoutKey(), self::LOCKOUT_MINUTES * 60);

        if ($user !== null) {
            $user->update(['login_attempts' => $user->login_attempts + 1]);
        }

        return $failures;
    }

    /**
     * A correct password clears the counter for this pair, so a user who
     * mistyped four times and then got it right starts fresh.
     */
    public function clearFailures(): void
    {
        RateLimiter::clear($this->lockoutKey());
    }

    /**
     * The key the lockout counts against: this account, from THIS SOURCE.
     *
     * The lockout used to live entirely in users.login_attempts /
     * users.locked_until, keyed on the account alone. Five failures from
     * anywhere locked the account for thirty minutes, which made the login form
     * a TARGETED DENIAL-OF-SERVICE PRIMITIVE: anyone who knew a staff email —
     * and on this platform email addresses appear in every assessment,
     * approval and audit record — could keep a named Chief Risk Officer
     * permanently signed out with five requests every half hour, holding no
     * credential at all.
     *
     * Keying on (email, IP) removes that. An attacker can now lock only the
     * pairing of the victim's account with the attacker's own source address,
     * which does not affect the victim signing in from the office or from home.
     *
     * THE TRADE, STATED PLAINLY: an attacker with many source addresses gets
     * LOCKOUT_ATTEMPTS guesses per address rather than five in total, so this
     * is weaker against a distributed, low-and-slow attack than the old counter
     * was. That is accepted deliberately, because the old counter bought its
     * strength by handing every attacker a reliable way to disable any named
     * user. What bounds the distributed case instead is the per-IP ceiling on
     * the `login` limiter, the 12-character mixed password policy, and detection
     * of failures spread across many accounts.
     *
     * The address is hashed into the key so that no cache entry contains a
     * staff email address in clear.
     */
    public function lockoutKey(): string
    {
        return 'login-lockout:'.sha1(Str::lower(trim((string) $this->input('email'))).'|'.$this->ip());
    }
}
