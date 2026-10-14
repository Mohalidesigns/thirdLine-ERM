<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaCycle;
use App\Models\RiskAuditTrail;
use App\Models\User;
use App\Services\Rcsa\RcsaCycleService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * RCSA-05: assigning assessors from the cycle page.
 *
 * `rcsa_assessments.assigned_to` always existed and was read by the reminders,
 * the dashboard and the return-for-rework notice; nothing wrote it.
 */
class CycleAssignmentTest extends CycleTestCase
{
    private RcsaCycle $cycle;

    private RcsaAssessment $retailAssessment;

    private RcsaAssessment $treasuryAssessment;

    private User $assessor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $this->publishedRisk(['risk_no' => 'TREAS-R1', 'business_unit_id' => $this->treasury->id, 'process_id' => null]);

        $this->cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($this->cycle, $this->actor);
        $this->cycle->refresh();

        $this->retailAssessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();
        $this->treasuryAssessment = RcsaAssessment::query()->where('business_unit_id', $this->treasury->id)->sole();

        // A risk champion for both units, with a distinctive name so the
        // flash message can be asserted exactly.
        $this->assessor = $this->userWith(['rcsa_cycle.view', 'rcsa_assessment.view', 'rcsa_assessment.complete']);
        $this->assessor->forceFill(['name' => 'Jane Doe'])->save();

