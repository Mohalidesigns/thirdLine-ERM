<?php

namespace App\Services\Tprm\Scoring;

use App\Enums\Tprm\RiskBand;

/**
 * TRD §7.5 — the residual risk score.
 *
 *   M  = AC × EC × Kmax
 *   FU = min(cap, Σ finding penalties)
 *   SU = min(cap, Σ signal penalties)     sanctions true match forces RR = 100
 *   RR = clamp(IR × (1 − M) + FU + SU, 0, 100)
 *
 * PURE. No database, no clock, no config lookups of its own — the coefficients
 * arrive as a `ResidualInputs` value object, because TRD §7.9 requires a score
 * to carry the engine version that produced it and a calculator reading config
 * directly could not be replayed against last year's settings.
 *
 * NOTHING IS ROUNDED UNTIL THE END. That is not fussiness, it is the
 * difference between the TRD's own worked example reproducing and not: with
 * `M` rounded to three decimals first, the second example yields 44.4, and at
 * full precision it yields the 44.3 the TRD states. Intermediate rounding
 * accumulates, and a score a client cannot reconcile against the specification
 * is a score they stop trusting.
 *
 * THE MITIGATION CEILING IS A POLICY POSITION. `Kmax` caps how much assurance
 * can reduce inherent risk — at 0.60 by default, a perfectly assured Critical
 * vendor still carries 40% of its inherent score. That is deliberate: no
 * amount of paperwork makes a vendor holding your core banking data a low
 * risk, and a model that let it would be a model that produced a comfortable
 * answer on request.
 */
class ResidualRiskCalculator
{
    public function calculate(ResidualInputs $inputs): ResidualResult
    {
        $mitigation = $this->mitigation($inputs);
        $findings = $this->findingsUplift($inputs);
        $signals = $this->signalUplift($inputs);

        // AC-08: a confirmed sanctions match is not a penalty to be summed and
        // capped, it is an override. A vendor on a sanctions list is not
        // "high risk with mitigating factors"; dealing with it is a criminal
        // offence, and no amount of assurance changes that.
        if ($signals['sanctions_override']) {
            return new ResidualResult(
                ir: $inputs->inherentScore,
                ac: $inputs->ac,
                ec: $inputs->ec,
                m: $mitigation['m'],
                fu: $findings['total'],
                su: $signals['total'],
                rr: (float) $inputs->sanctionsForcesScore,
                band: RiskBand::Critical,
                sanctionsOverride: true,
                findingContributions: $findings['contributions'],
                signalContributions: $signals['contributions'],
                kmax: $mitigation['kmax'],
            );
        }

        $raw = $inputs->inherentScore * (1 - $mitigation['m']) + $findings['total'] + $signals['total'];
        $rr = max(0.0, min(100.0, $raw));

        return new ResidualResult(
            ir: $inputs->inherentScore,
            ac: $inputs->ac,
            ec: $inputs->ec,
            m: $mitigation['m'],
            fu: $findings['total'],
            su: $signals['total'],
            rr: $rr,
            band: $this->band($rr, $inputs->bands),
            sanctionsOverride: false,
            findingContributions: $findings['contributions'],
            signalContributions: $signals['contributions'],
            kmax: $mitigation['kmax'],
        );
    }

    /**
     * M = AC × EC × Kmax, with Kmax held under its hard ceiling.
     *
     * The ceiling is applied here rather than trusted from the input, because
     * a tenant ruleset is editable and the 0.75 limit is a product position
     * rather than a default. A tenant that could raise it to 1.0 could score
     * every vendor as fully mitigated.
     *
     * @return array{m: float, kmax: float}
     */
    private function mitigation(ResidualInputs $inputs): array
    {
        $kmax = min($inputs->kmax, $inputs->kmaxCeiling);

        return ['m' => $inputs->ac * $inputs->ec * $kmax, 'kmax' => $kmax];
    }

