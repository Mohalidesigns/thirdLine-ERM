<?php

namespace Tests\Unit\Rcsa;

use App\Models\Rcsa\RcsaMethodology;
use App\Services\Rcsa\RcsaCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §12's P0 acceptance criterion, finally checked against its source:
 *
 * > 100-case truth table (5 likelihood × 5 impact × 4 CE) passes against the
 * > workbook formulas, byte for byte.
 *
 * P0 could not run it. The `.xlsx` was unavailable, so the truth table was
 * written from §3.3 of the plan and the criterion was carried as an open
 * verification gap through every phase to P9. The workbook is now in
 * `plans/SB _RCSA Template 2026 - Template.xlsx` and this closes it.
 *
 * THE FORMULAS ARE TRANSCRIBED HERE, NOT PARSED FROM THE FILE. Deliberately:
 * a test that opened the workbook would be a test of PhpSpreadsheet's formula
 * engine, would break if somebody re-saved the file, and would pass vacuously
 * if the file went missing. Transcribing them makes this a second, independent
 * statement of the rules that the engine has to agree with — which is the same
 * argument the truth table itself is built on. The originals are quoted in each
 * method and in `_meta.workbookFormulas` of the truth table.
 *
 * WHERE THE WORKBOOK CONTRADICTS ITSELF, THE FORMULA WINS, because the formula
 * is what calculates. Its Risk Matrix sheet calls impact level 3 "Moderate"
 * while column L matches on "Medium"; its Control effectiveness grid spells the
 * ratings lower-case while column P matches on "Fully Achieved". The engine
 * follows the formulas, and a bank typing "Moderate" into an upload is handled
 * by the importer's alias map rather than by changing what calculates.
 */
class RcsaWorkbookParityTest extends TestCase
{
    use RefreshDatabase;

    /** Column J's CHOOSE/MATCH list, in the workbook's order. */
    private const LIKELIHOOD = ['Rare' => 1, 'Unlikely' => 2, 'Possible' => 3, 'Likely' => 4, 'Almost certain' => 5];

    /** Column K's. */
    private const IMPACT = ['Very Low' => 1, 'Low' => 2, 'Medium' => 3, 'High' => 4, 'Very High' => 5];

    /** Column P: `=CHOOSE(MATCH(O3,{...},0),100,75,50,25)`. */
    private const MODIFIER = [
        'Fully Achieved' => 100,
        'Mostly Achieved' => 75,
        'Partially Achieved' => 50,
        'Not Achieved' => 25,
    ];

    /**
     * Columns M and R:
     * `=IF(x<=2,"VERY LOW",IF(x<=4,"LOW",IF(x<=9,"MEDIUM",IF(x<=16,"HIGH","VERY HIGH"))))`
     */
    private function band(float $score): string
    {
        return match (true) {
            $score <= 2 => 'VERY LOW',
            $score <= 4 => 'LOW',
            $score <= 9 => 'MEDIUM',
            $score <= 16 => 'HIGH',
            default => 'VERY HIGH',
        };
    }

    /**
     * Column S:
     * `=IF(R3="VERY HIGH","TREAT",IF(R3="HIGH","TREAT",IF(R3="MEDIUM","MITIGATE",…"ACCEPT")))`
     */
    private function treatment(string $level): string
    {
        return match ($level) {
            'VERY HIGH', 'HIGH' => 'TREAT',
            'MEDIUM' => 'MITIGATE',
            default => 'ACCEPT',
        };
    }

    private function methodology(): RcsaMethodology
    {
        return RcsaMethodology::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->with(['scaleItems', 'bands'])
            ->firstOrFail();
    }

