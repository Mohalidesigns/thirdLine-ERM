<?php

namespace App\Enums\Bcms;

/**
 * Where a contact record came from (`bcms_contacts`, Blueprint §8).
 *
 * The source decides who may edit which field, and that is an NDPA question
 * before it is a UX one. An AD-sourced name and department are overwritten on
 * every sync and editing them in BCMS is pointless; a personal mobile number
 * is NEVER sourced from AD, is entered by the person themselves under
 * `SelfService`, and carries its own consent record.
 *
 * `Ad` and `Entra` are separate cases rather than one `directory` case because
 * the sync mechanics, the attribute names and the failure modes differ, and a
 * hygiene report that cannot say which directory a stale record came from
 * cannot be actioned.
 */
enum ContactSource: string
{
    case Ad = 'ad';
    case Entra = 'entra';
    case Scim = 'scim';
    case Hris = 'hris';
    case Manual = 'manual';
    case SelfService = 'self_service';

    /** Sources whose records are overwritten by the next sync. */
    public function isDirectorySourced(): bool
    {
        return in_array($this, [self::Ad, self::Entra, self::Scim, self::Hris], true);
    }
}
