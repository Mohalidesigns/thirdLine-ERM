<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaCycle;
use App\Models\User;
use App\Services\Rcsa\RcsaActionPlanService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * §9.3 — the tracking register that survives the cycle.
 *
 * "This is where most RCSA implementations quietly fail — the assessment is
 * done and the remediation is never tracked." The tests below are the four
 * things that stop it being a text column: a sweep that decides overdue, an
 * extension that is a request rather than an edit, a closure the owner claims
 * and the second line accepts, and a register that does not empty itself when
 * the cycle closes.
 */
class ActionPlanRegisterTest extends ReviewTestCase
{
    private User $owner;

    private User $orm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->userWith(['rcsa_actionplan.view', 'rcsa_actionplan.update']);
        $this->orm = $this->userWith([
            'rcsa_actionplan.view', 'rcsa_actionplan.update', 'rcsa_actionplan.close', 'rcsa_actionplan.verify',
        ]);
    }

    private ?RcsaAssessment $assessment = null;

    private int $taken = 0;

    /**
     * The next action plan of a single submitted assessment, due on the date
     * given.
     *
     * ONE assessment for the whole test, provisioned lazily with more lines
     * than any test needs. Opening a cycle is one-way and a cycle per plan
     * would be several cycles open at once — a state the module refuses on
     * purpose. The register is what is under test here, not the cycle.
     */
    private function planDue(string $date, array $overrides = []): RcsaActionPlan
    {
        $this->assessment ??= $this->submittedAssessment(risks: 6, aboveAppetite: true);

        $line = $this->linesOf($this->assessment)->values()->get($this->taken++);

        $plan = $line->actionPlans()->sole();

        $plan->forceFill(array_merge([
            'owner_id' => $this->owner->id,
            'target_date' => $date,
        ], $overrides))->save();

        return $plan->refresh();
    }

    /* ------------------------------------------------------------------ */
    /*  Overdue is decided in one place */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_sweep_marks_overdue_and_tells_the_owner_once(): void
    {
        $plan = $this->planDue(now()->subDays(3)->toDateString());

        $this->assertSame(RcsaActionPlan::OPEN, $plan->status);

        $this->artisan('rcsa:check-action-plans')->assertSuccessful();

        $this->assertSame(RcsaActionPlan::OVERDUE, $plan->fresh()->status);

        $notices = DB::table('notifications_log')
            ->where('type', 'rcsa.actionplan.overdue')
            ->where('user_id', $this->owner->id);

        $this->assertSame(1, $notices->count());
        // Positive, in days. Carbon 3's diffInDays is negative for a past date
        // and the sign is chosen once, in daysUntilDue().
        $this->assertStringContainsString('3 days ago', (string) $notices->first()->body);

        // A SECOND RUN SAYS NOTHING NEW. The status can only flip once, which
        // is what makes the announcement idempotent without a "last announced"
        // column — and what stops a daily "still overdue" mail training owners
        // to filter the register into a folder they never open.
        $this->artisan('rcsa:check-action-plans')->assertSuccessful();

        $this->assertSame(1, DB::table('notifications_log')->where('type', 'rcsa.actionplan.overdue')->count());
    }

    #[Test]
    public function reminders_fire_at_t_minus_14_7_and_0_and_nowhere_else(): void
    {
        foreach ([14, 7, 0] as $days) {
            $this->planDue(now()->addDays($days)->toDateString());
        }

        // Nine days out matches no milestone.
        $this->planDue(now()->addDays(9)->toDateString());

        $this->artisan('rcsa:check-action-plans')->assertSuccessful();

        $reminders = DB::table('notifications_log')->where('type', 'rcsa.actionplan.reminder')->get();

        $this->assertCount(3, $reminders);
        $this->assertSame(
            1,
            $reminders->filter(fn ($n) => str_contains($n->subject, 'Due today'))->count(),
        );
        $this->assertSame(
            1,
            $reminders->filter(fn ($n) => str_contains($n->subject, 'Due in 14 days'))->count(),
        );
    }

    #[Test]
    public function a_completed_plan_is_never_swept_overdue(): void
    {
        $plan = $this->planDue(now()->subDays(10)->toDateString(), [
            'status' => RcsaActionPlan::COMPLETED,
            'completion_evidence' => 'The four-eyes check went live in the core system on the 3rd.',
        ]);

        $this->artisan('rcsa:check-action-plans')->assertSuccessful();

        $this->assertSame(RcsaActionPlan::COMPLETED, $plan->fresh()->status);
        $this->assertSame(0, DB::table('notifications_log')->where('type', 'rcsa.actionplan.overdue')->count());
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $plan = $this->planDue(now()->subDay()->toDateString());

        $this->artisan('rcsa:check-action-plans --dry-run')->assertSuccessful();

        $this->assertSame(RcsaActionPlan::OPEN, $plan->fresh()->status);
        $this->assertSame(0, DB::table('notifications_log')->where('type', 'like', 'rcsa.actionplan.%')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  An extension is a request, not an edit */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function requesting_an_extension_does_not_move_the_date(): void
    {
        $plan = $this->planDue(now()->addDays(5)->toDateString());
        $original = $plan->target_date->toDateString();

        $this->actingAs($this->owner)
            ->post(route('rcsa.action-plans.extension', $plan), [
                'proposed_target_date' => now()->addMonths(2)->toDateString(),
                'extension_reason' => 'The vendor change window moved to the following quarter.',
            ])
            ->assertSessionHasNoErrors();

        $plan->refresh();

        // STILL DUE WHEN IT WAS DUE. A register whose dates slide on request
        // reports 100% on time for ever.
        $this->assertSame($original, $plan->target_date->toDateString());
        $this->assertTrue($plan->hasPendingExtension());

        $this->artisan('rcsa:check-action-plans')->assertSuccessful();
        $this->assertSame(RcsaActionPlan::OPEN, $plan->fresh()->status);
    }

    #[Test]
    public function approving_an_extension_moves_the_date_and_remembers_the_first_one(): void
    {
        $plan = $this->planDue(now()->addDays(5)->toDateString());
        $original = $plan->target_date->toDateString();
        $newDate = now()->addMonths(2)->toDateString();

        $this->actingAs($this->owner)->post(route('rcsa.action-plans.extension', $plan), [
            'proposed_target_date' => $newDate,
            'extension_reason' => 'The vendor change window moved to the following quarter.',
        ]);

        $this->actingAs($this->orm)
            ->post(route('rcsa.action-plans.extension.decide', $plan), ['approve' => true])
            ->assertSessionHasNoErrors();

        $plan->refresh();

        $this->assertSame($newDate, $plan->target_date->toDateString());
        $this->assertSame($original, $plan->original_target_date->toDateString());
        $this->assertSame($this->orm->id, $plan->extension_approved_by);
        $this->assertNull($plan->proposed_target_date);

        // Extended twice, and it is STILL a plan that was due in the original
        // month — the first date is written once and never again.
        $later = now()->addMonths(5)->toDateString();

        $this->actingAs($this->owner)->post(route('rcsa.action-plans.extension', $plan), [
            'proposed_target_date' => $later,
            'extension_reason' => 'The change window slipped a second time.',
        ]);
        $this->actingAs($this->orm)->post(route('rcsa.action-plans.extension.decide', $plan), ['approve' => true]);

        $this->assertSame($original, $plan->fresh()->original_target_date->toDateString());
    }

    #[Test]
    public function refusing_an_extension_leaves_the_date_alone_and_says_so(): void
    {
        $plan = $this->planDue(now()->addDays(5)->toDateString());
        $original = $plan->target_date->toDateString();

        $this->actingAs($this->owner)->post(route('rcsa.action-plans.extension', $plan), [
            'proposed_target_date' => now()->addMonths(2)->toDateString(),
            'extension_reason' => 'It would be more convenient later.',
        ]);

        $this->actingAs($this->orm)->post(route('rcsa.action-plans.extension.decide', $plan), ['approve' => false]);

        $plan->refresh();

        $this->assertSame($original, $plan->target_date->toDateString());
        $this->assertFalse($plan->hasPendingExtension());
        $this->assertSame(
            1,
            DB::table('notifications_log')->where('type', 'rcsa.actionplan.extension_refused')->count(),
        );
    }

    #[Test]
    public function nobody_approves_their_own_extension_request(): void
    {
        $plan = $this->planDue(now()->addDays(5)->toDateString(), ['owner_id' => $this->orm->id]);

        $this->actingAs($this->orm)->post(route('rcsa.action-plans.extension', $plan), [
            'proposed_target_date' => now()->addMonths(2)->toDateString(),
            'extension_reason' => 'I would like a little longer on this one.',
        ]);

        $this->actingAs($this->orm)
            ->post(route('rcsa.action-plans.extension.decide', $plan), ['approve' => true])
            ->assertForbidden();

        $this->assertTrue($plan->fresh()->hasPendingExtension());
    }

    /* ------------------------------------------------------------------ */
    /*  Closure is claimed, then accepted */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function completion_needs_evidence(): void
    {
        $plan = $this->planDue(now()->addDays(20)->toDateString());

        $this->actingAs($this->owner)
            ->post(route('rcsa.action-plans.complete', $plan), ['completion_evidence' => ''])
            ->assertSessionHasErrors('completion_evidence');

        $this->assertSame(RcsaActionPlan::OPEN, $plan->fresh()->status);
    }

    #[Test]
    public function a_completed_plan_is_not_a_closed_one_until_the_orm_says_so(): void
    {
        $plan = $this->planDue(now()->addDays(20)->toDateString());

        $this->actingAs($this->owner)
            ->post(route('rcsa.action-plans.complete', $plan), [
                'completion_evidence' => 'Maker-checker went live on the 14th; change ticket CHG-4471 attached.',
            ])
            ->assertSessionHasNoErrors();

        $plan->refresh();

        $this->assertSame(RcsaActionPlan::COMPLETED, $plan->status);
        $this->assertSame(100, $plan->progress_pct);
        $this->assertNull($plan->verified_at);

        // The ORM is asked to verify.
        $this->assertSame(
            1,
            DB::table('notifications_log')
                ->where('type', 'rcsa.actionplan.completed')
                ->where('user_id', $this->orm->id)
                ->count(),
        );

        $this->actingAs($this->orm)
            ->post(route('rcsa.action-plans.verify', $plan), ['accept' => true])
            ->assertSessionHasNoErrors();

        $plan->refresh();

        $this->assertSame(RcsaActionPlan::CLOSED, $plan->status);
        $this->assertSame($this->orm->id, $plan->verified_by);
    }

    #[Test]
    public function the_person_who_claimed_completion_cannot_verify_it(): void
    {
        $plan = $this->planDue(now()->addDays(20)->toDateString(), ['owner_id' => $this->orm->id]);

        $this->actingAs($this->orm)->post(route('rcsa.action-plans.complete', $plan), [
            'completion_evidence' => 'Done — I put the control in myself.',
        ]);

        $this->actingAs($this->orm)
            ->post(route('rcsa.action-plans.verify', $plan), ['accept' => true])
            ->assertForbidden();

        $this->assertSame(RcsaActionPlan::COMPLETED, $plan->fresh()->status);
    }

    #[Test]
    public function the_orm_can_send_a_closure_back(): void
    {
        $plan = $this->planDue(now()->addDays(20)->toDateString());

        $this->actingAs($this->owner)->post(route('rcsa.action-plans.complete', $plan), [
            'completion_evidence' => 'It is done, take my word for it.',
        ]);

        $this->actingAs($this->orm)
            ->post(route('rcsa.action-plans.verify', $plan), [
                'accept' => false,
                'reason' => 'No change reference and nothing to test against — please attach the evidence.',
            ])
            ->assertSessionHasNoErrors();

        $plan->refresh();

        $this->assertSame(RcsaActionPlan::IN_PROGRESS, $plan->status);
        $this->assertNull($plan->closed_at);
        $this->assertSame(
            1,
            DB::table('notifications_log')->where('type', 'rcsa.actionplan.closure_rejected')->count(),
        );
    }

    #[Test]
    public function an_owner_cannot_update_somebody_elses_plan(): void
    {
        $plan = $this->planDue(now()->addDays(20)->toDateString());

        $stranger = $this->userWith(['rcsa_actionplan.view', 'rcsa_actionplan.update']);

        $this->actingAs($stranger)
            ->patch(route('rcsa.action-plans.progress', $plan), ['progress_pct' => 90])
            ->assertForbidden();

        $this->assertSame(0, $plan->fresh()->progress_pct);
    }

    /* ------------------------------------------------------------------ */
    /*  It outlives the cycle */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_register_survives_the_cycle_that_produced_it(): void
    {
        $plan = $this->planDue(now()->addDays(20)->toDateString());

        $cycle = RcsaCycle::sole();
        app(\App\Services\Rcsa\RcsaCycleService::class)->close($cycle, $this->actor);

        // The assessment is frozen…
        $this->assertFalse($plan->line->assessment->fresh()->acceptsEdits());

        // …and the remediation is still the ORM's business.
        $this->actingAs($this->owner)
            ->get(route('rcsa.action-plans.index', ['mine' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('RcsaActionPlans/Index')->has('plans.data', 1));

        $this->actingAs($this->owner)
            ->post(route('rcsa.action-plans.complete', $plan), [
                'completion_evidence' => 'Delivered after the cycle closed, which is when most remediation lands.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(RcsaActionPlan::COMPLETED, $plan->fresh()->status);
    }

    #[Test]
    public function the_owner_dashboard_counts_only_the_owners_plans(): void
    {
        $mine = $this->planDue(now()->subDays(2)->toDateString());
        $this->planDue(now()->addDays(3)->toDateString(), ['owner_id' => $this->orm->id]);

        $service = app(RcsaActionPlanService::class);

        $all = $service->summaryFor();
        $ownersOwn = $service->summaryFor($this->owner->id);

        // The cycle provisioned six above-appetite lines, each with a plan; two
        // of them have been given owners here and the rest are unowned.
        $this->assertSame(6, $all['total']);
        $this->assertSame(1, $ownersOwn['total']);
        $this->assertSame(1, $ownersOwn['overdue']);
        $this->assertSame(0, $ownersOwn['due_soon']);

        $this->assertSame($mine->owner_id, $this->owner->id);
    }
}
