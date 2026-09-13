<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\Risk;
use Illuminate\Support\Collection;

/**
 * §13 step 5: "Run one full RCSA cycle in both modules with a pilot business
 * unit. Compare outputs line by line."
 *
 * THE ONLY STEP OF §13 NO COMMAND CAN COMPLETE. A parallel run needs a real
 * unit to assess the same quarter twice, which is people and time. What a
 * command CAN do is the comparison at the end of it — and doing that by eye
 * across two hundred rows in a spreadsheet is how a discrepancy gets signed off
 * as a rounding difference.
 *
 * WHAT IS COMPARED, AND WHAT IT MEANS. The legacy module has no assessment of
 * its own: its figures are the risk register's own `inherent_*` and
 * `residual_*` columns, maintained by whoever last edited the risk. The v2
 * figures are an assessment — a named person's answers on a date, scored by the
 * methodology the cycle pinned. So this is not two implementations of one
 * calculation; it is two different things that a bank has been treating as the
 * same number, and the point of the comparison is to show where they part.
 *
 * A DIFFERENCE IS THEREFORE NOT AUTOMATICALLY A DEFECT. It is one of three
 * things, and the report says which it thinks each is:
 *
 *   - the register was stale and the assessment is the newer truth;
 *   - the assessor answered differently from whoever set the register;
 *   - or the engine disagrees with itself, which IS a defect.
 *
 * The third is what a parallel run is looking for, and it is detectable: if the
 * inherent inputs match and the inherent SCORE does not, no human judgement
 * explains it. That case is flagged `engine` and is the only one that should
 * ever block a sign-off.
 */
class RcsaParallelRunComparer
{
    /** Inherent inputs agree but the derived score does not — a real defect. */
    public const ENGINE = 'engine';

    /** The assessor answered differently from the register. Expected. */
    public const JUDGEMENT = 'judgement';

    /** The register holds no figure to compare against. */
    public const NO_BASELINE = 'no_baseline';

    public const MATCH = 'match';

    /**
     * Compare one v2 assessment against the register rows behind it.
     *
     * @return array<string, mixed>
     */
    public function compare(RcsaAssessment $assessment): array
    {
        $assessment->loadMissing(['cycle:id,name,period_start,period_end', 'businessUnit:id,name']);

        $lines = $assessment->lines()->with('registerRisk:id,legacy_risk_id,potential_risk')->get();

        $legacy = $this->legacyRisksFor($lines);

        $rows = $lines
            ->map(fn (RcsaAssessmentLine $line) => $this->compareLine($line, $legacy))
            ->values();

        return [
            'assessment_id' => $assessment->id,
            'business_unit' => $assessment->getRelationValue('businessUnit')?->name,
            'cycle' => $assessment->getRelationValue('cycle')?->name,
            'compared_at' => now()->toDateTimeString(),
            'summary' => $this->summarise($rows),
            'rows' => $rows->all(),
            'verdict' => $this->verdict($rows),
        ];
    }

    /**
     * The register rows the assessment's lines trace back to, keyed by line id.
     *
     * Traced through `rcsa_register_risks.legacy_risk_id`, which P8 wrote. A
     * line whose universe row was typed in rather than migrated has no legacy
     * counterpart and no baseline to compare against — that is reported, not
     * treated as a mismatch.
     *
     * @param  Collection<int, RcsaAssessmentLine>  $lines
     * @return array<int, Risk>
     */
    private function legacyRisksFor(Collection $lines): array
    {
        $registerIds = $lines->pluck('register_risk_id')->filter()->unique();

        $legacyIdByRegisterId = RcsaRegisterRisk::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $registerIds)
            ->whereNotNull('legacy_risk_id')
            ->pluck('legacy_risk_id', 'id');

