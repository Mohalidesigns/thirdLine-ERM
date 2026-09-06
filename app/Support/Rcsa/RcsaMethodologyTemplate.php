<?php

namespace App\Support\Rcsa;

/**
 * The RCSA methodology exactly as the client's `SB_RCSA Template 2026` workbook
 * defines it.
 *
 * This is the CONTRACT of the rewritten RCSA module. A user must be able to
 * take a completed RCSA out of the system as Excel, hand it to the regulator or
 * the Board Risk Committee, and have it calculate identically to the workbook.
 * Everything in this class is transcribed from that workbook — the labels, the
 * values, the percentage bands, the modifiers and the band cut points — and
 * nothing in it is invented.
 *
 * It lives in a class rather than inline in the migration because four callers
 * need the identical definition: the migration that seeds the system
 * methodology, the truth-table test, the template generator that writes the
 * `Risk Matrix` and `Control Effectiveness Grid` sheets, and the "create a
 * methodology" action in the admin screen. Four copies of a rating band is how
 * the duplicated scales elsewhere in this product came to exist — see
 * App\Support\Scoring\ScoringProfileTemplates, which exists for the same reason.
 *
 * WHY THIS IS NOT A ScoringProfile ROW. The platform already has a versioned,
 * per-tenant scoring configuration with a likelihood scale, an impact scale,
 * rating bands and a residual formula, and the residual formula it ships with
 * is character-for-character the one this module needs. It was the first thing
 * checked. Four things the RCSA methodology carries have nowhere to live on
 * `scoring_profiles`:
 *
 *   1. A control-effectiveness scale with a NUMERIC MODIFIER per rating.
 *      `scoring_profiles` has two scales, likelihood and impact; control
 *      effectiveness reaches RiskScoringService as a bare percentage computed
 *      elsewhere.
 *   2. Impact criteria as a MATRIX — a descriptor for every (impact level ×
 *      dimension) pair. `impact_dimensions` is a flat list of names, and
 *      `impact_scale` holds one definition per level, not one per pair. The
 *      workbook defines all thirty.
 *   3. A treatment and an appetite statement PER BAND. Those are RCSA outputs
 *      (columns S and T), not scoring inputs, and nothing on a scoring profile
 *      produces them.
 *   4. The bands themselves are a different shape: five bands cut at 2/4/9/16
 *      against the platform's four cut at 4/11/19. Widening `scoring_profiles`
 *      to hold both would make one row mean two things.
 *
 * So the methodology is its own aggregate. The overlap is deliberate and
 * documented rather than hidden; if the two ever need to agree, the direction
 * is to derive a ScoringProfile FROM a methodology, never the reverse, because
 * the workbook is the contract and the profile is not.
 */
final class RcsaMethodologyTemplate
{
    /** The code the system methodology is seeded under, per tenant and system-wide. */
    public const CODE = 'sb-rcsa-2026';

    public const NAME = 'SB RCSA 2026';

    public const VERSION = '1.0';

    /* ------------------------------------------------------------------ */
    /*  Scales — workbook columns J, K and O */
    /* ------------------------------------------------------------------ */

    /**
     * Likelihood, workbook column J. The horizon is 24–36 months.
     *
     * The percentage bands are the workbook's own and they are NOT contiguous:
     * Rare is "<5%" and Unlikely starts at 23%, leaving 5–23% unnamed. That is
     * how the client's approved scale reads, so it is transcribed as-is rather
     * than tidied — a scale that disagrees with the signed methodology is worse
     * than one with a gap in it. Raise it as a methodology question; do not
     * silently close the gap here.
     *
     * @var list<array{label: string, value: int, percent_band: string|null, description: string}>
     */
    public const LIKELIHOOD_SCALE = [
        [
            'label' => 'Rare',
            'value' => 1,
            'percent_band' => '<5%',
            'description' => 'May occur only in exceptional circumstances.',
        ],
        [
            'label' => 'Unlikely',
            'value' => 2,
            'percent_band' => '23% to <51%',
            'description' => 'Could occur at some time.',
        ],
        [
            'label' => 'Possible',
            'value' => 3,
            'percent_band' => '51% to <67%',
            'description' => 'Might occur at some time.',
        ],
        [
            'label' => 'Likely',
            'value' => 4,
            'percent_band' => '67% to <90%',
            'description' => 'Will probably occur in most circumstances.',
        ],
        [
            'label' => 'Almost Certain',
            'value' => 5,
            'percent_band' => '>=90%',
            'description' => 'Is expected to occur in most circumstances.',
        ],
    ];

