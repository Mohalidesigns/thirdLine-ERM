<?php

namespace App\Support\Tprm;

/**
 * The nine third-party KRIs published into the KRI Collection module —
 * FR-RPT-10.
 *
 * WHY THESE NINE AND NOT A DASHBOARD. Every figure here already exists on some
 * TPRM screen. Publishing them as KRIs puts them in the same board pack, on
 * the same RAG bands and through the same breach detection as credit and
 * market indicators — which is the difference between a third-party programme
 * the board reviews and one it visits.
 *
 * `assurance_depth` IS THE ONE TO PUT IN FRONT OF A REGULATOR. The other eight
 * measure activity: how many were assessed, how many findings are open, how
 * fast vendors reply. Assurance Depth measures how much of the comfort is
 * EVIDENCE rather than ASSERTION — the mean evidence coefficient across
 * Critical and High engagements. A programme can score well on all eight and
 * still be resting entirely on vendors' own questionnaires, and this is the
 * number that says so.
 *
 * THE THRESHOLDS SHIPPED HERE ARE DEFAULTS AND ARE THE TENANT'S TO CHANGE.
 * They are a starting position from the same risk function that wrote the
 * default ruleset, not a regulatory statement. Every one is editable on the
 * KRI screen once published, and republishing never overwrites a threshold
 * somebody has moved.
 *
 * `direction` USES THE KRI MODULE'S OWN VOCABULARY (`higher_is_worse` /
 * `lower_is_worse`) rather than inventing a second one, so the bands, the RAG
 * colours and the breach rules are the module's and not a TPRM copy of them.
 */
class KriCatalogue
{
    public const HIGHER_IS_WORSE = 'higher_is_worse';

    public const LOWER_IS_WORSE = 'lower_is_worse';

    /**
     * @return list<array{
     *     code: string, kri_code: string, name: string, unit: string,
     *     direction: string, frequency: string, formula: string,
     *     description: string, green: float, red: float
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'critical_assessed_within_cadence',
                'kri_code' => 'TPRM-01',
                'name' => 'Critical vendors assessed within cadence',
                'unit' => '%',
                'direction' => self::LOWER_IS_WORSE,
                'frequency' => 'monthly',
                'formula' => 'Critical engagements whose last validated assessment is within the tier policy '
                    .'cadence ÷ all Critical engagements × 100',
                'description' => 'Whether the vendors that could hurt the institution most are being looked at '
                    .'on the cycle the policy sets.',
                'green' => 95.0,
                'red' => 80.0,
            ],
            [
                'code' => 'contracts_with_blocking_clauses',
                'kri_code' => 'TPRM-02',
                'name' => 'Contracts with all blocking clauses present',
                'unit' => '%',
                'direction' => self::LOWER_IS_WORSE,
                'frequency' => 'monthly',
                'formula' => 'Executed contracts with no unwaived blocking clause gap ÷ all executed contracts × 100',
                'description' => 'A blocking gap is a term the institution requires and the agreement does not '
                    .'contain. A waived gap counts as covered, which is why the waiver register is read beside '
                    .'this number.',
                'green' => 98.0,
                'red' => 90.0,
            ],
            [
                'code' => 'average_finding_age',
                'kri_code' => 'TPRM-03',
                'name' => 'Average age of open third-party findings',
                'unit' => 'days',
                'direction' => self::HIGHER_IS_WORSE,
                'frequency' => 'monthly',
                'formula' => 'Mean days since identification across all open findings',
                'description' => 'Measured from identification, never from the target date. A finding whose '
                    .'target has been moved three times is old, and measuring from the latest target would '
                    .'show a programme that is always nearly done.',
                'green' => 45.0,
                'red' => 90.0,
            ],
            [
                'code' => 'overdue_findings',
                'kri_code' => 'TPRM-04',
                'name' => 'Open findings past their remediation date',
                'unit' => 'count',
                'direction' => self::HIGHER_IS_WORSE,
                'frequency' => 'monthly',
                'formula' => 'Count of open findings whose target date has passed',
                'description' => 'A count rather than a percentage, because one overdue Critical finding is a '
                    .'board conversation whatever the denominator says.',
                'green' => 0.0,
                'red' => 5.0,
            ],
            [
                'code' => 'expired_mandatory_evidence',
                'kri_code' => 'TPRM-05',
                'name' => 'Expired assurance evidence',
                'unit' => 'count',
                'direction' => self::HIGHER_IS_WORSE,
                'frequency' => 'monthly',
                'formula' => 'Count of assurance documents past their valid-to date and not superseded',
                'description' => 'Each of these has already decayed the assurance coefficient of whatever it '
                    .'evidenced, so the residual scores moved before anybody was told.',
                'green' => 0.0,
                'red' => 10.0,
            ],
            [
                'code' => 'concentration_index',
                'kri_code' => 'TPRM-06',
                'name' => 'Provider concentration (HHI)',
                'unit' => 'index',
                'direction' => self::HIGHER_IS_WORSE,
                'frequency' => 'quarterly',
                'formula' => 'Herfindahl-Hirschman index across provider groups',
                'description' => 'The bands match config/tprm.php: below 1500 diversified, 1500 to 2500 '
                    .'moderate, above 2500 concentrated.',
                'green' => 1500.0,
                'red' => 2500.0,
            ],
            [
                'code' => 'exit_plan_test_currency',
                'kri_code' => 'TPRM-07',
                'name' => 'Exit plans tested within their interval',
                'unit' => '%',
                'direction' => self::LOWER_IS_WORSE,
                'frequency' => 'quarterly',
                'formula' => 'Exit plans tested within the tier policy interval ÷ engagements requiring a plan × 100',
                'description' => 'The denominator is engagements that REQUIRE a plan, not plans that exist. A '
                    .'ratio over the plans written flatters a programme that has written three and needs thirty.',
                'green' => 90.0,
                'red' => 60.0,
            ],
            [
                'code' => 'mean_vendor_response_time',
                'kri_code' => 'TPRM-08',
                'name' => 'Mean vendor assessment response time',
                'unit' => 'days',
                'direction' => self::HIGHER_IS_WORSE,
                'frequency' => 'monthly',
                'formula' => 'Mean days from assessment issued to submitted, over assessments submitted in the '
                    .'last twelve months',
                'description' => 'Only submitted assessments are in the mean. An assessment a vendor has sat on '
                    .'for ninety days and not returned is not a slow response, it is an absent one, and '
                    .'counting it here would let the outstanding ones improve the number.',
                'green' => 14.0,
                'red' => 30.0,
            ],
            [
                'code' => 'assurance_depth',
                'kri_code' => 'TPRM-09',
                'name' => 'Assurance depth (mean EC, Critical and High)',
                'unit' => 'coefficient',
                'direction' => self::LOWER_IS_WORSE,
                'frequency' => 'quarterly',
                'formula' => 'Mean evidence coefficient across Critical and High engagements carrying a score',
                'description' => 'How much of the comfort is evidence rather than assertion. A programme can '
                    .'score well on every other indicator and still rest entirely on vendors\' own '
                    .'questionnaires; this is the number that says so, and it is the one to put in front of a '
                    .'regulator.',
                'green' => 0.70,
                'red' => 0.45,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $code): ?array
    {
        foreach (self::all() as $metric) {
            if ($metric['code'] === $code) {
                return $metric;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::all(), 'code');
    }
}
