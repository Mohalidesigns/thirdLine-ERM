<?php

namespace Tests\Feature\Kri;

use App\Enums\KriContactRole;
use App\Events\IssueOverdue;
use App\Models\Issue;
use App\Models\IssueEscalationLog;
use App\Models\IssueEscalationRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * ADR 0026 section 8: a KRI-raised Issue past its due date goes through the
 * UNCHANGED `issues:check-overdue` path, and escalates up the department's KRI
 * contacts on the built-in 1 / 7 / 14 / 30 day floor (production tenants have no
 * issue_escalation_rules rows). The status is never written as ESCALATED and no
 * rung fires twice.
 *
 * The clock is 06:00 UTC on 2 Nov 2026. An Issue due 2026-10-31 is 2 days overdue.
 */
class KriIssueEscalationTest extends KriCycleTestCase
{
    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'champion' => KriContactRole::RiskChampion,
            'lead' => KriContactRole::TeamLead,
            'head' => KriContactRole::UnitHead,
            'group' => KriContactRole::GroupHead,
        ] as $key => $role) {
            $this->people[$key] = $this->makeStaff(ucfirst($key).' Person');
            $this->makeContact($this->unit, $this->people[$key], $role);
        }
    }

    private function kriIssue(string $due, array $overrides = []): Issue
    {
        static $n = 0;
        $n++;

        $issue = Issue::create([
            'organization_id' => $this->organization->id,
            'issue_reference' => sprintf('ISS-KRI-%04d', $n),
            'title' => "KRI issue {$n}",
            'description' => 'Raised from a red KRI.',
            'issue_source' => 'kri_breach',
            'issue_category' => 'KRI breach',
            'priority' => 'high',
            'issue_status' => 'OPEN',
            'responsible_owner_id' => $this->actor->id,
            'remediation_due_date' => $due,
            'created_by' => $this->actor->id,
        ]);

        $issue->forceFill(array_merge(['business_unit_id' => $this->unit->id, 'escalation_path' => 'kri_department_contacts'], $overrides))->save();

        return $issue->fresh();
    }

    private function sweep(): void
    {
        $this->artisan('issues:check-overdue')->assertExitCode(0);
    }

    /** @return list<int> user ids told, ascending by notification id */
    private function told(): array
    {
        return DB::table('notifications_log')->where('type', 'issue_escalated')->orderBy('id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    private function toldCount(string $key): int
    {
        return count(array_keys($this->told(), $this->people[$key]->id));
    }

    #[Test]
    public function a_kri_issue_past_due_is_marked_overdue_fires_the_overdue_event_and_climbs_the_floor_ladder(): void
    {
        Event::fake([IssueOverdue::class]);
        $issue = $this->kriIssue('2026-10-31');

        $this->sweep();

        Event::assertDispatched(IssueOverdue::class, fn ($e) => $e->issue->id === $issue->id);
        $issue->refresh();
        $this->assertSame('OVERDUE', $issue->issue_status);
        $this->assertSame(1, (int) $issue->current_escalation_level, '2 days overdue: the first rung');

        $log = IssueEscalationLog::query()->where('issue_id', $issue->id)->get();
        $this->assertCount(1, $log);
        $this->assertSame(1, (int) $log[0]->escalation_level);
        $this->assertSame('kri_department_contacts', $log[0]->escalated_to_role);
        $this->assertTrue((bool) $log[0]->is_auto);
        $this->assertStringContainsString('2 days overdue', $log[0]->reason);
    }

    #[Test]
    public function each_floor_rung_reaches_one_tier_further_up_the_department_and_none_fires_twice(): void
    {
        $issue = $this->kriIssue('2026-10-31');

        $this->sweep();                                  // 2 days: level 1, champions
        $this->assertSame([$this->people['champion']->id], $this->told());

        $this->sweep();                                  // same night again: nothing new
        $this->sweep();
        $this->assertSame(1, IssueEscalationLog::query()->where('issue_id', $issue->id)->count(), 'no rung fires twice');
        $this->assertSame(1, count($this->told()));

        $this->freezeAt('2026-11-07 06:00:00');          // 7 days: level 2, + team leads
        $this->sweep();
        $this->assertSame(2, (int) $issue->fresh()->current_escalation_level);
        $this->assertSame(1, $this->toldCount('lead'));
        $this->assertSame(0, $this->toldCount('head'));

        $this->freezeAt('2026-11-14 06:00:00');          // 14 days: level 3, + unit heads
        $this->sweep();
        $this->assertSame(3, (int) $issue->fresh()->current_escalation_level);
        $this->assertSame(1, $this->toldCount('head'));
        $this->assertSame(0, $this->toldCount('group'));

        $this->freezeAt('2026-11-30 06:00:00');          // 30 days: level 4, + group heads
        $this->sweep();
        $this->assertSame(4, (int) $issue->fresh()->current_escalation_level);
        $this->assertSame(1, $this->toldCount('group'));

        $this->freezeAt('2026-12-30 06:00:00');          // far past: the ladder stops at 4
        $this->sweep();
        $this->assertSame(4, (int) $issue->fresh()->current_escalation_level);
        $this->assertSame(4, IssueEscalationLog::query()->where('issue_id', $issue->id)->count());

        $this->assertSame(0, Issue::query()->where('issue_status', 'ESCALATED')->count(), 'the status is never written as ESCALATED');
        $this->assertSame('OVERDUE', $issue->fresh()->issue_status);
    }

    #[Test]
    public function an_issue_very_late_at_its_first_sweep_goes_straight_to_the_right_rung_once(): void
    {
        $issue = $this->kriIssue('2026-09-22');          // 41 days overdue

        $this->sweep();

        $this->assertSame(4, (int) $issue->fresh()->current_escalation_level);
        $this->assertSame(1, IssueEscalationLog::query()->where('issue_id', $issue->id)->count());
        $this->assertSame(1, $this->toldCount('group'));
        $this->assertSame(1, $this->toldCount('champion'));
    }

    #[Test]
    public function an_issue_due_today_or_later_is_not_chased(): void
    {
        $issue = $this->kriIssue('2026-11-02');

        $this->sweep();

        $this->assertSame('OPEN', $issue->fresh()->issue_status);
        $this->assertSame(0, IssueEscalationLog::query()->count());
    }

    #[Test]
    public function inactive_contacts_are_not_told(): void
    {
        $this->people['champion']->forceFill(['is_active' => false])->save();
        $issue = $this->kriIssue('2026-10-31');

        $this->sweep();

        $this->assertSame(1, (int) $issue->fresh()->current_escalation_level, 'the rung still fires and is logged');
        $this->assertSame(0, $this->toldCount('champion'));
    }

    #[Test]
    public function tenant_rules_can_only_make_escalation_faster_never_stop_it(): void
    {
        IssueEscalationRule::create([
            'organization_id' => $this->organization->id, 'priority' => 'high', 'issue_source' => null,
            'escalation_level' => 3, 'escalation_to_role' => 'chief-risk-officer', 'days_overdue_trigger' => 2, 'is_active' => true,
        ]);
        $faster = $this->kriIssue('2026-10-31');

        $this->sweep();

        $this->assertSame(3, (int) $faster->fresh()->current_escalation_level, 'the rule says level 3 at 2 days; the floor says 1; the higher wins');
        $this->assertSame(1, $this->toldCount('head'));
    }

    #[Test]
    public function a_rule_that_matches_nothing_cannot_stop_the_floor(): void
    {
        IssueEscalationRule::create([
            'organization_id' => $this->organization->id, 'priority' => 'low', 'issue_source' => null,
            'escalation_level' => 1, 'escalation_to_role' => 'chief-risk-officer', 'days_overdue_trigger' => 90, 'is_active' => true,
        ]);
        $issue = $this->kriIssue('2026-10-31');

        $this->sweep();

        $this->assertSame(1, (int) $issue->fresh()->current_escalation_level);
    }

    #[Test]
    public function an_issue_without_the_kri_path_or_without_a_department_is_untouched_by_the_ladder(): void
    {
        $plain = $this->kriIssue('2026-10-31', ['escalation_path' => null, 'issue_source' => 'audit']);
        $orphan = $this->kriIssue('2026-10-31', ['business_unit_id' => null]);

        $this->sweep();

        // Both are still marked overdue by the unchanged command, but nothing is escalated.
        $this->assertSame('OVERDUE', $plain->fresh()->issue_status);
        $this->assertSame(0, (int) $plain->fresh()->current_escalation_level);
        $this->assertSame(0, (int) $orphan->fresh()->current_escalation_level);
        $this->assertSame(0, IssueEscalationLog::query()->count());
    }

    #[Test]
    public function with_no_contacts_to_tell_the_rung_is_still_logged_and_nothing_throws(): void
    {
        \App\Models\KriDepartmentContact::query()->delete();
        $issue = $this->kriIssue('2026-10-31');

        $this->sweep();

        $this->assertSame(1, (int) $issue->fresh()->current_escalation_level);
        $this->assertSame([], $this->told());
    }

    #[Test]
    public function another_tenants_contacts_are_never_told_about_this_tenants_issue(): void
    {
        $theirs = $this->foreignDepartment();
        $stranger = $this->foreignUser();
        $this->makeContact($theirs, $stranger);
        $this->kriIssue('2026-10-31');

        $this->sweep();

        $this->assertSame(0, count(array_keys($this->told(), $stranger->id)));
    }
}