    /**
     * Impact, workbook column K. The band is the FINANCIAL anchor; the five
     * non-financial dimensions are in IMPACT_CRITERIA and the highest
     * applicable dimension drives the rating.
     *
     * Money is written here as a display string rather than in minor units
     * because it is guidance shown beside a selector, not an amount the
     * platform computes with. The moment anything sums or compares these, they
     * move to minor units with an explicit currency, per the platform money
     * rule.
     *
     * @var list<array{label: string, value: int, percent_band: string|null, description: string}>
     */
    public const IMPACT_SCALE = [
        [
            'label' => 'Very Low',
            'value' => 1,
            'percent_band' => 'NGN 0 - NGN 5m',
            'description' => 'Negligible loss; absorbed within normal operating budget.',
        ],
        [
            'label' => 'Low',
            'value' => 2,
            'percent_band' => 'NGN 5m - NGN 10m',
            'description' => 'Minor loss; managed within the business unit.',
        ],
        [
            'label' => 'Medium',
            'value' => 3,
            'percent_band' => 'NGN 10m - NGN 15m',
            'description' => 'Moderate loss; requires management attention.',
        ],
        [
            'label' => 'High',
            'value' => 4,
            'percent_band' => 'NGN 15m - NGN 30m',
            'description' => 'Major loss; requires executive attention.',
        ],
        [
            'label' => 'Very High',
            'value' => 5,
            'percent_band' => 'Above NGN 30m',
            'description' => 'Severe loss; threatens the achievement of objectives.',
        ],
    ];

    /**
     * Control effectiveness, workbook column O, from the `Control effectiveness
     * grid` sheet. `modifier` is the percentage of the inherent risk the
     * control is assessed to remove, and it is what drives column P.
     *
     * @var list<array{label: string, value: int, modifier: int, percent_band: string, description: string}>
     */
    public const CONTROL_EFFECTIVENESS_SCALE = [
        [
            'label' => 'Fully Achieved',
            'value' => 1,
            'modifier' => 100,
            'percent_band' => '76% - 100%',
            'description' => 'The control is designed appropriately and is operating as intended '
                .'throughout the period. No exceptions noted.',
        ],
        [
            'label' => 'Mostly Achieved',
            'value' => 2,
            'modifier' => 75,
            'percent_band' => '51% - 75%',
            'description' => 'The control is designed appropriately and is largely operating as '
                .'intended. Minor exceptions noted that do not undermine the control objective.',
        ],
        [
            'label' => 'Partially Achieved',
            'value' => 3,
            'modifier' => 50,
            'percent_band' => '26% - 50%',
            'description' => 'The control addresses part of the risk only, or is applied '
                .'inconsistently. Significant exceptions noted.',
        ],
        [
            'label' => 'Not Achieved',
            'value' => 4,
            'modifier' => 25,
            'percent_band' => '0% - 25%',
            'description' => 'The control is absent, is not operating, or does not address the '
                .'risk. The control objective is not met.',
        ],
    ];

    /* ------------------------------------------------------------------ */
    /*  Bands — workbook columns M, R, S and T */
    /* ------------------------------------------------------------------ */

