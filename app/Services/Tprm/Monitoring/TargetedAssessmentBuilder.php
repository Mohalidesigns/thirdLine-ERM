<?php

namespace App\Services\Tprm\Monitoring;

use App\Enums\Tprm\AssessmentStatus;
use App\Enums\Tprm\SignalType;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\MonitoringSignal;
use App\Models\Tprm\Question;
use App\Models\Tprm\QuestionnaireTemplate;
use Illuminate\Support\Facades\DB;

/**
 * A mini-assessment covering only the controls a signal implicates —
 * FR-MON-06.
 *
 * THE POINT IS WHAT IS *NOT* ASKED. A cyber rating drop does not justify
 * re-issuing a forty-question questionnaire; it justifies asking the eight
 * questions about the controls that would have moved that rating. A vendor
 * asked forty questions because one number changed learns that the bank's
 * monitoring is theatre, and answers accordingly next time.
 *
 * THE MAPPING IS FROM SIGNAL TYPE TO CONTROL DOMAIN, and it is ours. The
 * signal says a rating dropped; the control framework says which controls a
 * rating reflects; the questionnaire says which questions test those controls.
 * Nothing in any published standard makes that leap for us, so the map below
 * is an editorial judgement — stated as such, in one readable place, rather
 * than buried in a query.
 *
 * AN EMPTY SELECTION BUILDS NOTHING AND SAYS SO. A targeted assessment with no
 * questions is worse than none: it arrives in the vendor's portal, they open
 * it, and it is empty. Where no question maps to the implicated controls the
 * builder refuses and reports why, and the alert records that as the outcome.
 */
class TargetedAssessmentBuilder
{
    /**
     * Signal type => the ISO 27002 control clauses it implicates.
     *
     * ISO 27002 because it is the framework the shipped packs map to most
     * densely, so a selection through it reaches the most questions. A tenant
     * whose own pack maps elsewhere gets the framework-agnostic fallback
     * below: the question's domain tag.
     *
     * @var array<string, array{controls: list<string>, domains: list<string>}>
     */
    private const IMPLICATED = [
        'cyber_rating_change' => [
            'controls' => ['8.8', '8.9', '8.16', '8.20', '8.21', '8.23', '5.7'],
            'domains' => ['security'],
        ],
        'data_breach' => [
            'controls' => ['5.24', '5.25', '5.26', '5.28', '6.8', '8.16'],
            'domains' => ['security', 'resilience'],
        ],
        'cve_exposure' => [
            'controls' => ['8.8', '8.19', '8.31', '8.32'],
            'domains' => ['security'],
        ],
        'regulatory_action' => [
            'controls' => ['5.31', '5.34', '5.36'],
            'domains' => ['governance', 'assurance'],
        ],
        'financial_distress' => [
            'controls' => ['5.30', '5.29'],
            'domains' => ['resilience', 'financial'],
        ],
        'adverse_media' => [
            'controls' => ['5.31', '5.36'],
            'domains' => ['governance'],
        ],
        'sla_breach' => [
            'controls' => ['5.22', '5.29', '5.30'],
            'domains' => ['resilience'],
        ],
        'unrevoked_access' => [
            'controls' => ['5.15', '5.18', '6.5', '8.2'],
            'domains' => ['access', 'security'],
        ],
        'missing_dpa' => [
            'controls' => ['5.34', '5.20'],
            'domains' => ['data_protection'],
        ],
    ];

