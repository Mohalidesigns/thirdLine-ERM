<?php

namespace App\Services\Tprm\Portal;

use App\Mail\Tprm\PortalSignInCode;
use App\Models\Organization;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\PortalInvitation;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Support\Auth\Totp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Everything that creates, unlocks or authenticates a portal account —
 * FR-PRT-01.
 *
 * ONE FILE, because the rules only hold if every path obeys all of them. A
 * lockout counter enforced in the login controller and skipped by the
 * invitation-acceptance flow is not a lockout counter; MFA required by a
 * middleware and not by the thing that mints the session is a checkbox.
 *
 * `attempt()` DOES NOT LOG THE USER IN. It authenticates the password and
 * nothing else — the session is minted by `completeMfa()`, after a code has
 * been checked. That split is what makes MFA mandatory in fact: there is no
 * code path that produces an authenticated portal session without passing
 * through the second step, so a future controller cannot accidentally skip it.
 */
class PortalAuthService
{
    /**
     * Invite somebody at a vendor.
     *
     * Returns the plain token exactly once, for the email. It is not stored
     * and cannot be recovered; a lost invitation is reissued, not looked up.
     *
     * @return array{invitation: PortalInvitation, token: string}
     */
    public function invite(
        ThirdParty $thirdParty,
        string $email,
        string $role = PortalInvitation::ROLE_RESPONDER,
        ?int $userId = null,
    ): array {
        $token = PortalInvitation::mintToken();

        $invitation = PortalInvitation::create([
            'organization_id' => $thirdParty->organization_id,
            'third_party_id' => $thirdParty->getKey(),
            'email' => mb_strtolower(trim($email)),
            'token_hash' => PortalInvitation::hash($token),
            'role' => in_array($role, PortalInvitation::ROLES, true) ? $role : PortalInvitation::ROLE_RESPONDER,
            'expires_at' => now()->addDays(PortalInvitation::TTL_DAYS),
            'created_by' => $userId,
        ]);

        $this->audit($invitation->organization_id, PortalInvitation::class, $invitation->getKey(), 'portal_invited', [
            'email' => $invitation->email,
            'third_party_id' => $thirdParty->getKey(),
            'role' => $invitation->role,
        ], $userId);

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * Find an invitation by its plain token.
     *
     * Returns null for a token that matches nothing AND for one that matches
     * something unusable — the caller asks `refusalReason()` on a found row to
     * tell the person which. A single "invalid" for both would be marginally
     * more secretive and would strand every vendor whose link simply expired.
     */
    public function findInvitation(string $token): ?PortalInvitation
    {
        return PortalInvitation::query()
            ->withoutGlobalScopes()
            ->where('token_hash', PortalInvitation::hash($token))
            ->first();
    }

    /**
     * Accept an invitation and create the account.
     *
     * The account is created ACTIVE but without MFA, which means it can sign
     * in as far as the enrolment screen and nowhere else. Creating it
     * `invited` instead would need a second state transition nobody would
     * remember to make; leaving MFA off is the state that already blocks.
     *
     * @throws RuntimeException
     */
    public function accept(PortalInvitation $invitation, string $name, string $password): PortalUser
    {
        $reason = $invitation->refusalReason();

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        return DB::transaction(function () use ($invitation, $name, $password): PortalUser {
            $existing = PortalUser::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $invitation->organization_id)
                ->where('email', $invitation->email)
                ->first();

            if ($existing !== null) {
                throw new RuntimeException(
                    'An account already exists for this address. Sign in, or use the forgotten-password link.'
                );
            }

            $user = new PortalUser([
                'organization_id' => $invitation->organization_id,
                'third_party_id' => $invitation->third_party_id,
                'email' => $invitation->email,
                'name' => $name,
                'password' => $password,
                'invited_by' => $invitation->created_by,
                'invited_at' => $invitation->created_at,
            ]);

            $user->forceFill([
                'status' => PortalUser::STATUS_ACTIVE,
                'accepted_at' => now(),
            ])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->audit($user->organization_id, PortalUser::class, $user->getKey(), 'portal_account_created', [
                'email' => $user->email,
                'third_party_id' => $user->third_party_id,
            ]);

            return $user;
        });
    }

