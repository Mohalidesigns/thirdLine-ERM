<?php

namespace App\Presenters\Bcms;

use App\Enums\Bcms\LadderLevel;
use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\Site;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Services\Bcms\Exercises\CalendarService;
use App\Services\Bcms\Exercises\IcsFeedBuilder;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Support\Carbon;

/**
 * The resilience calendar screen.
 *
 * THE VIEW IS CHOSEN ON THE SERVER AND ONLY THAT VIEW'S DATA IS SENT. The year
 * grid needs twelve aggregates; the month needs thirty rows; the Gantt needs
 * definitions. Sending all six shapes on every request would put a year of
 * occurrences through the wire to render a heat map of twelve numbers, and the
 * NFR is 500+ occurrences in under 1.5 seconds.
 *
 * THE STATUS COLOURS ARE DECLARED HERE, ONCE. Planned navy, confirmed green,
 * in progress gold, missed red, cancelled grey — the phase prompt fixes them,
 * three screens use them, and a palette repeated in three JSX files is a
 * palette that drifts.
 */
class ResilienceCalendarPresenter
{
    /** Blueprint §5.3's palette. */
    public const STATUS_COLOURS = [
        'planned' => '#1A365D',
        'needs_scheduling' => '#7C3AED',
        'confirmed' => '#2D7D46',
        'in_progress' => '#D4AF37',
        'completed' => '#2D7D46',
        'missed' => '#B91C1C',
        'cancelled' => '#9CA3AF',
        'deferred' => '#C2410C',
    ];

    public function __construct(
        private CalendarService $calendar,
        private IcsFeedBuilder $ics,
        private RcsaScope $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function present(string $view, int $year, ?Carbon $from, ?Carbon $to, ?User $user, array $filters): array
    {
        $from ??= Carbon::create($year, 1, 1)->startOfDay();
        $to ??= Carbon::create($year, 12, 31)->endOfDay();

        $payload = match ($view) {
            'year' => ['year_grid' => $this->calendar->year($year, $user, $filters)],
            'gantt' => ['gantt' => $this->calendar->gantt($year, $user, $filters)],
            'compliance' => ['compliance' => $this->calendar->compliance($year, $user, $filters)],
            'mine' => ['occurrences' => $user === null ? [] : $this->calendar->forUser($user, $from, $to)],
            'unscheduled' => ['occurrences' => $this->calendar->unscheduled($user, $filters)],
            // month, week and agenda are one query over three ranges; the
            // client decides how to draw them.
            default => ['occurrences' => $this->calendar->range($from, $to, $user, $filters)],
        };

        return $payload + [
            'view' => $view,
            'year' => $year,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'filters' => $filters,
            'options' => $this->options($user),
            'status_colours' => self::STATUS_COLOURS,
            'ics_url' => $user === null ? null : $this->ics->urlFor($user),
            'scope_note' => $this->scope->describe($user),
            'can' => [
                'manage' => $user?->can('bcms.exercise.manage') === true,
                'schedule' => $user?->can('bcms.exercise.schedule') === true,
                'approve' => $user?->can('bcms.exercise.approve') === true,
                'admin' => $user?->can('bcms.admin') === true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function options(?User $user): array
    {
        return [
            'programmes' => ExerciseProgramme::query()
                ->orderByDesc('year')
                ->get(['id', 'uuid', 'year', 'name', 'status'])
                ->all(),
            'types' => ExerciseType::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'ladder_level'])
                ->map(fn (ExerciseType $t) => [
                    'id' => $t->id,
                    'code' => $t->code,
                    'name' => $t->name,
                    'ladder_level' => $t->ladder_level?->value,
                ])->all(),
            'business_units' => BusinessUnit::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'statuses' => array_map(fn (OccurrenceStatus $s) => [
                'value' => $s->value,
                'label' => ucfirst(str_replace('_', ' ', $s->value)),
            ], OccurrenceStatus::cases()),
            'ladder_levels' => CalendarService::ladderOptions(),
        ];
    }

    /** @return list<string> */
    public static function views(): array
    {
        return ['year', 'month', 'week', 'agenda', 'gantt', 'compliance', 'mine', 'unscheduled'];
    }

    /** @return list<array{value: string, label: string}> */
    public static function ladderLegend(): array
    {
        return array_map(fn (LadderLevel $l) => ['value' => $l->value, 'label' => $l->label()], LadderLevel::cases());
    }
}
