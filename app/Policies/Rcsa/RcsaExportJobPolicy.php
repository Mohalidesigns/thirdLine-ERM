<?php

namespace App\Policies\Rcsa;

use App\Models\Rcsa\RcsaExportJob;
use App\Models\User;

/**
 * RCSA v2, P6. The bulk download and its log (§10.2).
 *
 * VIEWING THE LOG AND TAKING AN EXPORT ARE THE SAME PERMISSION, deliberately:
 * anyone who can export can see what they have exported. What differs is HOW
 * MUCH of the log they see, and that is `rcsa_audit.view` — the administrator's
 * view of everybody's downloads, which is the control §10.2 actually asks for.
 * The controller applies that; this decides who may open the screen at all.
 *
 * A COLLECTED FILE IS THE EXPORTER'S. Following somebody else's signed link
 * gets nothing without `rcsa_audit.view`, because the signature proves the URL
 * was issued, not that the person holding it was the one it was issued to.
 */
class RcsaExportJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rcsa_export.bulk') || $user->can('rcsa_audit.view');
    }

    public function view(User $user, RcsaExportJob $export): bool
    {
        if ($user->organization_id !== $export->organization_id) {
            return false;
        }

        return ((int) $export->user_id === (int) $user->id && $user->can('rcsa_export.bulk'))
            || $user->can('rcsa_audit.view');
    }

    public function create(User $user): bool
    {
        return $user->can('rcsa_export.bulk');
    }
}
