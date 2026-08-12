<?php

namespace App\Services;

use App\Models\Control;
use App\Models\KeyRiskIndicator;
use App\Models\MeasureBreach;
use App\Models\Risk;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WorkflowTask;
use App\Services\Workflow\TaskQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * WP-08 TASK 5 — everything one person owes the risk process, in one list.
 *
 * THE HARDEST PROBLEM IN ERM SOFTWARE IS FIRST-LINE PARTICIPATION. A branch
 * manager does not open a risk register; they open "what do I owe today".
 * This service computes that from data that already exists — workflow tasks,
 * ownership columns, breach rows, due dates — and never invents an item: an
 * empty queue means the person owes nothing, and saying so is the feature.
 *
 * Every item carries:
 *   due_at    when it is owed (null = no deadline, listed last)
 *   bucket    overdue | today | this_week | later — computed here so every
 *             surface (page, digest email, chat card) groups identically
 *   minutes   an honest EFFORT ESTIMATE from the static table below — a
 *             product decision about UI copy ("~3 min"), not a measurement,
 *             and labelled as an estimate wherever it renders
 *   url       the deep link that completes the item
 */
class MyResponsibilitiesService
{
    /**
     * Effort estimates per item type, in minutes. Static and deliberate: the
     * point of the label is to tell a first-line user "this is small enough
     * to do now", and the numbers are the product's promise about how long
     * each inline flow takes, reviewed when the flows change.
     */
    private const MINUTES = [
        'task' => 3,
        'treatment_overdue' => 3,
        'risk_review_due' => 5,
        'control_test_due' => 5,
        'kri_reading_due' => 2,
        'breach' => 3,
        'delegation_in' => 3,
        'delegation_out' => 1,
    ];

    public function __construct(private readonly TaskQueryService $tasks) {}

