<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureMfaVerified;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\OrganizationSsoSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign in and sign out. Split out of the retired AuthController (migration
 * Phase 1) in Breeze's shape; the lockout semantics are unchanged and live in
 * LoginRequest.
 *
 * THE MFA FIX. The old flow called Auth::logout() after a correct password and
 * sent the user to mfa.verify, which never called Auth::login() again — every
 * enrolled user was locked out permanently. Now an enrolled user's password is
 * CHECKED (Auth::validate) but the session is not established; the pending
 * user id is held in the session and MfaVerifyController logs them in once the
 * second factor is right. Nothing is ever logged out on the way in.
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * The sign-in screen.
     */
    public function create(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/risk/dashboard');
        }

        // Only offer single sign-on if some organization has actually
        // configured it — an inert button that always fails is worse than
        // none. Unscoped by necessity: nobody is authenticated on this page.
        $ssoAvailable = (bool) config('sso.enabled')
            && OrganizationSsoSetting::query()->withoutGlobalScopes()->where('enabled', true)->exists();

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'ssoAvailable' => $ssoAvailable,
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->credentials();

        if ($request->isLockedOut()) {
            return $this->refuse($request, $this->lockoutMessage($request));
        }

        $user = User::query()->where('email', $credentials['email'])->first();

        // An ADMINISTRATIVE lock still applies. Nothing in this class writes
        // users.locked_until, but the column and the admin screens that read
        // it stay: an account an administrator has locked must not sign in
        // from anywhere.
        if ($user && $user->locked_until && Carbon::parse($user->locked_until)->isFuture()) {
            return $this->refuse($request, 'Account is temporarily locked. Try again later.');
        }

        if ($user && ! $user->is_active) {
            return $this->refuse($request, 'This account has been deactivated.');
        }

        // An enrolled user is not signed in by their password alone. The
        // credentials are checked without establishing the session; the
        // second factor completes sign-in in MfaVerifyController.
        $secondFactorPending = EnsureMfaVerified::featureEnabled() && $user?->mfa_enabled;

        $ok = $secondFactorPending
            ? Auth::guard('web')->validate($credentials)
            : Auth::guard('web')->attempt($credentials, $request->boolean('remember'));

        if (! $ok) {
            $failures = $request->recordFailure($user);

            if ($failures >= LoginRequest::LOCKOUT_ATTEMPTS) {
                return $this->refuse($request, $this->lockoutMessage($request));
            }

            return $this->refuse($request, 'The provided credentials do not match our records.');
        }

        $request->clearFailures();
        $request->session()->regenerate();

        if ($secondFactorPending) {
            $request->session()->put([
                'mfa_pending_user_id' => $user->id,
                'mfa_pending_remember' => $request->boolean('remember'),
                'mfa_pending_at' => now()->timestamp,
            ]);
            $request->session()->forget('mfa_verified');

            return redirect()->route('mfa.verify');
        }

        $user = Auth::user();

        $user->update([
            'login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_activity_at' => now(),
        ]);

        // The organization can require MFA for particular roles
        // (organizations.settings->mfa_required_roles). Someone in one of those
        // roles who has not enrolled is sent to enrol now rather than being let
        // through with the requirement unmet.
        if (EnsureMfaVerified::featureEnabled() && self::mfaRequiredFor($user)) {
            return redirect()->route('mfa.setup')
                ->with('warning', 'Your role requires multi-factor authentication. Set it up to continue.');
        }

        if ($user->must_change_password) {
            Auth::logout();

            return redirect()->route('password.request')
                ->with('warning', 'You must change your password before proceeding.');
        }

        return redirect()->intended('/risk/dashboard');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /**
     * Whether this user's organization requires MFA for one of their roles.
     * Configured per tenant in organizations.settings->mfa_required_roles, so
     * a bank can mandate a second factor for its CRO and administrators
     * without forcing it on every read-only board member.
     */
    public static function mfaRequiredFor(User $user): bool
    {
        $required = (array) ($user->organization?->settings['mfa_required_roles'] ?? []);

        return $required !== [] && $user->hasAnyRole($required);
    }

    private function lockoutMessage(LoginRequest $request): string
    {
        $minutes = $request->lockoutMinutes();

        return "Too many failed sign-in attempts from this device. Try again in {$minutes} minute(s), or ask an administrator to reset your password.";
    }

    private function refuse(LoginRequest $request, string $message): RedirectResponse
    {
        return back()->withErrors(['email' => $message])->withInput($request->only('email'));
    }
}
