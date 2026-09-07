<?php

namespace App\Events\Tprm;

use App\Models\Tprm\Engagement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something changed that the residual score depends on.
 *
 * ONE EVENT, NOT SEVEN. The phase prompt lists listeners on
 * `AssessmentValidated`, `FindingRaised`, `FindingClosed`, `FindingOverdue`,
 * `RiskAcceptanceExpired`, `EvidenceExpired` and `MonitoringSignalReceived` —
 * and every one of them would carry the same listener doing the same thing.
 * Seven events with one handler is seven places to forget to dispatch from,
 * and the failure mode is silent: a score that stops moving, which looks
 * exactly like a score that has not changed.
 *
 * The `reason` carries what the seven names would have carried, and it lands
 * in `tp_score_runs.triggered_by` — so the run history still answers "why did
 * this recompute", which is the question the separate events existed to
 * answer. When Phase 6 brings monitoring signals and Phase 7 brings nth-party
 * changes, they dispatch this with their own reason rather than adding an
 * eighth class.
 */
class EngagementScoreInvalidated
{
    use Dispatchable, SerializesModels;

    public const ASSESSMENT_VALIDATED = 'assessment_validated';

    public const ASSESSMENT_SCORED = 'assessment_scored';

    public const FINDING_RAISED = 'finding_raised';

    public const FINDING_CLOSED = 'finding_closed';

    public const FINDING_OVERDUE = 'finding_overdue';

    public const RISK_ACCEPTED = 'risk_accepted';

    public const RISK_ACCEPTANCE_EXPIRED = 'risk_acceptance_expired';

    public const EVIDENCE_EXPIRED = 'evidence_expired';

    public const EVIDENCE_CONFIRMED = 'evidence_confirmed';

    public const MONITORING_SIGNAL = 'monitoring_signal';

    public const TIER_CHANGED = 'tier_changed';

    public const SCHEDULED = 'scheduled';

    public function __construct(
        public readonly Engagement $engagement,
        public readonly string $reason,
    ) {}
}
