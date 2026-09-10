<?php

namespace App\Support\Metadata;

use App\Models\EmergingRisk;

/**
 * WP-05 TASK 2 — the column-backed field definitions for the five object types
 * whose create/edit pairs are now rendered from metadata.
 *
 * WHY THIS REVERSES A DECISION MADE IN WP-03. That work package deliberately
 * left object_attributes empty, with the reasoning that "inventing system
 * attributes here would put a second, competing definition of fields that
 * already have columns". That was right at the time, because an attribute
 * could only describe a value in the JSON bag — so an attribute named `name`
 * really would have been a second, rival `name`.
 *
 * maps_to_column removes the rivalry. These rows do not define new storage;
 * they describe storage that already exists, so that one definition of "what
 * a Control form contains" drives the create form, the edit form, the detail
 * view, validation and, later, the importer. The hand-written pairs they
 * replace were the second definition.
 *
 * THE DEFINITIONS MATCH THE CONTROLLERS' VALIDATION RULES EXACTLY. Every enum
 * option here is copied from the `in:` rule the controller already enforces,
 * and every required flag from its `required`. A form offering an option the
 * controller rejects is worse than no form at all: it fails at submit, after
 * the user has filled everything in, with a message about a field they were
 * invited to set.
 *
 * THE CONTROLLERS STILL OWN PERSISTENCE. These forms post to the same routes,
 * with the same field names, and the same server-side validation runs. This
 * work package replaces the markup, not the write path — the reference-code
 * generation, the transactions and the tenant checks in those controllers are
 * exactly the things a rendering change should not touch.
 */
class FormFieldRegistry
{
    /**
     * @return array<string, list<array<string, mixed>>> object type code => fields
     */
    public static function definitions(): array
    {
        return [
            'Control' => self::control(),
            'KeyRiskIndicator' => self::kri(),
            'TreatmentPlan' => self::treatmentPlan(),
            'Issue' => self::issue(),
            'EmergingRisk' => self::emergingRisk(),
        ];
    }

    /* ------------------------------------------------------------------ */