        DB::table('notifications_log')->delete();
    }

    private function postAssign(array $ids, ?int $to, ?User $as = null, ?RcsaCycle $cycle = null)
    {
        return $this->actingAs($as ?? $this->actor)->post(
            route('rcsa.cycles.assign', $cycle ?? $this->cycle),
            ['assessment_ids' => $ids, 'assigned_to' => $to],
        );
    }

    private function assignedTo(RcsaAssessment $assessment): ?int
    {
        $value = $assessment->fresh()->assigned_to;

        return $value === null ? null : (int) $value;
    }

    private function notices()
    {
        return DB::table('notifications_log')->where('type', 'rcsa.assessment.assigned')->get();
    }

    #[Test]
    public function one_assessment_can_be_assigned(): void
    {
        $this->postAssign([$this->retailAssessment->id], $this->assessor->id)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '1 assessment assigned to Jane Doe.');

        $this->assertSame($this->assessor->id, $this->assignedTo($this->retailAssessment));
        $this->assertNull($this->assignedTo($this->treasuryAssessment));
    }

    #[Test]
    public function several_can_be_assigned_at_once_and_the_assignee_is_told_once(): void
    {
        $this->postAssign([$this->retailAssessment->id, $this->treasuryAssessment->id], $this->assessor->id)
            ->assertSessionHas('success', '2 assessments assigned to Jane Doe.');

        $this->assertSame($this->assessor->id, $this->assignedTo($this->retailAssessment));
        $this->assertSame($this->assessor->id, $this->assignedTo($this->treasuryAssessment));

        // ONE notification for the request, not one per unit.
        $notices = $this->notices();
        $this->assertCount(1, $notices);
        $this->assertSame($this->assessor->id, (int) $notices[0]->user_id);
        $this->assertStringContainsString('Retail Banking', (string) $notices[0]->body);
        $this->assertStringContainsString('Treasury', (string) $notices[0]->body);
    }

    #[Test]
    public function a_single_assignment_links_to_that_workspace(): void
    {
        $this->postAssign([$this->retailAssessment->id], $this->assessor->id);

        $notice = $this->notices()->sole();

        $this->assertSame(
            route('rcsa.assessments.show', $this->retailAssessment, absolute: false),
            $notice->action_url,
        );
    }

    #[Test]
    public function unassigning_clears_the_assignee_and_notifies_nobody(): void
    {
        $this->postAssign([$this->retailAssessment->id], $this->assessor->id);
        DB::table('notifications_log')->delete();

        $this->postAssign([$this->retailAssessment->id], null)
            ->assertSessionHas('success', '1 assessment unassigned.');

        $this->assertNull($this->assignedTo($this->retailAssessment));
        $this->assertCount(0, $this->notices());
    }

    #[Test]
    public function assigning_to_yourself_notifies_nobody(): void
    {
        $this->postAssign([$this->retailAssessment->id], $this->actor->id)
            ->assertSessionHas('success');

        $this->assertSame($this->actor->id, $this->assignedTo($this->retailAssessment));
        $this->assertCount(0, $this->notices());
    }

    #[Test]
    public function re_sending_the_same_assignment_changes_nothing_and_says_so(): void
    {
        $this->postAssign([$this->retailAssessment->id], $this->assessor->id);
        DB::table('notifications_log')->delete();
        $audit = RiskAuditTrail::query()->where('action_type', 'rcsa_assignment')->count();

        $this->postAssign([$this->retailAssessment->id], $this->assessor->id)
            ->assertSessionHas('success', 'Nothing to change: Jane Doe already has that assessment.');

        $this->assertCount(0, $this->notices());
        $this->assertSame($audit, RiskAuditTrail::query()->where('action_type', 'rcsa_assignment')->count());
    }

    #[Test]
    public function an_assessment_id_from_another_cycle_is_ignored_not_reached(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R9']);
        $other = $this->makeCycle(['name' => 'RCSA 2026 H2', 'period_start' => '2026-07-01', 'period_end' => '2026-12-31']);
        app(RcsaCycleService::class)->open($other, $this->actor);

        $elsewhere = RcsaAssessment::query()
            ->where('cycle_id', $other->id)
            ->where('business_unit_id', $this->retail->id)
            ->sole();

        $this->postAssign([$this->retailAssessment->id, $elsewhere->id], $this->assessor->id)
            ->assertSessionHas('success', '1 assessment assigned to Jane Doe.');

        $this->assertSame($this->assessor->id, $this->assignedTo($this->retailAssessment));
        $this->assertNull($this->assignedTo($elsewhere));
    }

    #[Test]
    public function an_assessment_id_from_another_tenant_is_ignored_not_reached(): void
    {
        $foreignCycle = TenantContext::bypass(fn () => RcsaCycle::create([
            'organization_id' => $this->otherOrg->id,
            'name' => 'Another bank',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'methodology_id' => $this->methodology()->id,
            'status' => RcsaCycle::OPEN,
        ]));

        $foreign = TenantContext::bypass(fn () => RcsaAssessment::create([
            'organization_id' => $this->otherOrg->id,
            'cycle_id' => $foreignCycle->id,
            'business_unit_id' => $this->foreignUnit->id,
            'status' => RcsaAssessment::DRAFT,
        ]));

        // Smuggled in beside a legitimate id.
        $this->postAssign([$this->retailAssessment->id, $foreign->id], $this->assessor->id)
            ->assertSessionHas('success', '1 assessment assigned to Jane Doe.');

        $this->assertNull(TenantContext::bypass(fn () => RcsaAssessment::query()->whereKey($foreign->id)->value('assigned_to')));

        // On its own, against a cycle it does not belong to: nothing to do.
        $this->postAssign([$foreign->id], $this->assessor->id)
            ->assertSessionHas('error', 'None of the selected assessments belong to this cycle.');

        // And another tenant's cycle is a 404, as it is everywhere.
        $this->postAssign([$foreign->id], $this->assessor->id, cycle: $foreignCycle)->assertNotFound();
    }

    /**
     * A coordinator who holds `rcsa_cycle.manage` but is assigned only to Retail:
     * the policy lets them assign IN the cycle, the service must still keep
     * them to their own units.
     */
    private function retailOnlyCoordinator(): User
    {
        $coordinator = $this->userWith(['rcsa_cycle.view', 'rcsa_cycle.manage', 'rcsa_assessment.view'], units: [$this->retail]);
        $coordinator->forceFill(['name' => 'Cora Coordinator'])->save();

        return $coordinator;
    }

    #[Test]
    public function a_coordinator_cannot_assign_a_unit_outside_their_own_scope_by_id(): void
    {
        $coordinator = $this->retailOnlyCoordinator();
        $auditBefore = RiskAuditTrail::query()->where('action_type', 'rcsa_assignment')->count();

        // On its own: nothing reachable, so the same answer as an id from another cycle.
        $this->postAssign([$this->treasuryAssessment->id], $this->assessor->id, as: $coordinator)
            ->assertSessionHas('error', 'None of the selected assessments belong to this cycle.');

        $this->assertNull($this->assignedTo($this->treasuryAssessment));
        $this->assertSame($auditBefore, RiskAuditTrail::query()->where('action_type', 'rcsa_assignment')->count());
        $this->assertCount(0, $this->notices());
    }

    #[Test]
    public function an_out_of_scope_id_mixed_with_an_in_scope_one_is_never_loaded(): void
    {
        $coordinator = $this->retailOnlyCoordinator();

        $this->postAssign([$this->retailAssessment->id, $this->treasuryAssessment->id], $this->assessor->id, as: $coordinator)
            ->assertSessionHas('success', '1 assessment assigned to Jane Doe.');

        $this->assertSame($this->assessor->id, $this->assignedTo($this->retailAssessment));

        // Treasury untouched, un-audited, and not mentioned to the assignee.
        $this->assertNull($this->assignedTo($this->treasuryAssessment));
        $this->assertSame(0, RiskAuditTrail::query()
            ->where('action_type', 'rcsa_assignment')
            ->where('entity_id', $this->treasuryAssessment->id)
            ->count());

        $notice = $this->notices()->sole();
        $this->assertStringContainsString('Retail Banking', (string) $notice->body);
        $this->assertStringNotContainsString('Treasury', (string) $notice->body);
        $this->assertSame([$this->retailAssessment->id], json_decode((string) $notice->metadata, true)['assessment_ids']);
    }

    #[Test]
    public function a_coordinator_cannot_unassign_a_unit_outside_their_scope_either(): void
    {
        $this->postAssign([$this->treasuryAssessment->id], $this->assessor->id);
        $coordinator = $this->retailOnlyCoordinator();

        $this->postAssign([$this->treasuryAssessment->id], null, as: $coordinator)
            ->assertSessionHas('error', 'None of the selected assessments belong to this cycle.');

        $this->assertSame($this->assessor->id, $this->assignedTo($this->treasuryAssessment));
    }

    #[Test]
    public function an_assignee_who_cannot_reach_a_selected_unit_refuses_the_whole_request_naming_it(): void
    {
        $retailOnly = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.complete'], units: [$this->retail]);
        $retailOnly->forceFill(['name' => 'Rita Retail'])->save();

        $this->postAssign([$this->retailAssessment->id, $this->treasuryAssessment->id], $retailOnly->id)
            ->assertSessionHasErrors(['assigned_to']);

        $this->assertStringContainsString(
            'Treasury',
            session('errors')->first('assigned_to'),
        );

        // Nothing was applied — not even the unit she CAN reach.
        $this->assertNull($this->assignedTo($this->retailAssessment));
        $this->assertNull($this->assignedTo($this->treasuryAssessment));
        $this->assertCount(0, $this->notices());

        // The unit she can reach is fine on its own.
        $this->postAssign([$this->retailAssessment->id], $retailOnly->id)->assertSessionHasNoErrors();
        $this->assertSame($retailOnly->id, $this->assignedTo($this->retailAssessment));
    }

    #[Test]
    public function an_assignee_with_no_unit_assignments_cannot_be_given_any(): void
    {
        $nobody = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.complete'], units: []);

        $this->postAssign([$this->retailAssessment->id], $nobody->id)->assertSessionHasErrors(['assigned_to']);

        $this->assertNull($this->assignedTo($this->retailAssessment));
    }

    #[Test]
    public function an_assignee_who_cannot_complete_assessments_is_refused(): void
    {
        $reader = $this->userWith(['rcsa_assessment.view']);

        $this->postAssign([$this->retailAssessment->id], $reader->id)->assertSessionHasErrors(['assigned_to']);

        $this->assertNull($this->assignedTo($this->retailAssessment));
    }

    #[Test]
    public function an_inactive_or_foreign_assignee_is_refused(): void
    {
        $left = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.complete']);
        $left->forceFill(['is_active' => false])->save();

        $this->postAssign([$this->retailAssessment->id], $left->id)->assertSessionHasErrors(['assigned_to']);

        $foreigner = TenantContext::bypass(fn () => User::create([
            'name' => 'Foreign Assessor',
            'email' => 'foreign-'.uniqid().'@example.test',
            'password' => 'password',
            'organization_id' => $this->otherOrg->id,
            'is_active' => true,
        ]));

        $this->postAssign([$this->retailAssessment->id], $foreigner->id)->assertSessionHasErrors(['assigned_to']);

        $this->assertNull($this->assignedTo($this->retailAssessment));
    }

    #[Test]
    public function the_body_is_validated(): void
    {
        $this->actingAs($this->actor)
            ->post(route('rcsa.cycles.assign', $this->cycle), ['assigned_to' => $this->assessor->id])
            ->assertSessionHasErrors('assessment_ids');

        $this->actingAs($this->actor)
            ->post(route('rcsa.cycles.assign', $this->cycle), ['assessment_ids' => range(1, 501), 'assigned_to' => $this->assessor->id])
            ->assertSessionHasErrors('assessment_ids');

        // Omitting `assigned_to` must not quietly unassign everybody; null must.
        $this->actingAs($this->actor)
            ->post(route('rcsa.cycles.assign', $this->cycle), ['assessment_ids' => [$this->retailAssessment->id]])
            ->assertSessionHasErrors('assigned_to');
    }

    #[Test]
    public function a_user_without_cycle_manage_gets_a_403(): void
    {
        $reader = $this->userWith(['rcsa_cycle.view', 'rcsa_assessment.view', 'rcsa_assessment.complete']);

        $this->postAssign([$this->retailAssessment->id], $this->assessor->id, as: $reader)->assertForbidden();

        $this->assertNull($this->assignedTo($this->retailAssessment));
    }

    #[Test]
    public function a_closed_cycle_refuses_assignment(): void
    {
        $this->actingAs($this->actor)->post(route('rcsa.cycles.close', $this->cycle))->assertRedirect();

        $this->postAssign([$this->retailAssessment->id], $this->assessor->id)
            ->assertSessionHas('error');

        $this->assertNull($this->assignedTo($this->retailAssessment));
        $this->assertCount(0, $this->notices());
    }

    #[Test]
    public function an_assessment_that_no_longer_accepts_edits_refuses_the_request_naming_it(): void
    {
        $this->treasuryAssessment->forceFill(['status' => RcsaAssessment::SUBMITTED])->save();

        $this->postAssign([$this->retailAssessment->id, $this->treasuryAssessment->id], $this->assessor->id)
            ->assertSessionHas('error');

        $this->assertStringContainsString('Treasury', (string) session('error'));
        $this->assertNull($this->assignedTo($this->retailAssessment));
        $this->assertNull($this->assignedTo($this->treasuryAssessment));
    }

    #[Test]
    public function each_change_is_recorded_in_the_audit_trail(): void
    {
        $this->postAssign([$this->retailAssessment->id, $this->treasuryAssessment->id], $this->assessor->id);
        $this->postAssign([$this->retailAssessment->id], null);

        $rows = RiskAuditTrail::query()
            ->where('action_type', 'rcsa_assignment')
            ->where('entity_type', $this->retailAssessment->getMorphClass())
            ->where('entity_id', $this->retailAssessment->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);

        $this->assertSame('assigned_to', $rows[0]->field_changed);
        $this->assertNull($rows[0]->old_value);
        $this->assertSame((string) $this->assessor->id, $rows[0]->new_value);
        $this->assertSame($this->actor->id, $rows[0]->changed_by);
        $this->assertSame('Assigned to Jane Doe', $rows[0]->change_reason);

        $this->assertSame((string) $this->assessor->id, $rows[1]->old_value);
        $this->assertNull($rows[1]->new_value);
        $this->assertSame('Unassigned', $rows[1]->change_reason);

        // One row per assessment that actually changed.
        $this->assertSame(1, RiskAuditTrail::query()
            ->where('action_type', 'rcsa_assignment')
            ->where('entity_id', $this->treasuryAssessment->id)
            ->count());
    }

    #[Test]
    public function the_cycle_page_carries_the_assignees_and_the_assign_ability(): void
    {
        $inactive = $this->userWith(['rcsa_assessment.complete']);
        $inactive->forceFill(['is_active' => false, 'name' => 'Zed Gone'])->save();
        $reader = $this->userWith(['rcsa_assessment.view']);
        $reader->forceFill(['name' => 'Aaron Reader'])->save();

        $this->postAssign([$this->retailAssessment->id], $this->assessor->id);

        $this->actingAs($this->actor)
            ->get(route('rcsa.cycles.show', $this->cycle))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($inactive, $reader) {
                $page->component('RcsaCycles/Show')
                    ->where('can.assign', true);

                $props = $page->toArray()['props'];

                $row = collect($props['assessments'])->firstWhere('id', $this->retailAssessment->id);
                $this->assertSame($this->assessor->id, $row['assignee_id']);
                $this->assertSame('Jane Doe', $row['assignee']);

                $unassigned = collect($props['assessments'])->firstWhere('id', $this->treasuryAssessment->id);
                $this->assertNull($unassigned['assignee_id']);

                $names = array_column($props['assignees'], 'name');
                $ids = array_column($props['assignees'], 'id');

                $this->assertContains('Jane Doe', $names);
                // Only people who could do the work, only active ones.
                $this->assertNotContains($reader->id, $ids);
                $this->assertNotContains($inactive->id, $ids);
                $this->assertSame($names, collect($names)->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all());
                $this->assertSame(['id', 'name'], array_keys($props['assignees'][0]));
            });
    }

    #[Test]
    public function a_viewer_without_manage_is_not_offered_the_assign_ability(): void
    {
        $reader = $this->userWith(['rcsa_cycle.view', 'rcsa_assessment.view']);

        $this->actingAs($reader)
            ->get(route('rcsa.cycles.show', $this->cycle))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.assign', false)->where('assignees', []));
    }
}
