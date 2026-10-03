<?php

namespace App\Support\Bcms;

/**
 * ADR 0024 §2 — the classification of every `{{variable}}` a shipped alert
 * template declares, as ONE constant map. The validator
 * (`StoreBcmsAlertRequest`), the presenter (`EmnsPresenter`) and the guard
 * test (`EmnsTemplateSeedingTest`) all read this list rather than each
 * keeping its own, so a variable added to a template without being
 * classified here is a failed test, not a runtime surprise reached only in
 * an emergency.
 *
 * FOUR CATEGORIES, NEVER A FIFTH WITHOUT AN ADR.
 *
 *   - `DERIVED` — computed by `TemplateRenderer::computeAlertDerivedVariables()`
 *     at every render, from what the alert already carries. Never stored,
 *     never operator-supplied, never overridable (an operator naming one of
 *     these in `template_variables` is refused — §3.1's "undeclared key"
 *     rule, since a template's own `variables` list only ever names the
 *     operator-facing subset it needs and DERIVED names never appear there).
 *   - `OPERATOR` — typed at compose, required, stored on
 *     `bcms_alerts.template_variables` and never edited again (write once).
 *   - `OPERATOR_OPTIONAL` — typed at compose, an explicit empty string is a
 *     valid, deliberate answer ("nothing to add").
 *   - `PER_RECIPIENT` — would need a different value per person. Deferred to
 *     the booked EMNS per-recipient-variables ADR. A template declaring one
 *     is refused at compose, not merely at release, and marked unavailable
 *     in the picker — an operator facing an emergency must not discover
 *     this only when the send fails.
 */
final class AlertTemplateVariables
{
    /**
     * Recomputed at every render; never stored, never operator-supplied.
     * The first seven are the standalone rendering fix's own derivations;
     * the rest are ADR 0024's.
     *
     * @var list<string>
     */
    public const DERIVED = [
        'alert_title', 'severity', 'organisation',
        'site_name', 'location', 'incident_reference', 'incident_title',
        'exercise_name', 'scheduled_date', 'days_remaining',
        'tree_name', 'open_task_count', 'blocking_task_count', 'blocking_note',
        'plan_link', 'training_link', 'profile_link', 'pir_link',
    ];

    /**
     * Human labels for the composer's read-only "filled in automatically"
     * list (ADR 0024 §3.6) — never a form field, so no `max`/`required`
     * alongside them the way `OPERATOR`/`OPERATOR_OPTIONAL` carry.
     *
     * @var array<string, string>
     */
    private const DERIVED_LABELS = [
        'alert_title' => 'Alert title',
        'severity' => 'Severity',
        'organisation' => 'Organisation',
        'site_name' => 'Site name (from the audience or the incident)',
        'location' => 'Location',
        'incident_reference' => 'Incident reference',
        'incident_title' => 'Incident title',
        'exercise_name' => 'Exercise name',
        'scheduled_date' => 'Scheduled date',
        'days_remaining' => 'Days remaining',
        'tree_name' => 'Call tree name',
        'open_task_count' => 'Open readiness tasks',
        'blocking_task_count' => 'Blocking readiness tasks',
        'blocking_note' => 'Blocking readiness note',
        'plan_link' => 'Link to the recipient\'s plans',
        'training_link' => 'Link to the recipient\'s training',
        'profile_link' => 'Link to the recipient\'s profile',
        'pir_link' => 'Link to the post-incident review',
    ];

    /**
     * Operator-entered at compose, REQUIRED. Name => [label, max length].
     *
     * @var array<string, array{label: string, max: int}>
     */
    public const OPERATOR = [
        'assembly_point' => ['label' => 'Assembly point', 'max' => 80],
        'bridge' => ['label' => 'Bridge / dial-in details', 'max' => 120],
        'convene_by' => ['label' => 'Convene by', 'max' => 40],
        'service_name' => ['label' => 'Affected service', 'max' => 80],
        'workaround' => ['label' => 'Workaround', 'max' => 160],
        'next_update' => ['label' => 'Next update', 'max' => 40],
        'lessons_summary' => ['label' => 'Lessons summary', 'max' => 300],
    ];

