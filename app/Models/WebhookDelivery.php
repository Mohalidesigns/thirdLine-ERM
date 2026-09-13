<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One attempt to deliver one event to one endpoint.
 *
 * A row per ATTEMPT, not per event: "we tried four times, here is what came
 * back each time" is the answer an integration incident actually needs, and a
 * single row overwritten on each retry cannot give it.
 */
class WebhookDelivery extends Model
{
    use BelongsToOrganization;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'organization_id', 'subscription_id', 'event', 'payload', 'attempt',
        'status', 'status_code', 'response_body', 'error', 'duration_ms',
        'delivered_at', 'next_retry_at', 'replay_of_id', 'replayed_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempt' => 'integer',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'delivered_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    public function subscription()
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    public function replayOf()
    {
        return $this->belongsTo(self::class, 'replay_of_id');
    }

    public function replayer()
    {
        return $this->belongsTo(User::class, 'replayed_by');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_FAILED, self::STATUS_ABANDONED]);
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_DELIVERED => 'green',
            self::STATUS_FAILED, self::STATUS_ABANDONED => 'red',
            self::STATUS_RETRYING => 'amber',
            default => 'slate',
        };
    }
}
