<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\SyncChangeDecision;
use App\Enums\Bcms\SyncChangeKind;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One staged directory fact — ADR 0018 §2.4. NO UUID: a child table nested
 * under its run (ADR 0007 deviation 4), addressed as
 * `identity/runs/{run}/changes/{change}`.
 *
 * `requires_ack` IS STORED, NOT COMPUTED AT READ TIME — it was evaluated
 * against the estate as it stood when the sync ran; recomputing when the
 * queue is opened would let an unrelated tree edit silently turn a change
 * that needed a signature into one that did not (ADR 0018 §2.4).
 *
 * `after_json` IS THE FIELD-LEVEL PROVENANCE STORE. It is what lets
 * `ChangeApplier` refuse to overwrite a value a human has edited since the
 * last sync without a per-field provenance column on `bcms_contacts`.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sync_run_id
 * @property ?int $contact_id
 * @property \App\Enums\Bcms\SyncChangeKind $kind
 * @property string $directory_object_id
 * @property string $subject_name
 * @property ?array<string, mixed> $before_json
 * @property ?array<string, mixed> $after_json
 * @property ?array<string, mixed> $impact_json
 * @property bool $requires_ack
 * @property \App\Enums\Bcms\SyncChangeDecision $decision
 * @property ?int $decided_by
 * @property ?\Illuminate\Support\Carbon $decided_at
 * @property ?\Illuminate\Support\Carbon $applied_at
 * @property ?string $apply_error_class
 */
class IdentitySyncChange extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasFactory;

    protected $table = 'bcms_identity_sync_changes';

    /**
     * NDPA register §7.5.1: without this override, `created` writes the
     * row's whole attribute set into `bcms_audit_logs.after` — including
     * `before_json`/`after_json`/`impact_json`, a second copy of every
     * synced person's name, mobile, email and department in an append-only
     * table with no purge path (Blueprint §14). ADR 0018 §2.2 point 6 is
     * explicit that the run/change rows themselves ARE the audit trail of a
     * directory read; an audit row over this table should record only that
     * a change was staged/decided/applied, by whom and when, not a second
     * copy of the payload the change row it points at already holds.
     *
     * `subject_name` IS DELIBERATELY LEFT IN. An audit row that cannot say
     * whose record was changed is not an audit row — the row still needs to
     * be readable, and a name without the JSON payload is the minimum that
     * keeps it so.
     *
     * `BcmsAuditable::auditExcluded()` IS A TRAIT METHOD, NOT A PARENT CLASS
     * ONE — there is no `parent::auditExcluded()` to call; the base
     * exclusions (`updated_at`, `push_token`, `raw_response`) are repeated
     * here rather than inherited, the same fix `IdentityConnector` already
     * carries for the same reason.
     *
     * @return list<string>
     */
    public function auditExcluded(): array
    {
        return ['updated_at', 'push_token', 'raw_response', 'before_json', 'after_json', 'impact_json'];
    }

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'sync_run_id', 'contact_id', 'kind', 'directory_object_id', 'subject_name',
        'before_json', 'after_json', 'impact_json', 'requires_ack', 'decision', 'decided_by',
        'decided_at', 'applied_at', 'apply_error_class',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'sync_run_id' => 'integer',
            'contact_id' => 'integer',
            'kind' => SyncChangeKind::class,
            'before_json' => 'array',
            'after_json' => 'array',
            'impact_json' => 'array',
            'requires_ack' => 'boolean',
            'decision' => SyncChangeDecision::class,
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IdentitySyncRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(IdentitySyncRun::class, 'sync_run_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
