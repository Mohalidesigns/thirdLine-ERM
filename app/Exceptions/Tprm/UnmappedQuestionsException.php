<?php

namespace App\Exceptions\Tprm;

use App\Models\Tprm\Question;
use App\Models\Tprm\QuestionnaireTemplate;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Raised when a template is published with a question mapped to no control.
 *
 * FR-ASM-05 calls this "the mechanism that keeps questionnaires short", and
 * the message says so — an author who hits this needs to understand it is a
 * design rule rather than an obstacle, or they will map every question to
 * A.5.19 to get past it and the gate will have achieved nothing.
 */
class UnmappedQuestionsException extends RuntimeException
{
    /**
     * @param  Collection<int, Question>  $questions
     */
    public function __construct(
        public readonly QuestionnaireTemplate $template,
        public readonly Collection $questions,
    ) {
        $message = $questions->isEmpty()
            ? "Template {$template->code} cannot be published before it has questions."
            : sprintf(
                'Template %s cannot be published: %d question(s) map to no control — %s. Every question must '
                .'name a control it tests, in ISO 27002, NIST 800-53, CSF 2.0, CCM or the Trust Services '
                .'Criteria. This is what keeps a questionnaire short enough that a vendor answers it carefully.',
                $template->code,
                $questions->count(),
                $questions->pluck('code')->take(10)->implode(', ')
            );

        parent::__construct($message);
    }

    /** @return list<array{code: string, text: string}> */
    public function details(): array
    {
        return $this->questions->map(fn (Question $question) => [
            'code' => $question->code,
            'text' => $question->text,
        ])->values()->all();
    }
}
