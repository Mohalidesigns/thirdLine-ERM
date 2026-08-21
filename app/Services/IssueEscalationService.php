<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\IssueEscalationLog;
use App\Models\IssueEscalationRule;
use App\Models\User;
use Spatie\Permission\Models\Role;

class IssueEscalationService
{
    /** The ladder stops here however many rungs a tenant configures. */
    private const MAX_LEVEL = 5;

    /**
     * Check all open issues and escalate overdue ones
     * Called by a scheduled job
     */
    public function escalateOverdueIssues(int $organizationId): int
    {
        $escalatedCount = 0;

        // 'ESCALATED' IS ADMITTED HERE AS A REPAIR, NOT AS A STATE WE WRITE.
        //
        // The previous version stamped `issue_status = 'ESCALATED'` when it
        // escalated, while this query only admitted OPEN / IN_PROGRESS /
        // OVERDUE. An issue therefore removed itself from its own working set
        // the first time it escalated and could never be reconsidered, so
        // current_escalation_level froze on rung one for ever — the seeded
        // ladder has two rungs for CRITICAL and three for HIGH, and nothing
        // ever reached them.
        //
        // 'ESCALATED' is also not a status the rest of the platform knows:
        // ObjectTypeRegistry's issue state machine, IssueController's
        // transition map, the issues grid and BoardPackAssembler all speak
        // OPEN / IN_PROGRESS / OVERDUE / PENDING_CLOSURE / CLOSED. A row parked
        // in 'ESCALATED' fell out of the board pack and could not be moved on
        // by hand either, because $allowedTransitions has no key for it.
        //
        // So the fix is in two parts: this service no longer writes the status
        // (an escalated issue is an OVERDUE issue at escalation level N, which
        // is what current_escalation_level and issue_escalation_log are for),
        // and 'ESCALATED' is admitted here so the rows the broken version left
        // stranded are picked back up and normalised to OVERDUE below.
        //
        // Admitting them does not re-fire a rung every night: the rule chosen
        // below is the highest-level rule whose days_overdue_trigger has been
        // passed, and the guard requires it to beat the level already recorded.
        // Night two with the same day count picks the same rule and fails
        // `1 > 1`; only crossing the next trigger picks a higher rule.
        $overdueIssues = Issue::where('organization_id', $organizationId)
            ->whereIn('issue_status', ['OPEN', 'IN_PROGRESS', 'OVERDUE', 'ESCALATED'])
            ->whereNotNull('remediation_due_date')
            ->whereDate('remediation_due_date', '<', now())
            ->get();

        foreach ($overdueIssues as $issue) {
            // Update status to OVERDUE if not already. This is also what
            // recovers a row stranded in the old 'ESCALATED' status.
            if ($issue->issue_status !== 'OVERDUE') {
                $issue->update(['issue_status' => 'OVERDUE']);
            }

            // Carbon 3 defaults $absolute to FALSE, so this returns a NEGATIVE
            // float for a past due date. Without the flag the comparison below
            // read `7 <= -20.4` and matched no rule at all: on the installed
            // Carbon nothing escalated to any level, ever.
            $daysOverdue = (int) now()->diffInDays($issue->remediation_due_date, true);

            // Get applicable escalation rules
            $rule = IssueEscalationRule::where('organization_id', $organizationId)
                ->where('priority', $issue->priority)
                ->where(function ($q) use ($issue) {
                    $q->whereNull('issue_source')
                        ->orWhere('issue_source', $issue->issue_source);
                })
                ->where('is_active', true)
                ->where('days_overdue_trigger', '<=', $daysOverdue)
                ->orderByDesc('escalation_level')
                ->first();

            if (! $rule) {
                continue;
            }

            // Apply CBN examination accelerated escalation
            $effectiveLevel = $issue->cbn_examination_finding
                ? min($rule->escalation_level + 1, self::MAX_LEVEL)
                : (int) $rule->escalation_level;

            $currentLevel = (int) ($issue->current_escalation_level ?? 0);

            // THE GUARD COMPARES THE EFFECTIVE LEVEL, NOT THE RULE'S.
            // It used to compare the un-accelerated rule level, so a CBN
            // finding pushed to level 2 by a level-1 rule was stranded: the
            // level-2 rule could never satisfy `2 > 2`, and the level-3 rung
            // was out of reach for the one class of issue that most needs it.
            if ($effectiveLevel <= $currentLevel) {
                continue;
            }

            IssueEscalationLog::create([
                'issue_id' => $issue->id,
                'escalation_level' => $effectiveLevel,
                'escalated_to_role' => $rule->escalation_to_role,
                'is_auto' => true,
                'reason' => "Auto-escalated: {$daysOverdue} days overdue (threshold: {$rule->days_overdue_trigger} days)",
                'escalated_at' => now(),
            ]);

            $issue->update([
                'current_escalation_level' => $effectiveLevel,
            ]);

            $this->announce($issue, $rule->escalation_to_role, $effectiveLevel, $daysOverdue);

            $escalatedCount++;
        }

        return $escalatedCount;
    }

    /**
     * Tell the role the issue has just been escalated to.
     *
     * Escalation used to write a log row and stop there: the role that now owns
     * the issue was never told it owned it, which makes an automatic ladder
     * indistinguishable from no ladder at all.
     *
     * A notification is never worth failing the sweep over, so everything here
     * degrades to a log line.
     */
    private function announce(Issue $issue, string $role, int $level, int $daysOverdue): void
    {
        try {
            // Spatie's User::role() scope THROWS RoleDoesNotExist for a name it
            // cannot find, so a tenant that has not seeded the role named on
            // its own escalation rule would take the whole sweep down. Resolve
            // the role first and skip if it is not there.
            if (! Role::query()->where('name', $role)->exists()) {
                logger()->warning('Issue escalated to a role that does not exist', [
                    'issue_id' => $issue->id,
                    'role' => $role,
                    'organization_id' => $issue->organization_id,
                ]);

                return;
            }

            $recipients = User::role($role)
                ->where('organization_id', $issue->organization_id)
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($recipients === []) {
                logger()->info('Issue escalation role has no active holders', [
                    'issue_id' => $issue->id,
                    'role' => $role,
                    'organization_id' => $issue->organization_id,
                ]);

                return;
            }

            NotificationService::sendMany(
                organizationId: (int) $issue->organization_id,
                userIds: $recipients,
                type: 'issue_escalated',
                subject: "Issue Escalated to Level {$level}: {$issue->issue_reference}",
                body: "Issue '{$issue->title}' is {$daysOverdue} days overdue and has been escalated to level {$level} ({$role}).",
                metadata: [
                    'entity_type' => 'issue',
                    'entity_id' => $issue->id,
                    'escalation_level' => $level,
                    'escalated_to_role' => $role,
                ],
                actionUrl: "/risk/issues/{$issue->id}",
                priority: $level >= 2 ? 'critical' : 'high',
                category: 'issue',
            );
        } catch (\Throwable $e) {
            logger()->warning('Could not notify issue escalation role', [
                'issue_id' => $issue->id,
                'role' => $role,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
