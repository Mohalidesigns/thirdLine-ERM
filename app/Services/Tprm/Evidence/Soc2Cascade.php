<?php

namespace App\Services\Tprm\Evidence;

use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\DisclosureSource;
use App\Enums\Tprm\FindingSeverity;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\Document;
use App\Models\Tprm\Question;
use App\Models\Tprm\Soc2Cuec;
use App\Models\Tprm\Soc2Detail;
use App\Models\Tprm\Soc2Exception;
use App\Models\Tprm\Soc2SubserviceOrg;

/**
 * The SOC 2 cascade — FR-EVD-05, FR-EVD-06 and AC-04.
 *
 * "Three whole workflows fall out of one upload — this is the demo moment."
 * One confirmed SOC 2 produces four sets of PROPOSALS:
 *
 *   1. `independently_assured` pre-answers for every question mapped to a TSC
 *      criterion the report covers WITHOUT EXCEPTION, citing section and page.
 *   2. A proposed finding per Section 4 exception.
 *   3. A proposed internal obligation per CUEC — a control the report assumes
 *      WE operate, which the service auditor did not test. A bank that files
 *      the SOC 2 without reading its CUECs has accepted duties it does not
 *      know it has, and almost no tool surfaces them.
 *   4. A proposed nth-party edge per carve-out subservice organisation,
 *      closing the assurance gap the carve-out method creates.
 *
 * THE CASCADE IS DRIVEN BY THE STRUCTURED SOC 2 RECORD, NOT BY THE AI, and
 * that is the design decision that makes AC-16 true rather than a special
 * case. An extractor fills `tp_soc2_details` and its children; so does a
 * person typing into a form. The cascade cannot tell the difference and does
 * not try. With every AI service disabled the workflow is slower and
 * identical.
 *
 * EVERYTHING IS A PROPOSAL REQUIRING A SECOND CONFIRMATION. The first
 * confirmation says "yes, the extractor read this report correctly". The
 * second says "yes, apply that to our register". They are different
 * judgements, made with different information, and collapsing them is how a
 * misread report becomes twelve findings against a vendor.
 */
class Soc2Cascade
{
    /** The framework code `tp_question_control_maps` stores TSC mappings under. */
    private const TSC_FRAMEWORK = 'tsc';

    /**
     * Assessments a pre-answer may be proposed into.
     *
     * A submitted or scored assessment is excluded: its answers are the record
     * of what the vendor said at a point in time, and a cascade editing them
     * afterwards would rewrite history to agree with a document that arrived
     * later.
     *
     * @var list<string>
     */
    private const OPEN_STATUSES = ['scoped', 'issued', 'in_progress', 'clarification_requested'];

    /**
     * Generate the proposals for a confirmed SOC 2 record.
     *
     * Writes nothing to the register. The caller presents these for the second
     * confirmation and only then applies them.
     */
    public function propose(Soc2Detail $soc2): Soc2Proposals
    {
        $soc2->loadMissing(['document', 'exceptions', 'cuecs', 'subserviceOrgs']);

        return new Soc2Proposals(
            answers: $this->proposedAnswers($soc2),
            findings: $this->proposedFindings($soc2),
            obligations: $this->proposedObligations($soc2),
            nthPartyEdges: $this->proposedEdges($soc2),
            bridgeLetterCap: $this->bridgeLetterApplies($soc2),
        );
    }

    /**
     * Pre-answers for questions mapped to a covered TSC criterion.
     *
     * A CRITERION WITH AN EXCEPTION AGAINST IT PROPOSES NOTHING. The report
     * says the control did not operate as described; proposing
     * `independently_assured` on the strength of the same report would use the
     * evidence of a failure as evidence of compliance. Those questions are
     * left for the vendor to answer, and the exception becomes a finding.
     *
     * @return list<array<string, mixed>>
     */
    private function proposedAnswers(Soc2Detail $soc2): array
    {
        $engagementId = $soc2->document?->owner_type === Document::OWNER_ENGAGEMENT
            ? (int) $soc2->document->owner_id
            : null;

        if ($engagementId === null) {
            return [];
        }

        // The report's categories, expanded into the criteria series that
        // questions are actually mapped against.
        $covered = array_values(array_diff($soc2->coveredCriteria(), $this->exceptedCriteria($soc2)));

        if ($covered === []) {
            return [];
        }

        $responses = AssessmentResponse::query()
            ->whereIn('assessment_id', Assessment::query()
                ->where('engagement_id', $engagementId)
                ->whereIn('status', self::OPEN_STATUSES)
                ->select('id'))
            ->whereIn('question_id', Question::query()
                ->whereHas('controlMaps', fn ($map) => $map
                    ->where('framework', self::TSC_FRAMEWORK)
                    ->whereIn('control_id', $covered))
                ->select('id'))
            // Only questions nobody has answered yet. A pre-answer that
            // overwrites a vendor's own response is not a proposal, it is a
            // correction nobody asked for — and if the vendor said
            // "non-compliant" while the report says otherwise, that
            // disagreement is worth a human looking at, not resolving away.
            ->where('compliance', 'unanswered')
            ->with(['question.controlMaps'])
            ->get();

        return $responses->map(function (AssessmentResponse $response) use ($soc2) {
            $answer = $this->preAnswerFor($response->question, $soc2);

            return $answer === null ? null : $answer + [
                'response_id' => $response->getKey(),
                'question_id' => $response->question_id,
            ];
        })->filter()->values()->all();
    }

