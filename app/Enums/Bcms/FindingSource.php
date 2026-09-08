<?php

namespace App\Enums\Bcms;

/**
 * What produced a finding (`bcms_findings.source`, ADR 0008).
 *
 * SIX SOURCES, FOUR OF WHICH THE DATABASE CAN CHECK. `aar`, `incident`,
 * `call_tree_test` and `dr_test` each have a nullable foreign key beside them;
 * `audit` comes from thirdLine's `issues` register and `gap_analysis` from the
 * maturity engine, and neither has a `bcms_` row to point at. The `source`
 * string names the kind for all six, so a register filter and an evidence pack
 * do not have to guess from which FK happens to be set.
 *
 * `management_review` is a seventh, added by the same ADR: an action arising
 * from a clause 9.3 review is a corrective action against a finding, not a
 * second action register.
 *
 * `plan_review` is an eighth, added in Phase 3. A plan whose bound sections no
 * longer match the BIA they were assembled from is a real gap and the register
 * is where gaps live; `affected_plan_id` was already on the table, so it is one
 * of the checkable four rather than a fifth orphan.
 *
 * WHY THE KIND IS STORED AS WELL AS THE LINK. "Show me every nonconformity that
 * came out of an exercise this year" is the question a certification auditor
 * asks, and answering it by testing which of six columns is non-null is a query
 * nobody will write correctly twice.
 */
enum FindingSource: string
{
    case Aar = 'aar';
    case Incident = 'incident';
    case CallTreeTest = 'call_tree_test';
    case DrTest = 'dr_test';
    case Audit = 'audit';
    case GapAnalysis = 'gap_analysis';
    case ManagementReview = 'management_review';
    case PlanReview = 'plan_review';

    /** The foreign key that carries the link, or null where none can. */
    public function foreignKey(): ?string
    {
        return match ($this) {
            self::Aar => 'aar_id',
            self::Incident => 'incident_id',
            self::CallTreeTest => 'call_tree_test_id',
            self::DrTest => 'dr_test_id',
            self::ManagementReview => 'management_review_id',
            self::PlanReview => 'affected_plan_id',
            self::Audit, self::GapAnalysis => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Aar => 'Exercise after-action report',
            self::Incident => 'Incident',
            self::CallTreeTest => 'Call tree test',
            self::DrTest => 'DR test',
            self::Audit => 'Internal audit',
            self::GapAnalysis => 'Gap analysis',
            self::ManagementReview => 'Management review',
            self::PlanReview => 'Plan review',
        };
    }

    /**
     * The clause this source's findings evidence.
     *
     * A nonconformity is filed under 10.1 whatever raised it; this is the
     * clause the SOURCE evidences, which is what an examiner working through
     * the standard is following.
     */
    public function clauseRef(): IsoClauseRef
    {
        return match ($this) {
            self::Aar => IsoClauseRef::Iso22301_8_5_report,
            self::Incident => IsoClauseRef::Iso22320_incident,
            self::CallTreeTest => IsoClauseRef::Iso22301_8_4_3,
            self::DrTest => IsoClauseRef::Iso22301_8_4_5,
            self::Audit => IsoClauseRef::Iso22301_9_2_results,
            self::GapAnalysis => IsoClauseRef::Iso22301_8_6,
            self::ManagementReview => IsoClauseRef::Iso22301_9_3_results,
            self::PlanReview => IsoClauseRef::Iso22301_8_4_4,
        };
    }
}
