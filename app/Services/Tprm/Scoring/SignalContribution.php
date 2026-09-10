<?php

namespace App\Services\Tprm\Scoring;

/**
 * One monitoring signal, as the residual calculator sees it.
 *
 * `label` is what the explanation panel shows a reader. It is carried rather
 * than derived from the type, because "cyber rating dropped from A to B on 14
 * August" tells somebody what happened and "cyber_rating_band_drop" does not.
 */
class SignalContribution
{
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly ?int $id = null,
        public readonly ?string $observedAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
            'observed_at' => $this->observedAt,
        ];
    }
}
