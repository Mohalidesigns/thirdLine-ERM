<?php

namespace App\Models\Rcsa;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One bulk download — who took what, under which filters, from where, and
 * whether they collected it (§10.2).
 *
 * A CONTROL, NOT TELEMETRY. A completed RCSA is the bank's operational risk
 * profile in one file, and the second line's question about an export is never
 * "did it work" but "who has a copy". The row is written BEFORE the file
 * exists and updated as it moves, so an export that failed and an export nobody
 * recorded are distinguishable.
 *
 * NO SOFT DELETE. The table has no `deleted_at`, which is the schema saying
 * what §11 says in words: audit views are read-only and non-deletable. An
 * export log somebody can tidy is not a log.
 */
class RcsaExportJob extends Model
{
    use BelongsToOrganization, HasFactory;

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $table = 'rcsa_export_jobs';

    protected $fillable = [
        'organization_id',
        'user_id',
        'filters',
        'format',
        'status',
        'row_count',
        'file_path',
        'failure_reason',
        'expires_at',
        'downloaded_at',
        'download_count',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'filters' => 'array',
        'row_count' => 'integer',
        'download_count' => 'integer',
        'expires_at' => 'datetime',
        'downloaded_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the file is still collectable.
     *
     * Expiry is checked on READ rather than swept by a job. A link that has
     * passed its date is refused the moment somebody follows it, whatever a
     * cleanup schedule has or has not got round to — which is the behaviour an
     * expiring link is bought for.
     */
    public function isCollectable(): bool
    {
        return $this->status === self::READY
            && filled($this->file_path)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
