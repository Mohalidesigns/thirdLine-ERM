<?php

namespace App\Services\Tprm\Assessment;

use App\Enums\Tprm\ComplianceLevel;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Question;
use Illuminate\Support\Facades\DB;

/**
 * Answer inheritance — FR-ASM-06.
 *
 * "Where an artefact already holds the answer, the question is pre-answered
 * and presented to the vendor as CONFIRM-OR-CORRECT with the source cited,
 * never re-asked cold."
 *
 * That last clause is the commercial argument. A vendor asked the same two
 * hundred questions every year answers the second year faster and worse; a
 * vendor shown its own previous answer and asked whether it still holds
 * corrects the three that changed and leaves the rest alone. It is also why
 * the carry-forward decay exists in TRD §7.4 — inheritance makes answering
 * cheap, and the decay stops cheapness from being free.
 *
 * TWO SOURCES, and only one is built here.
 *
 *   PRIOR RESPONSE — implemented. The most recent validated answer to the same
 *   question on the same engagement, within the carry-forward window.
 *
 *   CONFIRMED DOCUMENT EXTRACTION — Phase 3. `fromExtraction()` is the seam,
 *   and it returns null until the extractor exists rather than pretending. A
 *   stub that silently returned nothing would be indistinguishable from a
 *   working resolver that found nothing, which is the kind of thing that ships
 *   and is discovered a year later.
 */
class AnswerInheritanceResolver
{
    /**
     * Pre-answer a response where a source exists. Returns true when it did.
     */
    public function apply(AssessmentResponse $response, Question $question, Engagement $engagement): bool
    {
        $prior = $this->fromPriorResponse($question, $engagement);

        if ($prior !== null) {
            $response->forceFill([
                'value' => $prior->value,
                'assurance_level' => $prior->assurance_level?->value,
                'compliance' => $prior->compliance->value,
                'is_auto_answered' => true,
                'auto_answer_source' => [
                    'kind' => 'prior_response',
                    'response_id' => $prior->getKey(),
                    'assessment_id' => $prior->assessment_id,
                    'answered_at' => $prior->updated_at?->toDateString(),
                    // The citation the vendor is shown. "Carried from your
                    // 2025 assessment" is a sentence they can check; a
                    // pre-filled box with no provenance is one they distrust.
                    'citation' => 'Carried from your previous assessment, answered '
                        .($prior->updated_at?->format('F Y') ?? 'previously').'.',
                ],
                'carried_forward_from_response_id' => $prior->getKey(),
                'carry_forward_cycles' => $prior->carry_forward_cycles + 1,
            ])->save();

            return true;
        }

        $extraction = $this->fromExtraction($question, $engagement);

        if ($extraction !== null) {
            $response->forceFill($extraction)->save();

            return true;
        }

        return false;
    }

    /**
     * The most recent validated answer to this question on this engagement,
     * within the carry-forward window.
     *
     * Only from a VALIDATED assessment. Carrying an answer forward from a
     * cycle nobody reviewed would launder an unreviewed claim into the next
     * year's evidence base, where its provenance is one more step removed.
     */
    public function fromPriorResponse(Question $question, Engagement $engagement): ?AssessmentResponse
    {
        $limit = (int) config('tprm.defaults.carry_forward_cycle_limit');

        return AssessmentResponse::query()
            ->where('question_id', $question->getKey())
            ->where('compliance', '!=', ComplianceLevel::Unanswered->value)
            ->where('carry_forward_cycles', '<', $limit)
            ->whereIn('assessment_id', function ($query) use ($engagement) {
                $query->select('id')
                    ->from('tp_assessments')
                    ->where('engagement_id', $engagement->getKey())
                    ->whereIn('status', ['validated', 'scored', 'closed'])
                    ->whereNull('deleted_at');
            })
            ->latest('id')
            ->first();
    }

    /**
     * A confirmed document extraction answering this question.
     *
     * PHASE 3 FILLS THIS IN. It returns null now, and the interface exists so
     * that the assessment engine can be built and tested against it without
     * waiting — but it is a real query against a real table rather than a
     * `return null;`, so the day the extractor starts confirming rows this
     * begins working without another change here.
     *
     * @return array<string, mixed>|null
     */
    public function fromExtraction(Question $question, Engagement $engagement): ?array
    {
        $extraction = DB::table('tp_document_extractions')
            ->join('tp_documents', 'tp_document_extractions.document_id', '=', 'tp_documents.id')
            ->where('tp_document_extractions.status', 'confirmed')
            ->where('tp_documents.owner_type', 'engagement')
            ->where('tp_documents.owner_id', $engagement->getKey())
            ->whereNull('tp_documents.deleted_at')
            ->orderByDesc('tp_document_extractions.confirmed_at')
            ->select([
                'tp_document_extractions.id',
                'tp_document_extractions.extracted',
                'tp_document_extractions.citations',
                'tp_documents.title',
            ])
            ->get();

        foreach ($extraction as $row) {
            $extracted = json_decode((string) $row->extracted, true) ?: [];

            // The extractor keys its answers by question code. Nothing writes
            // this shape yet; Phase 3's cascade does.
            $answer = $extracted['answers'][$question->code] ?? null;

            if ($answer === null) {
                continue;
            }

            return [
                'value' => $answer['value'] ?? null,
                'assurance_level' => $answer['assurance_level'] ?? null,
                'compliance' => $answer['compliance'] ?? ComplianceLevel::Unanswered->value,
                'is_auto_answered' => true,
                'auto_answer_source' => [
                    'kind' => 'document_extraction',
                    'extraction_id' => $row->id,
                    'document' => $row->title,
                    'citation' => $answer['citation'] ?? ('Read from '.$row->title.'.'),
                ],
            ];
        }

        return null;
    }
}
