<?php

namespace App\Services\Tprm\Reporting;

use App\Models\Tprm\BoardPack;
use App\Services\Tprm\Extraction\LlmClient;
use App\Services\Tprm\Extraction\PromptRegistry;
use Illuminate\Support\Str;

/**
 * The narrative a Board and Risk Committee pack opens with — FR-RPT-05,
 * TRD §12.7.
 *
 * THERE IS ALWAYS A NARRATIVE, AND IT IS NEVER A BLANK BOX. AI is off by
 * default in this product (`config('tprm.ai.enabled')`), and a pack whose
 * narrative section reads "AI unavailable" on every installation that has not
 * bought a model subscription is a feature nobody has. So the deterministic
 * draft is the product, and the model — when one is configured — rewrites it
 * into better prose. It never supplies a fact of its own: the figures are
 * assembled here first, and the prompt is asked to rephrase them.
 *
 * THE PROVENANCE TRAVELS WITH THE TEXT. `draft()` returns which of the two
 * paths produced the paragraph, and `BoardPack::narrativeProvenance()` prints
 * it. A committee reading an assessment of its own third-party exposure is
 * entitled to know whether a person wrote it, a model drafted it, or the
 * product assembled it.
 *
 * IT LEADS WITH WHAT IS WRONG. The deterministic assembly below orders its
 * clauses by consequence — vendors with no exit plan, findings past their
 * date, evidence already expired — because a narrative that opened with the
 * size of the portfolio would be answering a question nobody asked.
 */
