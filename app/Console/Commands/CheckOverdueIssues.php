<?php

namespace App\Console\Commands;

use App\Events\IssueOverdue;
use App\Models\Issue;
use App\Models\Organization;
use App\Services\IssueEscalationService;
use Illuminate\Console\Command;

class CheckOverdueIssues extends Command
{
    protected $signature = 'issues:check-overdue';

    protected $description = 'Check for overdue issues, dispatch escalation events and apply escalation rules';

    public function handle(IssueEscalationService $escalationService): int
    {
        $this->info('Checking for overdue issues...');

        // Find all open issues that have passed their target resolution date
        $overdueIssues = Issue::whereNotIn('issue_status', ['CLOSED', 'PENDING_CLOSURE'])
            ->whereNotNull('remediation_due_date')
            ->whereDate('remediation_due_date', '<', now())
            ->get();

        if ($overdueIssues->isEmpty()) {
            $this->info('No overdue issues found.');

            return self::SUCCESS;
        }

        foreach ($overdueIssues as $issue) {
            $daysOverdue = (int) now()->diffInDays($issue->remediation_due_date, true);

            // Dispatch the event
            IssueOverdue::dispatch($issue, $daysOverdue);

            $this->line("Issue {$issue->issue_reference} is {$daysOverdue} days overdue.");
        }

        // Apply organisation-level escalation rules
        foreach (Organization::where('is_active', true)->pluck('id') as $orgId) {
            $escalated = $escalationService->escalateOverdueIssues($orgId);
            if ($escalated > 0) {
                $this->info("Escalated {$escalated} issue(s) for organization #{$orgId}.");
            }
        }

        $this->info("Checked {$overdueIssues->count()} overdue issues.");

        return self::SUCCESS;
    }
}