    /**
     * The five bands, their treatment (column S) and their appetite statement
     * (column T). `min_score` and `max_score` are INCLUSIVE and are expressed
     * against the 1–25 score, but the residual score is fractional, so the
     * upper bound of one band and the lower bound of the next are not
     * consecutive integers: VERY LOW ends at 2 and LOW starts at 2.01 so that a
     * residual of 2.5 bands as LOW rather than falling through a gap.
     *
     * The plan's §3.3 states the bands as `s <= 2`, `s <= 4`, `s <= 9`,
     * `s <= 16`, `else`; these rows are that, written as closed intervals.
     *
     * @var list<array{level: string, label: string, min_score: float, max_score: float, colour: string, treatment: string, appetite_status: string}>
     */
    public const RISK_BANDS = [
        [
            'level' => 'very_low',
            'label' => 'Very Low',
            'min_score' => 0.0,
            'max_score' => 2.0,
            'colour' => '#22c55e',
            'treatment' => 'accept',
            'appetite_status' => 'Within risk appetite: Continue routine monitoring',
        ],
        [
            'level' => 'low',
            'label' => 'Low',
            'min_score' => 2.01,
            'max_score' => 4.0,
            'colour' => '#84cc16',
            'treatment' => 'accept',
            'appetite_status' => 'Within risk appetite: Continue routine monitoring',
        ],
        [
            'level' => 'medium',
            'label' => 'Medium',
            'min_score' => 4.01,
            'max_score' => 9.0,
            'colour' => '#eab308',
            'treatment' => 'mitigate',
            'appetite_status' => 'Above risk appetite: Mitigate',
        ],
        [
            'level' => 'high',
            'label' => 'High',
            'min_score' => 9.01,
            'max_score' => 16.0,
            'colour' => '#f97316',
            'treatment' => 'treat',
            'appetite_status' => 'Above risk appetite: Treat',
        ],
        [
            'level' => 'very_high',
            'label' => 'Very High',
            'min_score' => 16.01,
            'max_score' => 25.0,
            'colour' => '#dc2626',
            'treatment' => 'treat',
            'appetite_status' => 'Above risk appetite: Treat',
        ],
    ];

    /**
     * The prefix that marks a line as needing an action plan. Column T is a
     * sentence, not a flag, so the flag is derived from it — and derived from
     * ONE constant, because "does this line need an action plan" is asked by
     * the calculation service, the submission gate, the import validator and
     * the export writer.
     */
    public const ABOVE_APPETITE_PREFIX = 'Above risk appetite';

    /* ------------------------------------------------------------------ */
    /*  Impact criteria — the `Risk Matrix` sheet */
    /* ------------------------------------------------------------------ */

    /**
     * The six impact dimensions, in the workbook's display order. Financial is
     * first because it is the one carrying the naira anchors on IMPACT_SCALE.
     *
     * @var list<string>
     */
    public const IMPACT_DIMENSIONS = [
        'financial',
        'brand_reputation',
        'operational',
        'legal_regulatory',
        'customer_satisfaction',
        'health_safety',
    ];

    /** @var array<string, string> */
    public const IMPACT_DIMENSION_LABELS = [
        'financial' => 'Financial',
        'brand_reputation' => 'Brand / Reputation',
        'operational' => 'Operational',
        'legal_regulatory' => 'Legal / Regulatory',
        'customer_satisfaction' => 'Customer Satisfaction',
        'health_safety' => 'Health & Safety',
    ];

    /**
     * The descriptor for every (impact value × dimension) pair — thirty rows.
     *
     * These are what turn the impact selector from a bare five-item dropdown
     * into the workbook's guidance, and the plan is explicit that they must be
     * stored and surfaced rather than left in a spreadsheet: an assessor
     * picking "High" should be able to see that it means a regulatory sanction
     * as well as NGN 15–30m.
     *
     * @var array<int, array<string, string>>
     */
    public const IMPACT_CRITERIA = [
        1 => [
            'financial' => 'Loss up to NGN 5m.',
            'brand_reputation' => 'No adverse publicity; isolated internal comment only.',
            'operational' => 'No disruption to service; handled within business-as-usual.',
            'legal_regulatory' => 'No breach; routine correspondence with the regulator.',
            'customer_satisfaction' => 'No customer impact; no complaints arising.',
            'health_safety' => 'No injury; no first aid required.',
        ],
        2 => [
            'financial' => 'Loss of NGN 5m to NGN 10m.',
            'brand_reputation' => 'Limited local comment; contained without intervention.',
            'operational' => 'Disruption of under one business day to a single unit.',
            'legal_regulatory' => 'Minor breach; resolved without regulatory action.',
            'customer_satisfaction' => 'Isolated complaints; resolved at first contact.',
            'health_safety' => 'Minor injury requiring first aid only.',
        ],
        3 => [
            'financial' => 'Loss of NGN 10m to NGN 15m.',
            'brand_reputation' => 'Adverse local media coverage; short-lived.',
            'operational' => 'Disruption of one to three business days to a single unit.',
            'legal_regulatory' => 'Breach attracting a regulatory query or a formal warning.',
            'customer_satisfaction' => 'Multiple complaints; measurable dip in service metrics.',
            'health_safety' => 'Injury requiring medical treatment; no lost time.',
        ],
        4 => [
            'financial' => 'Loss of NGN 15m to NGN 30m.',
            'brand_reputation' => 'Sustained adverse national coverage; customer attrition.',
            'operational' => 'Disruption of over three business days, or multiple units affected.',
            'legal_regulatory' => 'Breach attracting a monetary penalty or a directive.',
            'customer_satisfaction' => 'Widespread dissatisfaction; escalation to the regulator.',
            'health_safety' => 'Serious injury; lost time or hospitalisation.',
        ],
        5 => [
            'financial' => 'Loss above NGN 30m.',
            'brand_reputation' => 'Severe and lasting damage to the brand; board-level response.',
            'operational' => 'Critical service unavailable; enterprise-wide disruption.',
            'legal_regulatory' => 'Major breach; licence conditions, sanction or prosecution.',
            'customer_satisfaction' => 'Systemic customer harm; mass complaints or redress.',
            'health_safety' => 'Fatality or permanent disability.',
        ],
    ];

