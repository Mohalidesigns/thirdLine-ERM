<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignResponse extends Model
{
    /**
     * The one control-effectiveness vocabulary in this product.
     *
     * RcsaService's four effectiveness bands, SubmitRcsaWorksheetRequest and
     * the campaign respond form all have to agree, because all three write the
     * same `campaign_responses.control_effectiveness` column and the submission
     * screen reads it back through a single label map. Until Phase 4.5 they did
     * not: the campaign form offered a fifth option, `not_applicable`, that
     * exists nowhere else in the codebase — so a respondent who marked a risk
     * N/A had the answer stored and rendered back as an em dash. See the module
     * notes.
     *
     * @var list<string>
     */
    public const EFFECTIVENESS = ['effective', 'partially_effective', 'ineffective', 'not_tested'];

    protected $fillable = [
        'assignment_id', 'risk_id', 'control_id', 'likelihood_score', 'impact_score',
        'overall_score', 'rating', 'control_effectiveness', 'comments',
        'questionnaire_data',
    ];

    protected $casts = [
        'questionnaire_data' => 'array',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<CampaignAssignment, $this> */
    public function assignment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CampaignAssignment::class, 'assignment_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Risk, $this> */
    public function risk(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Control, $this> */
    public function control(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Control::class);
    }
}
