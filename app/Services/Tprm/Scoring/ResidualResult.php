<?php

namespace App\Services\Tprm\Scoring;

use App\Enums\Tprm\RiskBand;

/**
 * The residual score and everything needed to explain it — AC-15.
 *
 * The raw floats are kept at full precision and rounded only by `toArray()`,
 * so that a caller which needs to reproduce the arithmetic exactly can, and a
 * caller which needs to display it gets a sensible number.
 */
class ResidualResult
{
    /**
     * @param  list<array<string, mixed>>  $findingContributions
     * @param  list<array<string, mixed>>  $signalContributions
     */
    public function __construct(
        public readonly float $ir,
        public readonly float $ac,
        public readonly float $ec,
        public readonly float $m,
        public readonly float $fu,
        public readonly float $su,
        public readonly float $rr,
        public readonly RiskBand $band,
        public readonly bool $sanctionsOverride,
        public readonly array $findingContributions,
        public readonly array $signalContributions,
        public readonly float $kmax,
    ) {}

    /**
     * The score as it is displayed and stored: one decimal.
     *
     * `tp_score_runs.rr` is `decimal(5,2)`, so the stored value keeps two —
     * the display rounds to one, and both are derived from the same full
     * precision figure rather than from each other.
     */
    public function displayScore(): float
    {
        return round($this->rr, 1);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ir' => round($this->ir, 2),
            'ac' => round($this->ac, 3),
            'ec' => round($this->ec, 3),
            'm' => round($this->m, 3),
            'fu' => round($this->fu, 2),
            'su' => round($this->su, 2),
            'rr' => round($this->rr, 2),
            'display_score' => $this->displayScore(),
            'band' => $this->band->value,
            'band_label' => $this->band->label(),
            'sanctions_override' => $this->sanctionsOverride,
            'kmax' => $this->kmax,
        ];
    }

    /**
     * The arithmetic, written out.
     *
     * A reader who cannot reproduce the number from the panel will not believe
     * it, and "88 × (1 − 0.285) + 7 + 6 = 75.9" is a sentence anyone can check
     * with a calculator.
     */
    public function workingOut(): string
    {
        if ($this->sanctionsOverride) {
            // The reason travels with the number. A reader who sees 100
            // against a vendor with clean assurance and no findings will
            // otherwise assume the score is broken — and the arithmetic that
            // was overridden is shown beside it so they can see it was.
            return sprintf(
                'A confirmed sanctions match forces the residual score to %s. The arithmetic would otherwise '
                .'have produced %s; it is overridden rather than adjusted, because dealing with a sanctioned '
                .'entity is a criminal offence and no amount of assurance offsets it.',
                round($this->rr, 1),
                round(max(0, min(100, $this->ir * (1 - $this->m) + $this->fu + $this->su)), 1),
            );
        }

        return sprintf(
            '%s × (1 − %s) + %s + %s = %s',
            round($this->ir, 2),
            round($this->m, 3),
            round($this->fu, 2),
            round($this->su, 2),
            round($this->rr, 1),
        );
    }
}
