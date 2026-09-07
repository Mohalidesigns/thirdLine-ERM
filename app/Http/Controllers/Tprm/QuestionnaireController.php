<?php

namespace App\Http\Controllers\Tprm;

use App\Exceptions\Tprm\UnmappedQuestionsException;
use App\Http\Controllers\Controller;
use App\Models\Tprm\Question;
use App\Models\Tprm\QuestionControlMap;
use App\Models\Tprm\QuestionnaireSection;
use App\Models\Tprm\QuestionnaireTemplate;
use App\Support\Tprm\FactRegistry;
use App\Support\Tprm\RuleEvaluator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * The Questionnaire Builder (TRD §11).
 *
 * Three things on this screen are the phase's requirements rather than
 * convenience:
 *
 *   THE CONTROL-MAPPING PANEL blocks publish and lists what is unmapped
 *   (FR-ASM-05). The observer enforces it; this surfaces it as a panel the
 *   author can work through rather than as an error they meet once and
 *   resent.
 *
 *   THE RULE BUILDER emits the Phase 0 DSL and previews it against a chosen
 *   sample engagement, so an author can see that "show this only for
 *   cross-border processors" actually matches the engagement they have in
 *   mind before a vendor is ever asked.
 *
 *   CLONING is how a tenant customises a shipped pack, because editing the
 *   pack in place would rewrite the questions behind every assessment ever
 *   answered against it, in every tenant.
 */
