<?php

namespace App\Models\Tprm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One section of one assessment, handed to a colleague — FR-PRT-03.
 *
 * BETWEEN THE VENDOR'S OWN PEOPLE. Both ends are portal users; our staff are
 * not part of it and cannot be delegated to. A bank reviewer who wants an
 * answer from a particular person asks in the thread.
 *
 * It hangs off the ASSESSMENT, not the section. A section belongs to a
 * template shared by every assessment issued from it — and, for the packs we
 * ship, by every tenant — so recording "Ada owns security" on the section
 * would put one vendor's staffing on a row another vendor reads.
 */
class AssessmentDelegation extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_assessment_delegations';

    protected $fillable = [
        'organization_id', 'assessment_id', 'section_id',
        'delegated_to', 'delegated_by', 'note', 'delegated_at',
    ];

    /** @var list<string> */
    public const GUARDED_STATE = ['completed_at'];

    protected $casts = [
        'delegated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    /** @return BelongsTo<QuestionnaireSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireSection::class, 'section_id');
    }

    /** @return BelongsTo<PortalUser, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class, 'delegated_to');
    }

    /** @return BelongsTo<PortalUser, $this> */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class, 'delegated_by');
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null;
    }
}
