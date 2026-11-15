<?php

namespace App\Listeners;

use App\Services\ActivityLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

class LogAuthActivity
{
    public function handleLogin(Login $event): void
    {
        ActivityLogger::log(
            event: 'login',
            subject: $event->user,
            description: 'User signed in',
            userId: $event->user->getAuthIdentifier(),
            userName: $event->user->name ?? null,
            userEmail: $event->user->email ?? null,
            organizationId: $event->user->organization_id ?? null,
        );
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user) {
            return;
        }

        ActivityLogger::log(
            event: 'logout',
            subject: $event->user,
            description: 'User signed out',
            userId: $event->user->getAuthIdentifier(),
            userName: $event->user->name ?? null,
            userEmail: $event->user->email ?? null,
            organizationId: $event->user->organization_id ?? null,
        );
    }

    public function handleFailed(Failed $event): void
    {
        $credentials = $event->credentials ?? [];
        $email = is_array($credentials) ? ($credentials['email'] ?? null) : null;

        ActivityLogger::log(
            event: 'login_failed',
            subject: null,
            description: 'Login attempt failed'.($email ? " for {$email}" : ''),
            properties: [
                'email' => $email,
            ],
            userId: $event->user?->getAuthIdentifier(),
            userEmail: $email,
            organizationId: $event->user?->organization_id,
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        ActivityLogger::log(
            event: 'password_reset',
            subject: $event->user,
            description: 'Password reset completed',
            userId: $event->user->getAuthIdentifier(),
            userName: $event->user->name ?? null,
            userEmail: $event->user->email ?? null,
            organizationId: $event->user->organization_id ?? null,
        );
    }
}
