<?php

namespace App\Services\Bcms\Exercises;

use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseScore;
use App\Models\User;
use InvalidArgumentException;

/**
 * Observer scoring against the exercise's objectives (clause map §1.2 row 2,
 * §1.3, §2.1 conditions 5–6).
 *
 * OBJECTIVES ARE A LIST OF STRINGS ON THE DEFINITION (`objectives_template`'s
 * own shape, copied onto `bcms_exercise_definitions.objectives` — see the
 * phase notes for why: the JSON column was unused before this phase, so this
 * is a design decision Phase 9 makes rather than one it inherits).
 * `objective_index` addresses one entry; `objective_text` is what gets
 * SNAPSHOTTED onto the score row, and the snapshot — not the index — is what
 * every later read matches on, so an objective edited on the definition after
 * the exercise cannot rewrite what an evaluator scored.
 *
 * NO SCORING ON BEHALF OF ANOTHER EVALUATOR. `evaluator_id` is always the
 * signed-in user; a null one is rejected even though the column allows it
 * (clause map §1.3). A score is per (occurrence, objective, evaluator) —
 * re-scoring updates the same row, matching the observer-scoring screen's
 * "already scored by this evaluator" state.
 *
 * COMMENTARY IS MANDATORY BELOW 3, ENFORCED HERE REGARDLESS OF WHAT THE
 * CLIENT SENT. The screen enforces it as UX; this is the actual rule.
 */
class ScoringService
{
    /**
     * @return list<array{text: string, index: int}>
     */
    public function objectivesFor(ExerciseOccurrence $occurrence): array
    {
        $objectives = $occurrence->definition?->objectives;

        if (! is_array($objectives)) {
            return [];
        }

        $out = [];

        foreach (array_values($objectives) as $index => $text) {
            $text = is_array($text) ? (string) ($text['text'] ?? '') : (string) $text;

            if ($text === '') {
                continue;
            }

            $out[] = ['text' => $text, 'index' => $index];
        }

        return $out;
    }

    public function score(
        ExerciseOccurrence $occurrence,
        int $objectiveIndex,
        int $score,
        ?string $commentary,
        User $evaluator,
    ): ExerciseScore {
        if ($score < 1 || $score > 5) {
            throw new InvalidArgumentException('A score must be between 1 and 5.');
        }

        if ($score < 3 && trim((string) $commentary) === '') {
            throw new InvalidArgumentException(
                'A score below 3 needs commentary. That sentence is what the after-action report and the '
                .'next exercise will act on.'
            );
        }

        $objectives = $this->objectivesFor($occurrence);
        $objective = $objectives[$objectiveIndex] ?? null;

        if ($objective === null) {
            throw new InvalidArgumentException('This exercise has no objective at that position.');
        }

        if ($occurrence->aar?->status === 'final') {
            throw new InvalidArgumentException('Scoring is closed — this exercise is final.');
        }

        return ExerciseScore::query()->updateOrCreate(
            [
                'occurrence_id' => $occurrence->getKey(),
                'objective_text' => $objective['text'],
                'evaluator_id' => $evaluator->getKey(),
            ],
            [
                'organization_id' => $occurrence->organization_id,
                'score' => $score,
                'commentary' => $commentary,
            ],
        );
    }

    /**
     * Whether every objective has at least one score row with an evaluator —
     * condition 5.
     */
    public function everyObjectiveScored(ExerciseOccurrence $occurrence): bool
    {
        $objectives = $this->objectivesFor($occurrence);

        if ($objectives === []) {
            return true;
        }

        $scoredTexts = ExerciseScore::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->whereNotNull('evaluator_id')
            ->pluck('objective_text')
            ->all();

        foreach ($objectives as $objective) {
            if (! in_array($objective['text'], $scoredTexts, true)) {
                return false;
            }
        }

        return true;
    }
}
