<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Choose a new password from a reset link.
 */
class NewPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => (string) $request->query('email', ''),
            'token' => (string) $request->route('token'),
        ]);
    }

    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $token = cache()->get('password_reset_'.$validated['email']);

        // The same answer for an unknown address and a wrong token, so the
        // form cannot be used to confirm which addresses have accounts.
        $user = User::query()->where('email', $validated['email'])->first();

        if ($user === null || ! is_string($token) || ! hash_equals($token, $validated['token'])) {
            return back()->withErrors(['token' => 'Invalid or expired reset token.']);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ]);

        cache()->forget('password_reset_'.$validated['email']);

        // A reset must end any session an attacker may already hold. With the
        // database session driver, purge every session row for this user.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        return redirect('/login')->with('success', 'Password reset successfully. Please log in.');
    }

    /**
     * The password policy every password on the platform must meet — the one
     * the retired AuthController enforced.
     */
    public static function policy(): Password
    {
        return Password::min(12)->mixedCase()->numbers()->symbols();
    }
}
