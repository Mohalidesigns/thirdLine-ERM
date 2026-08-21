<?php

namespace Tests\Feature\Issues;

use App\Models\Issue;
use App\Models\IssueEscalationLog;
use App\Models\IssueEscalationRule;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The issue escalation ladder, driven the way production drives it: through
 * `issues:check-overdue`, once a night, over several nights.
 *
 * No test in the suite had ever run more than one night, which is exactly why
 * two bugs survived. The service wrote `issue_status = 'ESCALATED'` while its
 * own candidate query only admitted OPEN / IN_PROGRESS / OVERDUE, so an issue
 * left its own working set the moment it escalated once; and the day count it
 * compared against `days_overdue_trigger` was Carbon 3's SIGNED diff, i.e.
 * negative for a past due date, so `7 <= -20` matched no rule and nothing
 * escalated at all.
 *
 * The seeded ladder (EnterpriseGapSeeder) is the intent these tests pin:
 * CRITICAL escalates to risk-manager at 7 days and to the CRO at 14.
 */
class IssueEscalationLadderTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        $this->seedCriticalLadder();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /** The two CRITICAL rungs from EnterpriseGapSeeder. */
    private function seedCriticalLadder(): void
    {
        $this->makeRule(1, 'risk-manager', 7);
        $this->makeRule(2, 'chief-risk-officer', 14);
    }

    private function makeRule(int $level, string $role, int $days, ?string $source = null): IssueEscalationRule
    {
        return IssueEscalationRule::create([
            'organization_id' => $this->organization->id,
            'priority' => 'CRITICAL',
            'issue_source' => $source,
            'escalation_level' => $level,
            'escalation_to_role' => $role,
            'days_overdue_trigger' => $days,
            'is_active' => true,
        ]);
    }

    private function makeIssue(array $attributes = []): Issue
    {
        $n = ++$this->sequence;

        return Issue::create(array_merge([
            'organization_id' => $this->organization->id,
            'issue_reference' => sprintf('ISS-ESC-%04d', $n),
            'title' => "Issue {$n}",
            'description' => "Fixture issue {$n}",
            'issue_source' => 'INTERNAL_AUDIT',
            'issue_category' => 'OPERATIONAL',
            'priority' => 'CRITICAL',
            'issue_status' => 'OPEN',
            'responsible_owner_id' => $this->actor->id,
            'remediation_due_date' => now()->subDays(8),
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function makeUser(string $label): User
    {
        return User::create([
            'name' => $label,
            'email' => str($label)->slug().'-'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
    }

    private function runTheNightlySweep(): void
    {
        $this->artisan('issues:check-overdue')->assertExitCode(0);
    }

    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_issue_escalates_to_level_one_on_the_first_night_past_the_trigger(): void
    {
        $issue = $this->makeIssue(['remediation_due_date' => now()->subDays(8)]);

        $this->runTheNightlySweep();

        $this->assertSame(1, (int) $issue->fresh()->current_escalation_level);
        $this->assertSame(1, IssueEscalationLog::where('issue_id', $issue->id)->count());
    }

    #[Test]
    public function the_ladder_climbs_to_the_second_rung_on_a_later_night(): void
    {
        // THE test whose absence let the bug live. Two nights, two rungs.
        $issue = $this->makeIssue(['remediation_due_date' => now()->subDays(8)]);

        $this->runTheNightlySweep();
        $this->assertSame(1, (int) $issue->fresh()->current_escalation_level);

        // Seven days later the issue is 15 days overdue: the level-2 rung.
        $this->travel(7)->days();
        $this->runTheNightlySweep();

        $this->assertSame(
            2,
            (int) $issue->fresh()->current_escalation_level,
            'The ladder never climbed past its first rung.',
        );

        $levels = IssueEscalationLog::where('issue_id', $issue->id)
            ->orderBy('escalation_level')
            ->pluck('escalation_level')
            ->map(fn ($l) => (int) $l)
            ->all();

        $this->assertSame([1, 2], $levels);

        $this->travelBack();
    }

    #[Test]
    public function a_rung_does_not_fire_again_on_a_later_night_when_nothing_has_changed(): void
    {
        $issue = $this->makeIssue(['remediation_due_date' => now()->subDays(8)]);

        $this->runTheNightlySweep();
        $this->runTheNightlySweep();

        // A day later, still short of the 14-day rung.
        $this->travel(1)->day();
        $this->runTheNightlySweep();

        $this->assertSame(1, (int) $issue->fresh()->current_escalation_level);
        $this->assertSame(
            1,
            IssueEscalationLog::where('issue_id', $issue->id)->count(),
            'The same rung fired more than once.',
        );

        $this->travelBack();
    }

    #[Test]
    public function the_holders_of_the_escalation_role_are_told(): void
    {
        Role::findOrCreate('risk-manager');
        $first = $this->makeUser('Risk Manager One');
        $second = $this->makeUser('Risk Manager Two');
        $bystander = $this->makeUser('Unrelated Analyst');
        $first->assignRole('risk-manager');
        $second->assignRole('risk-manager');

        $issue = $this->makeIssue(['remediation_due_date' => now()->subDays(8)]);

        $this->runTheNightlySweep();

        $recipients = DB::table('notifications_log')
            ->where('type', 'issue_escalated')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($first->id, $recipients, 'The role that now owns the issue was never told.');
        $this->assertContains($second->id, $recipients);
        $this->assertNotContains($bystander->id, $recipients);

        $notification = DB::table('notifications_log')
            ->where('type', 'issue_escalated')
            ->where('user_id', $first->id)
            ->first();

        $this->assertSame("/risk/issues/{$issue->id}", $notification->action_url);
        $this->assertStringContainsString($issue->issue_reference, $notification->subject);
        $this->assertStringNotContainsString('-8', $notification->body);
    }

    #[Test]
    public function an_escalation_to_a_role_that_does_not_exist_still_escalates(): void
    {
        // No Role row for 'risk-manager'. Spatie's User::role() scope THROWS
        // RoleDoesNotExist for a name it cannot find; the escalation itself
        // must not go down with it.
        $issue = $this->makeIssue(['remediation_due_date' => now()->subDays(8)]);

        $this->runTheNightlySweep();

        $this->assertSame(1, (int) $issue->fresh()->current_escalation_level);
        $this->assertSame(0, DB::table('notifications_log')->where('type', 'issue_escalated')->count());
    }

    #[Test]
    public function a_cbn_accelerated_issue_can_still_advance(): void
    {
        // A CBN examination finding jumps a rung. The guard used to compare the
        // UN-accelerated rule level against the level the issue had already
        // been accelerated to, so a level-1 rule that pushed it to 2 stranded
        // it there: the level-2 rule could never satisfy 2 > 2.
        $issue = $this->makeIssue([
            'remediation_due_date' => now()->subDays(8),
            'cbn_examination_finding' => true,
        ]);

        $this->runTheNightlySweep();
        $this->assertSame(2, (int) $issue->fresh()->current_escalation_level);

        $this->travel(7)->days();
        $this->runTheNightlySweep();

        $this->assertSame(
            3,
            (int) $issue->fresh()->current_escalation_level,
            'Acceleration stranded the issue on the rung it jumped to.',
        );

        $this->travelBack();
    }

    #[Test]
    public function an_escalated_issue_keeps_a_status_the_rest_of_the_platform_understands(): void
    {
        // 'ESCALATED' is not in the issue status vocabulary: the state machine
        // in ObjectTypeRegistry, IssueController's transition map, the register
        // grid and the board-pack queries all speak OPEN / IN_PROGRESS /
        // OVERDUE / PENDING_CLOSURE / CLOSED. An issue parked in 'ESCALATED'
        // vanished from the board pack and could not be moved on by hand.
        $issue = $this->makeIssue(['remediation_due_date' => now()->subDays(8)]);

        $this->runTheNightlySweep();

        $this->assertSame('OVERDUE', $issue->fresh()->issue_status);
    }

    #[Test]
    public function an_issue_stranded_in_the_old_escalated_status_is_recovered(): void
    {
        // Rows the broken version left behind: status 'ESCALATED', level 1,
        // and outside every query that could have moved them on.
        $issue = $this->makeIssue([
            'remediation_due_date' => now()->subDays(20),
            'issue_status' => 'ESCALATED',
            'current_escalation_level' => 1,
        ]);

        $this->runTheNightlySweep();

        $issue = $issue->fresh();

        $this->assertSame('OVERDUE', $issue->issue_status);
        $this->assertSame(2, (int) $issue->current_escalation_level);
    }
}