    /**
     * @return array{
     *   sections: array<string, Collection<int, array<string, mixed>>>,
     *   buckets: array<string, list<array<string, mixed>>>,
     *   total_items: int,
     *   total_minutes: int,
     * }
     */
    public function for(User $user): array
    {
        $today = CarbonImmutable::today();

        $sections = [
            'tasks' => $this->workflowTasks($user),
            'owned' => $this->ownedObjectsNeedingAction($user, $today),
            'breaches' => $this->breachesOnOwnedMeasures($user),
            'delegations' => $this->delegations($user),
        ];

        $all = collect($sections)->flatMap(fn (Collection $items) => $items);

        $buckets = ['overdue' => [], 'today' => [], 'this_week' => [], 'later' => []];

        foreach ($all as $item) {
            $buckets[$item['bucket']][] = $item;
        }

        foreach ($buckets as $key => $items) {
            usort($items, fn (array $a, array $b) => ($a['due_at'] ?? '9999') <=> ($b['due_at'] ?? '9999'));
            $buckets[$key] = $items;
        }

        return [
            'sections' => $sections,
            'buckets' => $buckets,
            'total_items' => $all->count(),
            'total_minutes' => (int) $all->sum('minutes'),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Sections */
    /* ------------------------------------------------------------------ */

    /** Approvals, reviews and sign-offs the workflow engine has put on my list. */
    private function workflowTasks(User $user): Collection
    {
        return $this->tasks->openFor($user, 50)->map(function (WorkflowTask $task) {
            $subject = $task->instance?->entity();

            return $this->item(
                type: 'task',
                key: 'task-'.$task->id,
                title: $task->node_name ?: 'Decision required',
                subtitle: $this->subjectLabel($subject) ?? $task->instance?->definition?->name,
                dueAt: $task->due_at?->toIso8601String(),
                url: route('risk.my-tasks.show', $task),
                badge: $task->isOverdue() ? 'SLA breached' : null,
            );
        });
    }

    /** Objects I own that need an action, from due-date columns that exist. */
    private function ownedObjectsNeedingAction(User $user, CarbonImmutable $today): Collection
    {
        $items = collect();

        TreatmentPlan::query()
            ->where('owner_id', $user->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('target_date')
            ->whereDate('target_date', '<', $today)
            ->orderBy('target_date')
            ->limit(25)
            ->get()
            ->each(function (TreatmentPlan $plan) use (&$items) {
                $items->push($this->item(
                    type: 'treatment_overdue',
                    key: 'treatment-'.$plan->id,
                    title: 'Overdue treatment: '.$plan->action_title,
                    subtitle: 'Progress '.(int) $plan->progress_pct.'%',
                    dueAt: $plan->target_date?->toIso8601String(),
                    url: route('risk.treatments.show', $plan),
                ));
            });

        Risk::query()
            ->where('risk_owner_id', $user->id)
            ->where('status', 'active')
            ->where(function ($q) use ($today) {
                $q->whereNull('last_assessment_date')
                    ->orWhereDate('next_review_date', '<=', $today);
            })
            ->orderByRaw('next_review_date is null, next_review_date')
            ->limit(25)
            ->get()
            ->each(function (Risk $risk) use (&$items) {
                $items->push($this->item(
                    type: 'risk_review_due',
                    key: 'risk-'.$risk->id,
                    title: ($risk->last_assessment_date === null ? 'Unassessed risk: ' : 'Review due: ').$risk->title,
                    subtitle: $risk->risk_code,
                    dueAt: $risk->next_review_date?->toIso8601String(),
                    url: route('risk.register.show', $risk),
                ));
            });

        Control::query()
            ->where('owner_id', $user->id)
            ->where('status', 'active')
            ->whereNotNull('next_test_due')
            ->whereDate('next_test_due', '<=', $today->addDays(14))
            ->orderBy('next_test_due')
            ->limit(25)
            ->get()
            ->each(function (Control $control) use (&$items) {
                $items->push($this->item(
                    type: 'control_test_due',
                    key: 'control-'.$control->id,
                    title: 'Control test due: '.$control->name,
                    subtitle: $control->control_code,
                    dueAt: $control->next_test_due?->toIso8601String(),
                    url: route('risk.controls.show', $control),
                ));
            });

        KeyRiskIndicator::query()
            ->where('owner_id', $user->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (KeyRiskIndicator $kri) => $this->kriReadingDue($kri, $today))
            ->take(25)
            ->each(function (KeyRiskIndicator $kri) use (&$items) {
                $items->push($this->item(
                    type: 'kri_reading_due',
                    key: 'kri-'.$kri->id,
                    title: 'KRI reading due: '.$kri->name,
                    subtitle: $kri->kri_code.' · '.$kri->measurement_frequency,
                    dueAt: $this->kriDueDate($kri)?->toIso8601String(),
                    url: route('risk.kri.show', $kri),
                ));
            });

        return $items;
    }

    /** Open breaches on measures I own, or on objects I own. */
    private function breachesOnOwnedMeasures(User $user): Collection
    {
        return MeasureBreach::query()
            ->where('status', 'open')
            ->where(function ($q) use ($user) {
                $q->whereIn('measure_id', \App\Models\Measure::query()->where('owner_id', $user->id)->select('id'))
                    ->orWhereIn('object_id', \App\Models\GraphObject::query()->toBase()->where('owner_id', $user->id)->select('id'));
            })
            ->with('measure')
            ->orderByDesc('breached_at')
            ->limit(25)
            ->get()
            ->map(fn (MeasureBreach $breach) => $this->item(
                type: 'breach',
                key: 'breach-'.$breach->id,
                title: 'Threshold breach: '.($breach->measure?->name ?? 'measure'),
                subtitle: ($breach->band_from ?: '—').' → '.($breach->band_to ?: '—'),
                dueAt: $breach->breached_at?->toIso8601String(),
                url: route('risk.kri.breaches'),
                badge: strtoupper((string) $breach->severity),
            ));
    }

    /** Tasks somebody handed me, and tasks I handed away (still open). */
    private function delegations(User $user): Collection
    {
        $open = WorkflowTask::query()->open();

        $in = (clone $open)->where('delegated_to', $user->id)->limit(15)->get()
            ->map(fn (WorkflowTask $t) => $this->item(
                type: 'delegation_in',
                key: 'deleg-in-'.$t->id,
                title: 'Delegated to you: '.($t->node_name ?: 'task'),
                subtitle: 'From '.($t->delegatedFrom?->name ?? 'colleague'),
                dueAt: $t->due_at?->toIso8601String(),
                url: route('risk.my-tasks.show', $t),
            ));

        $out = (clone $open)->where('delegated_from', $user->id)->limit(15)->get()
            ->map(fn (WorkflowTask $t) => $this->item(
                type: 'delegation_out',
                key: 'deleg-out-'.$t->id,
                title: 'You delegated: '.($t->node_name ?: 'task'),
                subtitle: 'To '.($t->delegatedTo?->name ?? 'colleague'),
                dueAt: $t->due_at?->toIso8601String(),
                url: route('risk.my-tasks.show', $t),
            ));

        return $in->concat($out);
    }

    /* ------------------------------------------------------------------ */
    /*  Item plumbing */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function item(
        string $type,
        string $key,
        string $title,
        ?string $subtitle,
        ?string $dueAt,
        string $url,
        ?string $badge = null,
    ): array {
        return [
            'type' => $type,
            'key' => $key,
            'title' => $title,
            'subtitle' => $subtitle,
            'due_at' => $dueAt,
            'bucket' => $this->bucket($dueAt),
            'minutes' => self::MINUTES[$type] ?? 3,
            'url' => $url,
            'badge' => $badge,
        ];
    }

    private function bucket(?string $dueAt): string
    {
        if ($dueAt === null) {
            return 'later';
        }

        $due = CarbonImmutable::parse($dueAt);
        $today = CarbonImmutable::today();

        return match (true) {
            $due->lt($today) => 'overdue',
            $due->lt($today->addDay()) => 'today',
            $due->lt($today->addWeek()) => 'this_week',
            default => 'later',
        };
    }

    private function kriReadingDue(KeyRiskIndicator $kri, CarbonImmutable $today): bool
    {
        $due = $this->kriDueDate($kri);

        return $due !== null && $due->lte($today);
    }

    private function kriDueDate(KeyRiskIndicator $kri): ?CarbonImmutable
    {
        $last = $kri->last_measurement_at;

        if ($last === null) {
            // Never measured: due since creation.
            return $kri->created_at === null ? null : CarbonImmutable::parse($kri->created_at);
        }

        $days = match (strtolower((string) $kri->measurement_frequency)) {
            'daily' => 1,
            'weekly' => 7,
            'biweekly', 'fortnightly' => 14,
            'monthly' => 30,
            'quarterly' => 91,
            'semi_annual', 'semiannual' => 182,
            'annual', 'annually', 'yearly' => 365,
            default => 30,
        };

        return CarbonImmutable::parse($last)->addDays($days);
    }

    private function subjectLabel(mixed $subject): ?string
    {
        if (! is_object($subject)) {
            return null;
        }

        foreach (['title', 'name', 'reference', 'code'] as $attribute) {
            $value = $subject->{$attribute} ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
