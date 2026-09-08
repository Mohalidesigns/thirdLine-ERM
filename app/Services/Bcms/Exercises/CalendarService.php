<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\LadderLevel;
use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\BlackoutPeriod;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\Process;
use App\Models\User;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The resilience calendar, in the six shapes different people need it.
 *
 * SIX VIEWS OF ONE QUERY, NOT SIX QUERIES. Every view starts from the same
 * filtered occurrence set and reshapes it in PHP. That is what makes the NFR
 * reachable — the year grid has to render 500+ occurrences in under 1.5
 * seconds — and, more importantly, it is what stops the month view and the
 * compliance view disagreeing about whether an exercise happened.
 *
 * THE YEAR GRID IS A BOARD ARTEFACT AND IS BUILT AS ONE. Its question is not
 * "what are we doing on the 14th" but "are we clustering all our drills into
 * Q4", which is answered by density per month and nothing else. It therefore
 * selects four columns and aggregates, rather than hydrating a year of models
 * to count them.
 *
 * SCOPING IS THE SAME SCOPING AS EVERYWHERE ELSE. `RcsaScope` through the
 * definition's business unit, with organisation-level definitions visible to
 * everybody — a group-wide crisis simulation is something every branch needs to
 * see on its calendar (`ScopedToOrgHierarchy`'s null arm, applied here through
 * a relation).
 */
class CalendarService
{
    public function __construct(
        private readonly RcsaScope $scope,
        private readonly WorkingCalendar $workingCalendar,
    ) {}

