<?php

namespace App\Policies\Tprm\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The two questions every TPRM policy asks before its own, in order.
 *
 * Development standard §3: permission → tenancy → domain rule. The order is
 * not cosmetic. Answering the domain rule first ("only a draft engagement can
 * be edited") on another tenant's record tells the caller that record exists
 * and what state it is in, one probe at a time.
 */
trait ChecksTprmAccess
{
    /**
     * Whether the user holds the permission AND the record is theirs.
     *
     * A record belonging to another organisation is denied here regardless of
     * permission. In practice the tenancy global scope means such a record is
     * never loaded in the first place; this is the second lock on the same
     * door, for the paths that bypass the scope deliberately.
     */
    protected function allows(User $user, string $permission, ?Model $record = null): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        if ($record === null) {
            return true;
        }

        return (int) $record->getAttribute('organization_id') === (int) $user->organization_id;
    }
}
