<?php

namespace App\Events;

use App\Models\AssessmentCampaign;
use App\Models\CampaignAssignment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a respondent submits a completed RCSA worksheet.
 *
 * Only a submission raises this — saving a draft does not, because a draft is
 * not an assertion about the control environment and should not put anything in
 * a reviewer's queue.
 */
class RcsaWorksheetSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CampaignAssignment $assignment,
        public AssessmentCampaign $campaign,
        public int $responseCount,
    ) {}
}