    /**
     * Build a targeted assessment, or explain why not.
     *
     * @return array{assessment: Assessment|null, question_count: int, template_question_count: int, reason: string|null}
     */
    public function build(Engagement $engagement, MonitoringSignal $signal, ?int $userId = null): array
    {
        $template = $this->templateFor($engagement);

        if ($template === null) {
            return [
                'assessment' => null,
                'question_count' => 0,
                'template_question_count' => 0,
                'reason' => 'No published questionnaire template is available to draw questions from.',
            ];
        }

        $all = $this->questionsIn($template);
        $selected = $this->select($all, $signal->signal_type);

        if ($selected->isEmpty()) {
            return [
                'assessment' => null,
                'question_count' => 0,
                'template_question_count' => $all->count(),
                // The honest refusal. An empty questionnaire arriving in a
                // vendor's portal is worse than none.
                'reason' => sprintf(
                    'No question in "%s" maps to the controls a %s signal implicates, so there is nothing to '
                    .'ask. A full re-assessment would be the alternative, and that is a decision for a person '
                    .'rather than a rule.',
                    $template->name,
                    $signal->signal_type->label(),
                ),
            ];
        }

        $assessment = DB::transaction(function () use ($engagement, $template, $selected, $signal, $userId) {
            $assessment = Assessment::create([
                'organization_id' => $engagement->organization_id,
                'engagement_id' => $engagement->getKey(),
                'template_id' => $template->getKey(),
                'template_version' => $template->version,
                'assessment_type' => 'targeted',
                'cycle_label' => sprintf(
                    '%s targeted — %s',
                    now()->format('Y'),
                    $signal->signal_type->label(),
                ),
                'trigger_source' => 'monitoring_signal:'.$signal->getKey(),
                'status' => AssessmentStatus::Scoped->value,
                'due_at' => now()->addDays((int) config('tprm.defaults.targeted_assessment_days', 14)),
                // The trace says WHY each question is here, which is what the
                // vendor's contact will ask when a four-question
                // questionnaire arrives out of cycle.
                'scoping_trace' => [
                    'mode' => 'targeted',
                    'signal_type' => $signal->signal_type->value,
                    'signal_title' => $signal->title,
                    'implicated_controls' => self::IMPLICATED[$signal->signal_type->value]['controls'] ?? [],
                    'selected_question_codes' => $selected->pluck('code')->values()->all(),
                    'template_question_count' => $this->questionsIn($template)->count(),
                    'note' => 'Only the questions testing the controls this signal implicates. A full '
                        .'re-assessment is a separate decision.',
                ],
                'question_count' => $selected->count(),
                'applicable_count' => $selected->count(),
                'created_by' => $userId,
            ]);

            foreach ($selected as $question) {
                AssessmentResponse::create([
                    'organization_id' => $engagement->organization_id,
                    'assessment_id' => $assessment->getKey(),
                    'question_id' => $question->getKey(),
                ]);
            }

            return $assessment->refresh();
        });

        return [
            'assessment' => $assessment,
            'question_count' => $selected->count(),
            'template_question_count' => $all->count(),
            'reason' => null,
        ];
    }

    /**
     * The questions this signal implicates.
     *
     * Control mapping first, domain tag as the fallback. A tenant whose own
     * pack maps to CCM or NIST rather than ISO 27002 still gets a sensible
     * selection through the domain, rather than an empty one through a
     * framework they do not use.
     *
     * @param  \Illuminate\Support\Collection<int, Question>  $questions
     * @return \Illuminate\Support\Collection<int, Question>
     */
    public function select($questions, SignalType $type)
    {
        $map = self::IMPLICATED[$type->value] ?? null;

        if ($map === null) {
            return collect();
        }

        $byControl = $questions->filter(fn (Question $question) => $question->controlMaps
            ->contains(fn ($controlMap) => $controlMap->framework === 'iso27002'
                && in_array($controlMap->control_id, $map['controls'], true)));

        if ($byControl->isNotEmpty()) {
            return $byControl->values();
        }

        return $questions
            ->filter(fn (Question $question) => in_array(
                (string) $question->section?->domain_tag,
                $map['domains'],
                true,
            ))
            ->values();
    }

    /**
     * The template to draw from.
     *
     * The engagement's most recent scored assessment's template, so the
     * targeted questions are ones this vendor has answered before and the
     * comparison is like for like. Falling back to any published template
     * available to the tenant.
     */
    private function templateFor(Engagement $engagement): ?QuestionnaireTemplate
    {
        $previous = Assessment::query()
            ->where('engagement_id', $engagement->getKey())
            ->whereIn('status', ['validated', 'scored', 'closed'])
            ->orderByDesc('id')
            ->first();

        if ($previous !== null) {
            $template = QuestionnaireTemplate::query()
                ->withoutGlobalScopes()
                ->whereKey($previous->template_id)
                ->first();

            if ($template !== null) {
                return $template;
            }
        }

        return QuestionnaireTemplate::query()
            ->availableTo($engagement->organization_id)
            ->where('status', QuestionnaireTemplate::STATUS_PUBLISHED)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Question>
     */
    private function questionsIn(QuestionnaireTemplate $template)
    {
        return Question::query()
            ->whereIn('section_id', $template->sections()->select('id'))
            ->with(['controlMaps', 'section'])
            ->get();
    }
}
