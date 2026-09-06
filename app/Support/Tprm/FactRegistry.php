<?php

namespace App\Support\Tprm;

/**
 * The whitelist of facts a TPRM rule may reference, and the flattener that
 * turns domain objects into the context array `RuleEvaluator` reads.
 *
 * A WHITELIST RATHER THAN "WHATEVER IS ON THE MODEL", for two reasons.
 *
 * The first is that these rules are authored through a UI by risk officers,
 * and a rule builder that offers every column of every table offers
 * `password`, `mfa_secret` and `credentials`. A rule is a stored, shareable,
 * exportable object; a rule that can name a secret is an exfiltration route
 * with an approval workflow in front of it.
 *
 * The second is refactoring. A rule stored in a database in 2026 referencing
 * `engagement.pci_in_scope` has to keep meaning the same thing when somebody
 * renames that column in 2028. A whitelist is the list of names we have
 * promised to keep, and it is the place a rename gets noticed.
 *
 * `answer.<code>` is the one open namespace: question codes are tenant data
 * and cannot be enumerated here. It is safe because it is namespaced — the
 * prefix admits nothing but assessment answers.
 */
class FactRegistry
{
    /**
     * Engagement facts. Key is the fact name; value is the attribute read.
     *
     * @var array<string, string>
     */
    public const ENGAGEMENT = [
        'engagement.type' => 'engagement_type',
        'engagement.status' => 'status',
        'engagement.cloud_model' => 'cloud_model',
        'engagement.is_material_outsourcing' => 'is_material_outsourcing',
        'engagement.supports_critical_function' => 'supports_critical_function',
        'engagement.pci_in_scope' => 'pci_in_scope',
        'engagement.processes_personal_data' => 'processes_personal_data',
        'engagement.data_subject_volume_band' => 'data_subject_volume_band',
        'engagement.data_categories' => 'data_categories',
        'engagement.data_location_at_rest' => 'data_location_at_rest',
        'engagement.data_location_processing' => 'data_location_processing',
        'engagement.cross_border' => 'cross_border',
        'engagement.transfer_basis' => 'transfer_basis',
        'engagement.dpia_required' => 'dpia_required',
        'engagement.substitutability' => 'substitutability',
        'engagement.time_to_replace_months' => 'time_to_replace_months',
        'engagement.annual_spend' => 'annual_spend_minor',
        'engagement.inherent_score' => 'inherent_score',
        'engagement.inherent_tier' => 'inherent_tier',
        'engagement.effective_tier' => 'effective_tier',
        'engagement.residual_score' => 'residual_score',
        'engagement.residual_band' => 'residual_band',
        'engagement.exit_plan_required' => 'exit_plan_required',
    ];

    /**
     * Third-party facts.
     *
     * `ownership_type`, `country_of_incorporation` and the screening status are
     * here; the ownership ROWS are not. A rule that walked shareholders would
     * need a database at evaluation time, which the evaluator does not have —
     * derived flags (`third_party.has_pep_owner`) are computed by the caller
     * and passed in instead.
     *
     * @var array<string, string>
     */
    public const THIRD_PARTY = [
        'third_party.status' => 'status',
        'third_party.entity_type' => 'entity_type',
        'third_party.ownership_type' => 'ownership_type',
        'third_party.country_of_incorporation' => 'country_of_incorporation',
        'third_party.country_of_hq' => 'country_of_hq',
        'third_party.is_intra_group' => 'is_intra_group',
        'third_party.screening_status' => 'screening_status',
        'third_party.aggregate_residual' => 'aggregate_residual',
        'third_party.employee_band' => 'employee_band',
        'third_party.year_established' => 'year_established',
    ];

