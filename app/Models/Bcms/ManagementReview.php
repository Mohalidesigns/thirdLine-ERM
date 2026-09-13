<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A management review of the BCMS — ISO 22301 clause 9.3, and a MANDATORY
 * documented record.
 *
 * `inputs` IS A SNAPSHOT, NOT A LIVE QUERY. A review held in March considered
 * March's CAPA status, March's exercise outcomes and March's KRI performance.
 * Re-deriving them for a reader in December would rewrite what the meeting
 * actually looked at, which is the one thing this record exists to preserve.
 *
 * Actions arising are CORRECTIVE ACTIONS against a finding whose source is
 * `management_review` — not a second action register (ADR 0008).
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $programme_id
 * @property string $reference
 * @property string $title
 * @property \Illuminate\Support\Carbon $held_on
 * @property ?int $chaired_by
 * @property array<array-key, mixed> $attendees
 * @property array<array-key, mixed> $inputs
 * @property ?\Illuminate\Support\Carbon $inputs_captured_at
 * @property ?string $discussion
 * @property array<array-key, mixed> $decisions
 * @property string $status
 * @property ?int $approved_by
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class ManagementReview extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_management_reviews';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'programme_id', 'reference', 'title', 'held_on', 'chaired_by',
        'attendees', 'inputs', 'inputs_captured_at', 'discussion', 'decisions', 'status',
        'approved_by', 'approved_at', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'programme_id' => 'integer',
            'held_on' => 'date',
            'chaired_by' => 'integer',
            'attendees' => 'array',
            'inputs' => 'array',
            'inputs_captured_at' => 'datetime',
            'decisions' => 'array',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Programme, $this> */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    /** @return BelongsTo<User, $this> */
    public function chair(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chaired_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<Finding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'management_review_id');
    }
}
