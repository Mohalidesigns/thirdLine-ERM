<?php

namespace App\Observers\Tprm;

use App\Exceptions\Tprm\UnmappedQuestionsException;
use App\Models\Tprm\QuestionnaireTemplate;

/**
 * FR-ASM-05's publish gate: a template with an unmapped question cannot be
 * published.
 *
 * IN AN OBSERVER RATHER THAN A FORM REQUEST, because the phase prompt asks for
 * it there and because it is the right place: the rule has to hold for the
 * seeder that ships the packs, for a clone-and-publish, and for anything the
 * API later exposes — not only for the one HTTP route a validator guards.
 *
 * It throws rather than returning false. A silent refusal would leave the
 * author looking at a template that says draft with no explanation, and the
 * exception carries the offending questions so the validation panel can list
 * them by code.
 */
class QuestionnaireTemplateObserver
{
    public function updating(QuestionnaireTemplate $template): void
    {
        if (! $this->isBeingPublished($template)) {
            return;
        }

        $unmapped = $template->unmappedQuestions();

        if ($unmapped->isNotEmpty()) {
            throw new UnmappedQuestionsException($template, $unmapped);
        }
    }

    /**
     * A template cannot be created already published, for the same reason.
     * The seeder publishes as a second step, after its questions and their
     * mappings exist.
     */
    public function creating(QuestionnaireTemplate $template): void
    {
        if ($template->status !== QuestionnaireTemplate::STATUS_PUBLISHED) {
            return;
        }

        throw new UnmappedQuestionsException($template, collect());
    }

    private function isBeingPublished(QuestionnaireTemplate $template): bool
    {
        return $template->isDirty('status')
            && $template->status === QuestionnaireTemplate::STATUS_PUBLISHED;
    }
}