    /** @return list<array<string, mixed>> */
    private static function control(): array
    {
        return self::ordered([
            self::field('name', 'Control Name', 'string', [
                'is_required' => true,
                'width' => 'full',
                'validation' => ['rules' => ['max:200']],
                'help_text' => 'A short, specific statement of what the control does.',
            ]),
            self::field('description', 'Description', 'text', [
                'is_required' => true,
                'width' => 'full',
                'validation' => ['rules' => ['max:5000']],
            ]),
            self::enum('control_type', 'Control Type', [
                'preventive' => 'Preventive',
                'detective' => 'Detective',
                'corrective' => 'Corrective',
                'directive' => 'Directive',
            ], ['is_required' => true, 'section' => 'Classification']),
            self::enum('control_nature', 'Control Nature', [
                'manual' => 'Manual',
                'automated' => 'Automated',
                'semi_automated' => 'Semi-automated',
            ], ['section' => 'Classification']),
            self::enum('frequency', 'Frequency', [
                'continuous' => 'Continuous',
                'daily' => 'Daily',
                'weekly' => 'Weekly',
                'monthly' => 'Monthly',
                'quarterly' => 'Quarterly',
                'annually' => 'Annually',
                'ad_hoc' => 'Ad hoc',
            ], ['section' => 'Classification']),
            self::enum('effectiveness_rating', 'Effectiveness', [
                'effective' => 'Effective',
                'partially_effective' => 'Partially effective',
                'ineffective' => 'Ineffective',
            ], [
                'section' => 'Classification',
                'help_text' => 'Drives the residual score. Leave blank until the control has been tested.',
            ]),
            self::lookup('owner_id', 'Control Owner', 'users', [
                'is_required' => true, 'section' => 'Ownership',
            ]),
            self::lookup('business_unit_id', 'Business Unit', 'business_units', [
                'section' => 'Ownership',
            ]),
            self::enum('status', 'Status', [
                'active' => 'Active',
                'inactive' => 'Inactive',
                'under_review' => 'Under review',
            ], ['section' => 'Ownership', 'default_value' => 'active']),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function kri(): array
    {
        return self::ordered([
            self::lookup('risk_id', 'Linked Risk', 'risks', [
                'is_required' => true,
                'width' => 'full',
                'help_text' => 'The risk this indicator gives early warning of.',
            ]),
            self::field('kri_name', 'Indicator Name', 'string', [
                'is_required' => true,
                'width' => 'full',
                'validation' => ['rules' => ['max:255']],
            ]),
            self::field('description', 'Description', 'text', [
                'width' => 'full',
                'validation' => ['rules' => ['max:2000']],
            ]),
            self::field('measurement_unit', 'Unit of Measure', 'string', [
                'is_required' => true, 'section' => 'Measurement',
                'help_text' => 'What the number is counted in — %, ₦m, days, incidents.',
            ]),
            self::enum('measurement_frequency', 'Frequency', [
                'daily' => 'Daily',
                'weekly' => 'Weekly',
                'monthly' => 'Monthly',
                'quarterly' => 'Quarterly',
            ], ['is_required' => true, 'section' => 'Measurement']),
            self::field('data_source', 'Data Source', 'string', ['section' => 'Measurement']),
            self::lookup('kri_owner_id', 'Indicator Owner', 'users', [
                'is_required' => true, 'section' => 'Measurement',
            ]),
            // No `category` field. KriController validates one and
            // key_risk_indicators has no column for it, so every category a
            // user has ever chosen was accepted and discarded. Rendering it
            // from metadata would keep that promise going; leaving it out
            // stops the form claiming to store something it cannot. The
            // underlying controller bug is reported separately — fixing it
            // needs a column, which is a migration, not a rendering change.
            self::enum('direction', 'Direction', [
                'higher_is_worse' => 'Higher is worse',
                'lower_is_worse' => 'Lower is worse',
            ], [
                'is_required' => true,
                'section' => 'Thresholds',
                'help_text' => 'Which way the number has to move for things to be getting worse. '
                    .'This decides whether a threshold is a ceiling or a floor.',
            ]),
            self::field('target_value', 'Target', 'decimal', ['section' => 'Thresholds']),
            self::field('green_threshold', 'Green Threshold', 'decimal', ['section' => 'Thresholds']),
            self::field('amber_threshold', 'Amber Threshold', 'decimal', ['section' => 'Thresholds']),
            self::field('red_threshold', 'Red Threshold', 'decimal', ['section' => 'Thresholds']),
            // Posts as `formula`; both store() and update() map it onto the
            // metric_formula column. Reading it back for the edit form goes
            // through an accessor on the model, since there is no column of
            // that name to read.
            self::field('formula', 'Calculation', 'text', [
                'width' => 'full',
                'section' => 'Thresholds',
                'validation' => ['rules' => ['max:1000']],
                'help_text' => 'How the value is derived, in words or as an expression.',
            ]),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function treatmentPlan(): array
    {
        return self::ordered([
            self::lookup('risk_id', 'Risk Being Treated', 'risks', [
                'is_required' => true, 'width' => 'full',
            ]),
            self::field('treatment_title', 'Title', 'string', [
                'is_required' => true, 'width' => 'full',
                'validation' => ['rules' => ['max:255']],
            ]),
            self::field('treatment_description', 'Description', 'text', [
                'is_required' => true, 'width' => 'full',
                'validation' => ['rules' => ['max:5000']],
            ]),
            self::enum('treatment_type', 'Response', [
                'mitigate' => 'Mitigate — reduce it',
                'transfer' => 'Transfer — share it',
                'avoid' => 'Avoid — stop doing it',
                'accept' => 'Accept — live with it',
            ], [
                'is_required' => true,
                'section' => 'Plan',
                'help_text' => 'The four risk responses. Transfer covers insurance and contractual sharing.',
            ]),
            self::enum('priority', 'Priority', [
                'critical' => 'Critical',
                'high' => 'High',
                'medium' => 'Medium',
                'low' => 'Low',
            ], ['is_required' => true, 'section' => 'Plan']),
            self::lookup('treatment_owner_id', 'Owner', 'users', [
                'is_required' => true, 'section' => 'Plan',
            ]),
            self::field('target_completion_date', 'Target Completion', 'date', [
                'is_required' => true,
                'section' => 'Plan',
                'validation' => ['rules' => ['after:today']],
            ]),
            self::field('estimated_cost', 'Estimated Cost', 'money', [
                'section' => 'Plan',
                'validation' => ['rules' => ['min:0']],
            ]),
            self::scale('expected_residual_likelihood', 'Expected Residual Likelihood', 'likelihood', [
                'section' => 'Expected Outcome',
            ]),
            self::scale('expected_residual_impact', 'Expected Residual Impact', 'impact', [
                'section' => 'Expected Outcome',
            ]),
            self::field('success_criteria', 'Success Criteria', 'text', [
                'width' => 'full',
                'section' => 'Expected Outcome',
                'validation' => ['rules' => ['max:2000']],
                'help_text' => 'How you will know this worked.',
            ]),

            // The Progress section exists only on the edit form. A plan being
            // created has made no progress and has no actuals, and
            // TreatmentPlanController::store() does not accept these — so the
            // create view renders the earlier sections only, rather than
            // offering fields that would silently never save.
            self::enum('status', 'Status', [
                'not_started' => 'Not started',
                'in_progress' => 'In progress',
                'completed' => 'Completed',
                'overdue' => 'Overdue',
                'cancelled' => 'Cancelled',
                'pending_review' => 'Pending review',
            ], ['is_required' => true, 'section' => 'Progress']),
            self::field('progress_percentage', 'Progress %', 'int', [
                'section' => 'Progress',
                'validation' => ['rules' => ['min:0', 'max:100']],
            ]),
            self::field('actual_completion_date', 'Actual Completion', 'date', ['section' => 'Progress']),
            self::field('actual_cost', 'Actual Cost', 'money', [
                'section' => 'Progress',
                'validation' => ['rules' => ['min:0']],
            ]),
            self::field('implementation_notes', 'Implementation Notes', 'text', [
                'width' => 'full',
                'section' => 'Progress',
                'validation' => ['rules' => ['max:5000']],
            ]),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function issue(): array
    {
        return self::ordered([
            self::field('title', 'Title', 'string', [
                'is_required' => true, 'width' => 'full',
                'validation' => ['rules' => ['max:255']],
            ]),
            self::field('description', 'Description', 'text', [
                'is_required' => true, 'width' => 'full',
                'validation' => ['rules' => ['max:5000']],
            ]),
            self::enum('issue_source', 'Source', [
                'audit' => 'Audit',
                'risk_assessment' => 'Risk assessment',
                'incident' => 'Incident',
                'regulatory' => 'Regulatory examination',
                'self_identified' => 'Self-identified',
                'customer_complaint' => 'Customer complaint',
                'other' => 'Other',
            ], ['is_required' => true, 'section' => 'Classification']),
            self::enum('priority', 'Priority', [
                'critical' => 'Critical',
                'high' => 'High',
                'medium' => 'Medium',
                'low' => 'Low',
            ], ['is_required' => true, 'section' => 'Classification']),
            // The canonical column names. The create form used to post
            // `category` and `risk_id`, which IssueController::store() then
            // renamed on the way in while update() took the real ones — the
            // same two concepts under four names across two forms. store() now
            // accepts the canonical names as well, so one definition serves
            // both forms; the old names still work for anything posting them.
            self::field('issue_category', 'Category', 'string', ['section' => 'Classification']),
            self::field('examination_ref', 'Examination Reference', 'string', [
                'section' => 'Classification',
                'help_text' => 'The regulator\'s own reference, where there is one.',
                // Only meaningful for a regulatory finding, so it only appears
                // when the source says so. A display rule on the field, not an
                // @if in five view files.
                'visible_when' => ['field' => 'issue_source', 'equals' => 'regulatory'],
            ]),
            self::lookup('business_unit_id', 'Business Unit', 'business_units', [
                'is_required' => true, 'section' => 'Ownership',
            ]),
            self::lookup('responsible_owner_id', 'Responsible Owner', 'users', [
                'is_required' => true, 'section' => 'Ownership',
            ]),
            self::lookup('risk_register_id', 'Related Risk', 'risks', ['section' => 'Ownership']),
            self::field('remediation_due_date', 'Remediation Due', 'date', [
                'is_required' => true,
                'section' => 'Ownership',
                'validation' => ['rules' => ['after:today']],
            ]),
            self::field('root_cause', 'Root Cause', 'text', [
                'width' => 'full', 'section' => 'Analysis',
                'validation' => ['rules' => ['max:3000']],
            ]),
            self::field('impact_description', 'Impact', 'text', [
                'width' => 'full', 'section' => 'Analysis',
                'validation' => ['rules' => ['max:2000']],
            ]),
            self::field('recommended_action', 'Recommended Action', 'text', [
                'width' => 'full', 'section' => 'Analysis',
                'validation' => ['rules' => ['max:3000']],
            ]),

            // Remediation and Regulatory only appear on the edit form: an
            // issue being logged has no management response yet, and
            // IssueController::store() does not accept these.
            self::field('management_response', 'Management Response', 'text', [
                'width' => 'full', 'section' => 'Remediation',
                'validation' => ['rules' => ['max:5000']],
            ]),
            self::field('management_response_due', 'Response Due', 'date', ['section' => 'Remediation']),
            self::field('action_plan', 'Action Plan', 'text', [
                'width' => 'full', 'section' => 'Remediation',
                'validation' => ['rules' => ['max:5000']],
            ]),
            self::field('interim_controls', 'Interim Controls', 'text', [
                'width' => 'full', 'section' => 'Remediation',
                'validation' => ['rules' => ['max:3000']],
                'help_text' => 'What is holding the risk down until the fix lands.',
            ]),
            self::field('cbn_examination_finding', 'CBN Examination Finding', 'bool', [
                'section' => 'Regulatory',
            ]),
            self::field('cbn_response_deadline', 'CBN Response Deadline', 'date', [
                'section' => 'Regulatory',
                'visible_when' => ['field' => 'cbn_examination_finding', 'equals' => true],
            ]),
            self::field('regulatory_reportable', 'Reportable to a Regulator', 'bool', [
                'section' => 'Regulatory',
            ]),
            self::field('ndpa_breach_type', 'NDPA Breach Type', 'string', [
                'section' => 'Regulatory',
                'help_text' => 'Where the issue involves personal data, the breach class under the NDPA.',
            ]),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function emergingRisk(): array
    {
        return self::ordered([
            self::field('title', 'Title', 'string', [
                'is_required' => true, 'width' => 'full',
                'validation' => ['rules' => ['max:255']],
            ]),
            self::field('description', 'Description', 'text', [
                'width' => 'full',
                'validation' => ['rules' => ['max:5000']],
            ]),
            self::lookup('category_id', 'Category', 'risk_categories', ['section' => 'Classification']),
            // THESE THREE ARE DERIVED FROM THE MODEL'S CONSTANTS, NOT RETYPED,
            // and that is the whole point (migration Phase 4.6). Until then they
            // were hand-written literals and every one of them was wrong:
            // horizon offered near_term/medium_term/long_term against a column
            // of 0-3m/3-6m/6-12m/12m+, potential_impact offered
            // low/moderate/high/severe against Low/Medium/High/Critical, and
            // status offered escalating/promoted/dismissed against
            // assessing/escalated/converted/closed. Since horizon and
            // potential_impact are both required, THE CREATE FORM COULD NOT SAVE
            // AN ENTRY AT ALL — submitting exactly what it offered came back
            // with errors on two fields whose only offered values were invalid.
            // Nothing caught it because the one test that exercises the route
            // posts the correct values directly, never the form's.
            //
            // EmergingRisk::HORIZONS/IMPACTS/STATUSES are what the register
            // grid's filters, the radar and the controller's rules all read, so
            // the constants are the authority and these are labels for them. A
            // value added to a constant without a label here still renders,
            // under itself.
            self::enum('horizon', 'Horizon', self::labelled(EmergingRisk::HORIZONS, [
                '0-3m' => '0–3 months',
                '3-6m' => '3–6 months',
                '6-12m' => '6–12 months',
                '12m+' => 'Beyond 12 months',
            ]), [
                'is_required' => true,
                'section' => 'Classification',
                'help_text' => 'How far out this is expected to matter.',
            ]),
            self::enum('potential_impact', 'Potential Impact', self::labelled(EmergingRisk::IMPACTS), [
                'is_required' => true, 'section' => 'Classification',
            ]),
            self::enum('status', 'Status', self::labelled(EmergingRisk::STATUSES, [
                'converted' => 'Converted to register',
            ]), ['is_required' => true, 'section' => 'Classification', 'default_value' => 'monitoring']),
            self::field('velocity_score', 'Velocity', 'int', [
                'is_required' => true,
                'section' => 'Assessment',
                'validation' => ['rules' => ['min:1', 'max:5']],
                'help_text' => 'How fast it would arrive once it starts. 1 slow, 5 immediate.',
            ]),
            self::field('proximity_score', 'Proximity', 'int', [
                'is_required' => true,
                'section' => 'Assessment',
                'validation' => ['rules' => ['min:1', 'max:5']],
                'help_text' => 'How close it already is. 1 distant, 5 imminent.',
            ]),
            self::lookup('owner_id', 'Owner', 'users', ['section' => 'Assessment']),
            self::field('detected_at', 'First Detected', 'date', ['section' => 'Assessment']),
            self::field('last_reviewed_at', 'Last Reviewed', 'date', ['section' => 'Assessment']),
            self::field('source', 'Source', 'string', [
                'section' => 'Evidence',
                'validation' => ['rules' => ['max:160']],
            ]),
            self::field('source_reference', 'Source Reference', 'text', [
                'width' => 'full', 'section' => 'Evidence',
                'validation' => ['rules' => ['max:2000']],
            ]),
            self::field('potential_response', 'Potential Response', 'text', [
                'width' => 'full', 'section' => 'Evidence',
                'validation' => ['rules' => ['max:5000']],
            ]),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Builders */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function field(string $column, string $label, string $dataType, array $overrides = []): array
    {
        return array_merge([
            'code' => $column,
            'maps_to_column' => $column,
            'label' => $label,
            'data_type' => $dataType,
            'section' => 'Details',
            'width' => in_array($dataType, ['text'], true) ? 'full' : 'half',
            'is_required' => false,
            'is_system' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $options  stored value => label
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    /**
     * Option value => human label, for an enum whose values live on the model.
     *
     * Anything without an explicit label is title-cased from its own value, so
     * a constant that grows a member still renders rather than disappearing
     * from the form — which is the failure mode this helper exists to make
     * impossible.
     *
     * @param  list<string>  $values
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private static function labelled(array $values, array $labels = []): array
    {
        $options = [];

        foreach ($values as $value) {
            $options[$value] = $labels[$value] ?? ucfirst(str_replace('_', ' ', $value));
        }

        return $options;
    }

    private static function enum(string $column, string $label, array $options, array $overrides = []): array
    {
        return self::field($column, $label, 'enum', array_merge([
            'enum_options' => array_keys($options),
            // The stored value is the option; the label is what a human reads.
            // Kept beside the options rather than derived, because
            // 'semi_automated' does not title-case into 'Semi-automated'.
            'validation' => ['option_labels' => $options],
        ], $overrides));
    }

    /**
     * A select backed by a domain table, resolved at render time by
     * FormOptionResolver.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function lookup(string $column, string $label, string $source, array $overrides = []): array
    {
        $validation = array_merge(
            ['options_source' => $source],
            $overrides['validation'] ?? []
        );

        unset($overrides['validation']);

        return self::field($column, $label, 'int', array_merge([
            'validation' => $validation,
        ], $overrides));
    }

    /**
     * A point on one of the scoring profile's axes. The options are not fixed
     * here — they come from whichever profile governs the organisation, so a
     * tenant on a 4×4 matrix is offered four, not five.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function scale(string $column, string $label, string $axis, array $overrides = []): array
    {
        $validation = array_merge(
            ['options_source' => 'scoring_scale', 'axis' => $axis],
            $overrides['validation'] ?? []
        );

        unset($overrides['validation']);

        return self::field($column, $label, 'int', array_merge([
            'validation' => $validation,
        ], $overrides));
    }

    /**
     * Number the fields in the order they were declared, in steps of ten so a
     * tenant can slot their own field between two system ones.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private static function ordered(array $fields): array
    {
        foreach ($fields as $index => $field) {
            $fields[$index]['sort_order'] = ($index + 1) * 10;
        }

        return $fields;
    }
}