    /**
     * Operator-entered at compose, OPTIONAL — an explicit empty string is
     * stored and renders as nothing, distinct from "never answered".
     *
     * @var array<string, array{label: string, max: int}>
     */
    public const OPERATOR_OPTIONAL = [
        'additional_instructions' => ['label' => 'Additional instructions', 'max' => 160],
    ];

    /**
     * Would need a different value per recipient. Not built here — see the
     * class docblock. A template declaring one of these is unsendable today.
     *
     * @var list<string>
     */
    public const PER_RECIPIENT = ['your_task_count', 'last_verified'];

    /** Every name this map classifies, for the "no unclassified variable" guard test. */
    public static function allKnown(): array
    {
        return [
            ...self::DERIVED,
            ...array_keys(self::OPERATOR),
            ...array_keys(self::OPERATOR_OPTIONAL),
            ...self::PER_RECIPIENT,
        ];
    }

    public static function isDerived(string $name): bool
    {
        return in_array($name, self::DERIVED, true);
    }

    public static function isOperator(string $name): bool
    {
        return array_key_exists($name, self::OPERATOR);
    }

    public static function isOperatorOptional(string $name): bool
    {
        return array_key_exists($name, self::OPERATOR_OPTIONAL);
    }

    /** Either kind of operator-entered variable — the set a compose form asks for. */
    public static function isOperatorEntered(string $name): bool
    {
        return self::isOperator($name) || self::isOperatorOptional($name);
    }

    public static function isPerRecipient(string $name): bool
    {
        return in_array($name, self::PER_RECIPIENT, true);
    }

    /** @return array{label: string, max: int}|null */
    public static function operatorSpec(string $name): ?array
    {
        return self::OPERATOR[$name] ?? self::OPERATOR_OPTIONAL[$name] ?? null;
    }

    /**
     * Every declared variable this map has never heard of — a failed test,
     * per the class docblock, not a runtime surprise.
     *
     * @param  list<string>  $declared
     * @return list<string>
     */
    public static function unclassified(array $declared): array
    {
        return array_values(array_filter($declared, fn (string $v) => ! in_array($v, self::allKnown(), true)));
    }

    /**
     * Does this list of declared variables include one this product cannot
     * yet fill in for every recipient at once?
     *
     * @param  list<string>  $declared
     */
    public static function needsPerRecipientVariable(array $declared): bool
    {
        foreach ($declared as $name) {
            if (self::isPerRecipient($name)) {
                return true;
            }
        }

        return false;
    }

    /** The message shown at compose and in the picker — ADR 0024 §3.3, verbatim. */
    public const PER_RECIPIENT_MESSAGE = 'This template needs per-person details that cannot be sent yet';

    /**
     * The operator-facing input list for one template's declared variables,
     * in the order declared — `EmnsPresenter`'s `operator_variables`.
     *
     * @param  list<string>  $declared
     * @return list<array{name: string, label: string, required: bool, max: int}>
     */
    public static function operatorInputsFor(array $declared): array
    {
        $inputs = [];

        foreach ($declared as $name) {
            $spec = self::operatorSpec($name);

            if ($spec === null) {
                continue;
            }

            $inputs[] = [
                'name' => $name,
                'label' => $spec['label'],
                'required' => self::isOperator($name),
                'max' => $spec['max'],
            ];
        }

        return $inputs;
    }

    /**
     * ADR 0024 §3.6 — the composer's read-only "Filled in automatically"
     * list: this template's own declared variables that are `DERIVED`, in
     * declaration order, with a human label. Never a form field — the
     * composer shows these as text, not inputs.
     *
     * @param  list<string>  $declared
     * @return list<array{name: string, label: string}>
     */
    public static function derivedInputsFor(array $declared): array
    {
        $inputs = [];

        foreach ($declared as $name) {
            if (self::isDerived($name)) {
                $inputs[] = ['name' => $name, 'label' => self::DERIVED_LABELS[$name] ?? $name];
            }
        }

        return $inputs;
    }
}
