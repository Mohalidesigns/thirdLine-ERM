<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasObjectIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssessmentCampaign extends Model
{
    use BelongsToOrganization, HasObjectIdentity, SoftDeletes;

    protected $fillable = [
        'organization_id', 'campaign_code', 'title', 'description', 'campaign_type',
        'questionnaire_id', 'status', 'start_date', 'end_date', 'created_by',
        'reviewer_id', 'total_assignments', 'completed_assignments', 'completion_pct',
        'settings', 'launched_at', 'closed_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'launched_at' => 'datetime',
        'closed_at' => 'datetime',
        'completion_pct' => 'decimal:2',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function questionnaire()
    {
        return $this->belongsTo(Questionnaire::class);
    }

    public function assignments()
    {
        return $this->hasMany(CampaignAssignment::class, 'campaign_id');
    }

    /**
     * Assignments a reviewer has accepted. This — and only this — is what
     * completion_pct counts, because that figure is the campaign's assurance
     * number: it is averaged on the dashboard, sorted on in the register grid
     * and published through the API. Work that has been handed in but not yet
     * looked at is not assurance, so it must not inflate it.
     */
    public const COMPLETED_STATUSES = ['approved'];

    /**
     * Handed in and sitting with a reviewer. Shown as its own segment of the
     * progress bar so a campaign whose respondents have finished does not read
     * as 0% and look abandoned.
     */
    public const AWAITING_REVIEW_STATUSES = ['submitted', 'under_review'];

    public function recalculateProgress(): void
    {
        $total = $this->assignments()->count();
        $completed = $this->assignments()->whereIn('status', self::COMPLETED_STATUSES)->count();
        $this->update([
            'total_assignments' => $total,
            'completed_assignments' => $completed,
            'completion_pct' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
        ]);
    }

    /**
     * Eager-loads the three counts the progress bar needs, so a list screen
     * does not fall into a query per campaign.
     */
    public function scopeWithProgressCounts(Builder $query): Builder
    {
        return $query->withCount([
            'assignments',
            'assignments as completed_count' => fn ($q) => $q->whereIn('status', self::COMPLETED_STATUSES),
            'assignments as awaiting_review_count' => fn ($q) => $q->whereIn('status', self::AWAITING_REVIEW_STATUSES),
        ]);
    }

    /**
     * Counts behind the progress bar, from whichever source is already to hand:
     * an eager-loaded assignments collection, the counts from
     * withProgressCounts(), or — last resort — a query.
     */
    public function progressBreakdown(): array
    {
        if ($this->relationLoaded('assignments')) {
            $assignments = $this->assignments;
            $total = $assignments->count();
            $completed = $assignments->whereIn('status', self::COMPLETED_STATUSES)->count();
            $awaiting = $assignments->whereIn('status', self::AWAITING_REVIEW_STATUSES)->count();
        } elseif ($this->awaiting_review_count !== null) {
            $total = (int) $this->assignments_count;
            $completed = (int) $this->completed_count;
            $awaiting = (int) $this->awaiting_review_count;
        } else {
            $total = $this->assignments()->count();
            $completed = $this->assignments()->whereIn('status', self::COMPLETED_STATUSES)->count();
            $awaiting = $this->assignments()->whereIn('status', self::AWAITING_REVIEW_STATUSES)->count();
        }

        $pct = fn (int $n) => $total > 0 ? round(($n / $total) * 100, 2) : 0.0;

        return [
            'total' => $total,
            'completed' => $completed,
            'awaiting_review' => $awaiting,
            'completed_pct' => $pct($completed),
            'awaiting_review_pct' => $pct($awaiting),
        ];
    }
}
