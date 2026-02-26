<?php
namespace App\Services;

use App\Models\Issue;
use App\Models\IssueEscalationRule;
use App\Models\IssueEscalationLog;

class IssueEscalationService
{
    /**
     * Check all open issues and escalate overdue ones
     * Called by a scheduled job
     */
    public function escalateOverdueIssues(int $organizationId): int
    {
        $escalatedCount = 0;
        
        $overdueIssues = Issue::where('organization_id', $organizationId)
            ->whereIn('issue_status', ['OPEN', 'IN_PROGRESS', 'OVERDUE'])
            ->whereNotNull('target_resolution_date')
            ->whereDate('target_resolution_date', '<', now())
            ->get();

        foreach ($overdueIssues as $issue) {
            // Update status to OVERDUE if not already
            if ($issue->issue_status !== 'OVERDUE') {
                $issue->update(['issue_status' => 'OVERDUE']);
            }

            $daysOverdue = now()->diffInDays($issue->target_resolution_date);
            
            // Get applicable escalation rules
            $rules = IssueEscalationRule::where('organization_id', $organizationId)
                ->where('priority', $issue->priority)
                ->where(function ($q) use ($issue) {
                    $q->whereNull('issue_source')
                      ->orWhere('issue_source', $issue->issue_source);
                })
                ->where('is_active', true)
                ->where('days_overdue_trigger', '<=', $daysOverdue)
                ->orderByDesc('escalation_level')
                ->first();
            
            if ($rules && $rules->escalation_level > ($issue->current_escalation_level ?? 0)) {
                // Apply CBN examination accelerated escalation
                $effectiveLevel = $issue->cbn_examination_finding 
                    ? min($rules->escalation_level + 1, 5) 
                    : $rules->escalation_level;
                
                IssueEscalationLog::create([
                    'issue_id' => $issue->id,
                    'escalation_level' => $effectiveLevel,
                    'escalated_to_role' => $rules->escalation_to_role,
                    'is_auto' => true,
                    'reason' => "Auto-escalated: {$daysOverdue} days overdue (threshold: {$rules->days_overdue_trigger} days)",
                    'escalated_at' => now(),
                ]);
                
                $issue->update([
                    'current_escalation_level' => $effectiveLevel,
                    'issue_status' => 'ESCALATED',
                ]);
                
                $escalatedCount++;
            }
        }
        
        return $escalatedCount;
    }
}