    /**
     * Check a password. Does NOT create a session — see the class comment.
     *
     * @return array{user: PortalUser|null, reason: string|null}
     */
    public function attempt(int $organizationId, string $email, string $password): array
    {
        /** @var PortalUser|null $user */
        $user = PortalUser::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('email', mb_strtolower(trim($email)))
            ->first();

        if ($user === null) {
            /*
             * Hash anyway. Returning early on an unknown address makes the
             * response measurably faster than a wrong password does, which
             * turns this endpoint into an account-existence oracle for anybody
             * with a stopwatch and a list of email addresses.
             */
            Hash::make($password);

            return ['user' => null, 'reason' => 'Those details do not match an account.'];
        }

        if ($user->isLocked()) {
            return ['user' => null, 'reason' => $this->lockoutMessage($user)];
        }

        if (! $user->canAuthenticate()) {
            return ['user' => null, 'reason' => 'This account is not active. Contact your client\'s risk team.'];
        }

        if (! Hash::check($password, (string) $user->password)) {
            $this->recordFailure($user);

            return [
                'user' => null,
                'reason' => $user->isLocked()
                    ? $this->lockoutMessage($user->refresh())
                    : 'Those details do not match an account.',
            ];
        }

        return ['user' => $user, 'reason' => null];
    }

    /* ------------------------------------------------------------------ */
    /*  Email one-time codes — the default second factor */
    /* ------------------------------------------------------------------ */

