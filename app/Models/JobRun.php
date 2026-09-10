<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * The record of one background job: what it is, how far it has got, and how it
 * ended.
 *
 * organization_id is nullable and $tenantIncludesGlobal is false, so a system
 * job is visible to nobody's queue screen rather than to everybody's.
 */
class JobRun extends Model
{
    use BelongsToOrganization;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id', 'job_class', 'label', 'queue', 'subject_type', 'subject_id',
        'status', 'progress', 'processed', 'total', 'message', 'queued_at', 'started_at',
        'finished_at', 'cancel_requested_at', 'cancel_requested_by', 'error', 'result',
        'attempts', 'created_by',
    ];

    protected $casts = [
        'progress' => 'integer',
        'processed' => 'integer',
        'total' => 'integer',
        'attempts' => 'integer',
        'result' => 'array',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            $model->queued_at ??= now();
        });
    }

    /* ------------------------------------------------------------------ */

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancel_requested_by');
    }

    public function subject()
    {
        return $this->morphTo('subject', 'subject_type', 'subject_id');
    }

    /* ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING]);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('created_by', $user->id);
    }

    /* ------------------------------------------------------------------ */

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    public function isCancelRequested(): bool
    {
        return $this->cancel_requested_at !== null;
    }

    /**
     * How long it ran, in seconds, or null while it is still running.
     */
    public function durationSeconds(): ?float
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return round($this->started_at->diffInMilliseconds($this->finished_at) / 1000, 1);
    }

    /**
     * A rough estimate of the time left, from the rate so far.
     *
     * Returns null rather than a guess when there is not enough to go on: a
     * countdown that is wrong is worse than no countdown, because people plan
     * around it.
     */
    public function estimatedSecondsRemaining(): ?int
    {
        if ($this->status !== self::STATUS_RUNNING || $this->started_at === null) {
            return null;
        }

        if ($this->total === null || $this->total <= 0 || $this->processed < 1) {
            return null;
        }

        $elapsed = $this->started_at->diffInSeconds(now());

        if ($elapsed < 2) {
            return null;
        }

        $rate = $this->processed / $elapsed;

        return $rate > 0 ? (int) ceil(($this->total - $this->processed) / $rate) : null;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'green',
            self::STATUS_FAILED => 'red',
            self::STATUS_CANCELLED => 'slate',
            self::STATUS_RUNNING => 'blue',
            default => 'amber',
        };
    }
}
