<?php

namespace App\Services\Tprm\Assessment;

use App\Support\Tprm\RuleEvaluator;

/**
 * Decides which questions an engagement is actually asked — FR-ASM-03.
 *
 * THE TRACE IS THE POINT, not the question list. "Scoping must be provable —
 * the system records why each question was or was not asked." A supervisor
 * looking at a forty-question assessment against a two-hundred-question pack
 * will ask what happened to the other hundred and sixty, and "the tool decided"
 * is not an answer. Every exclusion here names the rule that caused it.
 *
 * A SECTION RULE SHORT-CIRCUITS ITS QUESTIONS, and the trace says so rather
 * than repeating the section's rule against each one: "excluded because section
 * PCI was not applicable" is a sentence a reader understands, where fifteen
 * identical question-level entries is a wall they skim.
 *
 * AN UNRESOLVED FACT DOES NOT SILENTLY EXCLUDE. A rule that cannot be
 * evaluated — because the engagement has not answered the attribute it depends
 * on — is reported in `unresolved`, and the question is INCLUDED. Excluding on
 * missing data is how a questionnaire quietly stops asking about the thing
 * nobody filled in, which is exactly the thing most worth asking about.
 */
class QuestionnaireScoper
{
    public function __construct(private readonly RuleEvaluator $evaluator) {}

    /**
     * @param  array<int, array{code: string, title?: string, visibility_rule?: array<string, mixed>|null, questions: list<array{code: string, visibility_rule?: array<string, mixed>|null, is_required?: bool}>}>  $sections
     * @param  array<string, mixed>  $context  flat, dot-keyed facts
     */
    public function scope(array $sections, array $context): ScopingResult
    {
        $included = [];
        $trace = [];
        $unresolved = [];

        foreach ($sections as $section) {
            $sectionCode = (string) ($section['code'] ?? '');
            $sectionRule = $section['visibility_rule'] ?? null;

            $sectionVisible = $this->evaluator->evaluate($sectionRule, $context);
            $sectionUnresolved = $this->evaluator->unresolvedFacts();

            // A section whose rule could not be evaluated is shown, for the
            // same reason a question is: missing data must not narrow the
            // questionnaire.
            if (! $sectionVisible && $sectionUnresolved !== []) {
                $sectionVisible = true;
                $unresolved = array_merge($unresolved, array_map(
                    fn (string $fact) => "section {$sectionCode}: {$fact}",
                    $sectionUnresolved
                ));
            }

            foreach ($section['questions'] ?? [] as $question) {
                $questionCode = (string) ($question['code'] ?? '');

                if (! $sectionVisible) {
                    $trace[$questionCode] = [
                        'included' => false,
                        'decided_by' => 'section_rule',
                        'section' => $sectionCode,
                        'reason' => "The section \"{$sectionCode}\" does not apply to this engagement.",
                        'rule' => $sectionRule,
                    ];

                    continue;
                }

                $questionRule = $question['visibility_rule'] ?? null;
                $visible = $this->evaluator->evaluate($questionRule, $context);
                $questionUnresolved = $this->evaluator->unresolvedFacts();

                if (! $visible && $questionUnresolved !== []) {
                    $unresolved = array_merge($unresolved, array_map(
                        fn (string $fact) => "{$questionCode}: {$fact}",
                        $questionUnresolved
                    ));

                    $included[] = $questionCode;
                    $trace[$questionCode] = [
                        'included' => true,
                        'decided_by' => 'unresolved_fact',
                        'section' => $sectionCode,
                        'reason' => 'The visibility rule could not be evaluated because the engagement has not '
                            .'recorded '.implode(', ', $questionUnresolved).'. The question is asked rather than '
                            .'skipped, so that missing data does not narrow the questionnaire.',
                        'rule' => $questionRule,
                    ];

                    continue;
                }

                if ($visible) {
                    $included[] = $questionCode;
                }

                $trace[$questionCode] = [
                    'included' => $visible,
                    'decided_by' => $questionRule === null ? 'always' : 'question_rule',
                    'section' => $sectionCode,
                    'reason' => $questionRule === null
                        ? 'Asked of every engagement scoped against this template.'
                        : ($visible
                            ? 'The question\'s visibility rule matched this engagement.'
                            : 'The question\'s visibility rule did not match this engagement.'),
                    'rule' => $questionRule,
                ];
            }
        }

        return new ScopingResult(
            includedCodes: $included,
            trace: $trace,
            unresolvedFacts: array_values(array_unique($unresolved)),
        );
    }
}
