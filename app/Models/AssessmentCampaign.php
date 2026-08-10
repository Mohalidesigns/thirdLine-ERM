<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasObjectIdentity;
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

    public function recalculateProgress(): void
    {
        $total = $this->assignments()->count();
        $completed = $this->assignments()->whereIn('status', ['approved'])->count();
        $this->update([
            'total_assignments' => $total,
            'completed_assignments' => $completed,
            'completion_pct' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
        ]);
    }
}