class BoardNarrativeWriter
{
    public const PROMPT_KEY = 'board_narrative';

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PromptRegistry $prompts,
    ) {}

    /**
     * @param  array<string, mixed>  $figures
     * @return array{text: string, source: string}
     */
    public function draft(array $figures): array
    {
        $deterministic = $this->assemble($figures);

        if (! $this->llm->available(LlmClient::NARRATIVE_GENERATION)) {
            return ['text' => $deterministic, 'source' => BoardPack::SOURCE_DETERMINISTIC];
        }

        $result = $this->llm->run(
            self::PROMPT_KEY,
            $this->prompts->renderKey(self::PROMPT_KEY, $deterministic),
            LlmClient::NARRATIVE_GENERATION,
        );

        $rewritten = $result->succeeded() ? ($result->data['narrative'] ?? null) : null;

        // A model that returned nothing usable does not get to blank the
        // section. The assembled text stands, and the pack says so.
        if (! is_string($rewritten) || trim($rewritten) === '') {
            return ['text' => $deterministic, 'source' => BoardPack::SOURCE_DETERMINISTIC];
        }

        return ['text' => trim($rewritten), 'source' => BoardPack::SOURCE_AI_ASSISTED];
    }

    /**
     * The deterministic draft — every sentence traceable to a figure above it.
     *
     * @param  array<string, mixed>  $figures
     */
    public function assemble(array $figures): string
    {
        $portfolio = $figures['portfolio'] ?? [];
        $exit = $figures['exit_readiness'] ?? [];
        $findings = $figures['findings'] ?? [];
        $assessments = $figures['overdue_assessments'] ?? [];
        $evidence = $figures['expiring_evidence'] ?? [];
        $incidents = $figures['incidents'] ?? [];
        $concentration = $figures['concentration'] ?? [];
        $waivers = $figures['overrides_and_waivers'] ?? [];

        $paragraphs = [];

        /* --- What needs a decision ------------------------------------- */

        $issues = [];

        if (($exit['no_plan'] ?? 0) > 0) {
            $issues[] = sprintf(
                '%d of the %d engagements that require an exit plan have none',
                $exit['no_plan'],
                $exit['require_a_plan'] ?? 0,
            );
        }

        if (($exit['never_tested'] ?? 0) > 0) {
            $issues[] = sprintf(
                '%d %s a plan that has never been exercised',
                $exit['never_tested'],
                $exit['never_tested'] === 1 ? 'has' : 'have',
            );
        }

        if (($assessments['critical_or_high_overdue'] ?? 0) > 0) {
            $issues[] = sprintf(
                '%d Critical or High engagements are past their assessment date',
                $assessments['critical_or_high_overdue'],
            );
        }

        if (($findings['overdue'] ?? 0) > 0) {
            $issues[] = sprintf('%d open findings are past their remediation date', $findings['overdue']);
        }

        if (($evidence['already_expired'] ?? 0) > 0) {
            $issues[] = sprintf(
                '%d pieces of assurance evidence have already expired, so the controls they evidenced are '
                .'currently unevidenced',
                $evidence['already_expired'],
            );
        }

        if (($waivers['lapsed_but_not_withdrawn'] ?? 0) > 0) {
            $issues[] = sprintf(
                '%d approved waivers have passed their expiry without being withdrawn',
                $waivers['lapsed_but_not_withdrawn'],
            );
        }

        $paragraphs[] = $issues === []
            ? 'No third-party control gap in this period requires a decision from the committee. The position '
                .'below is presented for information.'
            : 'The committee is asked to note the following. '.$this->sentenceList($issues).'.';

        /* --- The portfolio --------------------------------------------- */

        $portfolioSentence = sprintf(
            'The register holds %d live engagements, of which %d support a critical or important business '
            .'function and %d are material outsourcing arrangements.',
            $portfolio['total'] ?? 0,
            $portfolio['supports_critical_function'] ?? 0,
            $portfolio['material_outsourcing'] ?? 0,
        );

        if (($portfolio['unscored'] ?? 0) > 0) {
            $portfolioSentence .= sprintf(
                ' %d %s no residual score and %s excluded from the portfolio average, which stands at %s '
                .'across the %d that are scored.',
                $portfolio['unscored'],
                $portfolio['unscored'] === 1 ? 'has' : 'have',
                $portfolio['unscored'] === 1 ? 'is' : 'are',
                $portfolio['mean_residual'] === null ? 'no figure' : (string) $portfolio['mean_residual'],
                $portfolio['scored'] ?? 0,
            );
        } elseif ($portfolio['mean_residual'] !== null) {
            $portfolioSentence .= sprintf(' The mean residual score is %s.', $portfolio['mean_residual']);
        }

        if (($portfolio['untiered'] ?? 0) > 0) {
            // Not folded into Low: an engagement nobody has tiered has not
            // been found to be low risk, it has not been looked at.
            $portfolioSentence .= sprintf(
                ' %d %s not been tiered at all.',
                $portfolio['untiered'],
                $portfolio['untiered'] === 1 ? 'has' : 'have',
            );
        }

        $paragraphs[] = $portfolioSentence;

        /* --- Concentration --------------------------------------------- */

        if (isset($concentration['hhi'])) {
            $paragraphs[] = sprintf(
                'Provider concentration measures %s on the Herfindahl-Hirschman index, which this framework '
                .'reads as %s.%s',
                is_numeric($concentration['hhi']) ? round((float) $concentration['hhi']) : $concentration['hhi'],
                strtolower((string) ($concentration['band'] ?? 'unclassified')),
                count($concentration['single_points_of_failure'] ?? []) > 0
                    ? sprintf(
                        ' %d single %s of failure %s identified.',
                        count($concentration['single_points_of_failure']),
                        count($concentration['single_points_of_failure']) === 1 ? 'point' : 'points',
                        count($concentration['single_points_of_failure']) === 1 ? 'was' : 'were',
                    )
                    : '',
            );
        }

        /* --- Findings --------------------------------------------------- */

        if (($findings['open'] ?? 0) > 0) {
            $paragraphs[] = sprintf(
                '%d findings are open, with a mean age of %s days. %d %s been open for more than 180 days.',
                $findings['open'],
                $findings['mean_age_days'] === null ? 'no recorded' : (string) $findings['mean_age_days'],
                $findings['by_age']['over_180'] ?? 0,
                ($findings['by_age']['over_180'] ?? 0) === 1 ? 'has' : 'have',
            );
        } else {
            $paragraphs[] = 'No third-party findings are open.';
        }

        /* --- Incidents --------------------------------------------------- */

        $incidentSentence = ($incidents['count'] ?? 0) === 0
            ? 'No third-party incident was reported to the institution in the last twelve months.'
            : sprintf(
                '%d third-party %s %s reported to the institution in the last twelve months, of which %d '
                .'involved personal data and %d %s notified to a regulator.',
                $incidents['count'],
                $incidents['count'] === 1 ? 'incident' : 'incidents',
                $incidents['count'] === 1 ? 'was' : 'were',
                $incidents['personal_data'] ?? 0,
                $incidents['reported_to_a_regulator'] ?? 0,
                ($incidents['reported_to_a_regulator'] ?? 0) === 1 ? 'was' : 'were',
            );

        if (($incidents['loss_not_quantified'] ?? 0) > 0) {
            // The number a board asks about, and the one a total would hide.
            $incidentSentence .= sprintf(
                ' %d of them carry no quantified loss, so the reported total understates the position.',
                $incidents['loss_not_quantified'],
            );
        }

        $paragraphs[] = $incidentSentence;

        $paragraphs[] = 'This narrative was assembled from the figures in this pack. It has not been reviewed, '
            .'and the committee should treat it as a starting point for the Chief Risk Officer\'s own '
            .'assessment rather than as that assessment.';

        return implode("\n\n", $paragraphs);
    }

    /**
     * @param  list<string>  $items
     */
    private function sentenceList(array $items): string
    {
        $items = array_map(fn (string $item) => Str::ucfirst($item), $items);

        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode('; ', $items).'; and '.lcfirst($last);
    }
}
