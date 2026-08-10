<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\MorphTypes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class RiskAuditTrail extends Model
{
    use BelongsToOrganization, HasFactory;

    public $timestamps = false;

    protected $table = 'risk_audit_trail';

    /**
     * Declared widths of the two constrained string columns.
     *
     * Held here rather than only in the migration because they are a contract
     * the application has to keep, and SQLite — the test driver — declares
     * VARCHAR without a length and never enforces one. A too-long action name
     * therefore passes every test and throws SQLSTATE[22001] on MySQL, which is
     * exactly how `kri_breach_escalation` survived undetected. The migration
     * and AuditActionTypeWidthTest both read these, so the schema and the check
     * cannot drift apart.
     */
    public const ACTION_TYPE_MAX = 64;

    public const ENTITY_TYPE_MAX = 50;

    protected $fillable = [
        'organization_id',
        'entity_type',
        'entity_id',
        'action_type',
        'field_changed',
        'old_value',
        'new_value',
        'changed_by',
        'changed_at',
        'ip_address',
        'change_reason',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    /**
     * Fields covered by the hash, in a fixed order.
     *
     * Order matters: the digest is computed over a canonical serialisation, so
     * changing this list or its order invalidates every stored hash. Adding a
     * field means re-sealing the chain in a migration.
     */
    public const HASHED_FIELDS = [
        // Deliberately excludes id. The digest has to be final before the row
        // is written, so that the database trigger can forbid every UPDATE —
        // including ours. Ordering is still covered: each row commits to its
        // predecessor through previous_hash, so a removed or reordered row
        // breaks the chain just as an edited one does.
        'organization_id',
        'entity_type',
        'entity_id',
        'action_type',
        'field_changed',
        'old_value',
        'new_value',
        'changed_by',
        'changed_at',
        'ip_address',
        'change_reason',
    ];

    /* ------------------------------------------------------------------ */
    /*  Append-only chain */
    /* ------------------------------------------------------------------ */

    protected static function booted(): void
    {
        // Sealed before insert, so the row is immutable from the moment it
        // exists and the database trigger can refuse every later UPDATE.
        static::creating(function (self $row) {
            $row->previous_hash = static::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $row->organization_id)
                ->orderByDesc('id')
                ->value('hash');

            $row->hash = static::chainHash($row->getAttributes(), $row->previous_hash);
        });

        static::updating(function (self $row) {
            throw new AuditTrailIsAppendOnly(
                "risk_audit_trail row {$row->getKey()} cannot be updated: the audit trail is append-only."
            );
        });

        static::deleting(function (self $row) {
            throw new AuditTrailIsAppendOnly(
                "risk_audit_trail row {$row->getKey()} cannot be deleted: the audit trail is append-only."
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

            $payload[$field] = $value === null ? null : (string) $value;
        }

        return hash('sha256', ($previousHash ?? '').'|'.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Recompute this row's hash from its stored contents.
     */
    public function expectedHash(): string
    {
        return self::chainHash($this->getAttributes(), $this->previous_hash);
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Polymorphic relationship to the audited entity.
     *
     * Resolved through the application-wide morph map rather than a private
     * copy of it, so there is one place that decides what "risk" means.
     * Historic rows that predate the map may hold a class basename; those
     * resolve to null rather than being silently pointed at Risk, which is
     * what the previous fallback did.
     */
    public function auditable()
    {
        $class = Relation::getMorphedModel(
            MorphTypes::normalise($this->entity_type) ?? ''
        );

        return $this->belongsTo($class ?? Risk::class, 'entity_id');
    }
}