class QuestionnaireController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', QuestionnaireTemplate::class);

        $templates = QuestionnaireTemplate::query()
            ->availableTo($request->user()->organization_id)
            ->withCount('sections')
            ->orderBy('organization_id')
            ->orderBy('code')
            ->get()
            ->map(function (QuestionnaireTemplate $template) {
                $questions = Question::query()
                    ->whereIn('section_id', $template->sections()->pluck('id'));

                return [
                    'id' => $template->getKey(),
                    'code' => $template->code,
                    'name' => $template->name,
                    'version' => $template->version,
                    'status' => $template->status,
                    'is_system_pack' => $template->isSystemPack(),
                    'section_count' => $template->sections_count,
                    'question_count' => (clone $questions)->count(),
                    'unmapped_count' => (clone $questions)->whereDoesntHave('controlMaps')->count(),
                    // "16 of ~55" rather than "16".
                    'declared_question_count' => $template->declared_question_count,
                    'catalogue_status' => $template->catalogue_status,
                    'catalogue_note' => $template->catalogue_note,
                    'url' => route('tprm.templates.show', $template),
                ];
            });

        return Inertia::render('Tprm/Templates/Index', [
            'templates' => $templates,
            'can' => ['manage' => $request->user()->can('tprm.questionnaire.manage')],
        ]);
    }

    public function show(Request $request, QuestionnaireTemplate $template)
    {
        Gate::authorize('view', $template);

        $template->load(['sections.questions.controlMaps']);

        return Inertia::render('Tprm/Templates/Builder', [
            'template' => [
                'id' => $template->getKey(),
                'code' => $template->code,
                'name' => $template->name,
                'description' => $template->description,
                'version' => $template->version,
                'status' => $template->status,
                'is_system_pack' => $template->isSystemPack(),
                'editable' => ! $template->isSystemPack()
                    && $template->status === QuestionnaireTemplate::STATUS_DRAFT,
                'framework_tags' => $template->framework_tags,
                'applies_to' => $template->applies_to,
                'declared_question_count' => $template->declared_question_count,
                'catalogue_status' => $template->catalogue_status,
                'catalogue_note' => $template->catalogue_note,
            ],
            'sections' => $template->sections->map(fn (QuestionnaireSection $section) => [
                'id' => $section->getKey(),
                'code' => $section->code,
                'title' => $section->title,
                'domain_tag' => $section->domain_tag,
                'visibility_rule' => $section->visibility_rule,
                'questions' => $section->questions->map(fn (Question $question) => [
                    'id' => $question->getKey(),
                    'code' => $question->code,
                    'text' => $question->text,
                    'type' => $question->type,
                    'is_critical' => (bool) $question->is_critical,
                    'risk_weight' => $question->risk_weight,
                    'evidence_required' => (bool) $question->evidence_required,
                    'visibility_rule' => $question->visibility_rule,
                    'controls' => $question->controlMaps->map(fn (QuestionControlMap $map) => [
                        'id' => $map->getKey(),
                        'framework' => $map->framework,
                        'framework_version' => $map->framework_version,
                        'control_id' => $map->control_id,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            // FR-ASM-05's panel: what stands between this template and publish.
            'publishBlockers' => $template->unmappedQuestions()->map(fn (Question $q) => [
                'code' => $q->code,
                'text' => $q->text,
            ])->values(),
            'canPublish' => $template->canPublish(),
            'facts' => $this->factCatalogue(),
            'can' => [
                'edit' => $request->user()->can('update', $template),
                'clone' => $request->user()->can('clone', $template),
                'publish' => $request->user()->can('publish', $template),
            ],
        ]);
    }

    /**
     * Clone a template — the only way to customise a shipped pack.
     */
    public function clone(Request $request, QuestionnaireTemplate $template)
    {
        Gate::authorize('clone', $template);

        $copy = DB::transaction(function () use ($template, $request) {
            $new = QuestionnaireTemplate::create([
                'organization_id' => $request->user()->organization_id,
                'code' => Str::limit($template->code.'-COPY', 60, ''),
                'name' => $template->name.' (customised)',
                'description' => $template->description,
                'version' => '1.0',
                'status' => QuestionnaireTemplate::STATUS_DRAFT,
                'framework_tags' => $template->framework_tags,
                'applies_to' => $template->applies_to,
                'scoring_mode' => $template->scoring_mode,
                'parent_template_id' => $template->getKey(),
                // The clone is the tenant's own work and has no external
                // specification to be measured against, so it declares no
                // Appendix B size.
                'declared_question_count' => null,
                'catalogue_status' => null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($template->sections()->with('questions.controlMaps')->get() as $section) {
                $newSection = QuestionnaireSection::create(
                    array_merge($section->only([
                        'code', 'title', 'description', 'sort_order', 'weight', 'domain_tag', 'visibility_rule',
                    ]), ['template_id' => $new->getKey()])
                );

                foreach ($section->questions as $question) {
                    $newQuestion = Question::create(
                        array_merge($question->only([
                            'code', 'text', 'help_text', 'type', 'options', 'is_required', 'is_critical',
                            'weight', 'risk_weight', 'evidence_required', 'evidence_types',
                            'min_assurance_level', 'visibility_rule', 'scoring_map', 'auto_answer_rule', 'sort_order',
                        ]), ['section_id' => $newSection->getKey()])
                    );

                    // The mappings come with it, or the clone would be
                    // unpublishable the moment it is created.
                    foreach ($question->controlMaps as $map) {
                        QuestionControlMap::create(array_merge(
                            $map->only(['framework', 'framework_version', 'control_id', 'relationship', 'internal_control_id']),
                            ['question_id' => $newQuestion->getKey()]
                        ));
                    }
                }
            }

            return $new;
        });

        return redirect()
            ->route('tprm.templates.show', $copy)
            ->with('success', 'Cloned as a draft you can edit. The original is unchanged.');
    }

    public function publish(Request $request, QuestionnaireTemplate $template)
    {
        Gate::authorize('publish', $template);

        try {
            $template->update([
                'status' => QuestionnaireTemplate::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
        } catch (UnmappedQuestionsException $exception) {
            return back()
                ->with('error', $exception->getMessage())
                ->with('unmappedQuestions', $exception->details());
        }

        return back()->with('success', "{$template->code} is published and can now be issued.");
    }

    /**
     * Preview a visibility rule against a sample engagement.
     *
     * The rule builder's live panel. An author writing "cross-border
     * processors only" gets to see it match the engagement they have in mind
     * before a vendor is ever asked.
     */
    public function previewRule(Request $request)
    {
        Gate::authorize('viewAny', QuestionnaireTemplate::class);

        $validated = $request->validate([
            'rule' => ['nullable', 'array'],
            'engagement_id' => ['nullable', 'integer'],
        ]);

        $engagement = isset($validated['engagement_id'])
            ? \App\Models\Tprm\Engagement::find($validated['engagement_id'])
            : null;

        $context = $engagement === null
            ? []
            : app(\App\Services\Tprm\Scoring\EngagementContext::class)->build($engagement);

        $evaluator = new RuleEvaluator;
        $matches = $evaluator->evaluate($validated['rule'] ?? null, $context);

        return response()->json([
            'matches' => $matches,
            'unresolved_facts' => $evaluator->unresolvedFacts(),
            'facts_used' => FactRegistry::factsUsedBy($validated['rule'] ?? null),
            // Only the facts the rule actually names, so the panel can show
            // what the engagement said rather than dumping fifty attributes.
            'context' => collect(FactRegistry::factsUsedBy($validated['rule'] ?? null))
                ->mapWithKeys(fn (string $fact) => [$fact => $context[$fact] ?? null])
                ->all(),
        ]);
    }

    /**
     * The facts a rule may name, for the builder's dropdown.
     *
     * @return list<array{name: string, description: string}>
     */
    private function factCatalogue(): array
    {
        $described = FactRegistry::DERIVED;

        return collect(FactRegistry::names())
            ->map(fn (string $name) => [
                'name' => $name,
                'description' => $described[$name]
                    ?? ucfirst(str_replace(['engagement.', 'third_party.', '_'], ['', '', ' '], $name)),
            ])
            ->values()
            ->all();
    }
}