    /* ------------------------------------------------------------------ */
    /*  Vocabularies */
    /* ------------------------------------------------------------------ */

    /**
     * The thirteen risk categories from the workbook's `Sheet1`, in its order.
     *
     * Stored on the register row as a string against this vocabulary rather
     * than as a foreign key to `risk_categories`, because this list is an
     * import/export contract with a workbook that will be filled in offline on
     * machines that have never seen the tenant's taxonomy. The two are
     * reconciled in the universe screen, not in the file format.
     *
     * @var list<string>
     */
    public const RISK_CATEGORIES = [
        'Strategic',
        'Operational',
        'Compliance/Regulatory',
        'Financial',
        'Credit',
        'Market',
        'Liquidity',
        'IT/Cybersecurity',
        'Third-Party/Outsourcing',
        'Reputational',
        'Model',
        'Legal',
        'Others',
    ];

    /** The category that unlocks the free-text column I. */
    public const CATEGORY_OTHERS = 'Others';

    /**
     * Spellings accepted on import that are not the canonical label.
     *
     * Keys are compared lower-cased and whitespace-collapsed. `moderate` is
     * defect D1 in the plan: the workbook's `Risk Matrix` sheet labels the mid
     * impact rating "Moderate" while its own dropdown validation says "Medium",
     * so a file filled in from the printed matrix would fail validation on a
     * word the client's own template taught the user to write.
     *
     * @var array<string, string>
     */
    public const IMPORT_ALIASES = [
        // Impact — defect D1.
        'moderate' => 'Medium',
        'insignificant' => 'Very Low',
        'minor' => 'Low',
        'major' => 'High',
        'catastrophic' => 'Very High',

        // Likelihood.
        'almost certain' => 'Almost Certain',
        'certain' => 'Almost Certain',
        'remote' => 'Rare',

        // Control effectiveness.
        'fully achieved' => 'Fully Achieved',
        'fully' => 'Fully Achieved',
        'effective' => 'Fully Achieved',
        'mostly achieved' => 'Mostly Achieved',
        'mostly' => 'Mostly Achieved',
        'partially achieved' => 'Partially Achieved',
        'partially' => 'Partially Achieved',
        'partially effective' => 'Partially Achieved',
        'not achieved' => 'Not Achieved',
        'ineffective' => 'Not Achieved',

        // Risk categories.
        'it risk' => 'IT/Cybersecurity',
        'it/cyber' => 'IT/Cybersecurity',
        'cyber' => 'IT/Cybersecurity',
        'information technology' => 'IT/Cybersecurity',
        'compliance' => 'Compliance/Regulatory',
        'regulatory' => 'Compliance/Regulatory',
        'third party' => 'Third-Party/Outsourcing',
        'third-party' => 'Third-Party/Outsourcing',
        'outsourcing' => 'Third-Party/Outsourcing',
        'vendor' => 'Third-Party/Outsourcing',
        'reputation' => 'Reputational',
        'other' => 'Others',
    ];

