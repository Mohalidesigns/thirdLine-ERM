<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Control effectiveness bands
    |--------------------------------------------------------------------------
    |
    | The percentage each effectiveness rating contributes to a risk's
    | aggregate control effectiveness, used when a control has no measured
    | `effectiveness_pct` of its own.
    |
    | There used to be two of these: a three-band map on the Control model
    | (100 / 50 / 0) and this five-band one inside ControlEffectivenessService.
    | The same control scored differently depending on which code path asked.
    | The five-band map won — 100% effective is a claim no control auditor
    | would sign, and 0% is indistinguishable from having no control at all.
    |
    | An organization may override any band via its `settings` JSON under
    | `risk.control_effectiveness`.
    |
    */

    'control_effectiveness' => [
        'effective' => 95,
        'mostly_effective' => 80,
        'partially_effective' => 60,
        'ineffective' => 37,
        'not_operating' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Impact aggregation
    |--------------------------------------------------------------------------
    |
    | How the five impact dimensions (financial, operational, reputational,
    | regulatory, strategic) collapse into the single impact score that drives
    | the inherent rating.
    |
    |   max        the worst dimension wins (the default, and what the
    |              platform did before this was configurable)
    |   average    the mean of the dimensions that were scored
    |   weighted   a weighted mean using `impact_weights`
    |   worst_two  the mean of the two highest dimensions — a middle ground
    |              for organisations that consider a single bad dimension
    |              insufficient grounds for a critical rating
    |
    | Dimensions left unscored are excluded rather than treated as zero, so a
    | partially completed assessment is not silently rated lower than it should
    | be. Override per organization via `settings` under
    | `risk.impact_aggregation` and `risk.impact_weights`.
    |
    */

    'impact_aggregation' => 'max',

    'impact_weights' => [
        'financial' => 1.0,
        'operational' => 1.0,
        'reputational' => 1.0,
        'regulatory' => 1.0,
        'strategic' => 1.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Regulatory reportability threshold
    |--------------------------------------------------------------------------
    |
    | The gross loss, in naira, at or above which a loss event is flagged
    | `is_regulatory_reportable` unless the reporter answers the question
    | themselves. This was the literal `10000000` in
    | LossEventController::store() (migration Phase 4.3).
    |
    | IT DOES NOT AGREE WITH RegulatoryThresholdService::CBN_REPORTING_THRESHOLD_KOBO,
    | which is NGN 5,000,000, and that disagreement is older than this config
    | key. An event of NGN 6m raises a CBN alert saying notification is required
    | within seven days AND is left with the reportable flag false. The value
    | here is the one the product has been shipping; moving it is a policy
    | decision for the compliance owner, not a refactor, so it is surfaced here
    | rather than quietly aligned. See docs/migration/phase-4-notes/loss-events.md.
    |
    | An organization may override it via its `settings` JSON under
    | `risk.regulatory_reportable_threshold_ngn`.
    |
    */

    'regulatory_reportable_threshold_ngn' => 10_000_000,

];