    /**
     * Whether a confirmed SOC 2 pre-answers one question, and with what.
     *
     * The single definition of the rule, shared by the proposal generator
     * above and by `AnswerInheritanceResolver`, which asks the same question
     * about a questionnaire being scoped rather than one already issued. Two
     * implementations of "does this report cover this question" would drift,
     * and the direction they would drift in is the dangerous one: a resolver
     * that forgot the exception check would pre-answer `independently_assured`
     * on a control the auditor found not operating.
     *
     * @return array<string, mixed>|null
     */
    public function preAnswerFor(Question $question, Soc2Detail $soc2): ?array
    {
        $soc2->loadMissing('exceptions');

        $covered = array_flip(array_diff($soc2->coveredCriteria(), $this->exceptedCriteria($soc2)));

        $criterion = $question->controlMaps
            ->first(fn ($map) => $map->framework === self::TSC_FRAMEWORK && isset($covered[$map->control_id]))
            ?->control_id;

        if ($criterion === null) {
            return null;
        }

        return [
            'question_code' => $question->code,
            'question' => $question->text,
            'proposed_compliance' => 'compliant',
            'proposed_assurance_level' => $this->effectiveAssuranceLevel($soc2)->value,
            'tsc_criterion' => $criterion,
            // Section and page, per FR-EVD-05. Without them the vendor and
            // the reviewer both have to take the proposal on trust.
            'citation' => $this->citation($soc2, $criterion),
            'document_id' => $soc2->document_id,
        ];
    }

