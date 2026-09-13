<?php

namespace App\Presenters\Bcms;

use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\User;
use App\Services\Bcms\Notification\ChannelRegistry;
use App\Support\Bcms\ModuleSections;
use Illuminate\Support\Carbon;

/**
 * What the BCMS home screen shows, shaped for the page.
 *
 * A PRESENTER RATHER THAN A CONTROLLER METHOD, per development standard §1:
 * `Inertia::render` takes the result, it does not compute it. The reason
 * recorded there is that logic in a controller is logic only an HTTP test can
 * reach, and every figure here is one somebody will eventually put on a board
 * pack.
 *
 * EVERY COUNT IS A COUNT AND EVERY RATE IS NULL UNTIL IT HAS A DENOMINATOR.
 * `plans_current_rate` is null on an empty plan register, not 100 and not 0,
 * and the page prints "No plans on record" in its place. An empty register has
 * no currency rate; saying so is the whole of development standard §5.
 *
 * IT ALSO REPORTS WHICH NOTIFICATION CHANNELS ARE STILL MOCKS. A crisis manager
 * who believes an SMS went out because a tick appeared, when the Phase 0 mock
 * recorded it and dispatched nothing, is the worst failure this module could
 * have — so the state is on the first screen, not buried in settings.
 */
class BcmsHomePresenter
{
    public function __construct(private ChannelRegistry $channels) {}

    /** @return array<string, mixed> */
    public function present(?User $user): array
    {
        $planTotal = Plan::query()->where('status', 'approved')->count();
        $planCurrent = Plan::query()
            ->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('next_review_date')->orWhere('next_review_date', '>=', now()->toDateString()))
            ->count();

        $occurrencesThisYear = ExerciseOccurrence::query()
            ->whereYear('scheduled_date', now()->year);

        return [
            'programme' => $this->programme(),
            'counts' => [
                'processes' => Process::query()->count(),
                'critical_processes' => Process::query()->where('is_critical_service', true)->count(),
                'plans_approved' => $planTotal,
                'occurrences_planned' => (clone $occurrencesThisYear)->count(),
                'occurrences_completed' => (clone $occurrencesThisYear)->where('status', 'completed')->count(),
                'actions_open' => CorrectiveAction::query()->whereIn('status', ['open', 'in_progress', 'overdue'])->count(),
                'actions_overdue' => CorrectiveAction::query()
                    ->whereIn('status', ['open', 'in_progress', 'overdue'])
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', now()->toDateString())
                    ->count(),
            ],
            // Null, not zero, when there is nothing to divide by.
            'plans_current_rate' => $planTotal === 0 ? null : round($planCurrent / $planTotal * 100, 1),
            'upcoming' => $this->upcoming(),
            'sections' => $this->sections($user),
            'mock_channels' => $this->mockChannels(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function programme(): ?array
    {
        $programme = Programme::query()
            ->where('year', now()->year)
            ->orderByDesc('id')
            ->first();

        if ($programme === null) {
            return null;
        }

        return [
            'name' => $programme->name,
            'year' => $programme->year,
            'status' => $programme->status,
            'scope_statement' => $programme->scope_statement,
            'board_attested_at' => $programme->board_attested_at?->toDateString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function upcoming(): array
    {
        return ExerciseOccurrence::query()
            ->with('definition:id,name')
            ->whereNotNull('scheduled_date')
            ->whereBetween('scheduled_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->orderBy('scheduled_date')
            ->limit(10)
            ->get()
            ->map(fn (ExerciseOccurrence $occurrence) => [
                'uuid' => $occurrence->uuid,
                'name' => $occurrence->definition?->name,
                'scheduled_date' => Carbon::parse($occurrence->scheduled_date)->toDateString(),
                'status' => $occurrence->status->value ?? $occurrence->getRawOriginal('status'),
                'readiness_complete' => (bool) $occurrence->readiness_complete,
                'blocking_tasks_open' => (int) $occurrence->blocking_tasks_open,
            ])
            ->all();
    }

    /**
     * The sub-modules this user may see, from the same registry the routes and
     * the navigation are built from.
     *
     * @return list<array<string, mixed>>
     */
    private function sections(?User $user): array
    {
        return array_values(array_filter(
            ModuleSections::all(),
            fn (array $section) => $user?->can($section['permission']) === true
        ));
    }

    /** @return list<string> */
    private function mockChannels(): array
    {
        $mocks = [];

        foreach ($this->channels->all() as $key => $channel) {
            if (str_starts_with($channel->provider(), 'mock-')) {
                $mocks[] = $key;
            }
        }

        return $mocks;
    }
}
