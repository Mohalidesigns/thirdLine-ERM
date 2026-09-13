<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\Process;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
use App\Services\Bcms\Exercises\LadderAdvisor;
use App\Services\Bcms\Exercises\OccurrenceGenerator;
use App\Services\Bcms\Exercises\ProgrammeAdvisor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The annual exercise programme — clause 8.5's mandatory record.
 *
 * NOT THE SAME SCREEN AS PHASE 1'S PROGRAMME GOVERNANCE, and the phase prompt
 * is explicit that they must not share a route or a component. `bcms_programmes`
 * is the BCMS itself — scope, objectives, policy, management review.
 * `bcms_exercise_programmes` is one year of testing. Merging them would produce
 * a screen answering two unrelated questions badly.
 */
class ExerciseProgrammeController extends Controller
{
    public function __construct(
        private ExerciseProgrammeService $programmes,
        private LadderAdvisor $ladder,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.exercise.view');

        $programmes = ExerciseProgramme::query()
            ->with(['approver:id,name'])
            ->withCount('definitions')
            ->orderByDesc('year')
            ->get();

        return Inertia::render('Bcms/Exercises/Programmes', [
            'programmes' => $programmes->map(fn (ExerciseProgramme $p) => [
                'id' => $p->getKey(),
                'uuid' => $p->uuid,
                'year' => (int) $p->year,
                'name' => $p->name,
                'status' => $p->status,
                'approver' => $p->approver?->name,
                'approved_at' => $p->approved_at?->toDateString(),
                'definition_count' => (int) $p->getAttribute('definitions_count'),
                'summary' => $this->programmes->summary($p),
            ])->all(),
            'can' => [
                'manage' => $request->user()?->can('bcms.exercise.manage') === true,
                'approve' => $request->user()?->can('bcms.exercise.approve') === true,
            ],
        ]);
    }

    public function show(Request $request, ExerciseProgramme $programme, ProgrammeAdvisor $advisor): Response
    {
        Gate::authorize('bcms.exercise.view');

        $definitions = $programme->definitions()
            ->with(['exerciseType:id,code,name,ladder_level,cadence_clause_ref', 'businessUnit:id,name', 'owner:id,name'])
            ->withCount('occurrences')
            ->orderBy('name')
            ->get();

        return Inertia::render('Bcms/Exercises/Programme', [
            'programme' => [
                'id' => $programme->getKey(),
                'uuid' => $programme->uuid,
                'year' => (int) $programme->year,
                'name' => $programme->name,
                'status' => $programme->status,
                'approver' => $programme->approver?->name,
                'approved_at' => $programme->approved_at?->toDateString(),
                'total_planned' => (int) $programme->total_planned,
                'total_completed' => (int) $programme->total_completed,
            ],
            'summary' => $this->programmes->summary($programme),
            'definitions' => $definitions->map(fn ($d) => [
                'id' => $d->getKey(),
                'uuid' => $d->uuid,
                'name' => $d->name,
                'type' => $d->exerciseType?->name,
                'ladder_level' => $d->exerciseType?->ladder_level?->value,
                'ladder_label' => $d->exerciseType?->ladder_level?->label(),
                'business_unit' => $d->businessUnit?->name,
                'owner' => $d->owner?->name,
                'frequency_per_year' => (int) $d->frequency_per_year,
                'distribution_mode' => $d->distribution_mode,
                'occurrence_count' => (int) $d->getAttribute('occurrences_count'),
                'mandatory' => (bool) $d->mandatory,
                'unannounced' => (bool) $d->unannounced,
                'status' => $d->status,
                'generation_log' => $d->generation_log,
                'regulatory_drivers' => $d->regulatory_drivers ?? [],
            ])->all(),
            // The dashboard's centrepiece: which activities have been proven at
            // which rung, and which have never been.
            'coverage' => $this->ladder->coverageMatrix(
                Process::query()->where('status', 'active')->whereNotNull('criticality_tier')
                    ->where('criticality_tier', '<=', 2)->get()
            ),
            'advisor' => [
                'gaps' => $advisor->gaps($programme),
                'available' => $advisor->available($programme),
                'reason' => $advisor->unavailableReason($programme),
            ],
            'exercise_types' => ExerciseType::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name', 'ladder_level', 'default_frequency_per_year', 'default_duration_minutes', 'default_lead_time_days', 'cadence_clause_ref'])
                ->map(fn (ExerciseType $t) => [
                    'id' => $t->id, 'code' => $t->code, 'name' => $t->name,
                    'ladder_level' => $t->ladder_level?->value,
                    'ladder_label' => $t->ladder_level?->label(),
                    'default_frequency_per_year' => (int) $t->default_frequency_per_year,
                    'default_duration_minutes' => (int) $t->default_duration_minutes,
                    'default_lead_time_days' => (int) $t->default_lead_time_days,
                    'cadence_clause_ref' => $t->cadence_clause_ref,
                ])->all(),
            'can' => [
                'manage' => $request->user()?->can('bcms.exercise.manage') === true,
                'approve' => $request->user()?->can('bcms.exercise.approve') === true,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('bcms.exercise.manage');

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'name' => ['required', 'string', 'max:200'],
        ]);

        $programme = $this->programmes->create($data['year'], $data['name'], [], $request->user()?->getKey());

        return redirect()
            ->route('bcms.exercise-programmes.show', $programme)
            ->with('success', 'Programme created. Add the exercises, then generate the year.');
    }

    public function approve(Request $request, ExerciseProgramme $programme): RedirectResponse
    {
        Gate::authorize('bcms.exercise.approve');

        try {
            $this->programmes->approve($programme, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            'Programme approved. The planned count is now the commitment the year is measured against.'
        );
    }

    /** Generate every active definition in one go. */
    public function generate(Request $request, ExerciseProgramme $programme, OccurrenceGenerator $generator): RedirectResponse
    {
        Gate::authorize('bcms.exercise.manage');

        $result = $this->programmes->generateAll($programme, $generator, $request->user()?->getKey());

        return back()->with('success', sprintf(
            '%d definitions generated: %d occurrences placed, %d shifted around a conflict, %d could not be placed '
            .'and are waiting to be scheduled.',
            $result['definitions'], $result['placed'], $result['shifted'], $result['needs_scheduling'],
        ));
    }

    /** Ask the advisor to propose a programme. */
    public function advise(Request $request, ExerciseProgramme $programme, ProgrammeAdvisor $advisor): RedirectResponse
    {
        Gate::authorize('bcms.exercise.manage');

        $result = $advisor->advise($programme);

        if (! $result['ok']) {
            return back()->with('error', $result['reason']);
        }

        return back()
            ->with('proposals', $result['proposals'])
            ->with('success', count($result['proposals']).' exercises proposed. Nothing has been created — review '
                .'them and add the ones you want.');
    }
}
