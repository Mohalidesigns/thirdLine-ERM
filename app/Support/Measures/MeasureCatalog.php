<?php

namespace App\Support\Measures;

use App\Models\Measure;
use App\Models\ObjectType;
use App\Models\Unit;

/**
 * The measures the platform defines for itself.
 *
 * A KRI is defined by the organisation; these are defined by the product,
 * because the platform itself writes them — a risk score is period-stamped on
 * assessment approval, control effectiveness on a test, capital and CPI by
 * import. They are seeded per organisation rather than globally so that a
 * tenant can retire, rename or re-own one without affecting anybody else.
 *
 * aggregation here means "how this measure combines when rolled up" — across
 * the objects beneath a node, and across the finer periods inside a coarser
 * one. A score is a level, so it is `last`; an exposure is an amount, so it is
 * `sum`.
 */
class MeasureCatalog
{
    /* The nine risk measures WP-04 TASK 4 period-stamps. */
    public const RISK_INHERENT_SCORE = 'risk.inherent_score';

    public const RISK_RESIDUAL_SCORE = 'risk.residual_score';

    public const RISK_TARGET_SCORE = 'risk.target_score';

    public const RISK_INHERENT_LIKELIHOOD = 'risk.inherent_likelihood';

    public const RISK_INHERENT_IMPACT = 'risk.inherent_impact';

    public const RISK_RESIDUAL_LIKELIHOOD = 'risk.residual_likelihood';

    public const RISK_RESIDUAL_IMPACT = 'risk.residual_impact';

    public const RISK_CONTROL_EFFECTIVENESS = 'risk.control_effectiveness';

    public const RISK_FINANCIAL_EXPOSURE = 'risk.financial_exposure';

    /* Inputs that formula thresholds reference. */
    public const CAPITAL_TOTAL_QUALIFYING = 'capital.total_qualifying';

    public const FINANCIAL_REVENUE = 'financial.revenue';

    public const MACRO_CPI_INDEX = 'macro.cpi_index';

    /** @return list<string> */
    public static function riskMeasureCodes(): array
    {
        return [
            self::RISK_INHERENT_SCORE,
            self::RISK_RESIDUAL_SCORE,
            self::RISK_TARGET_SCORE,
            self::RISK_INHERENT_LIKELIHOOD,
            self::RISK_INHERENT_IMPACT,
            self::RISK_RESIDUAL_LIKELIHOOD,
            self::RISK_RESIDUAL_IMPACT,
            self::RISK_CONTROL_EFFECTIVENESS,
            self::RISK_FINANCIAL_EXPOSURE,
        ];
    }

