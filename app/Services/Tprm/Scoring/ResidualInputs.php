<?php

namespace App\Services\Tprm\Scoring;

/**
 * Everything `ResidualRiskCalculator` needs, and nothing it can look up for
 * itself.
 *
 * THE COEFFICIENTS TRAVEL WITH THE INPUTS rather than being read from config
 * inside the calculator, because TRD §7.9 requires a score to carry the engine
 * version that produced it — and a calculator that read today's config could
 * not be replayed against last year's settings to explain last year's number.
 * `fromConfig()` is the ordinary path; a replay passes its own values.
 */
class ResidualInputs
{
    /**
     * @param  list<FindingContribution>  $findings
     * @param  list<SignalContribution>  $signals
     * @param  array<string, float>  $severityPenalties
     * @param  array<string, float>  $signalPenalties
     * @param  array<string, array{0: int, 1: int}>  $bands
     */
    public function __construct(
        public readonly float $inherentScore,
        public readonly float $ac,
        public readonly float $ec,
        public readonly array $findings = [],
        public readonly array $signals = [],
        public readonly float $kmax = 0.60,
        public readonly float $kmaxCeiling = 0.75,
        public readonly array $severityPenalties = [],
        public readonly float $withinSlaMultiplier = 0.5,
        public readonly float $overdueMultiplier = 1.5,
        public readonly int $overdueThresholdMultiple = 2,
        public readonly float $riskAcceptedWeight = 0.5,
        public readonly int $findingsCap = 20,
        public readonly array $signalPenalties = [],
        public readonly int $signalsCap = 20,
        public readonly int $expiredEvidenceCap = 8,
        public readonly int $sanctionsForcesScore = 100,
        public readonly array $bands = [],
    ) {}

    /**
     * The ordinary path: today's configured coefficients.
     *
     * @param  list<FindingContribution>  $findings
     * @param  list<SignalContribution>  $signals
     */
    public static function fromConfig(
        float $inherentScore,
        float $ac,
        float $ec,
        array $findings = [],
        array $signals = [],
        ?float $kmax = null,
    ): self {
        /** @var array<string, mixed> $scoring */
        $scoring = config('tprm.scoring');

        return new self(
            inherentScore: $inherentScore,
            ac: $ac,
            ec: $ec,
            findings: $findings,
            signals: $signals,
            kmax: $kmax ?? (float) $scoring['kmax'],
            kmaxCeiling: (float) $scoring['kmax_ceiling'],
            severityPenalties: array_map('floatval', $scoring['findings_uplift']['severity']),
            withinSlaMultiplier: (float) $scoring['findings_uplift']['within_sla_multiplier'],
            overdueMultiplier: (float) $scoring['findings_uplift']['overdue_multiplier'],
            overdueThresholdMultiple: (int) $scoring['findings_uplift']['overdue_threshold_multiple'],
            riskAcceptedWeight: (float) $scoring['findings_uplift']['risk_accepted_weight'],
            findingsCap: (int) $scoring['findings_uplift']['cap'],
            signalPenalties: array_map('floatval', $scoring['signal_uplift']['penalty']),
            signalsCap: (int) $scoring['signal_uplift']['cap'],
            expiredEvidenceCap: (int) $scoring['signal_uplift']['expired_evidence_cap'],
            sanctionsForcesScore: (int) $scoring['signal_uplift']['sanctions_true_match_forces'],
            bands: $scoring['bands'],
        );
    }

    /**
     * The input snapshot stored on the score run — TRD §7.9's reproducibility
     * requirement. Everything here, replayed, produces the same number.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ir' => $this->inherentScore,
            'ac' => $this->ac,
            'ec' => $this->ec,
            'kmax' => $this->kmax,
            'kmax_ceiling' => $this->kmaxCeiling,
            'findings' => array_map(fn (FindingContribution $f) => $f->toArray(), $this->findings),
            'signals' => array_map(fn (SignalContribution $s) => $s->toArray(), $this->signals),
            'coefficients' => [
                'severity_penalties' => $this->severityPenalties,
                'within_sla_multiplier' => $this->withinSlaMultiplier,
                'overdue_multiplier' => $this->overdueMultiplier,
                'overdue_threshold_multiple' => $this->overdueThresholdMultiple,
                'risk_accepted_weight' => $this->riskAcceptedWeight,
                'findings_cap' => $this->findingsCap,
                'signal_penalties' => $this->signalPenalties,
                'signals_cap' => $this->signalsCap,
                'expired_evidence_cap' => $this->expiredEvidenceCap,
                'bands' => $this->bands,
            ],
        ];
    }
}
