<?php

namespace App\Models\Tprm;

use App\Models\AuditTrailIsAppendOnly;
use App\Models\Organization;
use App\Support\CanonicalJson;
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
     * The recipe's version, carried in the preimage as a domain-separation tag
     * so a digest sealed under another recipe can never verify by accident.
     * No column holds it. See ADR 0025.
     */
    public const HASH_RECIPE = 'tp_audit_logs/v2';

    /**
     * Fields covered by the digest, in a fixed order.
     *
     * Order is load-bearing: the hash is computed over a canonical
     * serialisation, so changing this list or its order invalidates every
     * stored hash and means a new HASH_RECIPE and the in-chain cutover of
     * ADR 0025 section 3; a re-seal is forbidden. `id` is
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

            // ADR 0025 section 2a: a PHP float is stored as a JSON string, BEFORE
            // the digest, because MySQL 8 does not read every double back exactly.
            foreach (['before', 'after'] as $field) {
                $raw = $row->getAttributes()[$field] ?? null;

                if ($raw === null) {
                    continue;
                }

                if (is_array($raw)) {
                    $raw = json_encode($raw, JSON_THROW_ON_ERROR);
                }

                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $quoted = static::quoteFloats($decoded);

                if ($quoted !== $decoded) {
                    $row->setAttribute($field, $quoted);
                }
            }

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
     * sha256(HASH_RECIPE | previous_hash | canonical row payload).
     *
     * `before` and `after` are hashed as the canonical text of their DECODED
     * value, never as the column's text: MySQL 8 returns a `json` column
     * re-serialised and MariaDB returns it verbatim, so the text is not the
     * same thing on both. See CanonicalJson and ADR 0025.
     *
     * Floats never reach this function: `creating` has already stored each one
     * as a string (see quoteFloats()). This function must NOT quote them itself.
     * If it did, a stored `72.5` and a stored `"72.5"` would hash alike, and a
     * raw edit that changes a value's type would go undetected.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function chainHash(array $attributes, ?string $previousHash): string
    {
        $payload = [];

        foreach (self::HASHED_FIELDS as $field) {
            $value = $attributes[$field] ?? null;

            if ($field === 'before' || $field === 'after') {
                $payload[$field] = CanonicalJson::fromColumn($value);

                continue;
            }

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }

            $payload[$field] = $value === null ? null : (string) $value;
        }

        $envelope = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', self::HASH_RECIPE.'|'.($previousHash ?? '').'|'.$envelope);
    }

    /**
     * Replace every PHP float anywhere in a decoded payload with its shortest
     * round-trip JSON text, as a string. Everything else, including keys and
     * list order, is untouched.
     *
     * Why: MySQL 8.0.46's JSON parser does not read every double back exactly
     * (`9.018867924528301` returns as `9.0188679245283`, `1.0e25` as
     * `9.999999999999999e24`), so an honest row holding one would read as
     * tampered. A JSON string never passes through that number parser. See
     * ADR 0025 section 2a.
     *
     * Never `(string) $float`: that rounds to `precision` (14) and would turn
     * `0.1 + 0.2` into `"0.3"`.
     *
     * @throws \JsonException on NAN or INF.
     */
    public static function quoteFloats(mixed $value): mixed
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            return self::quoteFloatsPinned($value);
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
    }

    private static function quoteFloatsPinned(mixed $value): mixed
    {
        if (is_float($value)) {
            return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::quoteFloatsPinned($item);
            }
        }

        return $value;
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