    /**
     * code => definition. object_type is a code from the type registry, or null
     * for a measure that hangs off whatever node reports it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $score = [
            'measure_kind' => 'risk_score',
            'object_type' => 'Risk',
            'unit' => 'score',
            'aggregation' => 'last',
            // A risk score going up is bad, so a lower value is better.
            'polarity' => 'lower_better',
            'decimal_places' => 0,
            'source' => 'calculated',
        ];

        return [
            self::RISK_INHERENT_SCORE => $score + [
                'name' => 'Inherent risk score',
                'description' => 'Likelihood x impact before controls, as approved for the period.',
            ],
            self::RISK_RESIDUAL_SCORE => $score + [
                'name' => 'Residual risk score',
                'description' => 'Likelihood x impact after controls, as approved for the period.',
            ],
            self::RISK_TARGET_SCORE => $score + [
                'name' => 'Target risk score',
                'description' => 'The score the treatment strategy is intended to reach.',
            ],
            self::RISK_INHERENT_LIKELIHOOD => $score + [
                'name' => 'Inherent likelihood',
                'description' => 'Likelihood rating before controls (1-5).',
            ],
            self::RISK_INHERENT_IMPACT => $score + [
                'name' => 'Inherent impact',
                'description' => 'Aggregated impact rating before controls (1-5).',
            ],
            self::RISK_RESIDUAL_LIKELIHOOD => $score + [
                'name' => 'Residual likelihood',
                'description' => 'Likelihood rating after controls (1-5).',
            ],
            self::RISK_RESIDUAL_IMPACT => $score + [
                'name' => 'Residual impact',
                'description' => 'Impact rating after controls (1-5).',
            ],

            self::RISK_CONTROL_EFFECTIVENESS => [
                'name' => 'Control effectiveness',
                'description' => 'Weighted effectiveness of the controls mitigating this risk.',
                'measure_kind' => 'control_eff',
                'object_type' => 'Risk',
                'unit' => 'pct',
                'aggregation' => 'weighted_avg',
                'polarity' => 'higher_better',
                'decimal_places' => 1,
                'source' => 'calculated',
            ],

            self::RISK_FINANCIAL_EXPOSURE => [
                'name' => 'Financial exposure',
                'description' => 'Monetary exposure attributed to this risk, in minor units of the recorded currency.',
                'measure_kind' => 'financial',
                'object_type' => 'Risk',
                'unit' => 'kobo',
                // Exposures add up the graph; a division's exposure is the sum
                // of the exposures of the risks beneath it.
                'aggregation' => 'sum',
                'polarity' => 'lower_better',
                'decimal_places' => 2,
                'source' => 'calculated',
            ],

            self::CAPITAL_TOTAL_QUALIFYING => [
                'name' => 'Total qualifying capital',
                'description' => 'Tier 1 plus eligible Tier 2 capital. The denominator for capital-linked limits.',
                'measure_kind' => 'capital',
                'object_type' => null,
                'unit' => 'kobo',
                'aggregation' => 'last',
                'polarity' => 'higher_better',
                'decimal_places' => 2,
                'source' => 'import',
                'frequency' => 'quarterly',
            ],

            self::FINANCIAL_REVENUE => [
                'name' => 'Revenue',
                'description' => 'Gross revenue for the period. Referenced by revenue-linked limits.',
                'measure_kind' => 'financial',
                'object_type' => null,
                'unit' => 'kobo',
                'aggregation' => 'sum',
                'polarity' => 'higher_better',
                'decimal_places' => 2,
                'source' => 'import',
                'frequency' => 'monthly',
            ],

            self::MACRO_CPI_INDEX => [
                'name' => 'Consumer price index',
                'description' => 'NBS all-items CPI for the month. Drives inflation re-baselining of naira thresholds.',
                'measure_kind' => 'financial',
                'object_type' => null,
                'unit' => 'index',
                'aggregation' => 'last',
                'polarity' => 'higher_better',
                'decimal_places' => 2,
                'source' => 'import',
                'frequency' => 'monthly',
            ],
        ];
    }

    /**
     * Create or refresh every catalogue measure for an organisation.
     *
     * Idempotent. Does NOT touch is_active or owner_id on a measure that
     * already exists — those are the organisation's to set, and a re-run of the
     * installer should not un-retire a measure somebody deliberately switched
     * off.
     *
     * @return array<string, Measure> code => measure
     */
    public static function install(int $organizationId): array
    {
        $installed = [];

        foreach (self::definitions() as $code => $definition) {
            $existing = Measure::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('code', $code)
                ->first();

            $attributes = [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'object_type_id' => $definition['object_type'] === null
                    ? null
                    : self::objectTypeId($definition['object_type']),
                'measure_kind' => $definition['measure_kind'],
                'unit_id' => Unit::where('code', $definition['unit'])->value('id'),
                'aggregation' => $definition['aggregation'],
                'polarity' => $definition['polarity'],
                'decimal_places' => $definition['decimal_places'],
                'is_derived' => false,
                'source' => $definition['source'],
                'frequency' => $definition['frequency'] ?? null,
            ];

            if ($existing === null) {
                $attributes['organization_id'] = $organizationId;
                $attributes['code'] = $code;
                $attributes['is_active'] = true;
                $installed[$code] = Measure::withoutGlobalScopes()->create($attributes);

                continue;
            }

            $existing->fill($attributes)->save();
            $installed[$code] = $existing;
        }

        return $installed;
    }

    private static function objectTypeId(string $code): ?int
    {
        $id = ObjectType::withoutGlobalScopes()
            ->where('code', $code)
            ->orderByRaw('CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
