<?php

namespace App\Enums\Bcms;

/**
 * The `iso_clause_ref` taxonomy — the single published list of clause
 * references any BCMS artefact may claim (Orchestration §5, compliance-analyst
 * remit).
 *
 * WHY AN ENUM AND NOT A STRING COLUMN WITH A CONVENTION. A convention is
 * checked by whoever remembers it. `ISO22301:8.5`, `iso-22301-8.5` and
 * `8.5` are three spellings of one clause, and a regulator evidence pack that
 * filters on one of them silently omits the artefacts filed under the other
 * two. An examiner does not get a second visit.
 *
 * WHY A SEEDED REFERENCE TABLE AS WELL. The enum is the closed set; the table
 * (`bcms_clause_refs`, system-owned, `organization_id = null`) carries the
 * title, the standard, the mandatory-record flag and the export grouping, and
 * is what a screen joins to. Neither is derivable from the other and neither
 * is optional: the enum stops an invented ref reaching the database, the table
 * stops every screen hard-coding clause titles.
 *
 * NO OTHER AGENT ADDS CASES. A phase that finds it needs a clause that is not
 * here raises it with the compliance-analyst, who checks it against the
 * published standard and adds it in one place. A case added here without a
 * corresponding row in `Database\Seeders\Bcms\Reference\ClauseRefs` fails
 * `Phase0FoundationsTest::every_clause_ref_case_is_seeded()`.
 *
 * SUB-CLAUSE DEPTH. The phase prompt asks for sub-clause level on 8.4, 8.5,
 * 9.2, 9.3 and 10.1 — the five that carry ISO 22301's mandatory documented
 * information, and therefore the five where "we satisfy clause 8.5" is not an
 * answer an auditor accepts.
 */
enum IsoClauseRef: string
{
    /* ------------------------------------------------------------------ */
    /*  ISO 22301:2019 — clauses 4 to 7 */
    /* ------------------------------------------------------------------ */

    case Iso22301_4_1 = 'iso22301.4.1';   // Understanding the organisation and its context
    case Iso22301_4_2 = 'iso22301.4.2';   // Interested parties, legal and regulatory requirements
    case Iso22301_4_3 = 'iso22301.4.3';   // Scope of the BCMS
    case Iso22301_5_2 = 'iso22301.5.2';   // Business continuity policy
    case Iso22301_5_3 = 'iso22301.5.3';   // Roles, responsibilities and authorities
    case Iso22301_6_2 = 'iso22301.6.2';   // Business continuity objectives
    case Iso22301_7_2 = 'iso22301.7.2';   // Competence  [MANDATORY RECORD]
    case Iso22301_7_3 = 'iso22301.7.3';   // Awareness
    case Iso22301_7_4 = 'iso22301.7.4';   // Communication
    case Iso22301_7_5 = 'iso22301.7.5';   // Documented information

    /* ------------------------------------------------------------------ */
    /*  Clause 8 — operation. The heart of the module. */
    /* ------------------------------------------------------------------ */

    case Iso22301_8_1 = 'iso22301.8.1';   // Operational planning and control
    case Iso22301_8_2_2 = 'iso22301.8.2.2'; // Business impact analysis
    case Iso22301_8_2_3 = 'iso22301.8.2.3'; // Risk assessment
    case Iso22301_8_3 = 'iso22301.8.3';   // Business continuity strategies and solutions

    // 8.4 — plans and procedures, to sub-clause. An auditor asks for the
    // response structure and the warning-and-communication procedure
    // separately from the plans themselves.
    case Iso22301_8_4_1 = 'iso22301.8.4.1'; // General — plans and procedures  [MANDATORY RECORD]
    case Iso22301_8_4_2 = 'iso22301.8.4.2'; // Response structure
    case Iso22301_8_4_3 = 'iso22301.8.4.3'; // Warning and communication
    case Iso22301_8_4_4 = 'iso22301.8.4.4'; // Business continuity plans
    case Iso22301_8_4_5 = 'iso22301.8.4.5'; // Recovery

    // 8.5 — the exercise programme. Split because the programme, the
    // individual exercise and the post-exercise report are three artefacts
    // with three retention answers.
    case Iso22301_8_5_programme = 'iso22301.8.5.programme'; // Exercise programme  [MANDATORY RECORD]
    case Iso22301_8_5_exercise = 'iso22301.8.5.exercise';   // An individual exercise and its scenario
    case Iso22301_8_5_report = 'iso22301.8.5.report';       // Post-exercise report  [MANDATORY RECORD]

    case Iso22301_8_6 = 'iso22301.8.6';   // Evaluation of BC documentation and capabilities

    /* ------------------------------------------------------------------ */
    /*  Clauses 9 and 10 — evaluation and improvement */
    /* ------------------------------------------------------------------ */

    case Iso22301_9_1 = 'iso22301.9.1';   // Monitoring, measurement, analysis, evaluation

    case Iso22301_9_2_programme = 'iso22301.9.2.programme'; // Internal audit programme  [MANDATORY RECORD]
    case Iso22301_9_2_results = 'iso22301.9.2.results';     // Internal audit results  [MANDATORY RECORD]

    case Iso22301_9_3_inputs = 'iso22301.9.3.inputs';       // Management review inputs
    case Iso22301_9_3_results = 'iso22301.9.3.results';     // Management review results  [MANDATORY RECORD]