    /**
     * Facts the caller derives rather than reads off a column, because they
     * need a query. They are declared here so the rule builder can offer them
     * and so nothing else invents a name for the same idea.
     *
     * @var array<string, string>
     */
    public const DERIVED = [
        'third_party.has_pep_owner' => 'Any director, shareholder or UBO flagged as a politically exposed person',
        'third_party.has_true_match' => 'Any confirmed sanctions or adverse-PEP match on the entity or an owner',
        'engagement.has_executed_contract' => 'An executed, unexpired contract exists',
        'engagement.max_function_criticality' => 'Highest criticality among supported business functions',
        'engagement.min_function_rto_hours' => 'Lowest RTO among supported business functions',
        'engagement.has_prohibited_function' => 'Any supported function flagged prohibited for outsourcing',
        'engagement.has_privileged_access' => 'Any active access grant at privileged or administrative level',
        'engagement.has_core_banking_connection' => 'Any connection to the core banking system or payment switch',
        'engagement.open_critical_findings' => 'Count of open findings at Critical severity',
        'engagement.open_high_findings' => 'Count of open findings at High severity',
        'engagement.expired_evidence_count' => 'Count of mandatory documents past their valid_to date',
        'engagement.assessment_overdue_days' => 'Days past next_assessment_due, zero when current',
        'engagement.exit_plan_status' => 'Status of the current exit plan, or null where none exists',
        'signal.type' => 'The signal type under evaluation, for alert rules',
        'signal.severity' => 'The signal severity under evaluation',
        'signal.age_days' => 'Days since the signal was observed',
        'document.type' => 'Document type code, for evidence rules',
        'document.is_expired' => 'Whether the document is past valid_to',
        'document.assurance_level' => 'The assurance level the document supports',
        'finding.severity' => 'Severity of the finding under evaluation',
        'finding.is_overdue' => 'Whether the finding is past its target date',
    ];

    /** The prefix under which assessment answers are addressed. */
    public const ANSWER_PREFIX = 'answer.';

    /**
     * Every fact name a rule may use, other than the open `answer.*` namespace.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::ENGAGEMENT),
            array_keys(self::THIRD_PARTY),
            array_keys(self::DERIVED),
        )));
    }

    /**
     * Whether a rule is allowed to reference this fact.
     *
     * The rule builder calls this on save. A rule referencing an unknown fact
     * is rejected THERE — not at evaluation time, where a rejection would take
     * a screen down and where the evaluator's answer is a quiet false.
     */
    public static function allows(string $fact): bool
    {
        if (str_starts_with($fact, self::ANSWER_PREFIX)) {
            return strlen($fact) > strlen(self::ANSWER_PREFIX);
        }

        return in_array($fact, self::names(), true);
    }

    /**
     * Every fact a rule references, in evaluation order, deduplicated.
     *
     * Used by the rule builder to show what a rule depends on, and by the
     * scoper to build the smallest context it needs rather than hydrating
     * every relationship on the chance a rule wants one.
     *
     * @param  array<string, mixed>|null  $rule
     * @return list<string>
     */
    public static function factsUsedBy(?array $rule): array
    {
        if ($rule === null || $rule === []) {
            return [];
        }

        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (! is_array($node)) {
                return;
            }

            if (isset($node['fact']) && is_string($node['fact'])) {
                $found[] = $node['fact'];

                return;
            }

            foreach (['all', 'any'] as $key) {
                if (isset($node[$key]) && is_array($node[$key])) {
                    foreach ($node[$key] as $child) {
                        $walk($child);
                    }
                }
            }

            if (isset($node['not'])) {
                $walk($node['not']);
            }
        };

        $walk($rule);

        return array_values(array_unique($found));
    }

    /**
     * Read the whitelisted facts off a source object into a flat context.
     *
     * Takes anything array-accessible or with public/magic properties — an
     * Eloquent model, an array, a DTO — so a calculator can be unit-tested
     * against a plain array and used in production against a model, with no
     * second code path between the two.
     *
     * @param  array<string, string>  $map  fact name => attribute name
     * @return array<string, mixed>
     */
    public static function extract(mixed $source, array $map): array
    {
        if ($source === null) {
            return [];
        }

        $context = [];

        foreach ($map as $fact => $attribute) {
            $value = match (true) {
                is_array($source) => $source[$attribute] ?? null,
                is_object($source) => $source->{$attribute} ?? null,
                default => null,
            };

            // A whitelisted fact that is null is still PRESENT: `transfer_basis`
            // being null is exactly the state KO-PII-XB fires on, so it must
            // reach the evaluator as a null rather than as an absence. Facts
            // the source does not carry at all are omitted by the caller
            // passing a narrower map.
            $context[$fact] = $value;
        }

        return $context;
    }

    /**
     * Namespace an assessment's answers into the `answer.<code>` space.
     *
     * @param  array<string, mixed>  $answers  question code => value
     * @return array<string, mixed>
     */
    public static function answers(array $answers): array
    {
        $context = [];

        foreach ($answers as $code => $value) {
            $context[self::ANSWER_PREFIX.$code] = $value;
        }

        return $context;
    }
}