    /**
     * All 5 × 5 × 4 of them, every derived column compared.
     */
    #[Test]
    public function every_one_of_the_hundred_cases_matches_the_workbook(): void
    {
        $calculator = app(RcsaCalculationService::class);
        $methodology = $this->methodology();

        $mismatches = [];
        $cases = 0;

        foreach (self::LIKELIHOOD as $likelihoodLabel => $likelihood) {
            foreach (self::IMPACT as $impactLabel => $impact) {
                foreach (self::MODIFIER as $control => $modifier) {
                    $cases++;

                    // The workbook, column by column.
                    $score = $likelihood * $impact;                 // L
                    $inherentLevel = $this->band((float) $score);   // M
                    $residual = $score * (1 - $modifier / 100);     // Q
                    $residualLevel = $this->band($residual);        // R
                    $treatment = $this->treatment($residualLevel);  // S

                    $result = $calculator->calculate(
                        likelihood: $likelihood,
                        impact: $impact,
                        controlEffectiveness: $control,
                        methodology: $methodology,
                    );

                    $upper = fn (?string $value) => strtoupper(str_replace('_', ' ', (string) $value));

                    $comparisons = [
                        'L risk score' => [$score, $result->inherentScore],
                        'M inherent level' => [$inherentLevel, $upper($result->inherentLevel)],
                        'P C.E modifier' => [$modifier, $result->ceModifier],
                        'Q residual' => [round($residual, 2), round((float) $result->residualScore, 2)],
                        'R residual level' => [$residualLevel, $upper($result->residualLevel)],
                        'S treatment' => [$treatment, $upper($result->riskTreatment)],
                    ];

                    foreach ($comparisons as $column => [$workbook, $ours]) {
                        if ((string) $workbook !== (string) $ours) {
                            $mismatches[] = sprintf(
                                '%s / %s / %s — %s: workbook %s, engine %s',
                                $likelihoodLabel, $impactLabel, $control, $column, $workbook, $ours,
                            );
                        }
                    }
                }
            }
        }

        $this->assertSame(100, $cases, 'The case matrix is not 5 × 5 × 4.');
        $this->assertSame([], $mismatches, "The engine disagrees with the workbook:\n".implode("\n", $mismatches));
    }

    /**
     * Defect D2, which §14 Q3 asks the bank about — pinned as template parity
     * rather than as an accident.
     *
     * A Fully Achieved control carries a modifier of 100, so `L*(1-100/100)` is
     * zero: the workbook takes a 5 × 5 risk to a residual of 0.00 and bands it
     * VERY LOW. That is what the file does, so it is what the engine does. The
     * bank may decide otherwise, and `residual_floor` is the column where that
     * decision goes.
     */
    #[Test]
    public function a_fully_achieved_control_takes_the_worst_risk_to_zero_exactly_as_the_workbook_does(): void
    {
        $result = app(RcsaCalculationService::class)->calculate(
            likelihood: 5,
            impact: 5,
            controlEffectiveness: 'Fully Achieved',
            methodology: $this->methodology(),
        );

        $this->assertSame(25, $result->inherentScore);
        $this->assertSame(0.0, round((float) $result->residualScore, 2));
        $this->assertSame('VERY LOW', strtoupper(str_replace('_', ' ', (string) $result->residualLevel)));
    }

