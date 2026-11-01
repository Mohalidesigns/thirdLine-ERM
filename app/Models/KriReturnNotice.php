<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * An email about a return to a tier of contacts - the monthly request or a
 * manual reminder (ADR 0026 section 5). The row is written BEFORE anything is
 * dispatched, and its idempotency_key is the "at most once" rule.
 */
class KriReturnNotice extends Model
{
    use BelongsToOrganization;

    public const KIND_REQUEST = 'request';

    public const KIND_REMINDER = 'reminder';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DISPATCHING = 'dispatching';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIALLY_FAILED = 'partially_failed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NO_RECIPIENTS = 'no_recipients';

    /** The return was submitted before the mail went out (Amendment 1, A1.13). */
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'kri_return_notices';

    protected $fillable = [
        'organization_id',
        'kri_return_id',
        'business_unit_id',
        'kind',
        'sequence',
        'tier',
        'idempotency_key',
        'sent_on',
        'status',
        'requested_by',
        'queued_at',
        'recipients_resolved_at',
        'completed_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'tier' => 'integer',
        'sent_on' => 'date',
        'queued_at' => 'datetime',
        'recipients_resolved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public static function requestKey(int $returnId): string
    {
        return 'request:r'.$returnId;
    }

    public static function reminderKey(int $businessUnitId, string $lagosDay): string
    {
        return 'reminder:bu'.$businessUnitId.':'.$lagosDay;
    }

    /** @return BelongsTo<KriReturn, $this> */
    public function kriReturn(): BelongsTo
    {
        return $this->belongsTo(KriReturn::class, 'kri_return_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<KriReturnNoticeRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(KriReturnNoticeRecipient::class, 'kri_return_notice_id');
    }
}
