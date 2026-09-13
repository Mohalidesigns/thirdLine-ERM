<?php

namespace App\Services\Tprm\Scoring;

use App\Enums\Tprm\RiskTier;
use App\Support\Tprm\DefaultRuleset;

/**
 * A tiering ruleset, as a value object the calculators can be handed.
 *
 * The calculators take one of these and NOTHING ELSE — no config lookups, no
 * database, no container. That is what makes them pure, and purity here is not
 * a style preference: these calculators decide a vendor's tier, the number
 * goes to a board and to a supervisor, and TRD §7.9 requires the derivation to
 * be reproducible from its stored inputs. A calculator that reads `config()`
 * mid-computation produces a number that depends on when it ran.
 *
 * Built either from a `tp_rulesets` row or from the shipped defaults.
 */
class Ruleset
{
    /**
     * @param  array<string, array{label: string, weight: int|float, source?: string, description?: string, options: list<array{value: string, label: string, score: int|float}>}>  $factors
     * @param  list<array{code: string, name: string, floor: string, citation: string, condition: array<string, mixed>, suspends?: bool}>  $knockouts
     * @param  array<string, array{int, int}>  $bandEdges
     * @param  array<string, float>  $volumeBands
     * @param  array<string, float>  $rtoBands
     */
    public function __construct(
        public readonly string $version,
        public readonly array $factors,
        public readonly array $knockouts,
        public readonly array $bandEdges,
        public readonly array $volumeBands,
        public readonly array $rtoBands,
    ) {}

    public static function shipped(): self
    {
        return new self(
            version: DefaultRuleset::VERSION,
            factors: DefaultRuleset::factors(),
            knockouts: DefaultRuleset::knockouts(),
            bandEdges: DefaultRuleset::bandEdges(),
            volumeBands: DefaultRuleset::dataVolumeBands(),
            rtoBands: DefaultRuleset::rtoBands(),
        );
    }

    /**
     * Build from a persisted row.
     *
     * The volume and RTO band tables are not stored per ruleset: they are the
     * multipliers in TRD §7.2's DATA and CRIT definitions, not tenant policy,
     * and a tenant that could edit them could make a million records of
     * restricted data score lower than a thousand.
     *
     * @param  object{version: string, factors: mixed, knockouts: mixed, band_edges: mixed}  $row
     */
    public static function fromRow(object $row): self
    {
        return new self(
            version: $row->version,
            factors: self::decode($row->factors),
            knockouts: array_values(self::decode($row->knockouts)),
            bandEdges: self::decode($row->band_edges),
            volumeBands: DefaultRuleset::dataVolumeBands(),
            rtoBands: DefaultRuleset::rtoBands(),
        );
    }

    /** @return array<mixed> */
    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    }

    /** Total of every factor weight. The denominator of the weighted mean. */
    public function totalWeight(): float
    {
        return array_sum(array_map(
            fn (array $factor) => (float) ($factor['weight'] ?? 0),
            $this->factors
        ));
    }

    /**
     * Whether the weights total 100, which FR-TIER-09 requires of a ruleset
     * before it may be published.
     *
     * Scoring does NOT depend on this — the weighted mean divides by the
     * actual total, so a ruleset summing to 97 still produces a sane 0–100
     * score. It is a publish-time validation so that a factor's stated weight
     * means what an administrator reading "25" thinks it means.
     */
    public function weightsTotalOneHundred(): bool
    {
        return abs($this->totalWeight() - 100.0) < 0.001;
    }

    /**
     * The score for one option of one factor, or null when either is unknown.
     *
     * Null rather than zero, because they are different statements: zero is
     * "no risk from this factor", null is "we do not recognise that answer",
     * and scoring an unrecognised answer as zero silently lowers a tier.
     */
    public function optionScore(string $factorCode, ?string $optionValue): ?float
    {
        if ($optionValue === null) {
            return null;
        }

        foreach ($this->factors[$factorCode]['options'] ?? [] as $option) {
            if (($option['value'] ?? null) === $optionValue) {
                return (float) $option['score'];
            }
        }

        return null;
    }

    public function factorWeight(string $factorCode): float
    {
        return (float) ($this->factors[$factorCode]['weight'] ?? 0);
    }

    public function factorLabel(string $factorCode): string
    {
        return (string) ($this->factors[$factorCode]['label'] ?? $factorCode);
    }

    /** @return list<string> */
    public function factorCodes(): array
    {
        return array_keys($this->factors);
    }

    /** The tier a weighted 0–100 score falls in, before knockouts. */
    public function tierForScore(float $score): RiskTier
    {
        foreach ($this->bandEdges as $tier => $edges) {
            [$lower, $upper] = $edges;

            if ($score >= $lower && $score <= $upper) {
                return RiskTier::from($tier);
            }
        }

        return $score > 0 ? RiskTier::Critical : RiskTier::Low;
    }
}
