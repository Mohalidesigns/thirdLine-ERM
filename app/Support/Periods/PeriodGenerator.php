<?php

namespace App\Support\Periods;

use Carbon\CarbonImmutable;

/**
 * Turns a fiscal-year start month into the year, halves, quarters and months
 * that make up that fiscal year, with the parent links already worked out.
 *
 * Pure — it touches no database. The seeding migration and PeriodService both
 * call it, so a period generated at install time and one generated three years
 * later when the calendar rolls forward have identical codes and boundaries.
 *
 * FISCAL YEAR LABELLING. A fiscal year is labelled by the calendar year it
 * STARTS in. For a January start that is uncontroversial. For an April 2026
 * start, this is "FY2026" and it ends in March 2027 — the convention CBN
 * returns use for institutions on a non-calendar year, and the one that keeps
 * FY2026-M01 meaning "the first month of FY2026" rather than "January".
 */
class PeriodGenerator
{
    /**
     * Every period making up one fiscal year, parents before children.
     *
     * @return list<array{code:string,type:string,name:string,start_date:string,end_date:string,parent_code:?string}>
     */
    public static function forFiscalYear(int $fiscalYear, int $startMonth = 1): array
    {
        $startMonth = max(1, min(12, $startMonth));
        $start = CarbonImmutable::create($fiscalYear, $startMonth, 1)->startOfDay();

        $yearCode = 'FY'.$fiscalYear;

        $periods = [[
            'code' => $yearCode,
            'type' => 'year',
            'name' => self::yearName($fiscalYear, $startMonth),
            'start_date' => $start->toDateString(),
            'end_date' => $start->addYear()->subDay()->toDateString(),
            'parent_code' => null,
        ]];

        foreach ([1, 2] as $half) {
            $halfStart = $start->addMonths(($half - 1) * 6);
            $periods[] = [
                'code' => $yearCode.'-H'.$half,
                'type' => 'half',
                'name' => 'H'.$half.' '.self::yearName($fiscalYear, $startMonth),
                'start_date' => $halfStart->toDateString(),
                'end_date' => $halfStart->addMonths(6)->subDay()->toDateString(),
                'parent_code' => $yearCode,
            ];
        }

        foreach (range(1, 4) as $quarter) {
            $quarterStart = $start->addMonths(($quarter - 1) * 3);
            $periods[] = [
                'code' => $yearCode.'-Q'.$quarter,
                'type' => 'quarter',
                'name' => 'Q'.$quarter.' '.self::yearName($fiscalYear, $startMonth),
                'start_date' => $quarterStart->toDateString(),
                'end_date' => $quarterStart->addMonths(3)->subDay()->toDateString(),
                'parent_code' => $yearCode.'-H'.($quarter <= 2 ? 1 : 2),
            ];
        }

        foreach (range(1, 12) as $ordinal) {
            $monthStart = $start->addMonths($ordinal - 1);
            $periods[] = [
                'code' => $yearCode.'-M'.str_pad((string) $ordinal, 2, '0', STR_PAD_LEFT),
                'type' => 'month',
                // The calendar month is what a user recognises on a selector;
                // the ordinal only matters to the code.
                'name' => $monthStart->format('M Y'),
                'start_date' => $monthStart->toDateString(),
                'end_date' => $monthStart->endOfMonth()->toDateString(),
                'parent_code' => $yearCode.'-Q'.(int) ceil($ordinal / 3),
            ];
        }

        return $periods;
    }

    /**
     * The fiscal year a given date falls in, for a calendar starting in
     * $startMonth.
     */
    public static function fiscalYearOf(CarbonImmutable $date, int $startMonth = 1): int
    {
        return $date->month >= $startMonth ? $date->year : $date->year - 1;
    }

    /**
     * "FY2026" for a January start; "FY2026/27" when the year straddles two.
     */
    public static function yearName(int $fiscalYear, int $startMonth): string
    {
        return $startMonth === 1
            ? 'FY'.$fiscalYear
            : 'FY'.$fiscalYear.'/'.substr((string) ($fiscalYear + 1), -2);
    }
}
