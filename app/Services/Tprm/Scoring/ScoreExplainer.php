<?php

namespace App\Services\Tprm\Scoring;

use App\Models\Tprm\Assessment;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\InherentAssessment;

/**
 * The "Why this score" derivation — AC-15.
 *
 * "Two users see identical derivations." That is only true because this
 * produces a STRUCTURE THAT IS STORED, not a view that recomputes. The panel
 * renders `tp_score_runs.explanation`; nobody triggers a fresh calculation by
 * opening a page, so the number and its explanation cannot drift apart between
 * two people looking at the same engagement at the same moment.
 *
 * IT IS WRITTEN TO BE ARGUED WITH. A vendor disputing a Critical rating, a
 * relationship owner asking why a score moved, an examiner asking how the
 * institution arrived at a number in a board pack — each needs to reach the
 * input they disagree with. So every section names its inputs and its
 * coefficients, the arithmetic is written out as a sentence anybody can check
 * with a calculator, and each finding and signal carries its own contribution
 * rather than being summarised into a total.
 *
 * THE ENGINE AND RULESET VERSIONS ARE PART OF THE EXPLANATION, not metadata
 * beside it. "The score changed because the ruleset changed" and "the score
 * changed because the vendor got worse" are different conversations, and a
 * derivation that could not tell them apart would be useless in the one that
 * matters.
 */
class ScoreExplainer
{
    /**
     * @return array<string, mixed>
     */
    public function explain(
        Engagement $engagement,
        ResidualResult $result,
        DataConfidenceResult $confidence,
        ?Assessment $assessment = null,
    ): array {
        $inherent = InherentAssessment::query()
            ->where('engagement_id', $engagement->getKey())
            ->where('is_current', true)
            ->first();

        $inherentExplanation = (array) ($inherent->explanation ?? []);

        return [
            // The Phase 1 keys, carried through unchanged. The score panel has
            // rendered the inherent derivation from these since tiering
            // shipped, and a residual run that dropped them would blank half
            // the panel — the tiering half, which is the part explaining why
            // the vendor is Critical in the first place.
            'engine_version' => (string) config('tprm.engine_version'),
            'ruleset_version' => $inherent->ruleset_version ?? 'unversioned',
            'knockouts_fired' => $inherentExplanation['knockouts_fired'] ?? ($inherent->knockouts_fired ?? []),
            'decided_by' => $inherentExplanation['decided_by'] ?? null,

            'headline' => $this->headline($result, $confidence),
            'arithmetic' => $result->workingOut(),
            'scores' => $result->toArray(),

            'inherent' => $this->inherentSection($inherent),
            'is_residual' => true,
            'mitigation' => $this->mitigationSection($result, $assessment),
            'findings' => [
                'total' => round($result->fu, 2),
                'contributions' => $result->findingContributions,
                'note' => $result->findingContributions === []
                    ? 'No open findings against this engagement.'
                    : 'Each open finding adds its severity penalty. A finding inside its remediation SLA with '
                        .'an accepted plan counts at half; one overdue beyond twice its SLA counts at one and '
                        .'a half; a risk-accepted one counts at half until its acceptance expires.',
            ],
            'signals' => [
                'total' => round($result->su, 2),
                'contributions' => $result->signalContributions,
                'note' => $result->signalContributions === []
                    ? 'No monitoring signals against this engagement.'
                    : 'Signals are events observed about the vendor rather than gaps found in an assessment.',
            ],
            'data_confidence' => $confidence->toArray(),

            'versions' => [
                'engine_version' => (string) config('tprm.engine_version'),
                'ruleset_version' => $inherent->ruleset_version ?? 'unversioned',
                'computed_at' => now()->toDayDateTimeString(),
                // Named so a reader can tell "the model changed" from "the
                // vendor changed" without opening two board packs.
                'note' => 'A score is never recomputed retrospectively. Changing the ruleset or the engine '
                    .'produces a new run against the new version, and the difference between the two is '
                    .'reportable rather than silent.',
            ],
        ];
    }

