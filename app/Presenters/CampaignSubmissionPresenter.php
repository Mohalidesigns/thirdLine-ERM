<?php

namespace App\Presenters;

use App\Models\CampaignAssignment;
use App\Models\CampaignResponse;

/**
 * What a respondent filed, in one shape (migration Phase 4.5).
 *
 * Lifted out of the 50-line @php block at the top of
 * risk/campaigns/submission.blade.php, where it could not be tested and where
 * the two shapes it normalises were discovered by reading two other controllers.
 *
 * THREE shapes land in campaign_responses and this screen has to read all of
 * them:
 *
 *   1. RCSA worksheet lines (RcsaWorksheetService) are free text. risk_id is
 *      null and everything the respondent typed — description, category, the
 *      inherent pair, the controls, the action plan — sits in
 *      questionnaire_data.
 *   2. Campaign respond-form lines (CampaignController::submitResponse) point
 *      at a register risk and leave questionnaire_data null.
 *   3. Questionnaire answers, new in 4.5, are one row with risk_id null and
 *      questionnaire_data carrying `answers`. See answerSheet() below.
 *
 * The scored columns stay authoritative for the residual position on shapes 1
 * and 2 — questionnaire_data repeats them so the reduction stays auditable, but
 * the columns are what the rest of the product reads.
 */
class CampaignSubmissionPresenter
{
    /**
     * The assessed lines: shapes 1 and 2. A questionnaire answer sheet is not
     * a risk line and is returned separately.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(CampaignAssignment $assignment): array
    {
        return $assignment->responses
            ->reject(fn (CampaignResponse $r) => $this->isAnswerSheet($r))
            ->map(function (CampaignResponse $response) {
                $data = $response->questionnaire_data ?? [];

                return [
                    'id' => $response->id,
                    'title' => $data['description'] ?? $this->subjectTitle($response),
                    'reference' => $this->subjectReference($response),
                    'category' => $data['category'] ?? $this->categoryName($response),
                    'inherentLikelihood' => $data['inherent_likelihood'] ?? null,
                    'inherentImpact' => $data['inherent_impact'] ?? null,
                    'inherentScore' => $data['inherent_score'] ?? null,
                    'inherentRating' => $data['inherent_rating'] ?? null,
                    'residualLikelihood' => $response->likelihood_score,
                    'residualImpact' => $response->impact_score,
                    'residualScore' => $response->overall_score,
                    'residualRating' => $response->rating,
                    'controlEffectiveness' => $response->control_effectiveness,
                    'existingControls' => $data['existing_controls'] ?? null,
                    'actionPlan' => $data['action_plan'] ?? $response->comments,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The questionnaire answers, if this submission carried any.
     *
     * Each answer stores the question's TEXT and TYPE alongside the value, not
     * just its id. A questionnaire is edited between campaigns — a question is
     * reworded, a section is dropped — and a read-back that resolved the text
     * from `questions` at display time would quietly restate somebody's answer
     * against a question they were never asked. What was on the screen when
     * they answered is what this shows.
     *
     * @return array{questionnaireId: int|null, sections: list<array<string, mixed>>}|null
     */
    public function answerSheet(CampaignAssignment $assignment): ?array
    {
        $row = $assignment->responses->first(fn (CampaignResponse $r) => $this->isAnswerSheet($r));

        if ($row === null) {
            return null;
        }

        $data = $row->questionnaire_data ?? [];

        $sections = collect($data['answers'] ?? [])
            ->groupBy(fn (array $answer) => $answer['section'] ?? 'Questions')
            ->map(fn ($answers, $section) => [
                'title' => (string) $section,
                'answers' => $answers->map(fn (array $answer) => [
                    'questionId' => $answer['question_id'] ?? null,
                    'question' => $answer['question'] ?? '—',
                    'type' => $answer['type'] ?? 'free_text',
                    'value' => $answer['value'] ?? null,
                    'label' => $answer['label'] ?? null,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'questionnaireId' => isset($data['questionnaire_id']) ? (int) $data['questionnaire_id'] : null,
            'sections' => $sections,
        ];
    }

    /**
     * What the line is about: the register risk, or the control, or nothing.
     *
     * `control_title` IS NOT A COLUMN ON `controls` AND NEVER WAS. The Blade
     * template read `$response->control?->control_title` behind a `?? '—'`, so
     * a line filed against a control printed a dash for its name on every
     * tenant, forever, and nothing failed. The column is `name`. Same family as
     * 3.8's seven RCSA columns and 4.1's four KRI properties; PHPStan found
     * this one the moment the relations were typed.
     */
    private function subjectTitle(CampaignResponse $response): string
    {
        $risk = $response->getRelationValue('risk');

        if ($risk !== null) {
            return (string) $risk->title;
        }

        $control = $response->getRelationValue('control');

        return $control !== null ? (string) $control->name : '—';
    }

    private function subjectReference(CampaignResponse $response): ?string
    {
        $risk = $response->getRelationValue('risk');

        if ($risk !== null) {
            return (string) $risk->risk_code;
        }

        $control = $response->getRelationValue('control');

        return $control !== null ? (string) $control->control_code : null;
    }

    private function categoryName(CampaignResponse $response): ?string
    {
        $category = $response->getRelationValue('risk')?->getRelationValue('category');

        return $category !== null ? (string) $category->name : null;
    }

    private function isAnswerSheet(CampaignResponse $response): bool
    {
        return is_array($response->questionnaire_data)
            && array_key_exists('answers', $response->questionnaire_data);
    }
}
