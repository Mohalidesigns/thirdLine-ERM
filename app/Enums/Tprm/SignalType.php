<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * What a monitoring signal is about — and, through `upliftPenalty()`, what it
 * costs the residual score (TRD §7.5).
 *
 * THE FIRST NINE ARE INTERNALLY DERIVED and need no subscription to anything.
 * That is FR-MON-09 and it is a commercial position, not a convenience: a
 * Nigerian client with no data budget still gets true continuous monitoring on
 * day one, because every one of these is computable from data the system
 * already holds — evidence that expired, an assessment that went overdue, an
 * access grant nobody revoked. The external drivers are the upgrade, not the
 * feature.
 *
 * `SanctionsMatch` has no penalty because it is not one: a confirmed match
 * forces RR to 100 outright (AC-08). `upliftPenalty()` returns null for it so
 * that a caller summing penalties cannot accidentally treat the worst signal
 * in the module as worth a handful of points.
 */
enum SignalType: string
{
    use EnumHelpers;

    /* Internally derived — FR-MON-09, no external subscription required. */
    case EvidenceExpiring = 'evidence_expiring';
    case EvidenceExpired = 'evidence_expired';
    case AssessmentOverdue = 'assessment_overdue';
    case FindingOverdue = 'finding_overdue';
    case SlaBreach = 'sla_breach';
    case MissingDpa = 'missing_dpa';
    case UnrevokedAccess = 'unrevoked_access';
    case ExitPlanStale = 'exit_plan_stale';
    case ScreeningOverdue = 'screening_overdue';
    case ContractNoticeWindow = 'contract_notice_window';

    /* Externally sourced — optional, credential-gated drivers. */
    case SanctionsMatch = 'sanctions_match';
    case AdverseMedia = 'adverse_media';
    case CyberRatingChange = 'cyber_rating_change';
    case FinancialDistress = 'financial_distress';
    case RegulatoryAction = 'regulatory_action';
    case DataBreach = 'data_breach';
    case CveExposure = 'cve_exposure';

    public function label(): string
    {
        return match ($this) {
            self::EvidenceExpiring => 'Evidence expiring',
            self::EvidenceExpired => 'Evidence expired',
            self::AssessmentOverdue => 'Assessment overdue',
            self::FindingOverdue => 'Finding overdue',
            self::SlaBreach => 'SLA breach',
            self::MissingDpa => 'Missing data processing agreement',
            self::UnrevokedAccess => 'Unrevoked access on terminated engagement',
            self::ExitPlanStale => 'Exit plan stale',
            self::ScreeningOverdue => 'Screening overdue',
            self::ContractNoticeWindow => 'Contract inside notice window',
            self::SanctionsMatch => 'Sanctions or PEP match',
            self::AdverseMedia => 'Adverse media',
            self::CyberRatingChange => 'Cyber rating change',
            self::FinancialDistress => 'Financial distress indicator',
            self::RegulatoryAction => 'Regulatory action against vendor',
            self::DataBreach => 'Confirmed data breach',
            self::CveExposure => 'CVE exposure',
        };
    }

    /**
     * Whether this signal is produced by InternalSignalGenerator rather than
     * by an external driver. The monitoring source-health panel groups by it.
     */
    public function isInternallyDerived(): bool
    {
        return in_array($this, [
            self::EvidenceExpiring,
            self::EvidenceExpired,
            self::AssessmentOverdue,
            self::FindingOverdue,
            self::SlaBreach,
            self::MissingDpa,
            self::UnrevokedAccess,
            self::ExitPlanStale,
            self::ScreeningOverdue,
            self::ContractNoticeWindow,
        ], true);
    }

    /**
     * The SU penalty this signal contributes, or null where it contributes
     * none — either because it is an override (sanctions) or because it is a
     * warning that has not yet cost anything (evidence EXPIRING, as opposed to
     * expired; a contract merely inside its notice window).
     *
     * @return int|null points, before the SU cap of 20
     */
    public function upliftPenalty(): ?int
    {
        /** @var array<string, int> $penalties */
        $penalties = config('tprm.scoring.signal_uplift.penalty');

        return match ($this) {
            self::SanctionsMatch => null,
            self::EvidenceExpiring, self::ContractNoticeWindow, self::CveExposure, self::AdverseMedia => null,
            self::DataBreach => $penalties['confirmed_breach_12m'],
            self::CyberRatingChange => $penalties['cyber_rating_band_drop'],
            self::FinancialDistress => $penalties['financial_distress'],
            self::RegulatoryAction => $penalties['regulatory_action'],
            self::SlaBreach => $penalties['sla_breach_3_periods'],
            self::EvidenceExpired, self::MissingDpa => $penalties['expired_mandatory_evidence'],
            self::AssessmentOverdue, self::ScreeningOverdue => $penalties['overdue_assessment'],
            self::UnrevokedAccess => $penalties['unrevoked_access_terminated'],
            self::FindingOverdue, self::ExitPlanStale => null,
        };
    }

    /** Whether a confirmed instance forces RR to 100 (AC-08). */
    public function forcesMaximumResidual(): bool
    {
        return $this === self::SanctionsMatch;
    }
}
