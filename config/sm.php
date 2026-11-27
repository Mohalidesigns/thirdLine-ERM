<?php

use App\Models\Sm\Scenario;
use App\Models\Sm\ScenarioCause;
use App\Models\Sm\Signoff;

/*
|--------------------------------------------------------------------------
| Scenario Modelling & Analysis — the WORDING, never the VALUE SET
|--------------------------------------------------------------------------
|
| Addendum-3 to ADR 0027 Amendment 1 is a narrow, deliberate reversal of
| §A1.8's refusal of a publishable config file. The refusal stands for what it
| said: config must NOT be the authority for the value set. What changes is the
| WORDING.
|
| Spec 03 §2 requires the cause checklist, both exposure scales and the yes/no
| labels to "arrive as data" — "Nothing in §4–§6 may be hard-coded in JSX" — so
| that a tenant can rename "External Salesforce / Distribution" or replace the
| affiliate wording without a front-end release.
|
| SO THE SPLIT IS: every `value` and every `code` in this file must be one of
| §A1.8's frozen const values on App\Models\Sm\{Scenario,ScenarioCause,Signoff},
| and `tests/Feature/Sm/ConfigMatchesFrozenVocabularyTest.php` asserts it. Config
| supplies wording, the consts supply the legal set. A tenant editing this file
| can rename a scale point but CANNOT make the database hold a value no code
| knows.
|
| NOT PUBLISHABLE-BY-ACCIDENT: like config/tprm.php, this file carries nothing a
| tenant may drift, because `engine_version`-style stamping does not apply here
| and a published copy could carry a label the code has never seen. It is read
| through config(), never edited at runtime.
|
| TWO CONSEQUENCES THE SPECS REQUIRE AND THE FREEZE CONFIRMS:
|
|   1. The ORDINAL of each scale lives in the `options[]` ARRAY ORDER here and
|      nowhere else (spec 03 §12.6). Anything that sorts by exposure server-side
|      reads that order rather than inventing a stored ordinal.
|   2. `requires_note` is config with NO Phase 1 editing surface (spec 03 §12.3),
|      so the Form Request enforces "note required when a `requires_note` cause
|      is selected" with spec 03 §7's message, and a tenant needing a 24th cause
|      needs a config change until Phase 5.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | The two exposure scales
    |--------------------------------------------------------------------------
    |
    | Each option is `{value, label, description|null}`. `value` is a frozen
    | const key; `label` and `description` are this file's.
    |
    | The unset state is NOT an option: it is `unset_label`, rendered as its own
    | radio that posts NULL (spec 03 §6.1). A native radio group cannot be
    | un-selected, so without an explicit unset radio a mis-click on "No impact"
    | silently converts an unanswered field into an affirmative assessment — the
    | precise inversion standard §5 forbids, and worse here because "No impact" is
    | the answer that makes a scenario look safe.
    |
    */

    'exposures' => [

        'reputational' => [
            // The workbook's own string (VOCABULARY-EXTRACTION.md [55,13]–[61,13]).
            // NOT the legal scale's "Not yet assessed": the two dropdowns literally
            // differ, and D1 plus Phase 5's round-trip require the stored value to
            // map back to that exact string (§A1.8).
            'unset_label' => Scenario::REPUTATIONAL_UNSET_LABEL,

            /*
             * SIX options, not five. "No impact" is an ASSESSMENT; "not yet
             * assessed" is the ABSENCE of one and lives above as `unset_label`.
             * Collapsing them would render an unanswered field as a clean bill of
             * health.
             *
             * Descriptions are verbatim from the workbook dropdown. The `severe`
             * one carries the artefact's own double space after the full stop —
             * markdown collapses it in spec 03 §6.2's table, but the extraction is
             * the evidence and D1 makes the workbook the specification.
             */
            'options' => [
                ['value' => 'no_impact', 'label' => 'No impact', 'description' => null],
                ['value' => 'insignificant', 'label' => 'Insignificant', 'description' => 'Minor isolated stakeholder concerns / impacts'],
                ['value' => 'minor', 'label' => 'Minor', 'description' => 'Serious segmented stakeholder concerns/incidents'],
                ['value' => 'moderate', 'label' => 'Moderate', 'description' => 'Concerns becoming broader and more vocalised within the industry'],
                ['value' => 'major', 'label' => 'Major', 'description' => 'Negative public exposure with significant impact'],
                ['value' => 'severe', 'label' => 'Severe', 'description' => 'Dramatic loss of stakeholder confidence undermining business viability.  Extensive negative public exposure'],
            ],
        ],

        'legal' => [
            'unset_label' => Scenario::LEGAL_UNSET_LABEL,

            /*
             * LABELS ONLY, every description null — and that is a decision, not an
             * omission. The workbook's legal/regulatory/compliance dropdown
             * ([64,13]–[69,13]) is a bare list of six labels with NO descriptions;
             * the five graded descriptions belong to the REPUTATIONAL dropdown.
             * Copying them here would put words in the artefact's mouth about legal
             * exposure that it never says — a stakeholder-confidence sentence is not
             * a statement about litigation or a regulatory penalty (spec 03 §6.3).
             * A tenant that wants legal descriptions configures them here.
             *
             * `no`, not `no_impact`: the two dropdowns literally differ (§A1.8).
             */
            'options' => [
                ['value' => 'no', 'label' => 'No', 'description' => null],
                ['value' => 'insignificant', 'label' => 'Insignificant', 'description' => null],
                ['value' => 'minor', 'label' => 'Minor', 'description' => null],
                ['value' => 'moderate', 'label' => 'Moderate', 'description' => null],
                ['value' => 'major', 'label' => 'Major', 'description' => null],
                ['value' => 'severe', 'label' => 'Severe', 'description' => null],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The three-state yes/no labels
    |--------------------------------------------------------------------------
    |
    | Three, not two. `insurance_risk` and `financial_risk` are NULLABLE booleans
    | (§A1.2 rows 23–24) because the workbook dropdowns offer a blank, and a
    | two-state control renders "not yet assessed" as "No" — `DynamicForm`'s
    | `bool` case has exactly that defect (spec 03 §6.4).
    |
    | `eligible_to_transfer` is NOT NULL default false (§A1.2 row 25), so it has
    | no unset state in Phase 1; spec 03 §6.4's three-state radio for it is a
    | divergence the schema does not support, recorded in the Phase 1 handoff.
    |
    */

    'yes_no' => [
        'yes' => 'Yes',
        'no' => 'No',
        'unset' => 'Not assessed',
    ],

    /*
    |--------------------------------------------------------------------------
    | The cause checklist — wording and order over ScenarioCause::CHECKLIST
    |--------------------------------------------------------------------------
    |
    | Every `code`, `side` and `group` here is a frozen value from
    | `ScenarioCause::CHECKLIST`; this file adds `group_label` (the on-screen
    | heading), `requires_note` and `sort`. The `label` is repeated from the const
    | so a tenant can rename it here without touching the const — and the test
    | asserts the CODE SET matches, not the labels.
    |
    | `sort` is the workbook's row order (LS cols B/C, rows 32–48), which is also
    | the order `sm_scenario_causes.sort_order` freezes so a tenant extending this
    | list in Phase 5 cannot reshuffle a signed-off scenario's qualitative report.
    |
    */

    'causes' => [
        'group_labels' => [
            'people' => 'People',
            'process' => 'Processes',
            'system' => 'Systems',
            'external' => 'External',
        ],

        'checklist' => [
            // ── Internal · People ────────────────────────────────────────
            ['code' => 'people_inappropriate_resources', 'side' => 'internal', 'cause_group' => 'people', 'label' => 'Inappropriate level of resources', 'requires_note' => false, 'sort' => 0],
            ['code' => 'people_inadequately_skilled', 'side' => 'internal', 'cause_group' => 'people', 'label' => 'Inadequately skilled people', 'requires_note' => false, 'sort' => 1],
            ['code' => 'people_inadequately_managed', 'side' => 'internal', 'cause_group' => 'people', 'label' => 'Inadequately managed people', 'requires_note' => false, 'sort' => 2],
            ['code' => 'people_intentional_act', 'side' => 'internal', 'cause_group' => 'people', 'label' => 'Intentional act', 'requires_note' => false, 'sort' => 3],
            ['code' => 'people_unintentional_acts', 'side' => 'internal', 'cause_group' => 'people', 'label' => 'Unintentional acts', 'requires_note' => false, 'sort' => 4],
            // "Other" on its own tells a validator nothing, so the note is the
            // field's whole content — hence requires_note (spec 03 §4.1).
            ['code' => 'people_other', 'side' => 'internal', 'cause_group' => 'people', 'label' => 'Other', 'requires_note' => true, 'sort' => 5],

            // ── Internal · Processes ─────────────────────────────────────
            ['code' => 'process_inadequately_designed', 'side' => 'internal', 'cause_group' => 'process', 'label' => 'Inadequately designed process', 'requires_note' => false, 'sort' => 6],
            ['code' => 'process_ineffective', 'side' => 'internal', 'cause_group' => 'process', 'label' => 'Ineffective process', 'requires_note' => false, 'sort' => 7],
            ['code' => 'process_lack_of', 'side' => 'internal', 'cause_group' => 'process', 'label' => 'Lack of process', 'requires_note' => false, 'sort' => 8],
            ['code' => 'process_other', 'side' => 'internal', 'cause_group' => 'process', 'label' => 'Other', 'requires_note' => true, 'sort' => 9],

            // ── Internal · Systems ───────────────────────────────────────
            ['code' => 'system_inadequately_designed', 'side' => 'internal', 'cause_group' => 'system', 'label' => 'Inadequately designed systems', 'requires_note' => false, 'sort' => 10],
            ['code' => 'system_inadequately_maintained', 'side' => 'internal', 'cause_group' => 'system', 'label' => 'Inadequately maintained systems', 'requires_note' => false, 'sort' => 11],
            ['code' => 'system_other', 'side' => 'internal', 'cause_group' => 'system', 'label' => 'Other', 'requires_note' => true, 'sort' => 12],

            // ── External · one flat group; the workbook has no sub-groups ─
            ['code' => 'ext_natural_environment', 'side' => 'external', 'cause_group' => 'external', 'label' => 'Natural environment', 'requires_note' => false, 'sort' => 13],
            ['code' => 'ext_technological', 'side' => 'external', 'cause_group' => 'external', 'label' => 'Technological', 'requires_note' => false, 'sort' => 14],
            ['code' => 'ext_cultural', 'side' => 'external', 'cause_group' => 'external', 'label' => 'Cultural', 'requires_note' => false, 'sort' => 15],
            ['code' => 'ext_regulatory', 'side' => 'external', 'cause_group' => 'external', 'label' => 'Regulatory', 'requires_note' => false, 'sort' => 16],
            ['code' => 'ext_other', 'side' => 'external', 'cause_group' => 'external', 'label' => 'Others', 'requires_note' => true, 'sort' => 17],
            ['code' => 'ext_supplier', 'side' => 'external', 'cause_group' => 'external', 'label' => 'Supplier', 'requires_note' => false, 'sort' => 18],
            ['code' => 'ext_customer', 'side' => 'external', 'cause_group' => 'external', 'label' => 'Customer', 'requires_note' => false, 'sort' => 19],
            ['code' => 'ext_salesforce_distribution', 'side' => 'external', 'cause_group' => 'external', 'label' => 'External Salesforce / Distribution', 'requires_note' => false, 'sort' => 20],
            /*
             * THE ONE DEVIATION FROM THE ARTEFACT, recorded in §A1.8. The
             * workbook's cell reads "Leadway Assurance company" — the reference
             * artefact's own tenant. Shipping one customer's insurer name into
             * every tenant's checklist would put another bank's competitor on
             * their screen, and VOCABULARY-EXTRACTION.md itself instructs the
             * generalisation. A tenant renames it HERE, without a release.
             */
            ['code' => 'ext_group_affiliate', 'side' => 'external', 'cause_group' => 'external', 'label' => 'Group/affiliate entity', 'requires_note' => false, 'sort' => 21],
            ['code' => 'ext_unknown', 'side' => 'external', 'cause_group' => 'external', 'label' => 'Unknown/yet to be determined', 'requires_note' => true, 'sort' => 22],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sign-off warnings — the wording for Signoff::WARNING_CODES
    |--------------------------------------------------------------------------
    |
    | These are NON-BLOCKING warnings shown in the sign-off modal (spec 01
    | §5.6.5), and the CODES are what gets stored on the sign-off row. The
    | wording is never stored: storing it would freeze text a tenant may
    | reconfigure and duplicate §A1.8's label maps (addendum-1).
    |
    | One message per code, verbatim from spec 01 §5.6.5's five bullets.
    |
    | CORRECTED BY ADR 0027 CORRECTION 1 §C1.1, which wins over addendum-1. The
    | eighth code was `eligible_to_transfer_not_assessed`; §A1.2 row 25 froze that
    | column NOT NULL default `false`, so the condition describes a state the
    | schema forbids and the code could never fire. It is replaced by
    | `financial_risk_not_assessed` — `financial_risk` IS nullable (§A1.2 row 24)
    | and spec 01 §5.6.5 bullet 5 warns on it, so without a code the sign-off modal
    | would silently omit one of the two flags it should surface. The count stays
    | at eight and `Signoff::WARNING_CODES` changes with this map, because
    | ConfigMatchesFrozenVocabularyTest asserts the two agree.
    |
    | Spec 01 §5.6.5's sixth bullet — the advisory shown when `eligible_to_transfer`
    | is `false` — has NO code and is UI-only. A ninth code was refused: `false` is
    | the column's default, so recording it would attach the same warning to most
    | sign-offs and teach signers to skip the list.
    |
    */

    'signoff' => [
        'warning_messages' => [
            'no_challenge_recorded' => 'No challenge or dissent is recorded in the notes log.',
            'no_cause_selected' => 'No cause is selected on the Qualitative tab.',
            'allocation_missing' => 'No business line is allocated.',
            'allocation_not_100' => 'Business-line allocation is not 100%.',
            'reputational_not_assessed' => 'Reputational impact is not yet assessed.',
            'legal_not_assessed' => 'Legal and regulatory risk is not yet assessed.',
            'insurance_risk_not_assessed' => 'Insurance risk is not assessed.',
            'financial_risk_not_assessed' => 'Financial or market risk is not assessed.',
        ],

        // The sentence under the warning list, and the promise it makes to the
        // backend: the outstanding items travel WITH the sign-off record, as a
        // snapshot, and are never recomputed at render (addendum-1).
        'warnings_note' => 'You can still sign off. These are recorded with the sign-off so a reviewer can see what was outstanding.',
    ],

    /*
    |--------------------------------------------------------------------------
    | The review cadence — the DEFAULT, not the tenant's value
    |--------------------------------------------------------------------------
    |
    | The tenant's value lives in the existing `organizations.settings` JSON bag
    | under the key `sm_review_cadence_months`, read through
    | `App\Services\Sm\ScenarioSettings` (addendum-3). NO new column on
    | `organizations` and no `sm_settings` table — a sixth table would breach
    | §A1.1's boundary.
    |
    | 12 is the shipped value, not an invented one: plan §4.2's cadence is annual
    | and capability 10 in plan §3.3 is the annual refresh.
    |
    | Read through the SERVICE and never through config() alone. `BcmsSettings`'
    | docblock gives the reason verbatim: "config() is process-wide and a queue
    | worker holds it across tenants". `suggestedReviewDueOn` is rendered inside a
    | request, so config-only would work today — but the same value is read by
    | Phase 4's review-cadence prompt, which is scheduled and queued. A setting
    | that is right in a request and wrong in a worker ships silently.
    |
    */

    'review_cadence_months' => 12,

    /*
    |--------------------------------------------------------------------------
    | Frequency driver presets (production defect SM-2026-001, Bug 5)
    |--------------------------------------------------------------------------
    |
    | Starting labels for the three frequency drivers. A preset fills in only each driver's
    | label (`criterion`) and provenance (`data_source`); the user can edit both afterwards.
    | The driver keys (`driver_type`) and roles never change, so the formula
    | Count × (1 − ControlScore) × Probability and existing rows are untouched. Each preset
    | lists drivers in role order: count, control_score_complement, probability.
    | `people` is the original workbook set and stays the default for a scenario with no
    | drivers saved.
    |
    */

    'frequency_driver_presets' => [
        'people' => [
            'label' => 'People (staff turnover, key-person loss)',
            'drivers' => [
                ['criterion' => 'Exposed Staff count', 'data_source' => 'HR information'],
                ['criterion' => 'Retention plan efficiency', 'data_source' => 'Scorecard'],
                ['criterion' => 'Competition pressure probability', 'data_source' => 'Expert judgement'],
            ],
        ],
        'process_site' => [
            'label' => 'Process & site disruption',
            'drivers' => [
                ['criterion' => 'Critical sites or processes exposed', 'data_source' => 'Business impact analysis'],
                ['criterion' => 'Business continuity control efficiency', 'data_source' => 'Scorecard'],
                ['criterion' => 'Annual probability of disruption per site or process', 'data_source' => 'Internal loss data'],
            ],
        ],
        'technology_cyber' => [
            'label' => 'Technology & cyber',
            'drivers' => [
                ['criterion' => 'Critical systems or applications exposed', 'data_source' => 'IT asset inventory'],
                ['criterion' => 'Cyber and IT control efficiency', 'data_source' => 'Scorecard'],
                ['criterion' => 'Annual probability of a successful attack or outage per system', 'data_source' => 'Threat intelligence'],
            ],
        ],
        'external_event' => [
            'label' => 'External event (pandemic, natural disaster, civil unrest)',
            'drivers' => [
                ['criterion' => 'Exposed staff or locations', 'data_source' => 'HR information'],
                ['criterion' => 'Preparedness and response control efficiency', 'data_source' => 'Scorecard'],
                ['criterion' => 'Annual probability of the event affecting each exposed unit', 'data_source' => 'External data'],
            ],
        ],
        'fraud' => [
            'label' => 'Fraud (internal or external)',
            'drivers' => [
                ['criterion' => 'Accounts, transactions or staff exposed', 'data_source' => 'Internal loss data'],
                ['criterion' => 'Fraud prevention and detection control efficiency', 'data_source' => 'Scorecard'],
                ['criterion' => 'Annual probability of a fraud event per exposed unit', 'data_source' => 'Internal loss data'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The deferred-sections disclosure
    |--------------------------------------------------------------------------
    |
    | Spec 01 §5.1: `roadmapNote` is a SERVER-SENT string rendered verbatim, so
    | the copy can change per phase without a front-end release, and one statement
    | of what this release does and does not do reaches both the index and the
    | workspace. Standard §5 applies to capability as well as to numbers: a strip
    | of greyed-out tabs would imply a quantitative module the product does not
    | have.
    |
    */

    /*
    | All quantitative and remediation sections (Frequency, Impact, Insurance,
    | Scorecard, Variants, Actions) are now implemented and active.
    */
    'roadmap_note' => null,

    'roadmap_note_index' => null,

    /*
    |--------------------------------------------------------------------------
    | The sign-off sources' picker labels
    |--------------------------------------------------------------------------
    |
    | Kept beside the rest of the wording so a tenant renaming "Source 1" to
    | "Design authority" does not need a release. The VALUES are
    | `Signoff::SOURCES` and are frozen.
    |
    */

    'sources' => Signoff::SOURCES,

    /*
    |--------------------------------------------------------------------------
    | Cause checklist groups, in display order
    |--------------------------------------------------------------------------
    |
    | Spec 03 §4 renders internal groups in a left column and external in a right
    | one, each in this order. The order is config because the grouping is
    | presentation; the `group` VALUES are `ScenarioCause::GROUPS`.
    |
    */

    'cause_groups' => ScenarioCause::GROUPS,
    'cause_sides' => ScenarioCause::SIDES,

    /*
    |--------------------------------------------------------------------------
    | The lifecycle's words
    |--------------------------------------------------------------------------
    |
    | Spec 00 §3.3 and spec 01 §2: "the words come from `status_label`, which the
    | server humanises so a tenant can rename a stage without a front-end release".
    | This map is the rename point for the WORKSPACE payload; a status absent from it
    | is humanised from its value, so a Phase 2 stage never renders as a bare
    | snake_case string.
    |
    | THE INDEX GRID HUMANISES INSTEAD, and the two agree by construction: every value
    | below is exactly what `GridPresenter::humanise()` produces from the status
    | (`ucfirst(str_replace('_', ' ', …))` → "In design", "Signed off", "Returned for
    | rework"), which is spec 01 §2's canonical chip wording. `Column::badge()` looks the
    | class up on the RAW value and humanises it for the text, so one closure cannot feed
    | it a config label without also moving the badge map's keys. Renaming a stage here
    | therefore changes the workspace and NOT the grid cell — recorded rather than left
    | to be discovered, because a rename that half-applies is worse than one that is
    | impossible.
    |
    | The VALUES are `Scenario::STATUSES` and are frozen;
    | ConfigMatchesFrozenVocabularyTest asserts the key set matches.
    |
    */

    'status_labels' => [
        'draft' => 'Draft',
        'in_design' => 'In design',
        'designed' => 'Designed',
        'in_validation' => 'In validation',
        'validated' => 'Validated',
        'signed_off' => 'Signed off',
        'archived' => 'Archived',
        'returned_for_rework' => 'Returned for rework',
    ],

    /*
    |--------------------------------------------------------------------------
    | The sign-off decisions' words
    |--------------------------------------------------------------------------
    |
    | TWO values only (§A1.6, upheld by the coordinator's Phase 1 ruling).
    | `approved_with_conditions` was refused — no transition branches on it, and a
    | condition is a notes row of kind `assumption` — because "a third value nothing
    | reads is how a workflow acquires a state nobody can leave". A signer who declines
    | without returning the scenario records a DISSENT NOTE instead.
    |
    | The VALUES are `Scenario::DECISIONS` and are frozen.
    |
    */

    'decision_labels' => [
        'approved' => 'Approved',
        'returned_for_rework' => 'Returned for rework',
    ],

    /*
    |--------------------------------------------------------------------------
    | The lifecycle buttons' words (spec 01 §5.2)
    |--------------------------------------------------------------------------
    |
    | Keyed `from>to` for ALL FOURTEEN EDGES, because the same target reads
    | differently depending on where it came from: `Archive` from `draft` is not the
    | same act as `Archive` from `signed_off`, and three edges lead to
    | `returned_for_rework` under three different names.
    |
    | Every key must be a legal edge in `Scenario::STATUS_TRANSITIONS` —
    | ConfigMatchesFrozenVocabularyTest asserts it, so a label cannot survive an edge
    | the freeze removed, and an edge cannot ship without a word.
    |
    | The client holds NO state machine (spec 01 §3): the server filters the
    | transition list by permission AND status and sends the words with it. "A label
    | the server does not send does not render: the shell has no fallback
    | vocabulary."
    |
    */

    'transition_labels' => [
        'draft>in_design' => 'Start design',
        'draft>archived' => 'Archive',
        'in_design>designed' => 'Design complete',
        'in_design>returned_for_rework' => 'Return for rework',
        'designed>in_validation' => 'Submit for validation',
        'designed>in_design' => 'Reopen design',
        'in_validation>validated' => 'Validate',
        'in_validation>returned_for_rework' => 'Return for rework',
        'validated>signed_off' => 'Sign off',
        'validated>returned_for_rework' => 'Return for rework',
        'signed_off>returned_for_rework' => 'Reopen for revalidation',
        'signed_off>in_validation' => 'Send back into validation',
        'signed_off>archived' => 'Archive',
        'returned_for_rework>in_design' => 'Resume design',
    ],
];
