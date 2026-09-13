<?php

namespace App\Services\Tprm\Scoring;

/**
 * One factor's contribution, with the arithmetic shown.
 *
 * `components` holds the intermediate steps for the two factors that are not a
 * single lookup — DATA is classification × volume, CRIT is criticality × RTO —
 * so the panel can render "Restricted (1.00) × >1m records (1.00) = 1.00"
 * rather than an unexplained 1.00.
 */
class FactorScore
{
    /**
     * @param  array<string, mixed>  $components
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly float $weight,
        public readonly float $score,
        public readonly ?string $selectedOption,
        public readonly ?string $selectedLabel,
        public readonly array $components = [],
        public readonly ?string $note = null,
    ) {}

    /** The factor's contribution to the weighted numerator. */
    public function weightedContribution(): float
    {
        return $this->score * $this->weight;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'weight' => $this->weight,
            'score' => round($this->score, 4),
            'weighted' => round($this->weightedContribution(), 4),
            'selected_option' => $this->selectedOption,
            'selected_label' => $this->selectedLabel,
            'components' => $this->components,
            'note' => $this->note,
        ];
    }
}
