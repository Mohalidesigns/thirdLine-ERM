<?php

namespace App\Services\Bcms\Bia;

use App\Enums\Bcms\ImpactHorizon;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\BiaImpact;
use App\Services\Bcms\BcmsSettings;
use Illuminate\Support\Collection;

/**
 * Deriving a proposed MTPD from the impact grid.
 *
 * THE SYSTEM SUGGESTS AND THE HUMAN DECIDES. This writes `derived_mtpd_hours`
 * and never `mtpd_hours`. The two live side by side precisely so a reviewer can
 * see where the assessor overrode the grid — which is the interesting part of a
 * BIA review, and would be erased by a single column.
 *
 * THE RULE: the MTPD is the FIRST horizon at which ANY impact category reaches
 * the tenant's intolerable score. Any, not all — a process that is
 * reputationally survivable for a week but regulatorily intolerable at four
 * hours has a four-hour MTPD, and averaging the categories would hide exactly
 * the one that matters. The threshold is the tenant's (`bcms_settings`), because
 * one bank's 4-out-of-5 is another's 3 and hard-coding it would make the
 * proposal our opinion of their business.
 *
 * A GRID THAT NEVER CROSSES THE THRESHOLD PROPOSES NOTHING. Null, not the
 * longest horizon: "we scored every category tolerable for two weeks" means the
 * grid does not answer the question, not that the MTPD is two weeks. The
 * screen says so and asks for a longer horizon or a rescore, which is a better
 * outcome than a confidently wrong fortnight.
 */
class MtpdDeriver
{
    public function __construct(private BcmsSettings $settings) {}

    /**
     * @return array{hours: float|null, horizon: string|null, categories: list<string>, threshold: int, rationale: string}
     */
    public function derive(BiaAssessment $assessment): array
    {
        $threshold = (int) $this->settings->for($assessment->organization_id)->impact_intolerable_score;

        /** @var Collection<int, BiaImpact> $impacts */
        $impacts = $assessment->relationLoaded('impacts')
            ? $assessment->impacts
            : $assessment->impacts()->get();

        if ($impacts->isEmpty()) {
            return [
                'hours' => null,
                'horizon' => null,
                'categories' => [],
                'threshold' => $threshold,
                'rationale' => 'No impact has been scored yet, so there is nothing to derive an MTPD from.',
            ];
        }

        // Horizons in time order, not in the order somebody filled the grid in.
        // Keyed by category too — a horizon that has SOME categories scored and
        // others blank must not be read the same as one fully assessed.
        $byHorizon = [];

        foreach ($impacts as $impact) {
            $horizon = $impact->horizon;

            if ($impact->severity_score === null) {
                continue;
            }

            $byHorizon[$horizon->value][$impact->impact_category->value] = $impact;
        }

        $ordered = array_values(array_filter(
            ImpactHorizon::cases(),
            fn (ImpactHorizon $h) => isset($byHorizon[$h->value])
        ));

        usort($ordered, fn (ImpactHorizon $a, ImpactHorizon $b) => $a->hours() <=> $b->hours());

        foreach ($ordered as $index => $horizon) {
            $breaching = [];

            foreach ($byHorizon[$horizon->value] as $categoryValue => $impact) {
                if ((int) $impact->severity_score >= $threshold) {
                    $breaching[] = $categoryValue;
                }
            }

            if ($breaching === []) {
                continue;
            }

            // A breach is only trustworthy as "the FIRST horizon" if the
            // category that breaches here was also scored at every earlier
            // horizon the assessor actually visited. A gap means the category
            // may have already crossed the threshold earlier and nobody
            // recorded it — walking past that gap is exactly the defect.
            $earlier = array_slice($ordered, 0, $index);
            $gaps = [];

            foreach ($breaching as $categoryValue) {
                $missingAt = [];

                foreach ($earlier as $earlierHorizon) {
                    if (! isset($byHorizon[$earlierHorizon->value][$categoryValue])) {
                        $missingAt[] = $earlierHorizon->value;
                    }
                }

                if ($missingAt !== []) {
                    $gaps[$categoryValue] = $missingAt;
                }
            }

            if ($gaps !== []) {
                return [
                    'hours' => null,
                    'horizon' => null,
                    'categories' => [],
                    'threshold' => $threshold,
                    'rationale' => $this->gapRationale($horizon, $gaps, $threshold),
                ];
            }

            return [
                'hours' => (float) $horizon->hours(),
                'horizon' => $horizon->value,
                'categories' => array_values(array_unique($breaching)),
                'threshold' => $threshold,
                'rationale' => sprintf(
                    'At %s, %s impact reaches %d out of 5, which this organisation has set as the point impact '
                    .'stops being tolerable. That is the first horizon at which any category crosses it.',
                    $horizon->value,
                    $this->list($breaching),
                    $threshold,
                ),
            ];
        }

        $longest = end($ordered) ?: null;

        return [
            'hours' => null,
            'horizon' => null,
            'categories' => [],
            'threshold' => $threshold,
            'rationale' => sprintf(
                'No category reaches %d out of 5 at any scored horizon%s. The grid does not answer the question — '
                .'either the disruption stays tolerable beyond the horizons scored, or the scores are too low. '
                .'Score a longer horizon or revisit them; it does not mean the MTPD is %s.',
                $threshold,
                $longest ? ', up to '.$longest->value : '',
                $longest ? $longest->value : 'unbounded',
            ),
        ];
    }

    /** Derive and persist the proposal. Never touches `mtpd_hours`. */
    public function apply(BiaAssessment $assessment): BiaAssessment
    {
        $derived = $this->derive($assessment);

        $assessment->forceFill(['derived_mtpd_hours' => $derived['hours']])->save();

        return $assessment;
    }

    /**
     * Explain why a candidate breach is being refused for a gap in its own
     * history, naming the category and the specific horizons it was never
     * scored at — silence is not an acceptable substitute for this.
     *
     * @param  array<string, list<string>>  $gaps  category value => horizon values it is missing at
     */
    private function gapRationale(ImpactHorizon $horizon, array $gaps, int $threshold): string
    {
        $named = [];

        foreach ($gaps as $category => $missingHorizons) {
            $named[] = sprintf('%s (not scored at %s)', $category, $this->list($missingHorizons));
        }

        return sprintf(
            'At %s, impact would reach %d out of 5 — but %s. Whether that category already crossed the threshold '
            .'at an earlier horizon is unknown, not tolerable, so %s cannot be proposed as the MTPD until every '
            .'earlier horizon is scored for it.',
            $horizon->value,
            $threshold,
            $this->list($named),
            $horizon->value,
        );
    }

    /** @param list<string> $items */
    private function list(array $items): string
    {
        $items = array_values(array_unique($items));

        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
