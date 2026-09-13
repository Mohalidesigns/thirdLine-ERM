<?php

namespace Tests\Feature\Treatments;

use App\Models\RiskAuditTrail;
use App\Models\TreatmentPlan;
use App\Support\Migration\Ported;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The five treatment plan screens on Inertia (migration Phase 3.5), and the
 * write paths behind them.
 */
class TreatmentPagesTest extends TreatmentsTestCase
{
    /* ------------------------------------------------------------------ */
    /*  Pages */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_ported_route_is_registered_as_ported(): void
    {
        foreach ([
            'risk.treatments.dashboard',
            'risk.treatments.review',
            'risk.treatments.create',
            'risk.treatments.show',
            'risk.treatments.edit',
        ] as $name) {
            $this->assertTrue(Ported::isRoute($name), $name);
        }
    }

    #[Test]
    public function the_blade_views_are_gone(): void
    {
        $this->assertDirectoryDoesNotExist(resource_path('views/risk/treatments'));
    }

    #[Test]
    public function the_create_page_renders_the_configured_schema(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.treatments.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Treatments/Create')
                ->has('schema.sections')
                ->where('canDraftWithAi', false));
    }

    /**
     * The create form is rendered entirely from the TreatmentPlan object type,
     * so the fields the Form Request requires must actually be in the schema —
     * a required rule with no input to satisfy it is an unsubmittable form.
     */
    #[Test]
    public function the_create_schema_carries_every_required_field(): void
    {
        $response = $this->actingAs($this->actor)->get(route('risk.treatments.create'))->assertOk();

        $codes = collect($response->viewData('page')['props']['schema']['sections'])
            ->flatMap(fn (array $section) => array_column($section['fields'], 'code'))
            ->all();

        foreach ([
            'risk_id', 'treatment_title', 'treatment_description', 'treatment_type',
            'treatment_owner_id', 'priority', 'target_completion_date',
        ] as $required) {
            $this->assertContains($required, $codes, $required);
        }

        // The Progress section is edit-only: store() does not accept these.
        $this->assertNotContains('progress_percentage', $codes);
        $this->assertNotContains('status', $codes);
    }

    #[Test]
    public function the_edit_page_omits_the_risk_and_carries_the_milestones(): void
    {
        $plan = $this->makePlan([
            'milestones' => json_encode([['title' => 'Scope agreed', 'due_date' => '2026-10-01', 'responsible' => 'Ops']]),
        ]);

        $response = $this->actingAs($this->actor)
            ->get(route('risk.treatments.edit', $plan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Treatments/Edit')
                ->where('plan.milestones.0.title', 'Scope agreed')
                ->where('plan.milestones.0.responsible', 'Ops'));

        $codes = collect($response->viewData('page')['props']['schema']['sections'])
            ->flatMap(fn (array $section) => array_column($section['fields'], 'code'))
            ->all();

        $this->assertNotContains('risk_id', $codes);
        $this->assertContains('progress_percentage', $codes);
    }

    #[Test]
    public function the_show_page_carries_the_plan_and_the_permissions(): void
    {
        $plan = $this->makePlan([
            'status' => 'in_progress',
            'progress_pct' => 40,
            'cost_estimate_ngn' => 1_000_000,
            'actual_cost_ngn' => 1_250_000,
            'target_date' => now()->addDays(10)->toDateString(),
            'expected_residual_likelihood' => 4,
            'expected_residual_impact' => 5,
        ]);

        $this->actingAs($this->actor)
            ->get(route('risk.treatments.show', $plan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Treatments/Show')
                ->where('plan.code', $plan->treatment_code)
                ->where('plan.progress', 40)
                ->where('plan.budget', 1_000_000)
                ->where('plan.actualSpend', 1_250_000)
                ->where('plan.daysRemaining', 10)
                ->where('plan.residual', 'critical')
                ->where('plan.risk.code', $this->risk->risk_code)
                ->where('can.update', true)
                // The actor holds treatment.approve but no approver role.
                ->where('can.approve', false)
                ->where('can.resubmit', true));
    }

    /** An overdue plan reports a negative day count; the page words it. */
    #[Test]
    public function days_remaining_goes_negative_once_the_target_has_passed(): void
    {
        $plan = $this->makePlan(['target_date' => now()->subDays(3)->toDateString()]);

        $this->actingAs($this->actor)
            ->get(route('risk.treatments.show', $plan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('plan.daysRemaining', -3));
    }

    #[Test]
    public function the_review_page_lists_only_plans_pending_review(): void
    {
        $pending = $this->makePlan(['status' => 'pending_review']);
        $this->makePlan(['status' => 'in_progress']);

        $approver = $this->userWith(['treatment.view', 'treatment.approve'], 'rm@example.test', ['risk-manager']);

        $this->actingAs($approver)
            ->get(route('risk.treatments.review'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Treatments/Review')
                ->has('pendingPlans', 1)
                ->where('pendingPlans.0.code', $pending->treatment_code)
                ->where('pendingPlans.0.canApprove', true));
    }

    /* ------------------------------------------------------------------ */
    /*  Writes */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function storing_a_plan_writes_the_canonical_columns(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.treatments.store'), $this->validPlan([
                'milestones' => [
                    ['title' => 'Scope agreed', 'due_date' => '2026-10-01', 'responsible' => 'Ops'],
                    ['title' => '', 'due_date' => '', 'responsible' => ''],
                ],
            ]))
            ->assertRedirect();

        $plan = TreatmentPlan::latest('id')->first();

        $this->assertSame('Tighten wire transfer limits', $plan->action_title);
        $this->assertSame('mitigate', $plan->strategy);
        $this->assertSame($this->actor->id, $plan->owner_id);
        $this->assertSame('250000.00', $plan->cost_estimate_ngn);
        $this->assertSame('not_started', $plan->status);
        $this->assertSame(0, $plan->progress_pct);
        $this->assertNotNull($plan->treatment_code);

        // The blank repeater row is dropped rather than stored as an empty
        // milestone — the form always renders one.
        $this->assertSame(
            [['title' => 'Scope agreed', 'due_date' => '2026-10-01', 'responsible' => 'Ops']],
            json_decode($plan->milestones, true),
        );
    }

    #[Test]
    public function storing_rejects_another_tenants_risk_and_owner(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.treatments.store'), $this->validPlan([
                'risk_id' => $this->foreignRisk->id,
                'treatment_owner_id' => $this->otherActor->id,
            ]))
            ->assertSessionHasErrors(['risk_id', 'treatment_owner_id']);
    }

    /**
     * The string form `exists:table,id` accepts another tenant's id. Every
     * foreign key here goes through a tenant-bound Rule::exists instead.
     * Matched on the QUOTED rule, so a docblock explaining the ban does not
     * trip it.
     */
    #[Test]
    public function no_form_request_uses_the_untenanted_exists_rule(): void
    {
        foreach (glob(app_path('Http/Requests/Treatments/*.php')) as $file) {
            $source = file_get_contents($file);

            foreach (["'exists:", '"exists:'] as $quoted) {
                $this->assertStringNotContainsString($quoted, $source, basename($file));
            }
        }
    }

    #[Test]
    public function completing_a_plan_dates_it_and_fires_the_event(): void
    {
        Event::fake([\App\Events\TreatmentCompleted::class]);

        $plan = $this->makePlan(['status' => 'in_progress', 'progress_pct' => 60]);

        $this->actingAs($this->actor)
            ->put(route('risk.treatments.update', $plan), $this->validPlan([
                'status' => 'completed',
                'target_completion_date' => now()->addMonths(3)->toDateString(),
            ]))
            ->assertRedirect(route('risk.treatments.show', $plan));

        $plan->refresh();

        $this->assertSame('completed', $plan->status);
        $this->assertSame(100, $plan->progress_pct);
        $this->assertNotNull($plan->completion_date);

        Event::assertDispatched(\App\Events\TreatmentCompleted::class);
    }

    /** Re-saving an already-completed plan must not re-fire the event. */
    #[Test]
    public function re_saving_a_completed_plan_does_not_re_fire_the_event(): void
    {
        Event::fake([\App\Events\TreatmentCompleted::class]);

        $plan = $this->makePlan(['status' => 'completed', 'progress_pct' => 100]);

        $this->actingAs($this->actor)
            ->put(route('risk.treatments.update', $plan), $this->validPlan([
                'status' => 'completed',
                'target_completion_date' => now()->addMonths(3)->toDateString(),
            ]))
            ->assertRedirect();

        Event::assertNotDispatched(\App\Events\TreatmentCompleted::class);
    }

    #[Test]
    public function the_edit_form_cannot_set_a_status_the_approval_path_owns(): void
    {
        $this->actingAs($this->actor)
            ->put(route('risk.treatments.update', $this->plan), $this->validPlan([
                'status' => 'approved',
                'target_completion_date' => now()->addMonths(3)->toDateString(),
            ]))
            ->assertSessionHasErrors('status');
    }

    #[Test]
    public function deleting_a_plan_leaves_an_audit_row(): void
    {
        $plan = $this->makePlan();
        $code = $plan->treatment_code;

        $this->actingAs($this->actor)
            ->delete(route('risk.treatments.destroy', $plan))
            ->assertRedirect(route('risk.treatments.index'));

        $this->assertSoftDeleted('treatment_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('risk_audit_trail', [
            'entity_id' => $plan->id,
            'action_type' => 'deleted',
            'change_reason' => "Treatment plan {$code} deleted",
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_owner_submits_a_draft_for_review(): void
    {
        $plan = $this->makePlan(['status' => 'not_started']);

        $this->actingAs($this->actor)
            ->post(route('risk.treatments.submit', $plan))
            ->assertRedirect()
            ->assertSessionHas('success', 'Plan submitted for review.');

        $this->assertSame('pending_review', $plan->fresh()->status);
    }

    #[Test]
    public function a_plan_in_the_wrong_status_is_answered_with_a_message_not_a_403(): void
    {
        $plan = $this->makePlan(['status' => 'in_progress']);
        $approver = $this->userWith(['treatment.view', 'treatment.approve'], 'rm@example.test', ['risk-manager']);

        $this->actingAs($approver)
            ->post(route('risk.treatments.approve', $plan), ['comments' => 'Fine by me.'])
            ->assertRedirect()
            ->assertSessionHas('error', 'Only plans pending review can be approved.');

        $this->assertSame('in_progress', $plan->fresh()->status);
    }

    #[Test]
    public function approving_a_pending_plan_approves_it(): void
    {
        $plan = $this->makePlan(['status' => 'pending_review']);
        $approver = $this->userWith(['treatment.view', 'treatment.approve'], 'rm@example.test', ['risk-manager']);

        $this->actingAs($approver)
            ->post(route('risk.treatments.approve', $plan), ['comments' => 'Fine by me.'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Treatment plan approved.');

        $this->assertSame('approved', $plan->fresh()->status);
    }

    #[Test]
    public function rejecting_requires_a_reason_and_records_it(): void
    {
        $plan = $this->makePlan(['status' => 'pending_review']);
        $approver = $this->userWith(['treatment.view', 'treatment.approve'], 'rm@example.test', ['risk-manager']);

        $this->actingAs($approver)
            ->post(route('risk.treatments.reject', $plan), [])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame('pending_review', $plan->fresh()->status);

        $this->actingAs($approver)
            ->post(route('risk.treatments.reject', $plan), ['rejection_reason' => 'Costs are not evidenced.'])
            ->assertRedirect();

        $plan->refresh();

        $this->assertSame('rejected', $plan->status);
        $this->assertSame('Costs are not evidenced.', $plan->rejection_reason);
    }

    #[Test]
    public function the_owner_returns_a_rejected_plan_to_draft(): void
    {
        $plan = $this->makePlan(['status' => 'rejected', 'rejection_reason' => 'Rework the costs.']);

        $this->actingAs($this->actor)
            ->post(route('risk.treatments.resubmit', $plan))
            ->assertRedirect();

        $plan->refresh();

        $this->assertSame('draft', $plan->status);
        $this->assertNull($plan->rejection_reason);
    }

    #[Test]
    public function a_stranger_cannot_return_someone_elses_plan_to_draft(): void
    {
        $plan = $this->makePlan(['status' => 'rejected']);
        $stranger = $this->userWith(['treatment.view', 'treatment.create'], 'stranger@example.test');

        $this->actingAs($stranger)
            ->post(route('risk.treatments.resubmit', $plan))
            ->assertForbidden();
    }

    /**
     * The Blade action defaulted an empty textarea to the literal string
     * 'Comment added', writing a placeholder into the audit trail. The comment
     * is required now.
     */
    #[Test]
    public function commenting_requires_something_to_say(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.treatments.comment', $this->plan), [])
            ->assertSessionHasErrors('comment');

        $this->actingAs($this->actor)
            ->post(route('risk.treatments.comment', $this->plan), ['comment' => 'Costs look light.'])
            ->assertRedirect();

        $this->assertTrue(RiskAuditTrail::where('entity_id', $this->plan->id)
            ->where('action_type', 'commented')
            ->where('change_reason', 'Costs look light.')
            ->exists());
    }
}