        $risks = Risk::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $legacyIdByRegisterId->values())
            ->get()
            ->keyBy('id');

        $byLine = [];

        foreach ($lines as $line) {
            $legacyId = $legacyIdByRegisterId[$line->register_risk_id] ?? null;

            if ($legacyId !== null && $risks->has($legacyId)) {
                $byLine[$line->id] = $risks->get($legacyId);
            }
        }

        return $byLine;
    }

    /**
     * @param  array<int, Risk>  $legacy
     * @return array<string, mixed>
     */
    private function compareLine(RcsaAssessmentLine $line, array $legacy): array
    {
        $risk = $legacy[$line->id] ?? null;

        $row = [
            'line_id' => $line->id,
            'risk_no' => $line->risk_no,
            'potential_risk' => \Illuminate\Support\Str::limit((string) $line->potential_risk, 80),
            'v2' => [
                'likelihood' => $line->inherent_likelihood,
                'impact' => $line->inherent_impact,
                'inherent_score' => $line->inherent_score,
                'control_effectiveness' => $line->control_effectiveness,
                'residual_score' => $line->residual_score === null ? null : (float) $line->residual_score,
                'residual_level' => $line->residual_level,
            ],
        ];

        if ($risk === null) {
            return $row + [
                'legacy' => null,
                'classification' => self::NO_BASELINE,
                'note' => 'This risk was created in the v2 universe, so the register holds nothing to compare it with.',
            ];
        }

        $row['legacy'] = [
            'likelihood' => $risk->inherent_likelihood,
            'impact' => $risk->inherent_impact,
            'inherent_score' => $risk->inherent_score,
            'residual_score' => $risk->residual_score === null ? null : (float) $risk->residual_score,
            'residual_level' => $this->normalise((string) $risk->residual_rating),
        ];

        return $row + $this->classify($row['v2'], $row['legacy']);
    }

    /**
     * Decide what a difference means.
     *
     * THE ENGINE CASE IS THE ONLY ONE THAT MATTERS. If both sides start from
     * the same likelihood and impact and reach a different inherent score, no
     * assessor judgement explains it — one of the two is computing wrongly, and
     * that is what a parallel run exists to catch. Everything else is two
     * people answering the same question differently, which is exactly what a
     * self-assessment is for.
     *
     * @param  array<string, mixed>  $v2
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function classify(array $v2, array $legacy): array
    {
        $sameInputs = $v2['likelihood'] !== null
            && $v2['likelihood'] === $legacy['likelihood']
            && $v2['impact'] === $legacy['impact'];

        if ($sameInputs && $v2['inherent_score'] !== null && $legacy['inherent_score'] !== null
            && (int) $v2['inherent_score'] !== (int) $legacy['inherent_score']) {
            return [
                'classification' => self::ENGINE,
                'note' => sprintf(
                    'Both sides scored %d × %d, and reached %d and %d. No human judgement explains that — '
                    .'one of the two engines is wrong.',
                    (int) $v2['likelihood'],
                    (int) $v2['impact'],
                    (int) $v2['inherent_score'],
                    (int) $legacy['inherent_score'],
                ),
            ];
        }

        $differs = $v2['likelihood'] !== $legacy['likelihood']
            || $v2['impact'] !== $legacy['impact']
            || $this->normalise((string) $v2['residual_level']) !== $legacy['residual_level'];

        if (! $differs) {
            return ['classification' => self::MATCH, 'note' => null];
        }

        return [
            'classification' => self::JUDGEMENT,
            'note' => 'The assessor answered differently from whoever last set the register. Expected in a '
                .'parallel run — the assessment is a person\'s answer on a date, the register is a standing '
                .'figure. Worth a spot-check, not a blocker.',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summarise(Collection $rows): array
    {
        return [
            'lines' => $rows->count(),
            'match' => $rows->where('classification', self::MATCH)->count(),
            'judgement' => $rows->where('classification', self::JUDGEMENT)->count(),
            'engine' => $rows->where('classification', self::ENGINE)->count(),
            'no_baseline' => $rows->where('classification', self::NO_BASELINE)->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function verdict(Collection $rows): array
    {
        $engine = $rows->where('classification', self::ENGINE);

        return [
            'signable' => $engine->isEmpty(),
            'blockers' => $engine
                ->map(fn (array $r) => sprintf('%s: %s', $r['risk_no'], $r['note']))
                ->values()
                ->all(),
            'note' => $engine->isEmpty()
                ? 'No calculation disagreement. Remaining differences are assessor judgement against a standing '
                    .'register figure, which is what a self-assessment is supposed to produce.'
                : 'Do not sign off. The rows below start from identical inputs and reach different scores.',
        ];
    }

    /**
     * `Very High`, `very_high` and `VERY HIGH` are one level.
     */
    private function normalise(?string $level): ?string
    {
        return blank($level) ? null : str_replace(' ', '_', mb_strtolower(trim($level)));
    }
}