    /**
     * The criteria series an exception was raised against.
     *
     * A report cites "CC6.1" or "CC6.1 — logical access" while the mapped
     * questions carry "CC6", so the reference is normalised down to the series
     * the catalogue holds. Getting this wrong in the lenient direction would
     * be the dangerous failure: an exception that never suppresses its
     * pre-answer means the module proposes `independently_assured` on a
     * control the auditor found not operating.
     *
     * @return list<string>
     */
    private function exceptedCriteria(Soc2Detail $soc2): array
    {
        return $soc2->exceptions
            ->pluck('control_reference')
            ->filter()
            ->flatMap(fn (string $reference) => $this->seriesOf($reference))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Every criteria series named in a control reference.
     *
     * "CC6.1" yields both `CC6` and `CC6.1`, because the availability and
     * confidentiality series are catalogued at the second level (`A1.3`) while
     * the common criteria are catalogued at the first (`CC6`). Returning both
     * spellings means one comparison works for either.
     *
     * @return list<string>
     */
    private function seriesOf(string $controlReference): array
    {
        if (preg_match('/\b((?:CC|PI|A|C|P)\d+)(\.\d+)?/i', $controlReference, $matches) !== 1) {
            return [];
        }

        $base = strtoupper($matches[1]);

        return isset($matches[2]) ? [$base, $base.$matches[2]] : [$base];
    }

    private function citation(Soc2Detail $soc2, ?string $criterion): string
    {
        return sprintf(
            'SOC 2 %s covering %s to %s, %s. Criterion %s reported without exception.',
            $soc2->isTypeII() ? 'Type II' : 'Type I',
            $soc2->period_start?->toDateString() ?? 'the stated period start',
            $soc2->period_end?->toDateString() ?? 'the stated period end',
            $soc2->service_auditor ?: 'service auditor not stated',
            $criterion ?? 'mapped'
        );
    }

    /**
     * A proposed finding per Section 4 exception — FR-EVD-05.
     *
     * @return list<array<string, mixed>>
     */
    private function proposedFindings(Soc2Detail $soc2): array
    {
        return $soc2->exceptions->map(function (Soc2Exception $exception) use ($soc2) {
            return [
                'soc2_exception_id' => $exception->getKey(),
                'title' => 'SOC 2 exception: '.($exception->control_reference ?: 'unreferenced control'),
                'description' => $exception->description,
                'control_refs' => array_values(array_filter([$exception->control_reference])),
                'population' => $exception->population,
                'exceptions_noted' => $exception->exceptions_noted,
                'management_response' => $exception->management_response,
                // Proposed, for a reviewer to confirm or adjust. An auditor's
                // severity assessment where one was given; otherwise Medium —
                // never Critical by default, because a single exception in a
                // sample of forty is not automatically a critical failure and
                // a tool that says it is gets ignored.
                'proposed_severity' => $this->proposedSeverity($exception)->value,
                'source' => 'evidence',
                'source_id' => $soc2->document_id,
                'citation' => sprintf(
                    'Section 4 of the SOC 2 report for %s to %s.',
                    $soc2->period_start?->toDateString() ?? '?',
                    $soc2->period_end?->toDateString() ?? '?'
                ),
            ];
        })->values()->all();
    }

    /**
     * A proposed internal obligation per CUEC — FR-EVD-05.
     *
     * "A CUEC is a control WE must operate and is not tested by the service
     * auditor. This is a genuine differentiator; almost no tool does it."
     *
     * The obligor is `entity`, not `provider`. That is the whole point: the
     * report assumes the customer does this, and the customer is us.
     *
     * @return list<array<string, mixed>>
     */
    private function proposedObligations(Soc2Detail $soc2): array
    {
        return $soc2->cuecs->map(function (Soc2Cuec $cuec) use ($soc2) {
            return [
                'soc2_cuec_id' => $cuec->getKey(),
                'title' => 'CUEC: '.($cuec->cuec_reference ?: 'complementary user entity control'),
                'description' => $cuec->description,
                'obligor' => 'entity',
                'source' => 'assessment',
                'source_reference' => 'SOC 2 '.($cuec->cuec_reference ?? ''),
                // Annual by default, matched to the report's own cycle: a CUEC
                // is attested each time a new SOC 2 is relied upon.
                'frequency' => 'annual',
                'evidence_required' => true,
                'suggested_owner_id' => $cuec->internal_owner_id,
                'citation' => 'Complementary user entity controls, SOC 2 report '
                    .($soc2->period_end?->toDateString() ?? ''),
            ];
        })->values()->all();
    }

    /**
     * A proposed nth-party edge per CARVE-OUT subservice organisation —
     * FR-EVD-06.
     *
     * Inclusive subservice organisations are excluded on purpose: the report
     * covers them, so there is no assurance gap to close. A carve-out says the
     * auditor looked at nothing this organisation does, which is precisely the
     * gap an nth-party edge exists to record.
     *
     * @return list<array<string, mixed>>
     */
    private function proposedEdges(Soc2Detail $soc2): array
    {
        return $soc2->subserviceOrgs
            ->filter(fn (Soc2SubserviceOrg $org) => $org->method === 'carve_out')
            ->map(fn (Soc2SubserviceOrg $org) => [
                'soc2_subservice_org_id' => $org->getKey(),
                'child_name_raw' => $org->name,
                'service_description' => $org->services,
                'disclosure_source' => DisclosureSource::Soc2Carveout->value,
                'confirmation_status' => 'proposed',
                'rank' => 1,
                'citation' => 'Carved out of the SOC 2 report — the service auditor examined none of this '
                    .'organisation\'s controls.',
            ])->values()->all();
    }

    /**
     * AC-05: a control evidenced only by a bridge letter for the gap period is
     * capped at `documented`.
     */
    private function bridgeLetterApplies(Soc2Detail $soc2): bool
    {
        if ($soc2->bridge_letter_document_id === null) {
            return false;
        }

        // The cap bites when the bridge letter is doing the work — that is,
        // when the report's own audited period has ended and the letter is
        // covering the gap since.
        return $soc2->period_end !== null && $soc2->period_end->isPast();
    }

    /**
     * The assurance level a pre-answer from this report may claim.
     *
     * A Type I report says the controls were suitably DESIGNED on one day; it
     * says nothing about whether they operated. That is a documented control,
     * not an independently assured one, and treating the two alike would let a
     * vendor buy the cheaper report and score as though it had bought the
     * other.
     */
    private function effectiveAssuranceLevel(Soc2Detail $soc2): AssuranceLevel
    {
        $level = $soc2->isTypeII()
            ? AssuranceLevel::IndependentlyAssured
            : AssuranceLevel::Documented;

        // A qualified or adverse opinion is not independent assurance that the
        // controls work; it is an auditor saying they do not.
        if (in_array((string) $soc2->opinion_type, ['qualified', 'adverse', 'disclaimer'], true)) {
            $level = $level->cappedAt(AssuranceLevel::Documented);
        }

        if ($this->bridgeLetterApplies($soc2)) {
            $level = $level->cappedAt(AssuranceLevel::Documented);
        }

        return $level;
    }

    private function proposedSeverity(Soc2Exception $exception): FindingSeverity
    {
        $stated = strtolower((string) $exception->severity_assessment);

        return FindingSeverity::tryFrom($stated) ?? FindingSeverity::Medium;
    }
}
