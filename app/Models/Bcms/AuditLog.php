<?php

namespace App\Models\Bcms;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One before/after record of a state change. Append only: there is no update
 * path and `updated_at` does not exist on the table (Blueprint §14 —
 * immutable audit log).
 *
 * @property int $id
 * @property ?int $organization_id
 * @property string $auditable_type
 * @property int $auditable_id
 * @property string $event
 * @property array<array-key, mixed> $before
 * @property array<array-key, mixed> $after
 * @property ?int $actor_id
 * @property ?string $actor_label
 * @property ?string $ip_address
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class AuditLog extends Model
{
    /**
     * Counts audit rows that could not be written, for BcmsWatchdog to report.
     *
     * Lives here rather than on BcmsAuditable because a trait constant cannot
     * be read through the trait's own name, and both the writer and the
     * watchdog need it. This model is the thing being written, so it is the
     * honest home for the key that counts failures to write it.
     *
     * Not tenant-scoped: a failure can happen before the organisation is known,
     * and an audit path broken at all is worth waking somebody for regardless
     * of whose row it was.
     */
    public const AUDIT_FAILURE_CACHE_KEY = 'bcms:audit-write-failures';

    use BelongsToOrganization;

    protected $table = 'bcms_audit_logs';

    /**
     * Append only — the table has no `updated_at`.
     *
     * Which means `created_at` has to be FILLABLE and is set by the trait. With
     * timestamps off, Eloquent stamps nothing; a non-fillable `created_at` is
     * silently dropped and every audit row lands with a null time, which is an
     * audit log that cannot answer "when".
     */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'auditable_type', 'auditable_id', 'event', 'before', 'after',
        'actor_id', 'actor_label', 'ip_address', 'created_at',
    ];

    /**
     * `created_at` casts to `datetime` even though `$timestamps = false`
     * (the class docblock has always claimed `?Carbon`) — `App\Models\Tprm\
     * AuditLog` casts it the same way. Without this, every reader of this
     * attribute gets the raw DB string, which 500s the first time anything
     * calls `->created_at?->toIso8601String()` on it (Phase 11's evidence-
     * pack export log, found by opening the screen in a browser after an
     * export exists — never exercised end to end before).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'organization_id' => 'integer',
            'auditable_id' => 'integer',
            'actor_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
