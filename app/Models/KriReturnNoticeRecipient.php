<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One person on one notice (ADR 0026 section 5) - the delivery outcome.
 *
 * `sent` means "accepted by the mail transport". Bounces are not tracked, and
 * no screen may imply delivery to an inbox.
 */
class KriReturnNoticeRecipient extends Model
{
    use BelongsToOrganization;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const SKIP_INACTIVE = 'inactive_user';

    public const SKIP_NO_EMAIL = 'no_email';

    public const SKIP_RETURN_SUBMITTED = 'return_submitted';

    protected $table = 'kri_return_notice_recipients';

    protected $fillable = [
        'organization_id',
        'kri_return_notice_id',
        'user_id',
        'email',
        'name',
        'contact_role',
        'status',
        'skip_reason',
        'attempts',
        'message_id',
        'error',
        'sent_at',
        'failed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /** @return BelongsTo<KriReturnNotice, $this> */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(KriReturnNotice::class, 'kri_return_notice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