    /**
     * The year heat grid: density by month, coloured by status.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function year(int $year, ?User $user, array $filters = []): array
    {
        $rows = $this->baseQuery($user, $filters)
            ->whereYear('bcms_exercise_occurrences.scheduled_date', $year)
            ->get([
                'bcms_exercise_occurrences.id',
                'bcms_exercise_occurrences.scheduled_date',
                'bcms_exercise_occurrences.status',
            ]);

        // COUNTED SEPARATELY, because `whereYear` on a NULL date matches
        // nothing — so an unplaced occurrence is invisible to the query above
        // by construction. It is exactly the row the year grid must not hide:
        // `needs_scheduling` exists so that a gap stays visible, and a grid
        // that only counted dated occurrences would defeat the status.
        $unscheduled = (int) $this->baseQuery($user, $filters)
            ->whereNull('bcms_exercise_occurrences.scheduled_date')
            ->count();

        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = [
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('M'),
                'total' => 0,
                'by_status' => [],
            ];
        }

        foreach ($rows as $row) {
            $month = (int) $row->scheduled_date->format('n');
            $status = $row->status->value;

            $months[$month]['total']++;
            $months[$month]['by_status'][$status] = ($months[$month]['by_status'][$status] ?? 0) + 1;
        }

        $totals = [];

        foreach ($rows as $row) {
            $totals[$row->status->value] = ($totals[$row->status->value] ?? 0) + 1;
        }

        return [
            'year' => $year,
            'months' => array_values($months),
            'totals' => $totals,
            'count' => $rows->count() + $unscheduled,
            'unscheduled' => $unscheduled,
            'peak_month' => $this->peakMonth($months),
            'blackouts' => $this->blackoutSummary($year),
        ];
    }

    /**
     * Month, week or agenda: the same rows, a different range.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function range(Carbon $from, Carbon $to, ?User $user, array $filters = []): array
    {
        $occurrences = $this->baseQuery($user, $filters)
            ->whereBetween('bcms_exercise_occurrences.scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->with([
                'definition:id,uuid,name,exercise_type_id,business_unit_id,owner_id,mandatory,unannounced,regulatory_drivers',
                'definition.exerciseType:id,code,name,ladder_level',
                'definition.businessUnit:id,name',
                'definition.owner:id,name',
                'site:id,name,code',
                'facilitator:id,name',
            ])
            ->orderBy('bcms_exercise_occurrences.scheduled_date')
            ->orderBy('bcms_exercise_occurrences.scheduled_start')
            ->get(['bcms_exercise_occurrences.*'])
            ->all();

        return array_map(fn (ExerciseOccurrence $o) => $this->present($o), $occurrences);
    }

    /**
     * Everything with no date at all.
     *
     * A view of its own because `needs_scheduling` occurrences are invisible on
     * every date-ranged view by definition, and an obligation nobody can see is
     * one nobody places.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function unscheduled(?User $user, array $filters = []): array
    {
        $occurrences = $this->baseQuery($user, $filters)
            ->whereNull('bcms_exercise_occurrences.scheduled_date')
            ->with(['definition.exerciseType:id,code,name,ladder_level', 'definition.businessUnit:id,name'])
            ->orderBy('bcms_exercise_occurrences.definition_id')
            ->orderBy('bcms_exercise_occurrences.sequence_no')
            ->get(['bcms_exercise_occurrences.*'])
            ->all();

        return array_map(fn (ExerciseOccurrence $o) => $this->present($o), $occurrences);
    }

    /**
     * The Gantt view: definitions as bars, ordered up the ladder.
     *
     * SEQUENCING IS SHOWN, NOT ENFORCED. The bars are ordered by ladder rank so
     * that a functional exercise sitting before its tabletop is visible as a
     * bar that starts too early — which is a picture somebody can argue with,
     * and better than a validation error that stops them recording what they
     * are actually doing.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function gantt(int $year, ?User $user, array $filters = []): array
    {
        $definitions = ExerciseDefinition::query()
            ->whereHas('exerciseProgramme', fn ($q) => $q->where('year', $year))
            ->when($user !== null, fn ($q) => $this->applyScope($q, $user, 'business_unit_id'))
            ->with(['exerciseType:id,code,name,ladder_level', 'businessUnit:id,name', 'owner:id,name'])
            ->withCount('occurrences')
            ->get();

        $rows = [];

        foreach ($definitions as $definition) {
            $dates = $definition->occurrences()
                ->whereNotNull('scheduled_date')
                ->orderBy('scheduled_date')
                ->pluck('scheduled_date');

            $level = $definition->exerciseType?->ladder_level;

            $rows[] = [
                'definition_id' => $definition->getKey(),
                'definition_uuid' => $definition->uuid,
                'name' => $definition->name,
                'type' => $definition->exerciseType?->name,
                'ladder_level' => $level?->value,
                'ladder_label' => $level?->label(),
                'ladder_rank' => $level?->rank() ?? 0,
                'business_unit' => $definition->businessUnit?->name,
                'owner' => $definition->owner?->name,
                'frequency_per_year' => (int) $definition->frequency_per_year,
                'occurrence_count' => (int) $definition->occurrences_count,
                'first_date' => $dates->first()?->toDateString(),
                'last_date' => $dates->last()?->toDateString(),
                'dates' => $dates->map(fn ($d) => $d->toDateString())->all(),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['ladder_rank'], $a['first_date'] ?? '9999']
            <=> [$b['ladder_rank'], $b['first_date'] ?? '9999']);

        return $rows;
    }

    /**
     * The compliance view: "CBN open banking — 4 required, 3 completed, 1 overdue".
     *
     * GROUPED BY THE DRIVER, NOT BY THE EXERCISE. A regulator asks whether the
     * cadence was met, and the cadence belongs to the obligation: four failover
     * exercises a year is a rule about failover, however many definitions the
     * bank chooses to satisfy it with.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function compliance(int $year, ?User $user, array $filters = []): array
    {
        $definitions = ExerciseDefinition::query()
            ->whereHas('exerciseProgramme', fn ($q) => $q->where('year', $year))
            ->when($user !== null, fn ($q) => $this->applyScope($q, $user, 'business_unit_id'))
            ->with(['exerciseType:id,code,name,cadence_clause_ref', 'occurrences:id,definition_id,status,scheduled_date'])
            ->get();

        $groups = [];

        foreach ($definitions as $definition) {
            $drivers = $definition->regulatory_drivers;
            $drivers = is_array($drivers) && $drivers !== []
                ? $drivers
                : array_filter([$definition->exerciseType?->cadence_clause_ref]);

            if ($drivers === []) {
                // Exercises with no regulatory driver are not compliance
                // evidence and are grouped as such, rather than being dropped
                // — a compliance view that hides the discretionary half of the
                // programme makes the programme look smaller than it is.
                $drivers = ['__none__'];
            }

            foreach ($drivers as $driver) {
                $driver = (string) $driver;

                $groups[$driver] ??= [
                    'driver' => $driver === '__none__' ? null : $driver,
                    'label' => $driver === '__none__' ? 'No regulatory driver' : $driver,
                    'required' => 0,
                    'planned' => 0,
                    'completed' => 0,
                    'overdue' => 0,
                    'definitions' => [],
                ];

                $groups[$driver]['required'] += (int) $definition->frequency_per_year;
                $groups[$driver]['definitions'][] = $definition->name;

                foreach ($definition->occurrences as $occurrence) {
                    if ($occurrence->status === OccurrenceStatus::Completed) {
                        $groups[$driver]['completed']++;

                        continue;
                    }

                    if ($this->isOverdue($occurrence)) {
                        $groups[$driver]['overdue']++;

                        continue;
                    }

                    if ($occurrence->status->isOpen()) {
                        $groups[$driver]['planned']++;
                    }
                }
            }
        }

        $rows = array_values($groups);

        foreach ($rows as &$row) {
            $row['definitions'] = array_values(array_unique($row['definitions']));
            $row['shortfall'] = max(0, $row['required'] - $row['completed'] - $row['planned']);
            // Null when nothing is required, never 100%. A driver with no
            // obligation has not been perfectly satisfied.
            $row['percentage'] = $row['required'] === 0
                ? null
                : round(($row['completed'] / $row['required']) * 100, 1);
        }

        usort($rows, fn (array $a, array $b) => [$b['overdue'], $b['required']] <=> [$a['overdue'], $a['required']]);

        return $rows;
    }

    /**
     * One person's calendar — what they are facilitating, running or attending.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, Carbon $from, Carbon $to): array
    {
        $occurrences = ExerciseOccurrence::query()
            ->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->where(function (Builder $q) use ($user) {
                $q->where('facilitator_id', $user->getKey())
                    ->orWhereHas('participants', fn ($p) => $p->where('user_id', $user->getKey()))
                    ->orWhereHas('definition', fn ($d) => $d->where('owner_id', $user->getKey())
                        ->orWhere('facilitator_id', $user->getKey()));
            })
            ->with([
                'definition:id,uuid,name,exercise_type_id,unannounced,mandatory',
                'definition.exerciseType:id,code,name,ladder_level',
                'site:id,name,code',
            ])
            ->orderBy('scheduled_date')
            ->get()
            ->all();

        return array_map(fn (ExerciseOccurrence $o) => $this->present($o), $occurrences);
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ExerciseOccurrence>
     */
    private function baseQuery(?User $user, array $filters): Builder
    {
        $query = ExerciseOccurrence::query()
            ->join('bcms_exercise_definitions as d', 'd.id', '=', 'bcms_exercise_occurrences.definition_id')
            ->whereNull('d.deleted_at');

        if ($user !== null) {
            $units = $this->scope->unitIdsFor($user);

            if ($units !== null) {
                // Organisation-level definitions are visible to everybody: a
                // group crisis simulation belongs on every branch's calendar.
                $query->where(function (Builder $q) use ($units) {
                    $q->whereNull('d.business_unit_id');

                    if ($units !== []) {
                        $q->orWhereIn('d.business_unit_id', $units);
                    }
                });
            }
        }

        foreach ([
            'business_unit_id' => 'd.business_unit_id',
            'exercise_type_id' => 'd.exercise_type_id',
            'owner_id' => 'd.owner_id',
        ] as $key => $column) {
            if (filled($filters[$key] ?? null)) {
                $query->where($column, $filters[$key]);
            }
        }

        if (filled($filters['site_id'] ?? null)) {
            $query->where('bcms_exercise_occurrences.site_id', $filters['site_id']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('bcms_exercise_occurrences.status', $filters['status']);
        }

        if (filled($filters['ladder_level'] ?? null)) {
            $query->whereExists(fn ($q) => $q->select('id')->from('bcms_exercise_types')
                ->whereColumn('bcms_exercise_types.id', 'd.exercise_type_id')
                ->where('bcms_exercise_types.ladder_level', $filters['ladder_level']));
        }

        if (filled($filters['regulatory_driver'] ?? null)) {
            $query->whereJsonContains('d.regulatory_drivers', $filters['regulatory_driver']);
        }

        if (filled($filters['criticality_tier'] ?? null)) {
            // Exercises that test a process at or above this tier.
            //
            // THE IDS ARE RESOLVED IN PHP AND MATCHED WITH `whereJsonContains`,
            // not with a raw `JSON_CONTAINS`. That function exists on MariaDB
            // and not on SQLite, so a raw call would pass every test and fail
            // on the only database a customer runs — the exact defect the port
            // checklist warns about. Laravel's builder spells it correctly for
            // both. The id list is the tier-N process catalogue, which is tens
            // of rows, not thousands.
            $processIds = Process::query()
                ->where('criticality_tier', '<=', (int) $filters['criticality_tier'])
                ->pluck('id')
                ->all();

            $query->where(function (Builder $q) use ($processIds) {
                // No matching process is not "no filter" — it is a filter that
                // matches nothing, which is what the user asked for.
                $q->whereRaw('1 = 0');

                foreach ($processIds as $id) {
                    $q->orWhereJsonContains('d.process_ids', (int) $id);
                }
            });
        }

        return $query;
    }

    /**
     * @param  Builder<ExerciseDefinition>  $query
     * @return Builder<ExerciseDefinition>
     */
    private function applyScope(Builder $query, User $user, string $column): Builder
    {
        $units = $this->scope->unitIdsFor($user);

        if ($units === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($units, $column) {
            $q->whereNull($column);

            if ($units !== []) {
                $q->orWhereIn($column, $units);
            }
        });
    }

    /** @return array<string, mixed> */
    private function present(ExerciseOccurrence $occurrence): array
    {
        $definition = $occurrence->definition;
        $level = $definition?->exerciseType?->ladder_level;

        return [
            'id' => $occurrence->getKey(),
            'uuid' => $occurrence->uuid,
            'sequence_no' => (int) $occurrence->sequence_no,
            'title' => $definition?->name,
            'definition_id' => $occurrence->definition_id,
            'definition_uuid' => $definition?->uuid,
            'type' => $definition?->exerciseType?->name,
            'type_code' => $definition?->exerciseType?->code,
            'ladder_level' => $level?->value,
            'ladder_label' => $level?->label(),
            'scheduled_date' => $occurrence->scheduled_date?->toDateString(),
            'scheduled_start' => $occurrence->scheduled_start?->toIso8601String(),
            'scheduled_end' => $occurrence->scheduled_end?->toIso8601String(),
            'status' => $occurrence->status->value,
            'is_overdue' => $this->isOverdue($occurrence),
            'site' => $occurrence->site?->name,
            'location' => $occurrence->location,
            'business_unit' => $definition?->businessUnit?->name,
            'owner' => $definition?->owner?->name,
            'facilitator' => $occurrence->facilitator?->name,
            // `$definition !== null &&` rather than `?->… ??`: a belongsTo on
            // a nullable key is genuinely nullable at runtime and typed
            // non-null by static analysis, and the complaint that follows a
            // `??` is how somebody eventually deletes the null check.
            'mandatory' => $definition !== null && (bool) $definition->mandatory,
            'unannounced' => $definition !== null && (bool) $definition->unannounced,
            'reschedule_count' => (int) $occurrence->reschedule_count,
            'originally_scheduled_date' => $occurrence->originally_scheduled_date?->toDateString(),
            'readiness_complete' => (bool) $occurrence->readiness_complete,
            'blocking_tasks_open' => (int) $occurrence->blocking_tasks_open,
            'regulatory_drivers' => $definition === null ? [] : ($definition->regulatory_drivers ?? []),
        ];
    }

    /**
     * Past its date, still open, and nobody has run it.
     *
     * DERIVED, NOT STORED. `missed` is a status the scheduler writes once the
     * day is properly over; "overdue" is what the calendar shows in between,
     * and computing it means the screen is right at the moment it is read
     * rather than at the moment a job last ran.
     */
    private function isOverdue(ExerciseOccurrence $occurrence): bool
    {
        if ($occurrence->status === OccurrenceStatus::Missed) {
            return true;
        }

        return $occurrence->status->isOpen()
            && $occurrence->scheduled_date !== null
            && $occurrence->scheduled_date->lt(now()->startOfDay());
    }

    /** @param array<int, array<string, mixed>> $months @return ?array<string, mixed> */
    private function peakMonth(array $months): ?array
    {
        $peak = null;

        foreach ($months as $month) {
            if ($peak === null || $month['total'] > $peak['total']) {
                $peak = $month;
            }
        }

        return ($peak === null || $peak['total'] === 0) ? null : $peak;
    }

    /** @return array<string, mixed> */
    private function blackoutSummary(int $year): array
    {
        $calendar = $this->workingCalendar->build(
            $year,
            BlackoutPeriod::query()->where('is_active', true)->get(),
        );

        return [
            'working_days' => $calendar->count(),
            'blocked_days' => count($calendar->blocked),
            'unresolved' => $calendar->unresolved,
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function ladderOptions(): array
    {
        return array_map(fn (LadderLevel $l) => ['value' => $l->value, 'label' => $l->label()], LadderLevel::cases());
    }
}