    /**
     * Every value the bank can pick in their own workbook must import.
     *
     * THE DEFECT THIS PINS WAS NOT COSMETIC. The workbook's category list
     * suffixes everything with " Risk" — "Operational Risk" where this module
     * stores "Operational" — and until the file was opened in P9, twelve of its
     * thirteen categories were REJECTED by the importer. A bank completing the
     * `SB_RCSA Template 2026` they already use and uploading it failed
     * validation on every row: the one document the module exists to be
     * compatible with was the one document it would not accept.
     *
     * The workbook also contradicts itself twice, and both spellings have to
     * work because a user will meet either: the Risk Matrix sheet says
     * "Moderate" and "Very high" where the formula says "Medium" and "Very
     * High", and the Control effectiveness grid spells the ratings lower-case
     * where the formula capitalises them.
     */
    #[Test]
    public function every_value_the_workbook_offers_is_accepted_by_the_importer(): void
    {
        $normaliser = app(\App\Services\Rcsa\RcsaImportNormaliser::class);
        $methodology = $this->methodology()->loadMissing('scaleItems');

        $labels = fn (string $type) => array_values(array_map(
            fn ($item) => $item->label,
            $methodology->scale($type),
        ));

        $vocabularies = [
            'likelihood, as the L formula spells it' => [
                array_keys(self::LIKELIHOOD),
                $labels(\App\Models\Rcsa\RcsaScaleItem::TYPE_LIKELIHOOD),
            ],
            'likelihood, as the Risk Matrix sheet spells it' => [
                ['Almost Certain', 'Likely', 'Possible', 'Unlikely', 'Rare'],
                $labels(\App\Models\Rcsa\RcsaScaleItem::TYPE_LIKELIHOOD),
            ],
            'impact, as the K formula spells it' => [
                array_keys(self::IMPACT),
                $labels(\App\Models\Rcsa\RcsaScaleItem::TYPE_IMPACT),
            ],
            'impact, as the Risk Matrix sheet spells it' => [
                ['Very Low', 'Low', 'Moderate', 'High', 'Very high'],
                $labels(\App\Models\Rcsa\RcsaScaleItem::TYPE_IMPACT),
            ],
            'control effectiveness, as the P formula spells it' => [
                array_keys(self::MODIFIER),
                $labels(\App\Models\Rcsa\RcsaScaleItem::TYPE_CONTROL_EFFECTIVENESS),
            ],
            'control effectiveness, as the grid sheet spells it' => [
                ['Fully achieved', 'Mostly achieved', 'Partially achieved', 'Not achieved'],
                $labels(\App\Models\Rcsa\RcsaScaleItem::TYPE_CONTROL_EFFECTIVENESS),
            ],
            'risk categories, from Sheet1' => [
                [
                    'Strategic Risk', 'Operational Risk', 'Compliance / Regulatory Risk',
                    // Two spaces, exactly as the workbook has it.
                    'Financial  Risk', 'Credit Risk', 'Market Risk', 'Liquidity Risk',
                    'IT / Cybersecurity Risk', 'Third-Party / Outsourcing Risk',
                    'Reputational Risk', 'Model Risk', 'Legal Risk', 'Others',
                ],
                \App\Support\Rcsa\RcsaMethodologyTemplate::RISK_CATEGORIES,
            ],
        ];

        $rejected = [];

        foreach ($vocabularies as $where => [$values, $vocabulary]) {
            foreach ($values as $value) {
                if ($normaliser->canonical($value, $vocabulary) === null) {
                    $rejected[] = sprintf('%s: "%s"', $where, $value);
                }
            }
        }

        $this->assertSame(
            [],
            $rejected,
            "The bank's own workbook contains values this module refuses to import:\n".implode("\n", $rejected),
        );
    }

    /**
     * The band boundaries, at the exact values the nested IFs turn over.
     *
     * Off-by-one here is the most likely way the engine could drift from the
     * workbook while every ordinary case still agreed.
     */
    #[Test]
    public function the_band_boundaries_turn_over_where_the_workbook_says(): void
    {
        $methodology = $this->methodology();
        $calculator = app(RcsaCalculationService::class);

        // score => the band the workbook's IF chain gives it.
        $boundaries = [
            [1, 1, 'VERY LOW'],   // 1  <= 2
            [1, 2, 'VERY LOW'],   // 2  <= 2
            [1, 3, 'LOW'],        // 3  <= 4
            [2, 2, 'LOW'],        // 4  <= 4
            [1, 5, 'MEDIUM'],     // 5  <= 9
            [3, 3, 'MEDIUM'],     // 9  <= 9
            [2, 5, 'HIGH'],       // 10 <= 16
            [4, 4, 'HIGH'],       // 16 <= 16
            [5, 4, 'VERY HIGH'],  // 20 >  16
            [5, 5, 'VERY HIGH'],  // 25 >  16
        ];

        foreach ($boundaries as [$likelihood, $impact, $expected]) {
            $result = $calculator->calculate(
                likelihood: $likelihood,
                impact: $impact,
                controlEffectiveness: 'Not Achieved',
                methodology: $methodology,
            );

            $this->assertSame(
                $expected,
                strtoupper(str_replace('_', ' ', (string) $result->inherentLevel)),
                sprintf('%d × %d = %d should band as %s.', $likelihood, $impact, $likelihood * $impact, $expected),
            );
        }
    }
}
