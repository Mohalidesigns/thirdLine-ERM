<?php

namespace App\Services\Tprm\Scoring;

/**
 * The inherent risk model of TRD §7.2 — pure, and the analytic core of the
 * module.
 *
 *     IR = Σ (factor_score × factor_weight) / Σ factor_weight × 100
 *
 * Takes an array of Appendix A answers and a Ruleset. Touches no database, no
 * config and no container: hand it the same inputs in five years and it
 * returns the same number, which is what TRD §7.9 requires of anything a
 * supervisor is shown.
 *
 * FIVE FACTORS ARE A SINGLE LOOKUP. Two are not, and both multiply rather than
 * add:
 *
 *   DATA = classification × volume band. Multiplying keeps volume subordinate
 *          to classification, which is the correct relationship: a million
 *          rows of public data is not a risk, and an additive model would say
 *          it was.
 *
 *   CRIT = criticality × RTO band. Same shape, same reason: a Standard
 *          function with a two-hour RTO is a fast-recovering unimportant
 *          thing, not a critical one.
 *
 * AN UNRECOGNISED ANSWER IS NOT ZERO. `Ruleset::optionScore()` returns null for
 * an option the ruleset does not define, and this calculator records a warning
 * and scores that factor zero WITH THE WARNING ATTACHED, rather than silently
 * treating an unknown answer as "no risk". A tier that came out Low because
 * somebody sent `access: "administrator"` instead of `"privileged"` is the
 * kind of defect that is invisible until a supervisor asks how a core-banking
 * vendor got tiered Low.
 */
class InherentRiskCalculator
{
    /**
     * Appendix A answer keys this model reads, and which factor each drives.
     */
    public const ANSWER_FACTORS = [
        'A1' => 'DATA',    // highest data classification
        'A2' => 'DATA',    // data subject volume band
        'A3' => 'GEO',     // cross-border transfer and basis
        'A5' => 'ACCESS',  // level of system access
        'A7' => 'CRIT',    // supported functions -> highest criticality
        'A8' => 'CRIT',    // longest tolerable outage
        'A10' => 'REG',    // applicable regulatory regimes
        'A12' => 'SUB',    // substitutability
        'A13' => 'SUB',    // time to transition
        'A14' => 'FIN',    // annual spend band
    ];

    /**
     * Appendix A questions that are captured but do not enter the arithmetic,
     * with the reason. Surfaced on the derivation so a user can see that the
     * question was asked, was recorded, and why it did not move the number.
     */
    public const UNSCORED_ANSWERS = [
        'A4' => 'Country of storage and processing — feeds the GEO supervisory-access adjustment and the register, not a score of its own.',
        'A6' => 'Connectivity type — recorded on the engagement and used to raise connection records; TRD §7.2 scores ACCESS from A5 alone.',
        'A9' => 'Customer visibility of failure — captured for the impact narrative; TRD §7.2 defines CRIT as criticality × RTO only.',
        'A11' => 'Regulated activity on our behalf — drives the KO-REGACT knockout rather than the weighted score.',
        'A15' => 'Sub-contracting — creates nth-party disclosure expectations; no weighted contribution.',
        'A16' => 'Physical access — recorded so an access grant can require an escort; TRD §7.2 scores ACCESS from A5 alone.',
        'A17' => 'Cardholder data — drives the KO-CHD knockout rather than the weighted score.',
        'A18' => 'Intra-group — a governance flag affecting the approval chain, not the score.',
    ];

