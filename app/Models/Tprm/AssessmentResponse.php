<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\ComplianceLevel;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One answer.
 *
 * `computed_conf` is STORED rather than recomputed on read, and that is the
 * same rule the engagement's scores follow: the confidence shown beside an
 * answer must be the one that produced the assessment's EC, not a fresh
 * calculation against today's evidence dates. An evidence document expiring
 * next week should not silently restate what last month's validated
 * assessment scored.
 *
 * `reviewer_status` and `compliance` are different things and both are needed.
 * `compliance` is the verdict that enters the arithmetic; `reviewer_status` is
 * whether a human has looked at it. An answer can be compliant and unreviewed,
 * which is exactly the state an auto-answered question starts in.
 */
class AssessmentResponse extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_assessment_responses';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_REJECTED = 'rejected';

    public const REVIEW_CLARIFICATION = 'clarification_requested';

    protected $fillable = [
        'organization_id', 'assessment_id', 'question_id', 'value',
        'assurance_level', 'compliance', 'is_auto_answered', 'auto_answer_source',
        'carried_forward_from_response_id', 'carry_forward_cycles',
        'vendor_comment', 'reviewer_status', 'reviewer_comment',
        'reviewed_by', 'reviewed_at', 'quality_flags', 'computed_conf',
    ];

    protected $casts = [
        'value' => 'array',
        'auto_answer_source' => 'array',
        'quality_flags' => 'array',
        'compliance' => ComplianceLevel::class,
        'assurance_level' => AssuranceLevel::class,
        'is_auto_answered' => 'boolean',
        'reviewed_at' => 'datetime',
        'computed_conf' => 'decimal:3',
    ];

    protected $attributes = [
        'compliance' => 'unanswered',
        'reviewer_status' => self::REVIEW_PENDING,
        'is_auto_answered' => false,
        'carry_forward_cycles' => 0,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    /** @return BelongsTo<self, $this> */
    public function carriedForwardFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_forward_from_response_id');
    }

    /** @return HasMany<ResponseEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(ResponseEvidence::class, 'response_id');
    }

    public function isAnswered(): bool
    {
        return $this->compliance !== ComplianceLevel::Unanswered;
    }
}