    /**
     * FU — the findings uplift.
     *
     * THE THREE MULTIPLIERS DO NOT COMPOUND, and the order of the checks is
     * the rule. A finding is either risk-accepted, or overdue, or inside its
     * SLA with an accepted plan, or none of those and it counts at face value.
     * Multiplying an overdue risk-accepted finding by 0.5 × 1.5 would produce
     * a number nobody could explain from the specification.
     *
     * A RISK-ACCEPTED FINDING STILL COUNTS. At half, not zero — accepting a
     * risk is a decision about whether to remediate it, not a statement that
     * it stopped existing, and a score that dropped to zero on acceptance
     * would make acceptance the cheapest way to improve a rating.
     *
     * @return array{total: float, contributions: list<array<string, mixed>>}
     */
    private function findingsUplift(ResidualInputs $inputs): array
    {
        $total = 0.0;
        $contributions = [];

        foreach ($inputs->findings as $finding) {
            $base = (float) ($inputs->severityPenalties[$finding->severity] ?? 0);

            [$multiplier, $reason] = match (true) {
                $finding->riskAccepted => [
                    $inputs->riskAcceptedWeight,
                    'Risk accepted — counted at '.$inputs->riskAcceptedWeight
                        .', because accepting a risk decides whether to remediate it, not whether it exists.',
                ],
                $finding->overdueBeyondThreshold => [
                    $inputs->overdueMultiplier,
                    'Overdue beyond '.$inputs->overdueThresholdMultiple.'× its remediation SLA.',
                ],
                $finding->withinSlaWithAcceptedPlan => [
                    $inputs->withinSlaMultiplier,
                    'Inside its remediation SLA with an accepted plan.',
                ],
                default => [1.0, 'Open, at face value.'],
            };

            $penalty = $base * $multiplier;
            $total += $penalty;

            $contributions[] = [
                'reference' => $finding->reference,
                'title' => $finding->title,
                'severity' => $finding->severity,
                'base_penalty' => $base,
                'multiplier' => $multiplier,
                'penalty' => round($penalty, 2),
                'reason' => $reason,
            ];
        }

        $capped = min($total, (float) $inputs->findingsCap);

        return [
            'total' => $capped,
            // The cap is reported when it bites. A vendor with forty open
            // findings and one with four both showing FU = 20 is a fact a
            // reader has to be told, or the score looks like it stopped
            // responding.
            'contributions' => $total > $capped
                ? array_merge($contributions, [[
                    'reference' => null,
                    'title' => 'Findings uplift capped',
                    'severity' => null,
                    'base_penalty' => round($total, 2),
                    'multiplier' => null,
                    'penalty' => round($capped - $total, 2),
                    'reason' => 'The penalties totalled '.round($total, 2).' and the cap is '
                        .$inputs->findingsCap.'. Further findings do not raise this score.',
                ]])
                : $contributions,
        ];
    }

    /**
     * SU — the signal uplift.
     *
     * Expired mandatory evidence is the one signal type with its own sub-cap:
     * a vendor with nine lapsed certificates would otherwise exhaust the whole
     * signal budget on paperwork and leave no room for a confirmed breach.
     *
     * @return array{total: float, contributions: list<array<string, mixed>>, sanctions_override: bool}
     */
    private function signalUplift(ResidualInputs $inputs): array
    {
        $total = 0.0;
        $evidenceTotal = 0.0;
        $contributions = [];
        $override = false;

        foreach ($inputs->signals as $signal) {
            if ($signal->type === 'sanctions_true_match') {
                $override = true;

                $contributions[] = [
                    'type' => $signal->type,
                    'label' => $signal->label,
                    'penalty' => null,
                    'reason' => 'A confirmed sanctions match forces the residual score to '
                        .$inputs->sanctionsForcesScore.' outright. It is not a penalty to be offset by '
                        .'assurance: dealing with a sanctioned entity is a criminal offence.',
                ];

                continue;
            }

            $penalty = (float) ($inputs->signalPenalties[$signal->type] ?? 0);

            if ($penalty === 0.0) {
                continue;
            }

            $reason = $signal->label;

            if ($signal->type === 'expired_mandatory_evidence') {
                $remaining = max(0.0, (float) $inputs->expiredEvidenceCap - $evidenceTotal);
                $penalty = min($penalty, $remaining);
                $evidenceTotal += $penalty;

                if ($penalty === 0.0) {
                    continue;
                }

                $reason .= ' (expired evidence contributes at most '.$inputs->expiredEvidenceCap.' in total)';
            }

            $total += $penalty;

            $contributions[] = [
                'type' => $signal->type,
                'label' => $signal->label,
                'penalty' => round($penalty, 2),
                'reason' => $reason,
            ];
        }

        return [
            'total' => min($total, (float) $inputs->signalsCap),
            'contributions' => $contributions,
            'sanctions_override' => $override,
        ];
    }

    /**
     * The band, from the ROUNDED score.
     *
     * The bands are closed intervals on an integer, so a score of 74.6 is
     * High and 74.5 rounds to 75 and is Critical. Banding the unrounded value
     * would put a score displayed as "75" in the High band, and a board pack
     * that shows a number in one band and a colour from another is the kind of
     * detail that costs a client's confidence in everything else on the page.
     *
     * @param  array<string, array{0: int, 1: int}>  $bands
     */
    private function band(float $rr, array $bands): RiskBand
    {
        $rounded = (int) round($rr);

        foreach ($bands as $band => [$low, $high]) {
            if ($rounded >= $low && $rounded <= $high) {
                return RiskBand::from($band);
            }
        }

        return $rounded >= 75 ? RiskBand::Critical : RiskBand::Low;
    }
}
