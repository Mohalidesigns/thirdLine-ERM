<?php

namespace App\Services\Campaigns;

use App\Models\Question;
use App\Models\Questionnaire;
use Illuminate\Support\Collection;

/**
 * The questionnaire half of a campaign submission (migration Phase 4.5).
 *
 * WHY THIS DID NOT EXIST. CampaignController::respond() has always eager-loaded
 * `campaign.questionnaire.sections.questions`, and risk/campaigns/respond.blade
 * .php has never referenced any of it: the page built its form from the business
 * unit's REGISTER RISKS and nothing else. So the whole questionnaire engine —
 * the builder, the sections, the eight question types, the publish step, the
 * `questionnaire_id` on the campaign — reached the respondent's screen and
 * rendered nothing. A bank could build a fraud-risk questionnaire, publish it,
 * attach it to a campaign, send two hundred people to answer it, and every one
 * of them would be shown a list of register risks instead. `questionnaire_data`
 * was accepted by submitResponse() and no input on the page could produce it.
 *
 * THE STORED SHAPE. One CampaignResponse row per submission, risk_id null,
 * questionnaire_data =
 *
 *     ['questionnaire_id' => 12, 'answers' => [
 *         ['question_id' => 3, 'section' => 'Operational Risk',
 *          'question' => 'Are reconciliations performed daily?',
 *          'type' => 'likert', 'value' => 4, 'label' => 'Agree'],
 *     ]]
 *
 * The question's TEXT, SECTION and TYPE are stored beside the value, not just
 * its id. Questionnaires are edited between campaigns — a question is reworded,
 * a section is dropped, a row is deleted — and a read-back that resolved the
 * text at display time would restate somebody's answer against a question they
 * were never asked. In an assurance product that is the same failure as a screen
 * that manufactures work: it reads as evidence and is not.
 */
class QuestionnaireAnswerSheet
{
    /**
     * The questionnaire's questions, in render order, keyed by id.
     *
     * @return Collection<int, Question>
     */
    public function questions(?Questionnaire $questionnaire): Collection
    {
        if ($questionnaire === null) {
            return collect();
        }

        return $questionnaire->sections
            ->flatMap(fn ($section) => $section->questions)
            ->keyBy('id');
    }

    /**
     * Question ids the respondent must answer.
     *
     * @return list<int>
     */
    public function requiredQuestionIds(?Questionnaire $questionnaire): array
    {
        return $this->questions($questionnaire)
            ->filter(fn (Question $question) => (bool) $question->is_required)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The questionnaire_data payload for a submission, or null when the
     * campaign has no questionnaire or nothing was answered.
     *
     * Answers for questions that are not in this questionnaire are dropped
     * rather than stored: the ids come off a form and the questionnaire is the
     * only authority on what was asked.
     *
     * @param  array<int|string, mixed>  $answers  question id => value
     * @return array{questionnaire_id: int, answers: list<array<string, mixed>>}|null
     */
    public function build(?Questionnaire $questionnaire, array $answers): ?array
    {
        if ($questionnaire === null) {
            return null;
        }

        $questions = $this->questions($questionnaire);

        $rows = collect($answers)
            ->filter(fn ($value, $id) => $questions->has((int) $id) && ! $this->isBlank($value))
            ->map(function ($value, $id) use ($questions) {
                $question = $questions->get((int) $id);

                return [
                    'question_id' => (int) $id,
                    'section' => $this->sectionTitle($question),
                    'question' => $question->question_text,
                    'type' => $question->question_type,
                    'value' => $value,
                    'label' => $this->labelFor($question, $value),
                ];
            })
            ->values()
            ->all();

        if ($rows === []) {
            return null;
        }

        return ['questionnaire_id' => (int) $questionnaire->id, 'answers' => $rows];
    }

    /** The section a question sits in, for grouping the read-back. */
    private function sectionTitle(Question $question): string
    {
        $section = $question->getRelationValue('section');

        return $section !== null ? (string) $section->title : 'Questions';
    }

    /**
     * What the respondent SAW next to the value they picked.
     *
     * A likert answer of 4 means nothing on its own; "Agree" is the answer. The
     * options live on the question and are edited with it, which is exactly why
     * the resolved label is stored rather than looked up later.
     */
    private function labelFor(Question $question, mixed $value): ?string
    {
        foreach ($question->options ?? [] as $option) {
            if (! is_array($option)) {
                continue;
            }

            if (array_key_exists('value', $option) && (string) $option['value'] === (string) $value) {
                return isset($option['label']) ? (string) $option['label'] : null;
            }
        }

        return null;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
