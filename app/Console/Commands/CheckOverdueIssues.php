<?php

namespace App\Console\Commands;

use App\Events\IssueOverdue;
use App\Models\Issue;
use Illuminate\Console\Command;

class CheckOverdueIssues extends Command
{
    protected $signature = 'issues:check-overdue';

    protected $description = 'Check for overdue issues and dispatch escalation events';

    public function handle(): int
    {
        $this->info('Checking for overdue issues...');

        // Find all open issues that have passed their due date
        $overdueIssues = Issue::where('status', '!=', 'closed')
            ->where('due_date', '<', now())
            ->get();

        if ($overdueIssues->isEmpty()) {
            $this->info('No overdue issues found.');
            return self::SUCCESS;
        }

        foreach ($overdueIssues as $issue) {
            $daysOverdue = now()->diffInDays($issue->due_date);

            // Dispatch the event
            IssueOverdue::dispatch($issue, $daysOverdue);

            $this->line("Issue {$issue->issue_reference} is {$daysOverdue} days overdue.");
        }

        $this->info("Checked {$overdueIssues->count()} overdue issues.");

        return self::SUCCESS;
    }
}