    case Iso22301_10_1_nonconformity = 'iso22301.10.1.nonconformity';       // Nonconformity  [MANDATORY RECORD]
    case Iso22301_10_1_corrective = 'iso22301.10.1.corrective_action';      // Corrective action  [MANDATORY RECORD]
    case Iso22301_10_2 = 'iso22301.10.2';  // Continual improvement

    /* ------------------------------------------------------------------ */
    /*  Companion standards — only where they add a requirement 22301 does */
    /*  not state, never as a decoration on an artefact 22301 already owns. */
    /* ------------------------------------------------------------------ */

    case Iso22317_bia_method = 'iso22317.bia_method';       // BIA method and its documented rationale
    case Iso22318_supply_chain = 'iso22318.supply_chain';   // Supplier continuity capability
    case Iso22320_incident = 'iso22320.incident_response';  // Incident response and command
    case Iso22331_strategy = 'iso22331.strategy';           // Strategy selection and justification
    case Iso22361_crisis = 'iso22361.crisis_management';    // Crisis management capability
    case Iso22398_exercise_design = 'iso22398.exercise_design'; // Exercise aims, objectives, scope
    case Iso22398_ladder = 'iso22398.exercise_ladder';      // Progression through the exercise ladder
    case Iso22398_evaluation = 'iso22398.exercise_evaluation'; // Evaluation and improvement from exercises

    /* ------------------------------------------------------------------ */
    /*  Nigeria — the obligations that drive the buying decision. */
    /* ------------------------------------------------------------------ */

    case Cbn_rcf_bcdr = 'cbn.rcf.bc_dr';                    // CBN Risk-Based Cybersecurity Framework — BC/DR
    case Cbn_rcf_incident = 'cbn.rcf.incident_response';    // …Incident response and recovery
    case Cbn_rcf_drills = 'cbn.rcf.cyber_drills';           // …Cyber drills and industry exercises
    case Cbn_rcf_csat = 'cbn.rcf.csat';                     // …Annual self-assessment (CSAT)
    case Cbn_ob_failover = 'cbn.open_banking.failover';     // Open Banking — quarterly failover exercise
    case Cbn_ob_dr_test = 'cbn.open_banking.dr_test';       // Open Banking — six-monthly DR test
    case Cbn_ob_threshold = 'cbn.open_banking.threshold';   // Open Banking — 30-minute failover threshold
    case Cbn_psb_bcms = 'cbn.psb.bcms';                     // PSB Supervisory Framework — BCMS section
    case Cbn_cg_board = 'cbn.governance.board_oversight';   // Corporate Governance 2023 — board oversight
    case Bofia_continuity = 'bofia.continuity';             // BOFIA 2020 / NDIC — continuity of operations
    case Ndpa_lawful_basis = 'ndpa.lawful_basis';           // NDPA 2023 — lawful basis for personal data
    case Ndpa_retention = 'ndpa.retention';                 // NDPA 2023 — retention and erasure
    case Ndpa_residency = 'ndpa.residency';                 // NDPA 2023 — residency and cross-border transfer

    /**
     * The clauses whose artefact ISO 22301 makes a mandatory documented record.
     *
     * Used by the evidence-sufficiency check: an artefact claiming one of
     * these must be a first-class row with an actor, a timestamp and an
     * immutable state, never an uploaded attachment (compliance-analyst
     * mandate 2).
     *
     * @return list<self>
     */
    public static function mandatoryRecords(): array
    {
        return [
            self::Iso22301_7_2,
            // 8.2.2 — the standard requires the BIA and risk assessment
            // RESULTS to be retained, not merely the method. An examiner asks
            // for the approved analysis, and every RTO downstream of it is
            // measured against what that analysis said at the time.
            self::Iso22301_8_2_2,
            self::Iso22301_8_4_1,
            // 8.4.4 — the plans themselves, distinct from 8.4.1's procedures.
            // A plan is the artefact handed to somebody during a disruption;
            // the procedure is how the organisation maintains it.
            self::Iso22301_8_4_4,
            self::Iso22301_8_5_programme,
            self::Iso22301_8_5_report,
            self::Iso22301_9_2_programme,
            self::Iso22301_9_2_results,
            self::Iso22301_9_3_results,
            self::Iso22301_10_1_nonconformity,
            self::Iso22301_10_1_corrective,
        ];
    }

    public function isMandatoryRecord(): bool
    {
        return in_array($this, self::mandatoryRecords(), true);
    }

    /** The standard or regulator this reference belongs to, from its prefix. */
    public function standard(): string
    {
        return match (true) {
            str_starts_with($this->value, 'iso22301.') => 'ISO 22301:2019',
            str_starts_with($this->value, 'iso22317.') => 'ISO/TS 22317:2021',
            str_starts_with($this->value, 'iso22318.') => 'ISO/TS 22318:2021',
            str_starts_with($this->value, 'iso22320.') => 'ISO 22320:2018',
            str_starts_with($this->value, 'iso22331.') => 'ISO 22331:2018',
            str_starts_with($this->value, 'iso22361.') => 'ISO 22361:2022',
            str_starts_with($this->value, 'iso22398.') => 'ISO 22398:2013',
            str_starts_with($this->value, 'cbn.') => 'CBN',
            str_starts_with($this->value, 'bofia.') => 'BOFIA 2020 / NDIC',
            str_starts_with($this->value, 'ndpa.') => 'NDPA 2023',
            default => 'Unclassified',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
