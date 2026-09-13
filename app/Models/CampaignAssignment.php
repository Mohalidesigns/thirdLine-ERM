<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignAssignment extends Model
{
    protected $fillable = [
        'campaign_id', 'business_unit_id', 'respondent_id', 'reviewer_id',
        'status', 'due_date', 'started_at', 'submitted_at', 'reviewed_at',
        'reviewer_notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<AssessmentCampaign, $this> */
    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssessmentCampaign::class, 'campaign_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function respondent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function reviewer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<CampaignResponse, $this> */
    public function responses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CampaignResponse::class, 'assignment_id');
    }
}