    /**
     * Issue a code and send it.
     *
     * THE CODE IS HASHED AT REST and returned to nobody. A one-time code
     * sitting in plaintext in a column is a credential that a reporting
     * replica, a support export or a database backup carries; its entire value
     * is that only one mailbox holds it.
     *
     * ISSUING REPLACES ANY LIVE CODE. Two valid codes at once is how a vendor
     * ends up typing the older of the two and being told it is wrong.
     *
     * @return array{sent: bool, reason: string|null, retry_after: int}
     */
    public function sendEmailCode(PortalUser $user, bool $force = false): array
    {
        if (! $user->canAuthenticate()) {
            return ['sent' => false, 'reason' => 'This account is not active.', 'retry_after' => 0];
        }

        $wait = $user->secondsUntilResend();

        if ($wait > 0 && ! $force) {
            return [
                'sent' => false,
                'reason' => sprintf('A code was just sent. You can ask for another in %d seconds.', $wait),
                'retry_after' => $wait,
            ];
        }

        /*
         * `random_int` rather than `rand`: this is a credential, and a
         * predictable one is not a second factor. Padded so that a code
         * beginning with a zero is still six digits, because a six-digit box
         * that sometimes wants five is a support call.
         */
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'mfa_code_hash' => Hash::make($code),
            'mfa_code_expires_at' => now()->addMinutes(PortalUser::CODE_TTL_MINUTES),
            'mfa_code_sent_at' => now(),
            'mfa_code_attempts' => 0,
        ])->save();

        $clientName = Organization::query()->find($user->organization_id)->name ?? config('app.name');

        Mail::to($user->email)->send(new PortalSignInCode(
            $user,
            $code,
            (string) $clientName,
            PortalUser::CODE_TTL_MINUTES,
        ));

        /*
         * The audit records that a code was SENT, never the code. An audit
         * trail readable by the client's staff must not hand them a live
         * second factor for one of their vendors.
         */
        $this->audit($user->organization_id, PortalUser::class, $user->getKey(), 'portal_mfa_code_sent', [
            'email' => $user->email,
            'expires_at' => $user->mfa_code_expires_at?->toIso8601String(),
        ]);

        return ['sent' => true, 'reason' => null, 'retry_after' => PortalUser::CODE_RESEND_SECONDS];
    }

    /**
     * Check an emailed code.
     *
     * A WRONG CODE BURNS AN ATTEMPT AGAINST THE ISSUED CODE, and five wrong
     * ones burn the code itself rather than only counting toward the account
     * lockout. Six digits is a million guesses; without a per-code ceiling an
     * attacker who can keep requesting fresh codes gets unlimited attempts at
     * a fresh target each time, which is a much better position than it looks.
     */
    public function verifyEmailCode(PortalUser $user, string $code): bool
    {
        if (! $user->hasLiveCode()) {
            return false;
        }

        if ((int) $user->mfa_code_attempts >= PortalUser::CODE_MAX_ATTEMPTS) {
            $this->burnCode($user);

            return false;
        }

        if (! Hash::check($code, (string) $user->mfa_code_hash)) {
            $user->forceFill(['mfa_code_attempts' => (int) $user->mfa_code_attempts + 1])->save();

            return false;
        }

        // Single use. Burned before the session is minted, so a replay of the
        // same code against a second session finds nothing.
        $this->burnCode($user);

        return true;
    }

    private function burnCode(PortalUser $user): void
    {
        $user->forceFill([
            'mfa_code_hash' => null,
            'mfa_code_expires_at' => null,
            'mfa_code_attempts' => 0,
        ])->save();
    }

    /**
     * Begin MFA enrolment, returning the secret and its otpauth URI.
     *
     * The secret is written but `mfa_enabled` stays false until a code proves
     * the vendor's authenticator actually holds it. Enabling on generation is
     * how people lock themselves out of a portal they were invited to twenty
     * minutes ago.
     *
     * @return array{secret: string, uri: string}
     */
    public function beginMfaEnrolment(PortalUser $user, string $issuer): array
    {
        $secret = Totp::generateSecret();

        $user->forceFill(['mfa_secret' => $secret, 'mfa_enabled' => false])->save();

        return [
            'secret' => $secret,
            'uri' => Totp::otpauthUri($issuer, $user->email, $secret),
        ];
    }

    /**
     * Confirm enrolment with a code from the authenticator.
     */
    public function confirmMfaEnrolment(PortalUser $user, string $code): bool
    {
        if ($user->mfa_secret === null || ! Totp::verify($code, (string) $user->mfa_secret)) {
            return false;
        }

        $user->forceFill(['mfa_enabled' => true])->save();

        $this->audit($user->organization_id, PortalUser::class, $user->getKey(), 'portal_mfa_enrolled', [
            'email' => $user->email,
        ]);

        return true;
    }

    /**
     * Check a challenge code and mint the session — the only place that does.
     *
     * IT DISPATCHES ON THE ACCOUNT'S METHOD rather than trying both. Trying
     * TOTP and then the emailed code, or the reverse, would mean an account on
     * the email method could still be signed into with a stale TOTP secret
     * left over from an abandoned enrolment.
     *
     * The account counter is reset here rather than after the password,
     * because a password that is right and a code that is wrong is exactly the
     * shape of a credential-stuffing attempt against a stolen password.
     * Resetting on the password alone would give an attacker unlimited code
     * guesses.
     */
    public function completeMfa(PortalUser $user, string $code, bool $remember = false): bool
    {
        if ($user->isLocked()) {
            return false;
        }

        $verified = $user->usesEmailCodes()
            ? $this->verifyEmailCode($user, $code)
            : ($user->mfa_secret !== null && Totp::verify($code, (string) $user->mfa_secret));

        if (! $verified) {
            $this->recordFailure($user);

            return false;
        }

        Auth::guard('tprm-portal')->login($user, $remember);

        session()->regenerate();
        session()->put('tprm_portal_mfa_verified', $user->getKey());

        $user->forceFill([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();

        $this->audit($user->organization_id, PortalUser::class, $user->getKey(), 'portal_signed_in', [
            'email' => $user->email,
        ]);

        return true;
    }

    public function logout(): void
    {
        $user = Auth::guard('tprm-portal')->user();

        Auth::guard('tprm-portal')->logout();

        session()->invalidate();
        session()->regenerateToken();

        if ($user instanceof PortalUser) {
            $this->audit($user->organization_id, PortalUser::class, $user->getKey(), 'portal_signed_out', [
                'email' => $user->email,
            ]);
        }
    }

    private function recordFailure(PortalUser $user): void
    {
        $attempts = (int) $user->failed_attempts + 1;

        $user->forceFill([
            'failed_attempts' => $attempts,
            'locked_until' => $attempts >= PortalUser::MAX_ATTEMPTS
                ? now()->addMinutes(PortalUser::LOCKOUT_MINUTES)
                : $user->locked_until,
        ])->save();

        if ($attempts >= PortalUser::MAX_ATTEMPTS) {
            $this->audit($user->organization_id, PortalUser::class, $user->getKey(), 'portal_account_locked', [
                'email' => $user->email,
                'attempts' => $attempts,
            ]);
        }
    }

    private function lockoutMessage(PortalUser $user): string
    {
        $minutes = max(1, (int) ceil(Carbon::now()->diffInSeconds($user->locked_until, true) / 60));

        return sprintf(
            'This account is locked after %d failed attempts. Try again in %d minute%s.',
            PortalUser::MAX_ATTEMPTS,
            $minutes,
            $minutes === 1 ? '' : 's',
        );
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(int $organizationId, string $type, int $id, string $event, array $after, ?int $userId = null): void
    {
        AuditLog::create([
            'organization_id' => $organizationId,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'event' => $event,
            'actor_type' => $userId !== null ? 'user' : 'portal',
            'actor_id' => $userId,
            'before' => null,
            'after' => $after,
            'ip' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500) ?: null,
        ]);
    }
}
