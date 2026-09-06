<?php

namespace App\Policies;

use App\Models\ApiToken;
use App\Models\User;

/**
 * Who may issue and revoke API tokens (migration Phase 6.7).
 *
 * A user may always issue a token FOR THEMSELVES, because a personal token can
 * never exceed the permissions of the person behind it: the worst anyone can do
 * with one is what they could already do by logging in.
 *
 * ISSUING A MACHINE TOKEN IS A DIFFERENT ACT. It acts as nobody, so its scopes
 * are the whole of its authority, and it needs `api.tokens.manage`. That is the
 * distinction `createMachine()` carries, and it is the reason this policy has
 * two create abilities rather than one.
 *
 * Listing follows the same logic: without `api.tokens.manage` a user sees only
 * their own tokens, because listing everyone's would show which integrations
 * exist and when they last ran — reconnaissance in itself.
 */
class ApiTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('api.tokens');
    }

    public function create(User $user): bool
    {
        return $user->can('api.tokens');
    }

    public function createMachine(User $user): bool
    {
        return $user->can('api.tokens.manage');
    }

    /**
     * Revoke: your own, or anybody's with api.tokens.manage.
     *
     * Revoked rather than deleted — the row is the record that the token
     * existed, what it could do and when it was last used, which is the first
     * thing asked for after an incident.
     */
    public function revoke(User $user, ApiToken $token): bool
    {
        if ((int) $token->organization_id !== (int) $user->organization_id) {
            return false;
        }

        return (int) $token->tokenable_id === (int) $user->id || $user->can('api.tokens.manage');
    }
}
