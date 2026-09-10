<?php

namespace App\Http\Controllers\Tprm\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tprm\MaturityAssessment;
use App\Models\Tprm\MaturityScore;
use App\Models\User;
use App\Services\Tprm\Reporting\MaturityService;
use App\Support\Tprm\MaturityModel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use RuntimeException;

/**
 * Programme maturity — FR-RPT-06.
 *
 * SCORING IS `tprm.admin`, NOT A REPORT PERMISSION. A maturity self-assessment
 * is the risk function's statement about its own programme, and the number it
 * records is the one a regulator is shown when it asks how mature the
 * institution's third-party management is. Whoever can run a register export
 * should not be able to raise it.
 *
 * READING IS `tprm.report.view`, because the point of the assessment is that
 * the business units it describes can see it.
 */
class MaturityController extends Controller
{
    public function __construct(private readonly MaturityService $maturity) {}

    public function index(Request $request)
    {
        Gate::authorize('tprm.report.view');

        $assessments = MaturityAssessment::query()
            ->with(['assessor:id,name', 'approver:id,name'])
            ->withCount(['scores as scored_count' => fn ($query) => $query->whereNotNull('current_level')])
            ->withCount('scores')
            ->orderByDesc('as_at')
            ->get();

        return Inertia::render('Tprm/Reports/Maturity', [
            'assessments' => $assessments->map(fn (MaturityAssessment $assessment) => [
                'uuid' => $assessment->uuid,
                'period_label' => $assessment->period_label,
                'as_at' => $assessment->as_at->toDateString(),
                'status' => $assessment->status,
                'framework_version' => $assessment->framework_version,
                // `withCount` aliases are runtime attributes with no declared
                // type, so they are read through getAttribute and cast here
                // rather than left as undeclared properties.
                'scored' => (int) $assessment->getAttribute('scored_count'),
                'categories' => (int) $assessment->getAttribute('scores_count'),
                'assessed_by' => $assessment->assessor?->name,
                'approved_by' => $assessment->approver?->name,
                'approved_at' => $assessment->approved_at?->toDayDateTimeString(),
                'url' => route('tprm.reports.maturity.show', $assessment),
            ]),
            'trend' => $this->maturity->trend(),
            'levels' => MaturityModel::LEVELS,
            'can' => [
                'assess' => $request->user()->can('tprm.admin'),
            ],
        ]);
    }

    public function show(Request $request, MaturityAssessment $maturityAssessment)
    {
        Gate::authorize('tprm.report.view');

        $maturityAssessment->load(['scores.owner:id,name', 'assessor:id,name', 'approver:id,name']);

        $criteria = collect(MaturityModel::vrmmmCategories())->keyBy('code');

        return Inertia::render('Tprm/Reports/MaturityAssessment', [
            'assessment' => [
                'uuid' => $maturityAssessment->uuid,
                'period_label' => $maturityAssessment->period_label,
                'as_at' => $maturityAssessment->as_at->toDateString(),
                'status' => $maturityAssessment->status,
                'framework_version' => $maturityAssessment->framework_version,
                'summary' => $maturityAssessment->summary,
                'editable' => $maturityAssessment->isEditable(),
                'assessed_by' => $maturityAssessment->assessor?->name,
                'approved_by' => $maturityAssessment->approver?->name,
                'approved_at' => $maturityAssessment->approved_at?->toDayDateTimeString(),
            ],
            'scores' => $maturityAssessment->scores->map(fn (MaturityScore $score) => [
                'id' => $score->getKey(),
                'framework' => $score->framework,
                'category_code' => $score->category_code,
                'category_name' => $score->category_name,
                // Our own criterion for the VRMMM categories; the CSF
                // subcategory titles are the framework's own words and are
                // already in `category_name`.
                'criterion' => $criteria[$score->category_code]['criterion'] ?? null,
                'current_level' => $score->current_level,
                'current_label' => $score->currentLabel(),
                'target_level' => $score->target_level,
                'target_label' => $score->targetLabel(),
                'gap' => $score->gap(),
                'evidence' => $score->evidence,
                'gap_actions' => $score->gap_actions,
                'owner_id' => $score->owner_id,
                'owner' => $score->owner?->name,
                'target_date' => $score->target_date?->toDateString(),
            ]),
            'gapPlan' => $this->maturity->gapPlan($maturityAssessment),
            'levels' => MaturityModel::LEVELS,
            'owners' => User::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => $user->getKey(), 'label' => $user->name]),
            'can' => [
                'assess' => $request->user()->can('tprm.admin'),
            ],
        ]);
    }

    public function open(Request $request)
    {
        Gate::authorize('tprm.admin');

        $validated = $request->validate([
            'period_label' => ['required', 'string', 'max:40'],
            'as_at' => ['required', 'date', 'before_or_equal:today'],
        ]);

        try {
            $assessment = $this->maturity->open(
                $validated['period_label'],
                CarbonImmutable::parse($validated['as_at']),
                $request->user()->id,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('tprm.reports.maturity.show', $assessment)
            ->with('success', "The {$assessment->period_label} maturity assessment is open.");
    }

    public function score(Request $request, MaturityScore $maturityScore)
    {
        Gate::authorize('tprm.admin');

        $validated = $request->validate([
            // Nullable on purpose: clearing a score returns a category to
            // "not assessed", which is a legitimate correction.
            'current_level' => ['nullable', 'integer', 'min:0', 'max:5'],
            'target_level' => ['nullable', 'integer', 'min:0', 'max:5'],
            'evidence' => ['nullable', 'string', 'max:5000'],
            'gap_actions' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'target_date' => ['nullable', 'date'],
        ]);

        try {
            $this->maturity->score($maturityScore, $validated, $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $maturityScore->category_code.' updated.');
    }

    public function approve(Request $request, MaturityAssessment $maturityAssessment)
    {
        Gate::authorize('tprm.admin');

        try {
            $this->maturity->approve($maturityAssessment, $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'The assessment is approved. Its scores are now frozen and on the trend.');
    }
}
