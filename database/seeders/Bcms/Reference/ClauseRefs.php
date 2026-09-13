<?php

namespace Database\Seeders\Bcms\Reference;

use App\Enums\Bcms\IsoClauseRef;

/**
 * The readable half of the `iso_clause_ref` taxonomy — title, requirement,
 * citation and export grouping for every case of {@see IsoClauseRef}.
 *
 * NOT TENANT DATA. `bcms_clause_refs` has no `organization_id` at all: ISO
 * 22301 clause 8.5 does not vary by customer, and a per-tenant copy would let
 * one tenant's edit change what a clause means in their evidence pack
 * (ADR 0006).
 *
 * THE `requirement` TEXT IS A PARAPHRASE, NOT A QUOTATION. ISO standards are
 * copyright and shipping their text would be redistribution. What is stored is
 * what the clause OBLIGES an organisation to do, in our words, plus a citation
 * a reader can look up. An auditor works from their own copy of the standard;
 * this exists so a screen can say which clause a piece of evidence answers.
 *
 * `export_packs` DECIDES WHICH REGULATOR PACK A REF APPEARS IN. A ref in no
 * pack is a ref nobody can produce evidence for, so every entry names at least
 * one, and `Phase0FoundationsTest` asserts it.
 */
class ClauseRefs
{
    /**
     * @return list<array{code: string, standard: string, clause: string|null, title: string, requirement: string, citation: string, packs: list<string>, mandatory: bool}>
     */
    public static function all(): array
    {
        return [
            // ---- ISO 22301 clauses 4 to 7 --------------------------------
            self::iso('4.1', IsoClauseRef::Iso22301_4_1, 'Understanding the organisation and its context',
                'Determine the internal and external issues relevant to the organisation\'s purpose that affect its ability to achieve the intended outcomes of the BCMS.'),
            self::iso('4.2', IsoClauseRef::Iso22301_4_2, 'Interested parties and legal requirements',
                'Identify interested parties relevant to the BCMS and their requirements, including applicable legal and regulatory obligations, and keep them under review.', ['iso22301', 'cbn_csat']),
            self::iso('4.3', IsoClauseRef::Iso22301_4_3, 'Scope of the BCMS',
                'Determine the boundaries and applicability of the BCMS, stating what is in scope and justifying any exclusion.'),
            self::iso('5.2', IsoClauseRef::Iso22301_5_2, 'Business continuity policy',
                'Establish a policy appropriate to the organisation\'s purpose, available as documented information, and communicated within the organisation.', ['iso22301', 'board']),
            self::iso('5.3', IsoClauseRef::Iso22301_5_3, 'Roles, responsibilities and authorities',
                'Assign and communicate responsibility and authority for the BCMS, including who reports on its performance to top management.', ['iso22301', 'board']),
            self::iso('6.2', IsoClauseRef::Iso22301_6_2, 'Business continuity objectives',
                'Establish measurable objectives consistent with the policy, with a plan stating what will be done, by whom, by when, and how results are evaluated.', ['iso22301', 'board']),
            self::iso('7.2', IsoClauseRef::Iso22301_7_2, 'Competence',
                'Determine the competence required of people affecting BCMS performance, ensure they are competent, and RETAIN DOCUMENTED EVIDENCE OF IT.', ['iso22301', 'cbn_csat'], true),
            self::iso('7.3', IsoClauseRef::Iso22301_7_3, 'Awareness',
                'Ensure people doing work under the organisation\'s control are aware of the policy, their contribution to the BCMS, and the implications of not conforming.'),
            self::iso('7.4', IsoClauseRef::Iso22301_7_4, 'Communication',
                'Determine internal and external communications relevant to the BCMS: what, when, with whom and by what means, including during a disruption.'),
            self::iso('7.5', IsoClauseRef::Iso22301_7_5, 'Documented information',
                'Create, control and retain the documented information the standard requires and that the organisation determines is necessary.'),

            // ---- Clause 8 ------------------------------------------------
            self::iso('8.1', IsoClauseRef::Iso22301_8_1, 'Operational planning and control',
                'Plan, implement and control the processes needed to meet requirements and to implement the actions determined in clause 6.'),
            self::iso('8.2.2', IsoClauseRef::Iso22301_8_2_2, 'Business impact analysis',
                'Analyse the impact over time of disrupting activities, identify prioritised timeframes for resumption, and identify the resources and dependencies activities need.', ['iso22301', 'cbn_csat', 'board'], true),
            self::iso('8.2.3', IsoClauseRef::Iso22301_8_2_3, 'Risk assessment',
                'Identify, analyse and evaluate the risk of disruption to prioritised activities and the resources supporting them.', ['iso22301', 'cbn_csat']),
            self::iso('8.3', IsoClauseRef::Iso22301_8_3, 'Business continuity strategies and solutions',
                'Identify and select strategies based on the BIA and risk assessment, determine the resources required, and implement the selected solutions.', ['iso22301', 'board']),
            self::iso('8.4.1', IsoClauseRef::Iso22301_8_4_1, 'Business continuity plans and procedures — general',
                'Implement and maintain a response structure and documented plans and procedures for managing a disruption. A MANDATORY DOCUMENTED RECORD.', ['iso22301', 'cbn_csat', 'cbn_open_banking'], true),
            self::iso('8.4.2', IsoClauseRef::Iso22301_8_4_2, 'Response structure',
                'Establish a structure identifying response teams, their thresholds for activation, and the authority of each to act.', ['iso22301', 'cbn_csat']),
            self::iso('8.4.3', IsoClauseRef::Iso22301_8_4_3, 'Warning and communication',
                'Establish and maintain procedures for detecting and monitoring an incident, communicating with interested parties, and recording vital information — including the means of communication when the primary means fails.', ['iso22301', 'cbn_csat']),
            self::iso('8.4.4', IsoClauseRef::Iso22301_8_4_4, 'Business continuity plans',
                'Maintain plans stating the roles and responsibilities, the process for activation, and the resources and procedures for resumption within the agreed timeframes.', ['iso22301', 'cbn_open_banking'], true),
            self::iso('8.4.5', IsoClauseRef::Iso22301_8_4_5, 'Recovery',
                'Establish and maintain processes to restore and return business activities from the temporary measures adopted during a disruption.', ['iso22301', 'cbn_open_banking']),
            self::iso('8.5.programme', IsoClauseRef::Iso22301_8_5_programme, 'Exercise programme',
                'Implement and maintain an EXERCISE PROGRAMME to validate the BCMS over time, consistent with the scope and objectives, and appropriate to the criticality of the activity exercised. A MANDATORY DOCUMENTED RECORD.', ['iso22301', 'cbn_csat', 'cbn_open_banking', 'board'], true),
            self::iso('8.5.exercise', IsoClauseRef::Iso22301_8_5_exercise, 'Individual exercise and its scenario',
                'Define exercises based on appropriate scenarios that are well planned, with clearly defined aims and objectives, and that minimise the risk of disruption to operations.', ['iso22301', 'cbn_csat']),
            self::iso('8.5.report', IsoClauseRef::Iso22301_8_5_report, 'Post-exercise report',
                'Produce a formal post-exercise report with outcomes, recommendations and actions to implement improvements. A MANDATORY DOCUMENTED RECORD.', ['iso22301', 'cbn_csat', 'board'], true),
            self::iso('8.6', IsoClauseRef::Iso22301_8_6, 'Evaluation of documentation and capabilities',
                'Evaluate the continued suitability, adequacy and effectiveness of BC documentation and capabilities at planned intervals and after a change or a disruption.', ['iso22301', 'board']),

            // ---- Clauses 9 and 10 ----------------------------------------
            self::iso('9.1', IsoClauseRef::Iso22301_9_1, 'Monitoring, measurement, analysis and evaluation',
                'Determine what needs to be monitored and measured, when, and by whom, and evaluate the BCMS performance and effectiveness against it.', ['iso22301', 'board']),
            self::iso('9.2.programme', IsoClauseRef::Iso22301_9_2_programme, 'Internal audit programme',
                'Plan, establish, implement and maintain an audit programme, including frequency, methods, responsibilities and reporting. A MANDATORY DOCUMENTED RECORD.', ['iso22301', 'board'], true),
            self::iso('9.2.results', IsoClauseRef::Iso22301_9_2_results, 'Internal audit results',
                'Retain documented information as evidence of the audit programme and of the audit results. A MANDATORY DOCUMENTED RECORD.', ['iso22301', 'board'], true),
            self::iso('9.3.inputs', IsoClauseRef::Iso22301_9_3_inputs, 'Management review inputs',
                'Review the BCMS at planned intervals, taking as input the status of prior actions, changes in issues, performance information, and opportunities for improvement.', ['iso22301', 'board']),
            self::iso('9.3.results', IsoClauseRef::Iso22301_9_3_results, 'Management review results',
                'Retain documented information as evidence of the results of management reviews. A MANDATORY DOCUMENTED RECORD.', ['iso22301', 'board'], true),
            self::iso('10.1.nonconformity', IsoClauseRef::Iso22301_10_1_nonconformity, 'Nonconformity',
                'React to a nonconformity, evaluate whether action is needed to eliminate its causes so it does not recur, and RETAIN EVIDENCE OF ITS NATURE. A MANDATORY DOCUMENTED RECORD.', ['iso22301', 'cbn_csat', 'board'], true),
            self::iso('10.1.corrective_action', IsoClauseRef::Iso22301_10_1_corrective, 'Corrective action',
                'Implement action appropriate to the effects of the nonconformity, review its effectiveness, and RETAIN EVIDENCE OF THE RESULTS. A MANDATORY DOCUMENTED RECORD.', ['iso22301', 'cbn_csat', 'board'], true),
            self::iso('10.2', IsoClauseRef::Iso22301_10_2, 'Continual improvement',
                'Continually improve the suitability, adequacy and effectiveness of the BCMS.', ['iso22301', 'board']),

            // ---- Companion standards -------------------------------------
            self::row(IsoClauseRef::Iso22317_bia_method, 'ISO/TS 22317:2021', null, 'BIA method and rationale',
                'Document the BIA method, its criteria and the rationale for the impact categories and timeframes chosen, so that results are repeatable and comparable between cycles.',
                'ISO/TS 22317:2021 — Guidelines for business impact analysis', ['iso22301']),
            self::row(IsoClauseRef::Iso22318_supply_chain, 'ISO/TS 22318:2021', null, 'Supply chain continuity',
                'Understand and manage the continuity capability of suppliers on which prioritised activities depend, including their own continuity arrangements and their sub-suppliers.',
                'ISO/TS 22318:2021 — Guidelines for supply chain continuity management', ['iso22301', 'cbn_csat']),
            self::row(IsoClauseRef::Iso22320_incident, 'ISO 22320:2018', null, 'Incident response and command',
                'Establish command and control, operational information handling, and cooperation for incident response.',
                'ISO 22320:2018 — Guidelines for incident management', ['iso22301', 'cbn_csat']),
            self::row(IsoClauseRef::Iso22331_strategy, 'ISO 22331:2018', null, 'Strategy selection and justification',
                'Identify strategy options, evaluate them against the recovery requirements and the resources they need, and record the basis on which one was selected.',
                'ISO 22331:2018 — Guidelines for business continuity strategy', ['iso22301', 'board']),
            self::row(IsoClauseRef::Iso22361_crisis, 'ISO 22361:2022', null, 'Crisis management capability',
                'Develop a crisis management capability: structure, decision making under uncertainty, and communication with interested parties.',
                'ISO 22361:2022 — Guidelines for crisis management', ['iso22301', 'board']),
            self::row(IsoClauseRef::Iso22398_exercise_design, 'ISO 22398:2013', null, 'Exercise aims, objectives and scope',
                'Design each exercise with stated aims and measurable objectives, a defined scope, and a scenario proportionate to what is being validated.',
                'ISO 22398:2013 — Guidelines for exercises', ['iso22301', 'cbn_csat']),
            self::row(IsoClauseRef::Iso22398_ladder, 'ISO 22398:2013', null, 'Progression through the exercise ladder',
                'Build an exercise programme that progresses in complexity — orientation, tabletop, walkthrough, drill, functional, full-scale — with each level building on the improvements from the last.',
                'ISO 22398:2013 — Guidelines for exercises', ['iso22301', 'cbn_csat', 'board']),
            self::row(IsoClauseRef::Iso22398_evaluation, 'ISO 22398:2013', null, 'Exercise evaluation and improvement',
                'Evaluate each exercise against its objectives, capture observations from participants and evaluators, and convert them into tracked improvement actions.',
                'ISO 22398:2013 — Guidelines for exercises', ['iso22301', 'board']),

            // ---- Nigeria --------------------------------------------------
            self::row(IsoClauseRef::Cbn_rcf_bcdr, 'CBN', 'RCF — BC/DR', 'Cyber resilience: business continuity and disaster recovery',
                'Maintain and test business continuity and disaster recovery arrangements for systems supporting critical banking services, with results reported to the board.',
                'CBN Risk-Based Cybersecurity Framework and Guidelines (DMBs/PSPs 2018; OFIs 2022; 2024 refresh) — Cyber Resilience part',
                ['cbn_csat', 'board']),
            self::row(IsoClauseRef::Cbn_rcf_incident, 'CBN', 'RCF — IR', 'Incident response and recovery',
                'Establish incident response and recovery capability, including reporting to the CBN and participation in NigFinCERT.',
                'CBN Risk-Based Cybersecurity Framework — Incident Response and Recovery part', ['cbn_csat']),
            self::row(IsoClauseRef::Cbn_rcf_drills, 'CBN', 'RCF — Drills', 'Cyber drills and industry exercises',
                'Conduct cyber drills and participate in industry-wide exercises, retaining evidence of the exercise and of the actions arising.',
                'CBN Risk-Based Cybersecurity Framework — Cyber Drills and Industry Exercises part', ['cbn_csat', 'board']),
            self::row(IsoClauseRef::Cbn_rcf_csat, 'CBN', 'RCF — CSAT', 'Annual cybersecurity self-assessment',
                'Complete the annual Cybersecurity Self-Assessment and submit it to the CBN, evidenced by the underlying control and testing records.',
                'CBN Risk-Based Cybersecurity Framework — self-assessment (CSAT)', ['cbn_csat']),
            self::row(IsoClauseRef::Cbn_ob_failover, 'CBN', 'Open Banking', 'Quarterly failover exercise',
                'Conduct failover exercises quarterly, covering the trigger events, the failover process and the fail-back process.',
                'CBN Operational Guidelines for Open Banking in Nigeria', ['cbn_open_banking', 'board']),
            self::row(IsoClauseRef::Cbn_ob_dr_test, 'CBN', 'Open Banking', 'Six-monthly disaster recovery test',
                'Test the disaster recovery plan at least every six months and retain the results.',
                'CBN Operational Guidelines for Open Banking in Nigeria', ['cbn_open_banking', 'board']),
            self::row(IsoClauseRef::Cbn_ob_threshold, 'CBN', 'Open Banking', 'Thirty-minute failover threshold',
                'Meet the stated failover and fail-back threshold of thirty minutes of downtime.',
                'CBN Operational Guidelines for Open Banking in Nigeria', ['cbn_open_banking']),
            self::row(IsoClauseRef::Cbn_psb_bcms, 'CBN', 'PSB Framework', 'Payment Service Bank BCMS',
                'Maintain a business continuity management system covering the PSB\'s operations, as required by the supervisory framework.',
                'CBN Supervisory Framework for Payment Service Banks', ['cbn_csat', 'board']),
            self::row(IsoClauseRef::Cbn_cg_board, 'CBN', 'Governance 2023', 'Board oversight of operational risk',
                'The board oversees the risk management framework, including operational resilience, and receives reporting sufficient to discharge that oversight.',
                'CBN Corporate Governance Guidelines 2023', ['board']),
            self::row(IsoClauseRef::Bofia_continuity, 'BOFIA 2020 / NDIC', null, 'Continuity of banking operations',
                'Maintain the continuity of banking operations and the arrangements that support resolution planning, including a register of critical services.',
                'BOFIA 2020; NDIC resolution planning expectations', ['board']),
            self::row(IsoClauseRef::Ndpa_lawful_basis, 'NDPA 2023', 's.25', 'Lawful basis for processing personal data',
                'Process personal data only on a lawful basis, for a specified purpose, and no more than is adequate for that purpose. Staff emergency contact data is personal data.',
                'Nigeria Data Protection Act 2023', ['ndpa']),
            self::row(IsoClauseRef::Ndpa_retention, 'NDPA 2023', 's.24', 'Retention and erasure',
                'Retain personal data no longer than is necessary for the purpose, and erase or anonymise it when the purpose has been served.',
                'Nigeria Data Protection Act 2023', ['ndpa']),
            self::row(IsoClauseRef::Ndpa_residency, 'NDPA 2023', 's.41', 'Residency and cross-border transfer',
                'Transfer personal data outside Nigeria only on one of the stated bases, and record which basis applies.',
                'Nigeria Data Protection Act 2023', ['ndpa']),
        ];
    }

