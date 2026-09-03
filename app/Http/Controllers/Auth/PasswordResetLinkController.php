<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot password": mail a one-hour reset link.
 *
 * The token mechanism is the one the retired AuthController used — a random
 * 64-character token held in the cache for an hour, keyed by email — kept as
 * is so nothing about deployed reset links changes. The route is throttled
 * per email (`throttle:password-reset`) because this endpoint is an outbound
 * mailer pointed at a named member of staff.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // The answer never reveals whether the address exists.
        $status = 'If that email exists, a reset link has been sent.';

        $user = User::query()->where('email', $request->input('email'))->first();

        if ($user === null) {
            return back()->with('status', $status);
        }

        $token = Str::random(64);
        cache()->put('password_reset_'.$user->email, $token, now()->addHour());

        $resetUrl = route('password.reset', ['token' => $token]).'?email='.urlencode($user->email);

        try {
            Mail::raw(
                "Hello {$user->name},\n\n"
                ."A password reset was requested for your Atheris ERM account.\n\n"
                ."Reset your password using the link below (valid for 1 hour):\n{$resetUrl}\n\n"
                .'If you did not request this, you can safely ignore this email.',
                fn ($message) => $message->to($user->email)->subject('Atheris ERM — Password Reset')
            );
        } catch (\Throwable $e) {
            Log::warning('Password reset mail failed: '.$e->getMessage(), ['email' => $user->email]);
        }

        return back()->with('status', $status);
    }
}
