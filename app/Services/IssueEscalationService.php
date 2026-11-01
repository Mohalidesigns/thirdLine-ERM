<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\IssueEscalationLog;
use App\Models\IssueEscalationRule;
use App\Models\User;
use App\Support\Kri\KriContactLadder;
use Spatie\Permission\Models\Role;

class IssueEscalationService
{
    /** The ladder stops here however many rungs a tenant configures. */
    private const MAX_LEVEL = 5;

    /** The escalation_path value of an Issue raised from a KRI breach. */
    public const KRI_PATH = 'kri_department_contacts';

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

            // ADR 0026 section 8.2: an Issue raised from a KRI breach escalates
            // through its department's KRI contacts. Production tenants have no
            // issue_escalation_rules, so the path carries a built-in floor
            // ladder; a tenant's rules can only make it faster, never stop it.
            $kriPath = $issue->escalation_path === self::KRI_PATH && $issue->business_unit_id !== null;

            if (! $rule && ! $kriPath) {
                continue;
            }

            // Apply CBN examination accelerated escalation
            $ruleLevel = $rule === null
                ? 0
                : ($issue->cbn_examination_finding
                    ? min($rule->escalation_level + 1, self::MAX_LEVEL)
                    : (int) $rule->escalation_level);

            $effectiveLevel = $kriPath
                ? max($ruleLevel, KriContactLadder::issueLevelForDaysOverdue($daysOverdue))
                : $ruleLevel;

            if ($effectiveLevel < 1) {
                continue;
            }

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
                'escalated_to_role' => $kriPath ? self::KRI_PATH : $rule->escalation_to_role,
                'is_auto' => true,
                'reason' => $kriPath
                    ? 'Auto-escalated to KRI contacts up to tier '.min($effectiveLevel, KriContactLadder::MAX_LEVEL)
                        ." ({$daysOverdue} days overdue)"
                    : "Auto-escalated: {$daysOverdue} days overdue (threshold: {$rule->days_overdue_trigger} days)",
                'escalated_at' => now(),
            ]);

            $issue->update([
                'current_escalation_level' => $effectiveLevel,
            ]);

            if ($kriPath) {
                $this->announceToDepartment($issue, $rule?->escalation_to_role, $effectiveLevel, $daysOverdue);
            } else {
                $this->announce($issue, $rule->escalation_to_role, $effectiveLevel, $daysOverdue);
            }

            $escalatedCount++;
        }

        return $escalatedCount;
    }

    /**
     * Tell the department's KRI contacts, up to the tier reached. Inactive users
     * are not notified. With nobody to tell, fall back to the matched rule's role
     * (today's behaviour) when there was one; otherwise log.
     */
    private function announceToDepartment(Issue $issue, ?string $fallbackRole, int $level, int $daysOverdue): void
    {
        try {
            $recipients = KriContactLadder::recipientsFor((int) $issue->business_unit_id, $level)
                ->filter(fn (array $row) => $row['user']->is_active)
                ->map(fn (array $row) => (int) $row['user']->id)
                ->unique()
                ->values()
                ->all();

            if ($recipients === []) {
                if ($fallbackRole !== null) {
                    $this->announce($issue, $fallbackRole, $level, $daysOverdue);

                    return;
                }

                logger()->info('KRI issue escalation has no active department contacts', [
                    'issue_id' => $issue->id,
                    'business_unit_id' => $issue->business_unit_id,
                    'level' => $level,
                ]);

                return;
            }

            NotificationService::sendMany(
                organizationId: (int) $issue->organization_id,
                userIds: $recipients,
                type: 'issue_escalated',
                subject: "Issue Escalated to Level {$level}: {$issue->issue_reference}",
                body: "Issue '{$issue->title}' is {$daysOverdue} days overdue and has been escalated to your department's KRI contacts (level {$level}).",
                metadata: [
                    'entity_type' => 'issue',
                    'entity_id' => $issue->id,
                    'escalation_level' => $level,
                    'escalated_to_role' => self::KRI_PATH,
                ],
                actionUrl: "/risk/issues/{$issue->id}",
                priority: $level >= 2 ? 'critical' : 'high',
                category: 'issue',
            );
        } catch (\Throwable $e) {
            logger()->warning('Could not notify KRI department contacts', ['issue_id' => $issue->id, 'error' => $e->getMessage()]);
        }
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