    /**
     * One sentence, before any of the detail.
     *
     * A panel that opens with a table asks the reader to do the summarising. A
     * panel that opens with "88 inherent, reduced by 28.5% of assurance, plus
     * 13 for open findings and signals" has already answered the question most
     * people came with.
     */
    private function headline(ResidualResult $result, DataConfidenceResult $confidence): string
    {
        if ($result->sanctionsOverride) {
            return 'This engagement scores '.$result->displayScore().' because a confirmed sanctions match '
                .'forces the maximum score. Nothing else on this page changes that.';
        }

        $parts = [sprintf(
            'An inherent risk of %s, reduced by %s%% for the assurance held, gives %s.',
            round($result->ir, 1),
            round($result->m * 100, 1),
            round($result->ir * (1 - $result->m), 1),
        )];

        if ($result->fu > 0) {
            $parts[] = sprintf('Open findings add %s.', round($result->fu, 1));
        }

        if ($result->su > 0) {
            $parts[] = sprintf('Monitoring signals add %s.', round($result->su, 1));
        }

        $parts[] = sprintf(
            'The residual score is %s, which is %s.',
            $result->displayScore(),
            strtolower($result->band->label()),
        );

        if (! $confidence->mayCloseReview()) {
            $parts[] = 'Data confidence is '.strtolower($confidence->badge()).', so this score may not be used '
                .'to close a review or support a board assertion.';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function inherentSection(?InherentAssessment $inherent): array
    {
        if ($inherent === null) {
            return [
                'score' => null,
                'factors' => [],
                'knockouts' => [],
                'note' => 'This engagement has not been tiered, so it carries no inherent risk score. Until it '
                    .'is, the residual score has nothing to reduce.',
            ];
        }

        $explanation = (array) ($inherent->explanation ?? []);
        // The tiering engine nests its factor table one level down, under its
        // own `inherent` key. Read from there first so the panel's existing
        // table keeps working, and fall back for a run written before it did.
        $nested = (array) ($explanation['inherent'] ?? []);

        return [
            'score' => $inherent->raw_score === null ? null : (float) $inherent->raw_score,
            'tier' => $inherent->resulting_tier?->value,
            'version' => $inherent->version,
            // The factor weights and the knockouts with their citations, as
            // the tiering engine recorded them. Re-derived here would risk
            // showing today's ruleset against last year's tier.
            'factors' => $nested['factors'] ?? $explanation['factors'] ?? [],
            'total_weight' => $nested['total_weight'] ?? null,
            'knockouts' => $explanation['knockouts_fired'] ?? ($inherent->knockouts_fired ?? []),
            'assessed_at' => $inherent->assessed_at?->toDayDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mitigationSection(ResidualResult $result, ?Assessment $assessment): array
    {
        if ($assessment === null) {
            return [
                'm' => 0.0,
                'ac' => 0.0,
                'ec' => 0.0,
                'kmax' => $result->kmax,
                'arithmetic' => 'No validated assessment, so no assurance is credited and the inherent score '
                    .'stands unreduced.',
                'note' => 'A vendor nobody has assessed has demonstrated nothing. The mitigation is zero '
                    .'rather than assumed, which is why an unassessed engagement scores close to its inherent '
                    .'risk.',
            ];
        }

        return [
            'm' => round($result->m, 3),
            'ac' => round($result->ac, 3),
            'ec' => round($result->ec, 3),
            'kmax' => $result->kmax,
            'assessment' => [
                'id' => $assessment->getKey(),
                'cycle' => $assessment->cycle_label,
                'validated_at' => $assessment->validated_at?->toDayDateTimeString(),
            ],
            'arithmetic' => sprintf(
                '%s × %s × %s = %s',
                round($result->ac, 3),
                round($result->ec, 3),
                $result->kmax,
                round($result->m, 3),
            ),
            // The sentence that explains the module's central claim to a
            // vendor who has answered every question correctly and still
            // scores poorly.
            'note' => 'AC is what the answers say; EC is how well they are evidenced. Two vendors giving '
                .'identical answers score differently because this number differs. The ceiling of '
                .$result->kmax.' is a policy position: no amount of assurance reduces inherent risk to nothing.',
        ];
    }
}
