<?php

namespace App\Console\Commands;

use App\Services\Workflow\WorkflowEngine;
use Illuminate\Console\Command;

/**
 * WP-06 TASK 2 — the SLA sweeper.
 *
 * Runs hourly. Marks breaches, escalates, honours each node's on_timeout, and
 * releases timer nodes whose wait has elapsed.
 *
 * Hourly rather than nightly because an SLA measured in hours cannot be
 * enforced by a job that runs once a day: a loss event with a 24-hour level-1
 * decision inside a 7-day CBN reporting window would be escalated up to a day
 * late, which is a day of the regulatory clock spent on the wrong desk.
 */
class SweepWorkflowSlas extends Command
{
    protected $signature = 'workflow:sweep-slas {--dry-run : Report what would happen without changing anything}';

    protected $description = 'Escalate overdue workflow tasks, honour timeouts and release elapsed timers';

    public function handle(WorkflowEngine $engine): int
    {
        if ($this->option('dry-run')) {
            return $this->report();
        }

        $counts = $engine->sweep();

        $this->info(sprintf(
            '%d breached, %d escalated, %d decided automatically, %d timers released.',
            $counts['breached'],
            $counts['escalated'],
            $counts['auto_decided'],
            $counts['timers_released'],
        ));

        return self::SUCCESS;
    }

    private function report(): int
    {
        $due = \App\Models\WorkflowTask::withoutGlobalScopes()
            ->whereIn('status', \App\Enums\WorkflowTaskStatus::openValues())
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->with('instance.definition')
            ->orderBy('due_at')
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nothing is overdue.');

            return self::SUCCESS;
        }

        $this->table(
            ['Task', 'Workflow', 'Step', 'Assignee', 'Due', 'Hours over'],
            $due->map(fn ($task) => [
                $task->id,
                $task->instance?->definition?->code ?? '—',
                $task->node_code,
                $task->assignee_id ?? ('role: '.($task->assignee_role ?? 'unassigned')),
                optional($task->due_at)->format('d M Y H:i'),
                $task->hoursOverdue() ?? 0,
            ])->all()
        );

        return self::SUCCESS;
    }
}
