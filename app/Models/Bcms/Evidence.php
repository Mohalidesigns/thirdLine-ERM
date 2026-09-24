<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\BindsToVisibleRecord;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One evidence artefact against an exercise occurrence — ADR 0019.
 *
 * ONE TABLE, A LOCAL KIND REGISTRY, NOT THE GLOBAL MORPH MAP. `owner_type` is
 * one of `occurrence`, `score`, `readiness_task` or `aar` — deliberately kept
 * out of the enforced morph map, exactly as `Document::ownerModels()` and
 * `tp_waivers.waivable_type` do, because `score` or `aar` in the global
 * namespace would claim names the wider product may want.
 *
 * `occurrence_id` IS NOT NULL. It is the single anchor path (ADR 0019 §4):
 * `orgAnchorPath()` = `occurrence.definition`, the same path
 * `ExerciseParticipant` and `ReadinessTask` take — an evidence photograph of
 * identifiable staff is personal data under the NDPA with a need-to-know
 * test, and a uuid in a URL is exactly how another division would read it
 * without this.
 *
 * `locked_at`/`locked_by` ARE SET ONLY BY `AarService::finalise()`, in the
 * same transaction as the AAR's own `status = final`, after every row's
 * stored bytes have been hash-verified. Nothing else in this model enforces
 * the lock — the write path (`EvidenceService`) refuses an update or delete
 * on a locked row and logs the refusal, which is what makes "immutable on
 * finalise" a claim rather than an assertion.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $occurrence_id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $kind
 * @property ?string $caption
 * @property string $file_name
 * @property string $file_path
 * @property string $mime
 * @property int $size
 * @property string $hash
 * @property ?\Illuminate\Support\Carbon $captured_at
 * @property ?string $iso_clause_ref
 * @property ?int $uploaded_by
 * @property ?\Illuminate\Support\Carbon $locked_at
 * @property ?int $locked_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Evidence extends Model
{
    use BcmsAuditable, BelongsToOrganization, BindsToVisibleRecord, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_evidence';

    /**
     * Derived (ADR 0017 §2 / ADR 0019 §4): evidence has no unit column of its
     * own and takes the shortest path to an anchor — its occurrence.
     */
    public function orgAnchorPath(): string
    {
        return 'occurrence.definition';
    }

    /**
     * ADR 0017 Amendment 1, extended to Phase 9 (Gate 2 finding from Phase
     * 7.5's review): declared here, not inherited transitively from
     * `ExerciseOccurrence`'s own arm — `constrainAnchorPath()`'s
     * `whereHas('occurrence', …)` chain consults only the occurrence's
     * `scopeVisibleTo()`, so a cross-unit facilitator who reaches the
     * occurrence through ITS arm still 404s on the evidence upload/download/
     * destroy routes without this, exactly the defect `ReadinessTask`'s own
     * docblock describes for its task.
     *
     * `occurrence.facilitator_id`: the facilitator runs the exercise and
     * manages its evidence regardless of which unit the occurrence sits in.
     *
     * `uploaded_by` is deliberately NOT an arm. Every evidence route nests
     * under `occurrences/{occurrence}` with `->scopeBindings()`, so the
     * parent segment resolves through `ExerciseOccurrence`'s own visibility
     * first; an uploader who is neither the facilitator nor a participant nor
     * in-unit 404s there before this model's arm is consulted. Declaring the
     * fact here would be a claim the routes cannot honour — gate 1 proved it
     * dead with a same-unit-uploader test. An evaluator reaches evidence the
     * way they reached the occurrence to upload it in the first place.
     *
     * @return list<string>
     */
    public function orgVisibilityNamedUsers(): array
    {
        return ['occurrence.facilitator_id'];
    }

    /** The four owner kinds this registry recognises. ADR 0019 §1.5 — deliberately no others yet. */
    public const KIND_OCCURRENCE = 'occurrence';

    public const KIND_SCORE = 'score';

    public const KIND_READINESS_TASK = 'readiness_task';

    public const KIND_AAR = 'aar';

    /** @return list<string> */
    public static function ownerKinds(): array
    {
        return [self::KIND_OCCURRENCE, self::KIND_SCORE, self::KIND_READINESS_TASK, self::KIND_AAR];
    }

    /**
     * Advisory 8 (Gate 2, ADR 0019 §2): `hash`, `locked_at` and `locked_by`
     * are DELIBERATELY not fillable. `hash` is computed by `EvidenceService`
     * from the bytes as stored, never taken from caller input; `locked_at`/
     * `locked_by` are written only by `AarService::finalise()`'s hash-verify-
     * then-lock transaction. Mass-assignable, any of the three would be a
     * caller-controlled route to "this file matches what was recorded" or
     * "this row is signed off" — exactly the claim ADR 0019 §2 says a hash
     * and a lock exist to make instead of merely asserting. Both write paths
     * already use `forceFill()`, so removing these three costs nothing there.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id', 'occurrence_id', 'owner_type', 'owner_id', 'kind', 'caption',
        'file_name', 'file_path', 'mime', 'size', 'captured_at', 'iso_clause_ref',
        'uploaded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'occurrence_id' => 'integer',
            'owner_id' => 'integer',
            'size' => 'integer',
            'captured_at' => 'datetime',
            'uploaded_by' => 'integer',
            'locked_at' => 'datetime',
            'locked_by' => 'integer',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<ExerciseOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ExerciseOccurrence::class, 'occurrence_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
