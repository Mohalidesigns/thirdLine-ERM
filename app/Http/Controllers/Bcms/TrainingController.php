<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\AssessBcmsTrainingRecordRequest;
use App\Http\Requests\Bcms\StoreBcmsTrainingCurriculumRequest;
use App\Http\Requests\Bcms\StoreBcmsTrainingRecordRequest;
use App\Models\Bcms\TrainingRecord;
use App\Models\User;
use App\Services\Bcms\Training\TrainingComplianceService;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Training & competency — `docs/bcms/screens/training-compliance.md`.
 *
 * LIFECYCLE RULES ANSWER WITH A FLASH MESSAGE, NOT A 403 (development
 * standard §3). "You cannot assess your own competence" is exactly that kind
 * of rule, and it is caught here from the service rather than encoded as a
 * policy.
 */
class TrainingController extends Controller
{
    public function __construct(private TrainingComplianceService $training) {}

    public function compliance(Request $request): Response
    {
        Gate::authorize('bcms.training.view');

        $viewer = $request->user();
        $canManage = $viewer?->can('bcms.training.manage') ?? false;

        $curriculumId = $request->integer('curriculum_id') ?: null;
        $department = $request->string('department')->value() ?: null;
        $page = max(1, $request->integer('page') ?: 1);

        // B10: computed once. The full (viewer-scoped, B11) row set backs
        // both the paginated table and the summary tiles, so a page change
        // never recomputes the tiles and the tiles can never disagree with
        // the rows a page below it (NDPA register §11.4 rule 5).
        $allRows = $this->training->complianceRows($curriculumId, $department, $viewer);
        $register = $this->paginate($allRows, $page);
        $summary = $this->training->summaryTiles($allRows);
        $assignedCounts = collect($allRows)->countBy('curriculum_id');

        // B10 follow-up: the "heavy amber" (attended, not assessed at
        // volume) banner moved server-side, over the full scoped set —
        // `Compliance.jsx` used to compute it over `rows`, which B10 made
        // one page of the register.
        $attendedNotAssessed = $this->training->attendedNotAssessedByCurriculum($allRows);

        // TrainingRecord keys on a plain numeric `id` (no HasBcmsUuid) — the
        // URL is still built here, not from a bare `record_id` in the
        // screen, so a future uuid migration on this model cannot silently
        // 404 every "Assess now" action at once.
        $rows = array_map(function (array $row) use ($canManage) {
            $eligibleForAssessment = $canManage
                && $row['requires_assessment']
                && $row['record_id'] !== null
                && $row['competency'] !== null
                && ! $row['competency']['assessed'];

            $row['assess_url'] = $eligibleForAssessment
                ? route('bcms.training-records.assess', $row['record_id'])
                : null;

            return $row;
        }, $register['rows']);

        // B11 rule 4: the assessor/subject picker and the departments filter
        // must be scoped exactly like the register itself, or either leaks
        // every employee's name and department to a unit-scoped viewer who
        // cannot see most of their rows.
        $userScope = $this->scopedUsersQuery($viewer);

        return Inertia::render('Bcms/Training/Compliance', [
            'curricula' => $this->training->curricula()->map(function ($c) use ($assignedCounts, $attendedNotAssessed) {
                $heavyAmber = $attendedNotAssessed[$c->getKey()] ?? null;

                return [
                    'id' => $c->getKey(),
                    'code' => $c->code,
                    'name' => $c->name,
                    'description' => $c->description,
                    'target_roles' => $c->target_roles,
                    'assigned_count' => $assignedCounts->get($c->getKey(), 0),
                    'frequency_months' => $c->frequency_months,
                    'requires_assessment' => (bool) $c->requires_assessment,
                    'pass_mark' => $c->pass_mark,
                    'is_mandatory' => (bool) $c->is_mandatory,
                    'iso_clause_ref' => $c->iso_clause_ref,
                    // Computed server-side over the full viewer-scoped
                    // register (B10/B11 follow-up), never over one page.
                    'attended_not_assessed_count' => $heavyAmber['attended_not_assessed_count'] ?? 0,
                    'assessed_count' => $heavyAmber['assessed_count'] ?? 0,
                    'heavy_amber' => $heavyAmber['heavy_amber'] ?? false,
                ];
            })->values(),
            'rows' => $rows,
            'pagination' => [
                'current_page' => $register['current_page'],
                'last_page' => $register['last_page'],
                'per_page' => $register['per_page'],
                'total' => $register['total'],
            ],
            'summary' => $summary,
            'users' => (clone $userScope)->orderBy('name')->get(['id', 'name', 'department']),
            'departments' => (clone $userScope)->whereNotNull('department')
                ->distinct()
                ->orderBy('department')
                ->pluck('department'),
            'filters' => [
                'curriculum_id' => $curriculumId,
                'department' => $department,
            ],
            'can' => ['manage' => $canManage],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     */
    private function paginate(array $rows, int $page, int $perPage = 25): array
    {
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        return [
            'rows' => array_values(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'total' => $total, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => $lastPage,
        ];
    }

    /**
     * B11 rule 4: the same org-hierarchy scope as the register, applied to
     * `User::query()` for the assessor/subject picker and the departments
     * filter. A whole-estate viewer (or no viewer — a console context) gets
     * every active user; a unit-scoped viewer gets people in their own unit
     * subtree plus themselves — never a no-unit person, the same inverted
     * null arm `complianceRows()` applies (a no-unit person is not
     * organisation-level content the way a group plan is).
     *
     * @return Builder<User>
     */
    private function scopedUsersQuery(?User $viewer): Builder
    {
        $units = app(RcsaScope::class)->unitIdsFor($viewer);
        $query = User::query()->where('is_active', true);

        if ($units === null) {
            return $query;
        }

        return $query->where(function ($q) use ($units, $viewer) {
            if ($units !== []) {
                $q->orWhereIn('business_unit_id', $units);
            }

            if ($viewer !== null) {
                $q->orWhere('id', $viewer->getKey());
            }
        });
    }

    public function storeCurriculum(StoreBcmsTrainingCurriculumRequest $request): RedirectResponse
    {
        \App\Models\Bcms\TrainingCurriculum::query()->create($request->validated() + [
            'organization_id' => $request->user()->organization_id,
            'created_by' => $request->user()->getKey(),
        ]);

        return back()->with('success', 'Curriculum created.');
    }

    public function storeRecord(StoreBcmsTrainingRecordRequest $request): RedirectResponse
    {
        try {
            $this->training->recordOutcome($request->validated(), $request->user()->getKey());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Recorded. Thank you.');
    }

    public function assess(AssessBcmsTrainingRecordRequest $request, TrainingRecord $record): RedirectResponse
    {
        try {
            // B4: the assessor is the acting user — there is no assessor
            // picker. Whoever is submitting this form is the assessor.
            $this->training->assess(
                $record,
                (float) $request->input('score'),
                $request->user()->getKey(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Assessment recorded.');
    }
}
