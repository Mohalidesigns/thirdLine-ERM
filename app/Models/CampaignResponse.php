<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignResponse extends Model
{
    protected $fillable = [
        'assignment_id', 'risk_id', 'control_id', 'likelihood_score', 'impact_score',
        'overall_score', 'rating', 'control_effectiveness', 'comments',
        'questionnaire_data',
    ];

    protected $casts = [
        'questionnaire_data' => 'array',
    ];

    public function assignment() { return $this->belongsTo(CampaignAssignment::class, 'assignment_id'); }
    public function risk()       { return $this->belongsTo(Risk::class); }
    public function control()    { return $this->belongsTo(Control::class); }
}
