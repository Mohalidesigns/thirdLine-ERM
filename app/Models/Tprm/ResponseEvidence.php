<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A document attached to a specific answer, with the page it was found on.
 *
 * `page_reference` and `extract_text` are what turn "here is our SOC 2" into
 * "here is the paragraph that answers this question" — the difference between
 * a reviewer opening a 90-page PDF and a reviewer reading one sentence.
 */
class ResponseEvidence extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_response_evidence';

    protected $fillable = [
        'organization_id', 'response_id', 'document_id',
        'page_reference', 'extract_text', 'added_by_portal',
    ];

    protected $casts = ['added_by_portal' => 'boolean'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AssessmentResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(AssessmentResponse::class, 'response_id');
    }
}
