<?php

namespace App\Services\Tprm\Scoring;

/**
 * The confidence figure and the badge that goes beside every residual score.
 *
 * `mayCloseReview()` IS THE TEETH. TRD §7.6: "a residual score with DC < 0.5
 * may not be used to close a review or to support a board assertion; the UI
 * says so." Without that, the badge is decoration — a number people learn to
 * read past — and the module joins every other product that computes a
 * confident score from stale inputs and prints it in bold.
 */
class DataConfidenceResult
{
    /**
     * @param  array<string, array<string, mixed>>  $components
     */
    public function __construct(
        public readonly float $dc,
        public readonly array $components,
        public readonly float $floor = 0.2,
    ) {}

    /**
     * Current / Ageing / Stale.
     *
     * Three words rather than a percentage, because a badge reading "0.63" on
     * a board pack invites a conversation about the number and a badge reading
     * "Ageing" invites one about the vendor.
     */
    public function badge(): string
    {
        return match (true) {
            $this->dc >= 0.8 => 'Current',
            $this->dc >= 0.5 => 'Ageing',
            default => 'Stale',
        };
    }

    public function mayCloseReview(float $threshold = 0.5): bool
    {
        return $this->dc >= $threshold;
    }

    /**
     * Why it is not `Current`, in the order worth fixing.
     *
     * Weakest-weighted-contribution first: telling somebody "the assessment is
     * two years old" is actionable, and telling them "confidence is 0.41" is
     * not.
     *
     * @return list<string>
     */
    public function weaknesses(): array
    {
        $labels = [
            'assessment_age' => 'the questionnaire assessment',
            'evidence_age' => 'the supporting evidence',
            'screening_age' => 'sanctions and adverse-media screening',
            'monitoring_recency' => 'continuous monitoring',
        ];

        $rows = [];

        foreach ($this->components as $component => $detail) {
            if ($detail['state'] === 'current') {
                continue;
            }

            $label = $labels[$component] ?? str_replace('_', ' ', $component);

            $rows[] = [
                'shortfall' => $detail['weight'] * (1 - $detail['score']),
                'text' => match ($detail['state']) {
                    'never' => ucfirst($label).' has never been carried out.',
                    'stale' => ucfirst($label).' is '.$detail['age_days'].' days old, against a policy '
                        .'interval of '.$detail['interval_days'].' days — beyond the point where it counts '
                        .'for anything.',
                    default => ucfirst($label).' is '.$detail['age_days'].' days old, against a policy '
                        .'interval of '.$detail['interval_days'].' days.',
                },
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['shortfall'] <=> $a['shortfall']);

        return array_column($rows, 'text');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dc' => round($this->dc, 3),
            'badge' => $this->badge(),
            'may_close_review' => $this->mayCloseReview(),
            'components' => $this->components,
            'weaknesses' => $this->weaknesses(),
        ];
    }
}
