<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureMfaVerified;
use App\Models\BusinessUnit;
use App\Models\OrganizationSsoSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Failed sign-ins tolerated from one place, for one account, before that
     * pair is shut out for LOCKOUT_MINUTES.
     */
    private const LOCKOUT_ATTEMPTS = 5;

    private const LOCKOUT_MINUTES = 30;

    /**
     * Password policy rules
     */
    private function getPasswordRules(): array
    {
        return [
            'password' => [
                'required',
                'confirmed',
                Password::min(12)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ];
    }

    /**
     * Show login form
     */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect('/risk/dashboard');
        }

        // Only offer single sign-on if some organization has actually
        // configured it — an inert button that always fails is worse than none.
        // Unscoped by necessity: nobody is authenticated on this page.
        $ssoAvailable = config('sso.enabled')
            && OrganizationSsoSetting::query()->withoutGlobalScopes()->where('enabled', true)->exists();

        return view('auth.login', compact('ssoAvailable'));
    }

    /**
     * The key the lockout counts against: this account, from THIS SOURCE.
     *
     * PREVIOUS BEHAVIOUR — AND WHY IT CHANGED. The lockout used to live entirely
     * in users.login_attempts / users.locked_until, keyed on the account alone.
     * Five failures from anywhere locked the account for thirty minutes, which
     * made the login form a TARGETED DENIAL-OF-SERVICE PRIMITIVE: anyone who
     * knew a staff email — and on this platform email addresses appear in every
     * assessment, approval and audit record — could keep a named Chief Risk
     * Officer permanently signed out with five requests every half hour, from a
     * single script, without ever holding a credential. There was no way for the
     * victim to sign in and no signal to distinguish it from a forgotten
     * password.
     *
     * Keying on (email, IP) removes that. An attacker can now lock only the
     * pairing of the victim's account with the attacker's own source address,
     * which does not affect the victim signing in from the office or from home.
     *
     * THE TRADE, STATED PLAINLY: an attacker with many source addresses gets
     * LOCKOUT_ATTEMPTS guesses per address rather than five in total, so this is
     * weaker against a distributed, low-and-slow attack than the old counter
     * was. That is accepted deliberately, because the old counter bought its
     * strength by handing every attacker a reliable way to disable any named
     * user. What bounds the distributed case instead is the per-IP ceiling on
     * the `login` limiter in routes/web.php, the 12-character mixed password
     * policy above, and detection of failures spread across many accounts.
     *
     * The address is hashed into the key so that no cache entry contains a staff
     * email address in clear.
     */
    private function lockoutKey(Request $request, string $email): string
    {
        return 'login-lockout:'.sha1(Str::lower(trim($email)).'|'.$request->ip());
    }

    /**
     * Handle login
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        $lockoutKey = $this->lockoutKey($request, $credentials['email']);

        // Too many recent failures for this account FROM THIS SOURCE. Checked
        // before the credentials are tested, and deliberately without loading
        // or touching the user row: the answer must not differ between an
        // account that exists and one that does not, or the lockout becomes a
        // user-enumeration oracle.
        if (RateLimiter::tooManyAttempts($lockoutKey, self::LOCKOUT_ATTEMPTS)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($lockoutKey) / 60));

            return back()->withErrors([
                'email' => "Too many failed sign-in attempts from this device. Try again in {$minutes} minute(s), or ask an administrator to reset your password.",
            ])->withInput($request->only('email'));
        }

        // An ADMINISTRATIVE lock still applies. Nothing in this method writes
        // users.locked_until any more, but the column and the admin screens that
        // read it stay: an account an administrator has locked must not sign in
        // from anywhere.
        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            return back()->withErrors([
                'email' => 'Account is temporarily locked. Try again later.',
            ])->withInput($request->only('email'));
        }

        // Check if user is active
        if ($user && ! $user->is_active) {
            return back()->withErrors([
                'email' => 'This account has been deactivated.',
            ])->withInput($request->only('email'));
        }

        // Try authentication
        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $authenticatedUser = Auth::user();

            // Reset login attempts on success
            $authenticatedUser->update([
                'login_attempts' => 0,
                'locked_until' => null,
                'last_login_at' => now(),
                'last_activity_at' => now(),
            ]);

            // A correct password clears the counter for this pair, so a user who
            // mistyped four times and then got it right starts fresh.
            RateLimiter::clear($lockoutKey);

            $request->session()->regenerate();

            /*
             * MFA ENTRY POINT — GATED ON features.mfa_totp, DEFAULT OFF.
             *
             * Both branches below are unreachable while the flag is off, and
             * that is deliberate. The flow they lead into is broken in three
             * specific ways:
             *
             *   1. SIGN-IN CANNOT COMPLETE. The first branch calls
             *      Auth::logout() and hands off to mfa.verify; verifyMfa()
             *      below sets session('mfa_verified') and never calls
             *      Auth::login(). Nothing in this class returns the user to an
             *      authenticated session, so every user with mfa_enabled = true
             *      is permanently locked out of the platform.
             *
             *   2. THE CODES ARE NOT RFC 6238 TOTP. verifyTotpCode() packs the
             *      time step with pack('N', $time) — four bytes where the spec
             *      requires eight — so no authenticator app can ever produce a
             *      code it accepts, even if (1) were fixed.
             *
             *   3. THE SHARED SECRET WENT TO A THIRD PARTY. generateQrCodeUrl()
             *      builds an api.qrserver.com URL carrying the TOTP seed and the
             *      user's email, which the enrolling browser then fetched.
             *
             * DEFERRED, NOT FORGOTTEN: the rebuild is scheduled for deployment
             * readiness and none of this code has been deleted. The conditions
             * that must hold before FEATURE_MFA_TOTP is switched on are listed
             * in full in config/features.php.
             *
             * WITH THE FLAG OFF the user proceeds as an ordinary authenticated
             * session — including a user who has already self-enrolled. That is
             * the point: it is the only behaviour that does not strand them.
             */
            if (EnsureMfaVerified::featureEnabled()) {
                // Check if MFA is enabled
                if ($authenticatedUser->mfa_enabled) {
                    // Store user ID in session for MFA verification
                    $request->session()->put('mfa_pending_user_id', $authenticatedUser->id);
                    Auth::logout();

                    return redirect()->route('mfa.verify');
                }

                // The organization can require MFA for particular roles
                // (organizations.settings->mfa_required_roles). Someone in one of
                // those roles who has not enrolled is sent to enrol now rather
                // than being let through with the requirement unmet.
                if ($this->mfaRequiredFor($authenticatedUser)) {
                    return redirect()->route('mfa.setup')
                        ->with('warning', 'Your role requires multi-factor authentication. Set it up to continue.');
                }
            }

            // Check if password must be changed
            if ($authenticatedUser->must_change_password) {
                Auth::logout();

                return redirect()->route('password.request')->with('warning', 'You must change your password before proceeding.');
            }

            return redirect()->intended('/risk/dashboard');
        }

        // Count the failure against (account, source). Recorded for EVERY failed
        // attempt, including ones naming an address with no account behind it,
        // so that the endpoint behaves identically either way.
        $failures = RateLimiter::hit($lockoutKey, self::LOCKOUT_MINUTES * 60);

        // users.login_attempts is still incremented, but it is now a REPORTING
        // signal rather than the lock itself — it is what the administrator sees
        // on the user screen, and a number that climbs while the person insists
        // they have not been trying is the first evidence of an attack on that
        // account. users.locked_until is deliberately no longer written here;
        // see lockoutKey() for why.
        if ($user) {
            $user->update(['login_attempts' => $user->login_attempts + 1]);
        }

        if ($failures >= self::LOCKOUT_ATTEMPTS) {
            // The window opened on the FIRST failure, so the wait is the
            // remainder of it rather than a fresh thirty minutes. Reporting the
            // real figure is more useful than repeating the constant.
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($lockoutKey) / 60));

            return back()->withErrors([
                'email' => "Too many failed sign-in attempts from this device. Try again in {$minutes} minute(s), or ask an administrator to reset your password.",
            ])->withInput($request->only('email'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->withInput($request->only('email'));
    }

    /**
     * Show register form (admin only)
     */
    public function showRegister()
    {
        // Only super-admin can register new users - see policy check in routes
        $orgId = auth()->user()->organization_id;
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        return view('auth.register', compact('businessUnits'));
    }

    /**
     * Register new user (admin only)
     */
    public function register(Request $request)
    {
        // Validate request
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'staff_id' => 'required|string|unique:users',
            'job_title' => 'required|string',
            'department' => 'required|string',
            'phone' => 'required|string',
            'roles' => 'required|array',
            'business_unit_id' => 'required|exists:business_units,id',
        ]);

        // Generate temporary password
        $tempPassword = Str::random(12);

        // Create user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($tempPassword),
            'staff_id' => $validated['staff_id'],
            'job_title' => $validated['job_title'],
            'department' => $validated['department'],
            'phone' => $validated['phone'],
            'business_unit_id' => $validated['business_unit_id'],
            'is_active' => true,
            'must_change_password' => true,
            'password_changed_at' => now(),
        ]);

        // Assign roles
        $user->syncRoles($validated['roles']);

        // Deliver the temporary password. Routed through MAIL_MAILER, which in
        // a default environment is `log` — the message lands in
        // storage/logs/laravel.log until SMTP is configured. This matches the
        // convention already used by ApprovalService.
        //
        // The outcome is reported honestly: if delivery fails, the admin is
        // told so and shown the password once, so the account is still usable.
        // Silently claiming an email was sent is how new starters end up locked
        // out with nobody able to explain why.
        $delivered = $this->sendTemporaryPassword($user, $tempPassword);

        if ($delivered) {
            return redirect()->route('admin.users.show', $user)
                ->with('success', "User created. A temporary password has been sent to {$user->email}; they must change it on first login.");
        }

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'User created. They must change their password on first login.')
            ->with('warning', "The welcome email could not be sent. Pass this temporary password to {$user->name} through a secure channel — it will not be shown again: {$tempPassword}");
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /**
     * Show forgot password form
     */
    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    /**
     * Send password reset link
     */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            // Don't reveal if email exists
            return back()->with('status', 'If that email exists, a reset link has been sent.');
        }

        // Generate reset token
        $token = Str::random(64);

        // Store in cache or database
        // For now, using cache with 1 hour expiry
        cache()->put('password_reset_'.$request->email, $token, now()->addHour());

        $resetUrl = route('password.reset', ['token' => $token]).'?email='.urlencode($user->email);

        try {
            \Illuminate\Support\Facades\Mail::raw(
                "Hello {$user->name},\n\n"
                ."A password reset was requested for your Atheris ERM account.\n\n"
                ."Reset your password using the link below (valid for 1 hour):\n{$resetUrl}\n\n"
                .'If you did not request this, you can safely ignore this email.',
                function ($message) use ($user) {
                    $message->to($user->email)->subject('Atheris ERM — Password Reset');
                }
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Password reset mail failed: '.$e->getMessage(), ['email' => $user->email]);
        }

        return back()->with('status', 'If that email exists, a reset link has been sent.');
    }

    /**
     * Show reset password form
     */
    public function showResetPassword($token)
    {
        return view('auth.reset-password', ['token' => $token]);
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|exists:users',
            'token' => 'required',
            'password' => [
                'required',
                'confirmed',
                Password::min(12)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ]);

        // Verify token
        $token = cache()->get('password_reset_'.$validated['email']);
        if (! $token || $token !== $validated['token']) {
            return back()->withErrors(['token' => 'Invalid or expired reset token.']);
        }

        // Update password
        $user = User::where('email', $validated['email'])->first();
        $user->update([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ]);

        // Invalidate token
        cache()->forget('password_reset_'.$validated['email']);

        return redirect('/login')->with('success', 'Password reset successfully. Please log in.');
    }

    /**
     * Show MFA setup page
     */
    public function showMfaSetup()
    {
        $user = Auth::user();

        // If already set up, don't regenerate
        if ($user->mfa_secret && ! $user->mfa_enabled) {
            $secret = $user->mfa_secret;
        } else {
            // Generate new secret
            $secret = $this->generateMfaSecret();
        }

        // Generate QR code URL for authenticator app
        $qrCodeUrl = $this->generateQrCodeUrl($user->email, $secret);

        return view('auth.mfa-setup', [
            'secret' => $secret,
            'qrCodeUrl' => $qrCodeUrl,
        ]);
    }

    /**
     * Enable MFA
     */
    public function enableMfa(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|numeric|digits:6',
            'secret' => 'required|string',
        ]);

        $user = Auth::user();

        // Verify TOTP code
        if (! $this->verifyTotpCode($validated['code'], $validated['secret'])) {
            return back()->withErrors(['code' => 'Invalid verification code.']);
        }

        // Enable MFA
        $user->update([
            'mfa_secret' => $validated['secret'],
            'mfa_enabled' => true,
        ]);

        return redirect()->route('risk.dashboard')
            ->with('success', 'Two-factor authentication enabled successfully.');
    }

    /**
     * Show MFA verification page
     */
    public function showMfaVerify()
    {
        if (! session('mfa_pending_user_id')) {
            return redirect('/login');
        }

        return view('auth.mfa-verify');
    }

    /**
     * Verify MFA code
     */
    public function verifyMfa(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|numeric|digits:6',
        ]);

        $userId = session('mfa_pending_user_id');
        if (! $userId) {
            return redirect('/login');
        }

        $user = User::find($userId);
        if (! $user) {
            return redirect('/login');
        }

        // Verify TOTP code
        if (! $this->verifyTotpCode($validated['code'], $user->mfa_secret)) {
            return back()->withErrors(['code' => 'Invalid verification code.']);
        }

        // Mark MFA as verified in session
        session(['mfa_verified' => true]);
        $request->session()->forget('mfa_pending_user_id');

        return redirect()->intended('/risk/dashboard');
    }

    /**
     * Generate TOTP secret
     */
    private function generateMfaSecret(): string
    {
        // Simple base32 encoding of random bytes
        $secret = random_bytes(32);

        return $this->base32Encode($secret);
    }

    /**
     * Base32 encode
     */
    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded = '';
        $buffer = 0;
        $bufferSize = 0;

        for ($i = 0; $i < strlen($data); $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bufferSize += 8;

            while ($bufferSize >= 5) {
                $bufferSize -= 5;
                $encoded .= $alphabet[($buffer >> $bufferSize) & 31];
            }
        }

        if ($bufferSize > 0) {
            $encoded .= $alphabet[($buffer << (5 - $bufferSize)) & 31];
        }

        return $encoded;
    }

    /**
     * Generate QR code URL
     */
    private function generateQrCodeUrl(string $email, string $secret): string
    {
        $issuer = config('app.name');
        $label = urlencode("$issuer ($email)");
        $secret = urlencode($secret);

        return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data='.
            urlencode("otpauth://totp/$label?secret=$secret&issuer=".urlencode($issuer));
    }

    /**
     * Verify TOTP code
     */
    private function verifyTotpCode(string $code, string $secret): bool
    {
        $timeWindow = 1; // Allow 1 step forward/backward
        $now = floor(time() / 30);

        for ($i = -$timeWindow; $i <= $timeWindow; $i++) {
            $time = $now + $i;
            $hash = hash_hmac('sha1', pack('N', $time), $this->base32Decode($secret), true);
            $offset = ord($hash[19]) & 0x0F;
            $totp = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;

            if ((int) $code === $totp) {
                return true;
            }
        }

        return false;
    }

    /**
     * Base32 decode
     */
    private function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $decoded = '';
        $buffer = 0;
        $bufferSize = 0;

        for ($i = 0; $i < strlen($data); $i++) {
            $char = strpos($alphabet, $data[$i]);
            if ($char === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $char;
            $bufferSize += 5;

            if ($bufferSize >= 8) {
                $bufferSize -= 8;
                $decoded .= chr(($buffer >> $bufferSize) & 255);
            }
        }

        return $decoded;
    }

    /**
     * Whether this user's organization requires MFA for one of their roles.
     *
     * Configured per tenant in organizations.settings->mfa_required_roles, so
     * a bank can mandate a second factor for its CRO and administrators
     * without forcing it on every read-only board member.
     */
    private function mfaRequiredFor(User $user): bool
    {
        $required = (array) ($user->organization?->settings['mfa_required_roles'] ?? []);

        return $required !== [] && $user->hasAnyRole($required);
    }

    /**
     * Send a newly created user their temporary password.
     *
     * Returns whether delivery was accepted by the configured mailer, so the
     * caller can tell the administrator the truth either way.
     */
    private function sendTemporaryPassword(User $user, string $temporaryPassword): bool
    {
        $organisation = $user->organization?->name ?? config('app.name');

        $body = <<<TEXT
        Hello {$user->name},

        An account has been created for you on the {$organisation} risk management platform.

        Email:              {$user->email}
        Temporary password: {$temporaryPassword}

        You will be asked to choose a new password the first time you sign in.
        This temporary password stops working at that point.

        If you were not expecting this account, tell your risk administrator.
        TEXT;

        try {
            Mail::raw($body, function ($message) use ($user, $organisation) {
                $message->to($user->email, $user->name)
                    ->subject("Your {$organisation} risk platform account");
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('Welcome email could not be sent', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
