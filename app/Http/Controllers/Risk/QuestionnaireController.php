<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionLibrary;
use App\Models\Questionnaire;
use App\Models\QuestionnaireSection;
use Illuminate\Http\Request;

class QuestionnaireController extends Controller
{
    public function index()
    {
        $orgId = auth()->user()->organization_id;
        $questionnaires = Questionnaire::where('organization_id', $orgId)
            ->with('creator')
            ->withCount('sections')
            ->latest()
            ->paginate(20);

        return view('risk.questionnaires.index', compact('questionnaires'));
    }

    public function create()
    {
        return view('risk.questionnaires.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'questionnaire_type' => 'required',
            'scoring_method' => 'required|in:average,weighted,highest,sum',
        ]);

        $questionnaire = Questionnaire::create([
            'organization_id' => auth()->user()->organization_id,
            'title' => $request->title,
            'description' => $request->description,
            'questionnaire_type' => $request->questionnaire_type,
            'scoring_method' => $request->scoring_method,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('risk.questionnaires.edit', $questionnaire)->with('success', 'Questionnaire created. Add sections and questions.');
    }

    public function show(Questionnaire $questionnaire)
    {
        $questionnaire->load('sections.questions');

        return view('risk.questionnaires.show', compact('questionnaire'));
    }

    public function edit(Questionnaire $questionnaire)
    {
        $questionnaire->load('sections.questions');
        $orgId = auth()->user()->organization_id;
        $library = QuestionLibrary::where(function ($q) use ($orgId) {
            $q->where('organization_id', $orgId)->orWhere('is_global', true);
        })->orderBy('category')->get()->groupBy('category');

        return view('risk.questionnaires.edit', compact('questionnaire', 'library'));
    }

    public function addSection(Request $request, Questionnaire $questionnaire)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'weight' => 'nullable|numeric|min:0|max:100',
        ]);

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

    public function addQuestion(Request $request, QuestionnaireSection $section)
    {
        $request->validate([
            'question_text' => 'required|string',
            'question_type' => 'required|in:multiple_choice,likert,yes_no,free_text,numeric,file_upload,matrix,rating',
        ]);

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

    public function removeQuestion(Question $question)
    {
        $question->delete();

        return back()->with('success', 'Question removed.');
    }

    public function publish(Questionnaire $questionnaire)
    {
        if ($questionnaire->sections()->count() === 0) {
            return back()->with('error', 'Cannot publish a questionnaire with no sections.');
        }

        $questionnaire->update(['status' => 'published']);

        return back()->with('success', 'Questionnaire published.');
    }

    public function library()
    {
        $orgId = auth()->user()->organization_id;
        $library = QuestionLibrary::where(function ($q) use ($orgId) {
            $q->where('organization_id', $orgId)->orWhere('is_global', true);
        })->orderBy('category')->paginate(50);

        return view('risk.questionnaires.library', compact('library'));
    }

    public function storeLibraryQuestion(Request $request)
    {
        $request->validate([
            'category' => 'required|string|max:100',
            'question_text' => 'required|string',
            'question_type' => 'required',
        ]);

        QuestionLibrary::create([
            'organization_id' => auth()->user()->organization_id,
            'category' => $request->category,
            'question_text' => $request->question_text,
            'question_type' => $request->question_type,
            'default_options' => $request->default_options,
            'tags' => $request->tags,
        ]);

        return back()->with('success', 'Question added to library.');
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
