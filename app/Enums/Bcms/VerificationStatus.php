<?php

namespace App\Enums\Bcms;

/**
 * Whether a contact's address on record is known good (`bcms_contacts`).
 *
 * A separate question from consent: a number can be `verified` and its
 * consent still `withdrawn`, and a number can be `granted` consent and be
 * `bounced` — the person agreed to be reached, but the address that was
 * on file no longer works. Both facts are needed and neither substitutes
 * for the other.
 */
enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Bounced = 'bounced';
    case Invalid = 'invalid';
}