    /**
     * @param  array<string, mixed>  $answers  Appendix A answers, keyed A1…A18
     * @param  array<string, mixed>  $context  derived facts (supervisory access, criticality, RTO)
     */
    public function calculate(array $answers, Ruleset $ruleset, array $context = []): InherentRiskResult
    {
        $warnings = [];
        $factors = [];

        $factors[] = $this->data($answers, $ruleset, $warnings);
        $factors[] = $this->access($answers, $ruleset, $warnings);
        $factors[] = $this->criticality($answers, $ruleset, $context, $warnings);
        $factors[] = $this->regulatory($answers, $ruleset);
        $factors[] = $this->substitutability($answers, $ruleset, $warnings);
        $factors[] = $this->geography($answers, $ruleset, $context, $warnings);
        $factors[] = $this->financial($answers, $ruleset, $warnings);

        $totalWeight = $ruleset->totalWeight();

        // A ruleset whose weights total zero cannot produce a mean. Zero with
        // a warning, never a division by zero and never a silent 100.
        if ($totalWeight <= 0.0) {
            $warnings[] = 'The ruleset has no factor weight, so no inherent score can be computed.';
            $score = 0.0;
        } else {
            $numerator = array_sum(array_map(
                fn (FactorScore $factor) => $factor->weightedContribution(),
                $factors
            ));

            $score = ($numerator / $totalWeight) * 100;
        }

        $score = max(0.0, min(100.0, $score));

        return new InherentRiskResult(
            score: $score,
            tier: $ruleset->tierForScore($score),
            factors: $factors,
            totalWeight: $totalWeight,
            rulesetVersion: $ruleset->version,
            unscoredAnswers: $this->collectUnscored($answers),
            warnings: $warnings,
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * DATA = classification score × volume band multiplier.
     *
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $warnings
     */
    private function data(array $answers, Ruleset $ruleset, array &$warnings): FactorScore
    {
        $classification = $this->stringAnswer($answers, 'A1');
        $classificationScore = $ruleset->optionScore('DATA', $classification);

        if ($classificationScore === null) {
            $warnings[] = $this->unknownOption('DATA', 'A1', $classification);
            $classificationScore = 0.0;
        }

        $volume = $this->stringAnswer($answers, 'A2');
        $volumeFactor = $ruleset->volumeBands[$volume] ?? null;

        if ($volumeFactor === null) {
            // No volume answer is not the same as "no data subjects": a
            // service handling restricted data whose volume was never asked
            // must not score as though it handled none. The neutral 1.0 keeps
            // the classification intact and the warning says the input is
            // missing.
            if ($volume !== null) {
                $warnings[] = $this->unknownOption('DATA', 'A2', $volume);
            }
            $volumeFactor = 1.0;
        }

        return new FactorScore(
            code: 'DATA',
            label: $ruleset->factorLabel('DATA'),
            weight: $ruleset->factorWeight('DATA'),
            score: $classificationScore * $volumeFactor,
            selectedOption: $classification,
            selectedLabel: $this->optionLabel($ruleset, 'DATA', $classification),
            components: [
                'classification' => ['answer' => $classification, 'score' => $classificationScore],
                'volume' => ['answer' => $volume, 'multiplier' => $volumeFactor],
                'formula' => 'classification × volume',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $warnings
     */
    private function access(array $answers, Ruleset $ruleset, array &$warnings): FactorScore
    {
        $answer = $this->stringAnswer($answers, 'A5');
        $score = $ruleset->optionScore('ACCESS', $answer);

        if ($score === null) {
            $warnings[] = $this->unknownOption('ACCESS', 'A5', $answer);
            $score = 0.0;
        }

        return new FactorScore(
            code: 'ACCESS',
            label: $ruleset->factorLabel('ACCESS'),
            weight: $ruleset->factorWeight('ACCESS'),
            score: $score,
            selectedOption: $answer,
            selectedLabel: $this->optionLabel($ruleset, 'ACCESS', $answer),
        );
    }

    /**
     * CRIT = highest supported-function criticality × RTO band multiplier.
     *
     * The criticality comes from the engagement's linked functions rather than
     * from a free answer (FR-TIER-06: an engagement inherits the highest
     * criticality of any function it supports, and the derivation is shown).
     * A7 is the function multi-select; `context` carries what that resolved to.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $context
     * @param  list<string>  $warnings
     */
    private function criticality(array $answers, Ruleset $ruleset, array $context, array &$warnings): FactorScore
    {
        $criticality = $this->stringValue($context['max_function_criticality'] ?? null)
            ?? $this->stringAnswer($answers, 'A7');

        $criticalityScore = $ruleset->optionScore('CRIT', $criticality);

        if ($criticalityScore === null) {
            $warnings[] = $this->unknownOption('CRIT', 'A7', $criticality);
            $criticalityScore = 0.0;
        }

        $rtoBand = $this->stringAnswer($answers, 'A8');
        $rtoMultiplier = $ruleset->rtoBands[$rtoBand] ?? null;

        if ($rtoMultiplier === null) {
            if ($rtoBand !== null) {
                $warnings[] = $this->unknownOption('CRIT', 'A8', $rtoBand);
            }
            // Neutral, for the same reason as the volume band: an unanswered
            // outage tolerance must not discount a critical function.
            $rtoMultiplier = 1.0;
        }

        return new FactorScore(
            code: 'CRIT',
            label: $ruleset->factorLabel('CRIT'),
            weight: $ruleset->factorWeight('CRIT'),
            score: $criticalityScore * $rtoMultiplier,
            selectedOption: $criticality,
            selectedLabel: $this->optionLabel($ruleset, 'CRIT', $criticality),
            components: [
                'criticality' => ['answer' => $criticality, 'score' => $criticalityScore],
                'rto' => ['answer' => $rtoBand, 'multiplier' => $rtoMultiplier],
                'formula' => 'highest supported-function criticality × RTO band',
            ],
            note: isset($context['max_function_criticality'])
                ? 'Inherited from the highest-criticality business function this engagement supports.'
                : null,
        );
    }

    /**
     * REG = Σ(severity of each applicable regime) / Σ(severity of all regimes).
     *
     * A proportion of the total possible exposure rather than a count, so that
     * "CBN cyber plus AML" outranks "consumer protection plus open banking
     * plus sector" — three regimes that together matter less than either of
     * the first two.
     *
     * @param  array<string, mixed>  $answers
     */
    private function regulatory(array $answers, Ruleset $ruleset): FactorScore
    {
        $selected = $this->arrayAnswer($answers, 'A10');

        $allOptions = $ruleset->factors['REG']['options'] ?? [];
        $maximum = array_sum(array_map(fn (array $o) => (float) $o['score'], $allOptions));

        $applied = [];
        $total = 0.0;

        foreach ($selected as $regime) {
            $severity = $ruleset->optionScore('REG', (string) $regime);

            // An unrecognised regime is skipped rather than warned about: this
            // is a tenant-extensible list and a client naming a regime we do
            // not ship should not produce a warning on every recomputation.
            if ($severity === null) {
                continue;
            }

            $applied[(string) $regime] = $severity;
            $total += $severity;
        }

        $score = $maximum > 0 ? min(1.0, $total / $maximum) : 0.0;

        return new FactorScore(
            code: 'REG',
            label: $ruleset->factorLabel('REG'),
            weight: $ruleset->factorWeight('REG'),
            score: $score,
            selectedOption: null,
            selectedLabel: $applied === [] ? 'None' : implode(', ', array_keys($applied)),
            components: [
                'applied' => $applied,
                'severity_total' => round($total, 4),
                'severity_maximum' => round($maximum, 4),
                'formula' => 'Σ severity of applicable regimes ÷ Σ severity of all regimes',
            ],
        );
    }

    /**
     * SUB, with the §7.2 override: "sole source OR more than six months to
     * replace" scores 1.0. A vendor with three named alternatives that would
     * take nine months to move to is a sole source in every sense that matters
     * during an incident.
     *
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $warnings
     */
    private function substitutability(array $answers, Ruleset $ruleset, array &$warnings): FactorScore
    {
        $answer = $this->stringAnswer($answers, 'A12');
        $score = $ruleset->optionScore('SUB', $answer);

        if ($score === null) {
            $warnings[] = $this->unknownOption('SUB', 'A12', $answer);
            $score = 0.0;
        }

        $transition = $this->stringAnswer($answers, 'A13');
        $note = null;

        if ($transition === 'over_6m') {
            $soleScore = $ruleset->optionScore('SUB', 'sole') ?? 1.0;

            if ($soleScore > $score) {
                $score = $soleScore;
                $note = 'Raised to sole-source: TRD §7.2 scores a transition of more than six months as sole source '
                    .'regardless of how many alternatives exist.';
            }
        }

        return new FactorScore(
            code: 'SUB',
            label: $ruleset->factorLabel('SUB'),
            weight: $ruleset->factorWeight('SUB'),
            score: $score,
            selectedOption: $answer,
            selectedLabel: $this->optionLabel($ruleset, 'SUB', $answer),
            components: ['time_to_replace' => $transition],
            note: $note,
        );
    }

    /**
     * GEO, plus the §7.2 adjustment: +0.2 where the jurisdiction impedes
     * supervisory access, capped at 1.0.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $context
     * @param  list<string>  $warnings
     */
    private function geography(array $answers, Ruleset $ruleset, array $context, array &$warnings): FactorScore
    {
        $answer = $this->stringAnswer($answers, 'A3');
        $score = $ruleset->optionScore('GEO', $answer);

        if ($score === null) {
            $warnings[] = $this->unknownOption('GEO', 'A3', $answer);
            $score = 0.0;
        }

        $base = $score;
        $impeded = (bool) ($context['supervisory_access_impeded'] ?? false);
        $note = null;

        if ($impeded) {
            $score = min(1.0, $score + 0.2);
            $note = 'A jurisdiction recorded as impeding supervisory access adds 0.2, capped at 1.0 (TRD §7.2).';
        }

        return new FactorScore(
            code: 'GEO',
            label: $ruleset->factorLabel('GEO'),
            weight: $ruleset->factorWeight('GEO'),
            score: $score,
            selectedOption: $answer,
            selectedLabel: $this->optionLabel($ruleset, 'GEO', $answer),
            components: [
                'base' => $base,
                'supervisory_access_impeded' => $impeded,
                'country' => $context['processing_country'] ?? null,
            ],
            note: $note,
        );
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $warnings
     */
    private function financial(array $answers, Ruleset $ruleset, array &$warnings): FactorScore
    {
        $answer = $this->stringAnswer($answers, 'A14');
        $score = $ruleset->optionScore('FIN', $answer);

        if ($score === null) {
            $warnings[] = $this->unknownOption('FIN', 'A14', $answer);
            $score = 0.0;
        }

        return new FactorScore(
            code: 'FIN',
            label: $ruleset->factorLabel('FIN'),
            weight: $ruleset->factorWeight('FIN'),
            score: $score,
            selectedOption: $answer,
            selectedLabel: $this->optionLabel($ruleset, 'FIN', $answer),
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, array{answer: mixed, reason: string}>
     */
    private function collectUnscored(array $answers): array
    {
        $unscored = [];

        foreach (self::UNSCORED_ANSWERS as $key => $reason) {
            if (array_key_exists($key, $answers)) {
                $unscored[$key] = ['answer' => $answers[$key], 'reason' => $reason];
            }
        }

        return $unscored;
    }

    private function unknownOption(string $factor, string $question, ?string $value): string
    {
        return $value === null
            ? "{$question} was not answered, so factor {$factor} scored zero."
            : "{$question} answered `{$value}`, which factor {$factor} does not define. Scored zero.";
    }

    /** @param  array<string, mixed>  $answers */
    private function stringAnswer(array $answers, string $key): ?string
    {
        return $this->stringValue($answers[$key] ?? null);
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return list<mixed>
     */
    private function arrayAnswer(array $answers, string $key): array
    {
        // `?? []` has already turned a missing OR null answer into an empty
        // array, so only the empty-string case remains to be normalised.
        $value = $answers[$key] ?? [];

        if (is_array($value)) {
            return array_values($value);
        }

        return $value === '' ? [] : [$value];
    }

    private function optionLabel(Ruleset $ruleset, string $factor, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach ($ruleset->factors[$factor]['options'] ?? [] as $option) {
            if (($option['value'] ?? null) === $value) {
                return (string) $option['label'];
            }
        }

        return null;
    }
}
