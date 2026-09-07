<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\AssessmentStatus;
use App\Models\Organization;
use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One questionnaire cycle against one engagement.
 *
 * `template_version` is stored, not derived from the template row. A published
 * template is frozen, but a successor may be published while this cycle is
 * still open, and the assessment has to keep saying which version its answers
 * were given against — otherwise a reviewer six months later reads today's
 * questions beside last year's answers.
 *
 * `scoping_trace` holds the per-question record of why each question was or
 * was not asked (FR-ASM-03). It is written once, at scoping, and never
 * recomputed: re-running the scoper against today's engagement attributes
 * would produce a trace that no longer explains the questionnaire in front of
 * the reader.
 */
class Assessment extends Model
{
    use BelongsToOrganization, HasTprmUuid, SoftDeletes, TprmAuditable;

    protected $table = 'tp_assessments';

    /** @var list<string> */
    public const TYPES = ['initial', 'periodic', 'targeted', 'delta', 'internal_only', 'analyst_assisted'];

    protected $fillable = [
        'organization_id', 'engagement_id', 'template_id', 'template_version',
        'cycle_label', 'assessment_type', 'trigger_source', 'status',
        'issued_at', 'due_at', 'submitted_at', 'validated_at', 'expires_at',
        'assigned_portal_contact_id', 'internal_reviewer_id', 'analyst_id',
        'parent_assessment_id', 'scoping_trace',
        'question_count', 'applicable_count', 'answered_count',
        'created_by', 'updated_by',
    ];

    /**
     * Score columns are not fillable. `AssessmentService` writes them after
     * `AssessmentScorer` has produced them; a form post must never be able to
     * set an assurance score.
     *
     * @var list<string>
     */
    public const SCORE_COLUMNS = ['raw_score', 'ac', 'ec', 'section_scores', 'domain_scores'];

    protected $casts = [
        'status' => AssessmentStatus::class,
        'scoping_trace' => 'array',
        'section_scores' => 'array',
        'domain_scores' => 'array',
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'submitted_at' => 'datetime',
        'validated_at' => 'datetime',
        'expires_at' => 'datetime',
        'raw_score' => 'decimal:3',
        'ac' => 'decimal:3',
        'ec' => 'decimal:3',
    ];

    protected $attributes = [
        'status' => 'draft',
        'assessment_type' => 'initial',
        'question_count' => 0,
        'applicable_count' => 0,
        'answered_count' => 0,
    ];

    /** @param  Builder<self>  $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereIn('status', ['issued', 'in_progress', 'clarification_requested']);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<QuestionnaireTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireTemplate::class, 'template_id');
    }

    /** @return HasMany<AssessmentResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(AssessmentResponse::class, 'assessment_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parentAssessment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_assessment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'internal_reviewer_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function portalContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'assigned_portal_contact_id');
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && $this->status->isOpenToVendor();
    }

    /** Days past the due date, or null when it is not overdue. */
    public function daysOverdue(): ?int
    {
        return $this->isOverdue() ? (int) now()->startOfDay()->diffInDays($this->due_at, false) * -1 : null;
    }
}
