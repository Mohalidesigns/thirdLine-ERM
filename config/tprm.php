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

                /*
                 * AC-11. An exit plan past its test interval is a document
                 * about exiting rather than a demonstrated ability to exit,
                 * and the residual risk carries the difference. Four is the
                 * number the acceptance criterion names.
                 */
                'exit_plan_stale' => 4,
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
         *
         * The thresholds below fire an alert rather than blocking anything
         * (FR-NTH-05). Concentration is a board-level judgement about the
         * institution's shape, not a control somebody violated, and a hard
         * block would be routed around within a week.
         *
         * The defaults are conservative for a Nigerian bank, where a handful
         * of providers genuinely carry most of the market's switching, hosting
         * and core banking. A client whose portfolio breaches these on day one
         * is learning something true about itself.
         */
        'concentration' => [
            'hhi_bands' => [
                'diversified' => [0, 1499],
                'moderate' => [1500, 2500],
                'concentrated' => [2501, 10000],
            ],

            // More than this many critical or important business functions on
            // one provider group. Three is where a single failure stops being
            // an incident and starts being an outage.
            'max_critical_functions_per_group' => 3,

            // Share of third-party spend with one group.
            'max_spend_share_per_group' => 0.35,
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

        /*
         * How often a third party is re-screened when its tier policy sets no
         * cadence of its own. Twelve months is the floor rather than the
         * ambition: CBN AML/CFT Reg. 29 expects screening to be ongoing, and a
         * tier policy on a Critical vendor should shorten this rather than
         * rely on it.
         */
        'screening_interval_months' => 12,

        /*
         * How long a vendor gets to answer a targeted mini-assessment raised
         * by a monitoring signal. Shorter than a full cycle deliberately: it
         * is a handful of questions about a specific event, and a fortnight is
         * generous for that, while a quarter would let the answer arrive after
         * the thing it asks about has stopped mattering.
         */
        'targeted_assessment_days' => 14,

        'carry_forward_cycle_limit' => 2,

        /*
         * How deep the graph walks — the default depth for the supply-chain
         * screen and for the provider-group dimension of the concentration
         * analysis. Four covers vendor → host → platform → network, which is
         * where the shared dependencies actually sit.
         */
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

    /*
    |--------------------------------------------------------------------------
    | Screening
    |--------------------------------------------------------------------------
    |
    | Two sanctions lists ship built in — the UN Security Council consolidated
    | list and the Nigeria Sanctions List — because a Nigerian bank with no
    | data budget should still have working AML screening on day one. Both are
    | free to obtain and neither is redistributed with the product.
    |
    | NO LIST DATA SHIPS. Designations change weekly, so a list frozen at build
    | time would be wrong the day after release and would look authoritative
    | while being so. Both lists install EMPTY, `tprm:refresh-sanctions-lists`
    | populates them, and the driver refuses to report a clear result against
    | an empty list — an installation that has never refreshed cannot mistake
    | silence for a clean search.
    |
    | The URLs below are the publishers' own. They are configurable because a
    | client behind a proxy, or one that prefers to fetch and vet the file
    | itself, should not have to patch the product to do so.
    |
    */

    'screening' => [
        'sources' => [
            'unscr' => env('TPRM_UNSCR_URL', 'https://scsanctions.un.org/resources/xml/en/consolidated.xml'),
            // NigSAC publishes through the NFIU rather than at a stable
            // machine-readable endpoint, so this is left unset by default and
            // the list is loaded from an uploaded file.
            'nigsac' => env('TPRM_NIGSAC_URL', ''),
        ],

        /*
         * Commercial providers register here — OFAC SDN, EU consolidated, UK
         * HMT, and PEP or adverse-media vendors. Each is a class implementing
         * `ScreeningDriver`; absent credentials it reports itself unavailable
         * rather than throwing, so the screening console shows a row that says
         * why instead of a page that will not load.
         */
        'drivers' => [],

        /*
         * A match at or above this score is worth a person's attention. Set
         * low on purpose: a false positive costs a reviewer thirty seconds and
         * a rationale, and a false negative costs an institution a designated
         * counterparty nobody ever saw.
         */
        'match_threshold' => 0.5,
    ],

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
