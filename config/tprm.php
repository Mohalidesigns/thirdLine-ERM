<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third-Party Risk Management
    |--------------------------------------------------------------------------
    |
    | Every constant the TPRM scoring engine reads lives here rather than in the
    | calculators, for one reason: TRD §7.9 requires that a score be
    | reproducible and that a change to the rules produce a NEW run rather than
    | silently restating an old one. A constant buried in a calculator can be
    | changed without anyone noticing that yesterday's board pack no longer
    | reproduces. A constant here is version-stamped onto every `tp_score_runs`
    | row through `engine_version` below.
    |
    | BUMP `engine_version` WHENEVER A NUMBER IN THE `scoring` BLOCK CHANGES.
    | The golden-file suite (Phase 2 onward) pins these values; a change that
    | moves a golden value has to be an explicit decision, and the version is
    | how a reader of an old score knows which decision applied to it.
    |
    | Tenant-editable settings — factor weights, band edges per tenant, SLA days
    | — are seeded FROM here into `tp_tier_policies` and the ruleset tables. The
    | tenant's row wins at runtime; this file is the default and the fallback.
    |
    */

    'engine_version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Scoring — TRD §7
    |--------------------------------------------------------------------------
    */

    'scoring' => [

        /*
         * Mitigation ceiling. `M = AC × EC × Kmax` (TRD §7.5).
         *
         * The hard ceiling is 0.75 and it is a policy position, not a rounding:
         * no amount of vendor assurance may reduce an inherent risk by more
         * than three quarters, because the residual score is what a supervisor
         * is shown and a vendor's own controls cannot be allowed to argue a
         * critical exposure down to nothing.
         */
        'kmax' => 0.60,
        'kmax_ceiling' => 0.75,

        /*
         * Evidence confidence coefficients — TRD §7.4, the differentiator.
         * Keyed by AssuranceLevel::value.
         */
        'confidence' => [
            'self_attested' => 0.35,
            'documented' => 0.60,
            'independently_assured' => 0.85,
            'validated' => 1.00,
        ],

        /*
         * Multiplicative modifiers on conf_q, floored at `modifier_floor`.
         * `bridge_letter` is not a multiplier — it CAPS the assurance level at
         * `documented` (TRD §7.4, AC-05), which is why it is absent here.
         */
        'modifiers' => [
            'evidence_expired' => 0.5,
            'scope_mismatch' => 0.7,
            'quality_flag' => 0.5,
            'carry_forward_per_cycle' => 0.9,
            'carry_forward_floor' => 0.5,
        ],
        'modifier_floor' => 0.2,

        /*
         * A question flagged is_critical and answered non-compliant caps the
         * whole assessment's AC here, regardless of the rest of the answers.
         */
        'critical_question_ac_cap' => 0.5,

        /*
         * Compliance weights entering AC. `na` is excluded from both sums
         * rather than scored zero — an inapplicable control is not a failed
         * one, and scoring it zero would punish a vendor for a question that
         * should never have been asked.
         */
        'compliance' => [
            'compliant' => 1.0,
            'partial' => 0.5,
            'non_compliant' => 0.0,
        ],

        /*
         * Findings uplift — TRD §7.5. Penalty per open finding by severity,
         * summed and capped at `cap`.
         */
        'findings_uplift' => [
            'cap' => 20,
            'severity' => [
                'critical' => 12,
                'high' => 7,
                'medium' => 3,
                'low' => 1,
            ],
            'within_sla_multiplier' => 0.5,
            'overdue_multiplier' => 1.5,
            'overdue_threshold_multiple' => 2,
            'risk_accepted_weight' => 0.5,
        ],

        /*
         * Signal uplift — TRD §7.5.
         *
         * `sanctions_true_match` is not a penalty, it is an override: a
         * confirmed match forces RR to 100 outright (AC-08). It is listed here
         * so the table is complete and so the calculator has one place to read.
         */
        'signal_uplift' => [
            'cap' => 20,
            'sanctions_true_match_forces' => 100,
            'penalty' => [
                'confirmed_breach_12m' => 10,
                'cyber_rating_band_drop' => 6,
                'financial_distress' => 8,
                'regulatory_action' => 8,
                'sla_breach_3_periods' => 4,
                'expired_mandatory_evidence' => 4,
                'overdue_assessment' => 5,
                'unrevoked_access_terminated' => 6,
            ],
            'expired_evidence_cap' => 8,
        ],

        /*
         * Residual bands — TRD §7.5. Lower bound inclusive, upper inclusive.
         * A band is a closed interval on an integer-rounded score.
         */
        'bands' => [
            'low' => [0, 24],
            'moderate' => [25, 49],
            'high' => [50, 74],
            'critical' => [75, 100],
        ],

        /*
         * Data confidence — TRD §7.6. f(age) = 1 up to the policy interval,
         * linear decay to `floor` at `decay_multiple` × interval, `floor`
         * beyond. A residual score below `assertion_threshold` may not close a
         * review or support a board assertion.
         */
        'data_confidence' => [
            'weights' => [
                'assessment_age' => 0.35,
                'evidence_age' => 0.30,
                'screening_age' => 0.20,
                'monitoring_recency' => 0.15,
            ],
            'floor' => 0.2,
            'decay_multiple' => 2,
            'assertion_threshold' => 0.5,
        ],

        /*
         * Inherent risk factors — TRD §7.2. Weights must total 100; the
         * ruleset editor enforces it and a seeded tenant ruleset copies these.
         */
        'inherent_weights' => [
            'DATA' => 25,
            'ACCESS' => 20,
            'CRIT' => 18,
            'REG' => 12,
            'SUB' => 10,
            'GEO' => 7,
            'FIN' => 8,
        ],

        /*
         * Inherent tier edges on the 0–100 weighted score, before knockouts.
         * Knockouts raise the tier and never lower it (TRD §7.3).
         */
        'inherent_tiers' => [
            'low' => [0, 24],
            'moderate' => [25, 49],
            'high' => [50, 74],
            'critical' => [75, 100],
        ],

        /*
         * Portfolio metrics — TRD §7.8. HHI over critical-function dependency
         * by provider group.
         */
        'concentration' => [
            'hhi_bands' => [
                'diversified' => [0, 1499],
                'moderate' => [1500, 2500],
                'concentrated' => [2501, 10000],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Service level defaults
    |--------------------------------------------------------------------------
    |
    | Seeded into `tp_tier_policies`; the tenant's policy row is authoritative
    | at runtime. Remediation SLA is in days, by finding severity.
    |
    */

    'defaults' => [
        'remediation_sla_days' => [
            'critical' => 30,
            'high' => 60,
            'medium' => 90,
            'low' => 180,
        ],

        'evidence_expiry_notice_days' => [90, 60, 30, 7],

        'carry_forward_cycle_limit' => 2,

        'nth_party_depth' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Regulatory clocks — TRD §6.12, AC-07
    |--------------------------------------------------------------------------
    |
    | Hours from clock start to the deadline. These are statutory and are NOT
    | tenant-editable: NDPA §40(2) says 72 hours and a tenant cannot decide
    | otherwise. They are configuration only so that the number appears once.
    |
    | `cbn_materiality_pct_of_shareholders_funds` is the 0.01% test in the CBN
    | Cyber Framework Appendix I. The shareholders'-funds figure itself is a
    | per-tenant setting, not a constant — without it the test is uncomputable
    | and the clock must not be guessed at.
    |
    */

    'clocks' => [
        'ndpc_breach_hours' => 72,
        'cbn_cyber_incident_hours' => 24,
        'aml_str_hours' => 24,
        'escalate_at_elapsed_pct' => [50, 80],
        'cbn_materiality_pct_of_shareholders_funds' => 0.01,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Screening evidence is five years and retrievable within 48 hours — CBN
    | AML/CFT Regulations 2022 Reg. 35. The retrieval half is a test, not a
    | setting; see the phase 6 acceptance criteria.
    |
    */

    'retention' => [
        'screening_years' => 5,
        'audit_log_years' => 7,
        'assessment_years' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI services — TRD §12
    |--------------------------------------------------------------------------
    |
    | EVERY SERVICE DEFAULTS OFF. AC-16 requires that with all of these
    | disabled every workflow completes by hand with no screen errors, and a
    | default-on flag would mean that path is never the one being exercised in
    | development. A tenant turns them on deliberately, and the per-tenant
    | settings screen (Phase 11) overrides this file per organisation.
    |
    | Nothing an extractor produces is ever applied without a human
    | confirmation — that is a rule in the code, not a setting here.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Suite integration
    |--------------------------------------------------------------------------
    |
    | TRD §15. TPRM is part of the ERM programme rather than a module beside
    | it, and these two switches are how that link is turned off for a
    | deployment that does not want it — a TPRM-only installation, or one
    | mid-migration on its issue register.
    |
    | Both default ON, because the whole reason this module was asked for is
    | that third-party risk should reach the key risk areas. Defaulting them
    | off would ship the integration as a feature nobody discovers.
    |
    */

    'integration' => [
        // Every finding mirrors into the ERM issue register, with closure
        // syncing both ways.
        'mirror_findings_to_issues' => env('TPRM_MIRROR_FINDINGS', true),

        // Critical and High tier engagements, and any engagement with an open
        // Critical finding, appear in the ERM risk register under
        // "Third-Party and Outsourcing Risk". NOT every engagement: a risk
        // register holding four hundred stationery suppliers is one nobody
        // reads.
        'mirror_engagements_to_risks' => env('TPRM_MIRROR_ENGAGEMENTS', true),
    ],

    'ai' => [
        'enabled' => env('TPRM_AI_ENABLED', false),

        'services' => [
            'evidence_extraction' => env('TPRM_AI_EVIDENCE_EXTRACTION', false),
            'clause_analysis' => env('TPRM_AI_CLAUSE_ANALYSIS', false),
            'subprocessor_discovery' => env('TPRM_AI_SUBPROCESSOR_DISCOVERY', false),
            'response_quality' => env('TPRM_AI_RESPONSE_QUALITY', false),
            'adverse_media_triage' => env('TPRM_AI_ADVERSE_MEDIA', false),
            'narrative_generation' => env('TPRM_AI_NARRATIVE', false),
            'scoping_assistant' => env('TPRM_AI_SCOPING', false),
        ],

        /*
         * An extraction below this confidence is routed to the confirmation
         * queue flagged low-confidence rather than presented as a proposal.
         */
        'confidence_threshold' => 0.70,

        /*
         * Monthly spend cap per tenant, in minor units of the tenant's billing
         * currency. Null means uncapped, which is deliberately not the default
         * anywhere a client can reach.
         */
        'monthly_spend_cap_minor' => env('TPRM_AI_SPEND_CAP', null),
    ],

];