    /* ------------------------------------------------------------------ */
    /*  Modes */
    /* ------------------------------------------------------------------ */

    /** Residual is always derived from inherent and the CE modifier. */
    public const RESIDUAL_CALCULATED = 'calculated';

    /** The assessor supplies their own residual likelihood and impact. */
    public const RESIDUAL_ASSESSED = 'assessed';

    /** Calculated by default; the assessor may override with a justification. */
    public const RESIDUAL_HYBRID = 'hybrid';

    /** @var list<string> */
    public const RESIDUAL_MODES = [
        self::RESIDUAL_CALCULATED,
        self::RESIDUAL_ASSESSED,
        self::RESIDUAL_HYBRID,
    ];

    /**
     * The methodology row as the migration and the admin builder insert it.
     *
     * `residual_floor` DEFAULTS TO ZERO, which is template parity: the workbook
     * drives residual to 0.00 on Fully Achieved, so a 25-score inherent risk
     * with one fully-achieved control bands as VERY LOW. That is defect D2 in
     * the plan and it is a methodology decision for the bank (§14 Q3), not a
     * bug to fix unilaterally — so the floor is configurable and seeded at 0,
     * and the module behaves exactly like the workbook until someone with the
     * authority to change the methodology raises it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function methodology(array $overrides = []): array
    {
        return array_merge([
            'code' => self::CODE,
            'name' => self::NAME,
            'version' => self::VERSION,
            'description' => 'The scoring methodology defined by the SB_RCSA Template 2026 workbook: '
                .'a 5×5 inherent matrix, a four-point control effectiveness grid discounting the '
                .'inherent score, and five residual bands driving treatment and appetite alignment.',
            'status' => 'active',
            'residual_mode' => self::RESIDUAL_CALCULATED,
            'residual_floor' => 0,
            'appetite_ceiling_level' => 'low',
            'is_system' => true,
            'is_locked' => false,
        ], $overrides);
    }

    /**
     * Every scale row, flattened for insertion into `rcsa_scale_items`.
     *
     * @return list<array<string, mixed>>
     */
    public static function scaleItems(): array
    {
        $rows = [];
        $sort = 0;

        foreach (self::LIKELIHOOD_SCALE as $item) {
            $rows[] = [
                'type' => 'likelihood',
                'label' => $item['label'],
                'value' => $item['value'],
                'modifier' => null,
                'percent_band' => $item['percent_band'],
                'description' => $item['description'],
                'sort_order' => $sort += 10,
            ];
        }

        $sort = 0;

        foreach (self::IMPACT_SCALE as $item) {
            $rows[] = [
                'type' => 'impact',
                'label' => $item['label'],
                'value' => $item['value'],
                'modifier' => null,
                'percent_band' => $item['percent_band'],
                'description' => $item['description'],
                'sort_order' => $sort += 10,
            ];
        }

        $sort = 0;

        foreach (self::CONTROL_EFFECTIVENESS_SCALE as $item) {
            $rows[] = [
                'type' => 'control_effectiveness',
                'label' => $item['label'],
                'value' => $item['value'],
                'modifier' => $item['modifier'],
                'percent_band' => $item['percent_band'],
                'description' => $item['description'],
                'sort_order' => $sort += 10,
            ];
        }

        return $rows;
    }

    /**
     * The thirty impact criteria, flattened for insertion.
     *
     * @return list<array<string, mixed>>
     */
    public static function impactCriteria(): array
    {
        $rows = [];

        foreach (self::IMPACT_CRITERIA as $value => $dimensions) {
            $sort = 0;

            foreach (self::IMPACT_DIMENSIONS as $dimension) {
                $rows[] = [
                    'impact_value' => $value,
                    'dimension' => $dimension,
                    'descriptor' => $dimensions[$dimension],
                    'sort_order' => $sort += 10,
                ];
            }
        }

        return $rows;
    }

    /**
     * The five bands, flattened for insertion.
     *
     * @return list<array<string, mixed>>
     */
    public static function riskBands(): array
    {
        $rows = [];
        $sort = 0;

        foreach (self::RISK_BANDS as $band) {
            $rows[] = $band + ['sort_order' => $sort += 10];
        }

        return $rows;
    }
}
