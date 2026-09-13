<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureMfaVerified;
use App\Http\Requests\ProfileUpdateRequest;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A person's own account: profile details, password, second factor.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'staff_id' => $user->staff_id,
                'job_title' => $user->job_title,
                'department' => $user->department,
                'phone' => $user->phone,
                'organization' => $user->organization()->value('name'),
                'roles' => $user->getRoleNames()->values()->all(),
                'mfa_enabled' => (bool) $user->mfa_enabled,
                'last_login_at' => $user->last_login_at ? Carbon::parse($user->last_login_at)->toIso8601String() : null,
                'password_changed_at' => $user->password_changed_at ? Carbon::parse($user->password_changed_at)->toIso8601String() : null,
            ],
            'mfaAvailable' => EnsureMfaVerified::featureEnabled(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back()->with('success', 'Profile updated.');
    }
}
