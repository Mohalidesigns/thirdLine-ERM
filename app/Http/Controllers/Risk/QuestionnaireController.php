<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Questionnaires\AddQuestionRequest;
use App\Http\Requests\Questionnaires\AddSectionRequest;
use App\Http\Requests\Questionnaires\StoreLibraryQuestionRequest;
use App\Http\Requests\Questionnaires\StoreQuestionnaireRequest;
use App\Models\Question;
use App\Models\QuestionLibrary;
use App\Models\Questionnaire;
use App\Models\QuestionnaireSection;
use App\Presenters\GridPresenter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class QuestionnaireController extends Controller
{
    /**
     * The question types the builder offers, in the Blade page's order.
     *
     * `matrix` and `file_upload` are valid column values and are NOT offered:
     * neither the old builder nor the respond page has ever been able to render
     * one, so a questionnaire holding either would put a question in front of a
     * respondent with no way to answer it. AddQuestionRequest still accepts the
     * full enum, so a row created before this phase keeps working.
     *
     * @var array<string, string>
     */
    public const BUILDABLE_QUESTION_TYPES = [
        'likert' => 'Likert Scale',
        'rating' => 'Rating (1-5)',
        'yes_no' => 'Yes/No',
        'multiple_choice' => 'Multiple Choice',
        'free_text' => 'Free Text',
        'numeric' => 'Numeric',
    ];

    /**
     * WP-09: the register is the shared data grid — see
     * App\Grids\Definitions\QuestionnairesGrid.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', Questionnaire::class);

        $total = Questionnaire::where('organization_id', TenantContext::organizationId())->count();

        return Inertia::render('Questionnaires/Index', [
            'total' => $total,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('questionnaires'), $request, $request->user()),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', Questionnaire::class);

        return Inertia::render('Questionnaires/Create', [
            'types' => StoreQuestionnaireRequest::TYPES,
            'scoringMethods' => StoreQuestionnaireRequest::SCORING_METHODS,
        ]);
    }

    public function store(StoreQuestionnaireRequest $request)
    {
        $questionnaire = Questionnaire::create([
            ...$request->safe()->only(['title', 'description', 'questionnaire_type', 'scoring_method']),
            'organization_id' => auth()->user()->organization_id,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('risk.questionnaires.edit', $questionnaire)->with('success', 'Questionnaire created. Add sections and questions.');
    }

    public function show(Questionnaire $questionnaire)
    {
        Gate::authorize('view', $questionnaire);

        $questionnaire->load('sections.questions');

        return Inertia::render('Questionnaires/Show', [
            'questionnaire' => $this->summary($questionnaire),
            'sections' => $this->sectionsPayload($questionnaire),
        ]);
    }

    /**
     * The builder.
     *
     * WHAT IS NO LONGER HERE: `$library`. This method grouped the whole
     * question library by category and handed it to the view, and the view has
     * never referenced it — a dead query on every load of every builder page,
     * of the same family as 3.8's RCSA columns and 4.1's KRI reads. The library
     * has its own screen (risk.questionnaires.library, an Inertia grid since
     * Phase 2); pulling a question across from it is a feature this module does
     * not have, and inventing one is not this phase's job. Recorded in the
     * module notes.
     */
    public function edit(Request $request, Questionnaire $questionnaire)
    {
        Gate::authorize('update', $questionnaire);

        $questionnaire->load('sections.questions');

        return Inertia::render('Questionnaires/Edit', [
            'questionnaire' => $this->summary($questionnaire),
            'sections' => $this->sectionsPayload($questionnaire),
            // The Blade builder's type select offered six of the eight; the two
            // it left out — matrix and file_upload — have no renderer on the
            // respond page either, so the same six are offered here.
            'questionTypes' => self::BUILDABLE_QUESTION_TYPES,
            'canPublish' => $request->user()->can('publish', $questionnaire),
        ]);
    }

    public function addSection(AddSectionRequest $request, Questionnaire $questionnaire)
    {
        $maxOrder = $questionnaire->sections()->max('sort_order') ?? 0;

        QuestionnaireSection::create([
            'questionnaire_id' => $questionnaire->id,
            'title' => $request->title,
            'description' => $request->description,
            'sort_order' => $maxOrder + 1,
            'weight' => $request->weight ?? 1.00,
        ]);

        return back()->with('success', 'Section added.');
    }

    public function addQuestion(AddQuestionRequest $request, QuestionnaireSection $section)
    {
        // AddQuestionRequest::authorize() has already walked {section} up to
        // its questionnaire — the only tenant-scoped model in the chain — and
        // 404'd if it belongs to another bank. See the class comment there.
        $maxOrder = $section->questions()->max('sort_order') ?? 0;

        $options = null;
        if (in_array($request->question_type, ['multiple_choice', 'likert', 'rating'])) {
            $options = $request->options ?? $this->defaultOptionsForType($request->question_type);
        }

        Question::create([
            'section_id' => $section->id,
            'question_type' => $request->question_type,
            'question_text' => $request->question_text,
            'options' => $options,
            'scoring_rules' => $request->scoring_rules,
            'is_required' => $request->boolean('is_required', true),
            'sort_order' => $maxOrder + 1,
            'help_text' => $request->help_text,
            'weight' => $request->weight ?? 1.00,
        ]);

        return back()->with('success', 'Question added.');
    }

    /**
     * Delete a question.
     *
     * THIS ROUTE DELETED ACROSS TENANTS. `questions` carries no
     * organization_id, so the model is not scoped and route model binding
     * resolved any id in the table: a user holding questionnaire.edit in one
     * bank could destroy another bank's question, and a probe against HEAD
     * confirmed the row went. The question's section's questionnaire IS scoped,
     * so walking up to it is both the tenancy check and the lookup.
     */
    public function removeQuestion(Question $question)
    {
        Gate::authorize('update', $this->tenantQuestionnaireFor($question));

        $question->delete();

        return back()->with('success', 'Question removed.');
    }

    public function publish(Questionnaire $questionnaire)
    {
        Gate::authorize('publish', $questionnaire);

        if ($questionnaire->sections()->count() === 0) {
            return back()->with('error', 'Cannot publish a questionnaire with no sections.');
        }

        $questionnaire->update(['status' => 'published']);

        return back()->with('success', 'Questionnaire published.');
    }

    /**
     * WP-09: the library table is the shared data grid — see
     * App\Grids\Definitions\QuestionLibraryGrid, which also owns the
     * "mine or system-wide" scoping this method used to spell out.
     */
    public function library(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', Questionnaire::class);

        $organizationId = TenantContext::organizationId();

        $total = QuestionLibrary::where(fn ($q) => $q
            ->where('question_library.organization_id', $organizationId)
            ->orWhereNull('question_library.organization_id'))
            ->count();

        return Inertia::render('Questionnaires/Library', [
            'total' => $total,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('question_library'), $request, $request->user()),
        ]);
    }

    public function storeLibraryQuestion(StoreLibraryQuestionRequest $request)
    {
        QuestionLibrary::create([
            ...$request->safe()->only(['category', 'question_text', 'question_type', 'default_options', 'tags']),
            'organization_id' => auth()->user()->organization_id,
        ]);

        return back()->with('success', 'Question added to library.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Questionnaire $questionnaire): array
    {
        return [
            'id' => $questionnaire->id,
            'title' => $questionnaire->title,
            'description' => $questionnaire->description,
            'type' => $questionnaire->questionnaire_type,
            'scoringMethod' => $questionnaire->scoring_method,
            'version' => (int) $questionnaire->version,
            'status' => $questionnaire->status,
            'editUrl' => route('risk.questionnaires.edit', $questionnaire),
            'publishUrl' => route('risk.questionnaires.publish', $questionnaire),
            'addSectionUrl' => route('risk.questionnaires.add-section', $questionnaire),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sectionsPayload(Questionnaire $questionnaire): array
    {
        return $questionnaire->sections->map(fn (QuestionnaireSection $section) => [
            'id' => $section->id,
            'title' => $section->title,
            'description' => $section->description,
            'weight' => (float) $section->weight,
            'addQuestionUrl' => route('risk.questionnaires.add-question', $section),
            'questions' => $section->questions->map(fn (Question $question) => [
                'id' => $question->id,
                'text' => $question->question_text,
                'type' => $question->question_type,
                'isRequired' => (bool) $question->is_required,
                'helpText' => $question->help_text,
                'removeUrl' => route('risk.questionnaires.remove-question', $question),
            ])->all(),
        ])->all();
    }

    /**
     * The question's questionnaire, or 404 — see the note on removeQuestion().
     */
    private function tenantQuestionnaireFor(Question $question): Questionnaire
    {
        $questionnaire = $question->section()->first()?->questionnaire()->first();

        abort_if($questionnaire === null, 404);

        return $questionnaire;
    }

    private function defaultOptionsForType(string $type): array
    {
        return match ($type) {
            'likert' => [
                ['label' => 'Strongly Disagree', 'value' => 1],
                ['label' => 'Disagree', 'value' => 2],
                ['label' => 'Neutral', 'value' => 3],
                ['label' => 'Agree', 'value' => 4],
                ['label' => 'Strongly Agree', 'value' => 5],
            ],
            'rating' => [
                ['label' => '1 - Very Low', 'value' => 1],
                ['label' => '2 - Low', 'value' => 2],
                ['label' => '3 - Medium', 'value' => 3],
                ['label' => '4 - High', 'value' => 4],
                ['label' => '5 - Very High', 'value' => 5],
            ],
            default => [],
        };
    }
}