    /**
     * @param  list<string>  $packs
     * @return array{code: string, standard: string, clause: string|null, title: string, requirement: string, citation: string, packs: list<string>, mandatory: bool}
     */
    private static function iso(string $clause, IsoClauseRef $ref, string $title, string $requirement, array $packs = ['iso22301'], bool $mandatory = false): array
    {
        return self::row($ref, 'ISO 22301:2019', $clause, $title, $requirement, 'ISO 22301:2019 clause '.$clause, $packs, $mandatory);
    }

    /**
     * @param  list<string>  $packs
     * @return array{code: string, standard: string, clause: string|null, title: string, requirement: string, citation: string, packs: list<string>, mandatory: bool}
     */
    private static function row(IsoClauseRef $ref, string $standard, ?string $clause, string $title, string $requirement, string $citation, array $packs, bool $mandatory = false): array
    {
        return [
            'code' => $ref->value,
            'standard' => $standard,
            'clause' => $clause,
            'title' => $title,
            'requirement' => $requirement,
            'citation' => $citation,
            'packs' => $packs,
            // The enum is the authority on which clauses are mandatory records;
            // the flag passed in is a readability aid that must agree with it,
            // and `Phase0FoundationsTest` asserts they do.
            'mandatory' => $mandatory || $ref->isMandatoryRecord(),
        ];
    }
}
