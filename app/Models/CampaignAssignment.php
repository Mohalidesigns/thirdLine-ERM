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
        'due_date'     => 'date',
        'started_at'   => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at'  => 'datetime',
    ];

    public function campaign()     { return $this->belongsTo(AssessmentCampaign::class, 'campaign_id'); }
    public function businessUnit() { return $this->belongsTo(BusinessUnit::class); }
    public function respondent()   { return $this->belongsTo(User::class, 'respondent_id'); }
    public function reviewer()     { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function responses()    { return $this->hasMany(CampaignResponse::class, 'assignment_id'); }
}
