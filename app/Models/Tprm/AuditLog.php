<?php

namespace App\Models\Tprm;

use App\Models\AuditTrailIsAppendOnly;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * The TPRM audit trail — append-only and tamper-evident.
 *
 * This is `risk_audit_trail`'s pattern applied to the TPRM tables rather than
 * a second, weaker one. Two guarantees, and they are different guarantees:
 *
 *   APPEND-ONLY stops the application changing a row. The `updating` and
 *   `deleting` hooks throw, so a job, a console command, a tinker session and
 *   a future controller all fail the same way. A policy would only have
 *   covered the routes.
 *
 *   THE HASH CHAIN makes a change made AROUND the application detectable. Each
 *   row's digest covers its predecessor's, so editing, deleting or reordering
 *   any row invalidates every row after it. Without it, "the audit log is
 *   append-only" rests on nobody having run an UPDATE, which is not a
 *   statement anyone can make about a production database.
 *
 * `actor_type` distinguishes `user`, `portal_user` and `system`. A vendor's
 * action recorded as a colleague's would misrepresent the record on exactly
 * the export where it matters most — the supervisory one.
 */
class AuditLog extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_audit_logs';

    public const UPDATED_AT = null;

    /**
     * Fields covered by the digest, in a fixed order.
     *
     * Order is load-bearing: the hash is computed over a canonical
     * serialisation, so changing this list or its order invalidates every
     * stored hash and means re-sealing the chain in a migration. `id` is
     * excluded deliberately — the digest has to be final before the row is
     * written, and ordering is still covered because each row commits to its
     * predecessor.
     *
     * @var list<string>
     */
    public const HASHED_FIELDS = [
        'organization_id',
        'auditable_type',
        'auditable_id',
        'event',
        'actor_type',
        'actor_id',
        'actor_label',
        'before',
        'after',
        'ip',
        'user_agent',
        'correlation_id',
        'created_at',
    ];

    /** @var list<string> */
    public const ACTOR_TYPES = ['user', 'portal_user', 'system'];

    protected $fillable = [
        'organization_id',
        'auditable_type',
        'auditable_id',
        'event',
        'actor_type',
        'actor_id',
        'actor_label',
        'before',
        'after',
        'ip',
        'user_agent',
        'correlation_id',
        'created_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->created_at ??= now();

            $row->previous_hash = static::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $row->organization_id)
                ->orderByDesc('id')
                ->value('hash');

            $row->hash = static::chainHash($row->getAttributes(), $row->previous_hash);
        });

        static::updating(function (self $row): void {
            throw new AuditTrailIsAppendOnly(
                "tp_audit_logs row {$row->getKey()} cannot be updated: the TPRM audit trail is append-only."
            );
        });

        static::deleting(function (self $row): void {
            throw new AuditTrailIsAppendOnly(
                "tp_audit_logs row {$row->getKey()} cannot be deleted: the TPRM audit trail is append-only."
            );
        });
    }

    /**
     * sha256(previous_hash || canonical row payload).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function chainHash(array $attributes, ?string $previousHash): string
    {
        $payload = [];

        foreach (self::HASHED_FIELDS as $field) {
            $value = $attributes[$field] ?? null;

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }

            // `before` and `after` arrive as arrays before the cast has run on
            // a fresh model and as JSON strings when read back. Encoding both
            // to the same canonical JSON is what makes a rehydrated row's
            // recomputed digest match the one sealed at insert.
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $payload[$field] = $value === null ? null : (string) $value;
        }

        return hash('sha256', ($previousHash ?? '').'|'.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Recompute this row's digest from its stored contents. */
    public function expectedHash(): string
    {
        return self::chainHash($this->getAttributes(), $this->previous_hash);
    }

    /** Whether this row still matches the digest sealed when it was written. */
    public function isIntact(): bool
    {
        return $this->hash !== null && hash_equals($this->hash, $this->expectedHash());
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
