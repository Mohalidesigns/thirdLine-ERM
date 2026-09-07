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

    /**
     * The document itself.
     *
     * Absent until Phase 6 needed it, and its absence was a silent one:
     * `ResponseQualityChecker` read `$evidence->document` to find answers
     * relying on expired evidence, and with no relation that read returned
     * null every time — the check existed and never fired. PHPStan found it;
     * no test would have, because "no flags raised" is what a clean assessment
     * looks like too.
     *
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
