<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\CorrectiveActionStatus;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A corrective action against a finding (ISO 22301 clause 10.1).
 *
 * `completed` and `verified` are two states and the gap between them is the
 * whole point of the clause: the owner says it is done, somebody who is not the
 * owner confirms it worked.
 *
 * `carried_to_occurrence_id` IS WRITTEN EXCLUSIVELY BY THE EXERCISE ENGINE. It
 * is the mechanism behind the ISO 22398 ladder — each level builds on the
 * corrective actions of the one below — and anything else writing it breaks the
 * audit trail of why an action appeared on an exercise's checklist.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $finding_id
 * @property string $reference
 * @property string $title
 * @property ?string $description
 * @property ?int $owner_id
 * @property ?\Illuminate\Support\Carbon $due_date
 * @property ?string $priority
 * @property \App\Enums\Bcms\CorrectiveActionStatus $status
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?int $completed_by
 * @property ?int $verified_by
 * @property ?\Illuminate\Support\Carbon $verified_at
 * @property ?int $verification_evidence_id
 * @property ?string $verification_note
 * @property ?int $carried_to_occurrence_id
 * @property ?\Illuminate\Support\Carbon $carried_at
 * @property ?string $acceptance_rationale
 * @property ?int $accepted_by
 * @property ?\Illuminate\Support\Carbon $accepted_at
 * @property ?\Illuminate\Support\Carbon $acceptance_expires_on
 * @property ?int $erm_issue_id
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class CorrectiveAction extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_corrective_actions';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'finding_id', 'reference', 'title', 'description', 'owner_id',
        'due_date', 'priority', 'status', 'completed_at', 'completed_by', 'verified_by',
        'verified_at', 'verification_evidence_id', 'verification_note', 'carried_to_occurrence_id',
        'carried_at', 'acceptance_rationale', 'accepted_by', 'accepted_at',
        'acceptance_expires_on', 'erm_issue_id', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'finding_id' => 'integer',
            'owner_id' => 'integer',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'completed_by' => 'integer',
            'verified_by' => 'integer',
            'verified_at' => 'datetime',
            'verification_evidence_id' => 'integer',
            'carried_to_occurrence_id' => 'integer',
            'carried_at' => 'datetime',
            'accepted_by' => 'integer',
            'accepted_at' => 'datetime',
            'acceptance_expires_on' => 'date',
            'erm_issue_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'status' => CorrectiveActionStatus::class,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Finding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class, 'finding_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<ExerciseOccurrence, $this> */
    public function carriedToOccurrence(): BelongsTo
    {
        return $this->belongsTo(ExerciseOccurrence::class, 'carried_to_occurrence_id');
    }

    /** @return BelongsTo<Issue, $this> */
    public function ermIssue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'erm_issue_id');
    }
}
