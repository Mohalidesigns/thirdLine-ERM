<?php

namespace Tests\Feature\Sm;

use App\Models\Sm\Scenario;
use App\Services\Sm\ScenarioLifecycle;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The lifecycle: §A1.8's frozen map with Addendum-4's two corrections, the designed
 * gate, and Correction 1's three rulings.
 *
 * TWO REFUSAL SHAPES, AND WHICH IS WHICH IS THE POINT. An ILLEGAL EDGE answers with a
 * FLASH (§A1.8, following `LossEventController::updateStatus()`) because the user is
 * allowed to try and the machine says no and says why. A FAILED GATE answers with a 422
 * (§A1.5: "answers 422 on key `allocations`"; patched spec 03 §7: "these strings must be
 * the Form Request's messages"). Neither is ever a 403 — standard §3 keeps lifecycle rules
 * out of policies for exactly that reason.
 */
class ScenarioLifecycleTest extends SmTestCase
{
    /* ------------------------------------------------------------------ */
    /*  The frozen map */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_transition_map_is_the_frozen_one_with_both_addendum_4_corrections(): void
    {
        // C1.6 confirmed this const as the authoritative spelling of the map.
        $this->assertSame([
            'draft' => ['in_design', 'archived'],
            'in_design' => ['designed', 'returned_for_rework'],
            'designed' => ['in_validation', 'in_design'],
            'in_validation' => ['validated', 'returned_for_rework'],
            'validated' => ['signed_off', 'returned_for_rework'],
            'signed_off' => ['returned_for_rework', 'in_validation', 'archived'],
            'archived' => [],
            'returned_for_rework' => ['in_design'],
        ], Scenario::STATUS_TRANSITIONS);
    }

    #[Test]
    public function archived_is_terminal_so_no_scenario_can_leave_it(): void
    {
        // ADDENDUM-4 CORRECTION 1. §A1.8 drew an `archived → in_design` edge for the annual
        // refresh; the coordinator ruled no restore and spec 01 §5.5 already said Lifecycle:
        // none. The refresh therefore acts on a SIGNED_OFF scenario, through reopen-for-
        // revalidation. What it costs is recorded in spec 01 §9.1: an archived scenario can
        // only be revived in the database.
        $this->assertSame([], Scenario::STATUS_TRANSITIONS['archived']);

        $scenario = $this->makeScenario(['status' => 'archived']);

        $this->assertFalse($scenario->canTransitionTo('in_design'));
        $this->assertFalse($scenario->canTransitionTo('draft'));

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'in_design'])
            ->assertSessionHas('error');

        $this->assertSame('archived', $scenario->fresh()->status);
    }

    #[Test]
    public function signed_off_can_be_reopened_for_revalidation_and_also_sent_back_into_validation(): void
    {
        // ADDENDUM-4 CORRECTION 2. §A1.8 allowed only `signed_off → in_validation`, which
        // cannot express spec 01 §5.5's "Reopen for revalidation" in one step. The two exits
        // are DIFFERENT ACTS and both survive: one makes the scenario editable, the other
        // does not, and merging them would either freeze a record that needs editing or let a
        // signer edit without a second pair of eyes.
        $this->assertSame(
            ['returned_for_rework', 'in_validation', 'archived'],
            Scenario::STATUS_TRANSITIONS['signed_off']
        );

        $editable = $this->makeScenario(['status' => 'signed_off']);
        $this->actingAs($this->actor)->post(route('scenario.status', $editable), [
            'to' => 'returned_for_rework',
            'reason' => 'The allocation no longer matches the reorganisation.',
        ])->assertRedirect();

        $this->assertSame('returned_for_rework', $editable->fresh()->status);
        $this->assertFalse($editable->fresh()->isReadOnly(), 'reopening must make it editable again');

        $readOnly = $this->makeScenario(['status' => 'signed_off']);
        $this->actingAs($this->actor)->post(route('scenario.status', $readOnly), ['to' => 'in_validation'])
            ->assertRedirect();

        $this->assertSame('in_validation', $readOnly->fresh()->status);
        $this->assertTrue($readOnly->fresh()->isReadOnly(), 'sending it back must keep it read-only');
    }

    /** Every legal edge moves the status. */
    #[Test]
    #[DataProvider('legalEdgeProvider')]
    public function every_legal_edge_moves_the_status(string $from, string $to): void
    {
        // Two edges carry a guard of their own, so the fixture has to satisfy it or this test
        // would be asserting the guard rather than the MAP: `in_design → designed` needs
        // §A1.8's four requirements, and `validated → signed_off` needs C1.2's three.
        $scenario = $to === 'designed'
            ? $this->makeDesignedReadyScenario()
            : $this->makeScenario(['status' => $from]);

        if ($to === 'signed_off') {
            $this->prepareForSignOff($scenario);
        }

        $payload = ['to' => $to];

        if ($to === 'returned_for_rework') {
            $payload['reason'] = 'The design has to change before this can go further.';
        }

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), $payload)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame($to, $scenario->fresh()->status);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function legalEdgeProvider(): array
    {
        $edges = [];

        foreach (Scenario::STATUS_TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $edges["{$from} to {$to}"] = [$from, $to];
            }
        }

        return $edges;
    }

    /** An edge the map does not draw is refused with a FLASH, not a 403 and not a 422. */
    #[Test]
    #[DataProvider('illegalEdgeProvider')]
    public function an_illegal_edge_is_refused_with_a_flash_and_not_a_403(string $from, string $to): void
    {
        $scenario = $this->makeScenario(['status' => $from]);

        $response = $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), [
                'to' => $to,
                'reason' => 'A reason long enough to satisfy the rule if it applied.',
            ]);

        $response->assertSessionHas('error');

        $this->assertNotSame(403, $response->getStatusCode(), 'a lifecycle rule must not answer as an authorization failure');
        $this->assertNotSame(422, $response->getStatusCode(), 'an illegal edge is a flash, not a validation error');
        $this->assertSame($from, $scenario->fresh()->status, 'the status must not have moved');
    }

    /** @return list<array{0: string, 1: string}> */
    public static function illegalEdgeProvider(): array
    {
        return [
            'draft cannot be signed off' => ['draft', 'signed_off'],
            'draft cannot be validated' => ['draft', 'validated'],
            'in_design cannot be signed off' => ['in_design', 'signed_off'],
            'designed cannot be signed off directly' => ['designed', 'signed_off'],
            'in_validation cannot be signed off' => ['in_validation', 'signed_off'],
            'validated cannot go back to in_design' => ['validated', 'in_design'],
            'archived cannot be revived' => ['archived', 'in_design'],
            'returned_for_rework cannot be validated directly' => ['returned_for_rework', 'validated'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  The designed gate */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_designed_gate_refuses_an_allocation_of_99_99(): void
    {
        $scenario = $this->makeDesignedReadyScenario();
        $this->allocate($scenario, [[0, '59.99'], [1, '40.00']]);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'designed'])
            ->assertSessionHasErrors(['allocations' => 'Business-line allocation adds to 99.99%. It must add to exactly 100%.']);

        $this->assertSame('in_design', $scenario->fresh()->status);
    }

    #[Test]
    public function the_designed_gate_refuses_an_allocation_of_100_01(): void
    {
        $scenario = $this->makeDesignedReadyScenario();
        $this->allocate($scenario, [[0, '60.01'], [1, '40.00']]);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'designed'])
            ->assertSessionHasErrors('allocations');

        $this->assertSame('in_design', $scenario->fresh()->status);
    }

    #[Test]
    public function the_designed_gate_refuses_three_lines_of_33_33_because_that_is_99_99(): void
    {
        // Spec 03 §5.2's exact case: "three lines of 33.33 are a GENUINE validation failure
        // the user has to fix, not a float artefact silently accepted". Float arithmetic
        // would call this 99.99000000000001 and might round it into a pass.
        $scenario = $this->makeDesignedReadyScenario();
        $this->units[2] = $this->units[2] ?? $this->makeUnit('BU-EX', 'Extra');

        $this->allocate($scenario, [[0, '33.33'], [1, '33.33'], [2, '33.33']]);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'designed'])
            ->assertSessionHasErrors(['allocations' => 'Business-line allocation adds to 99.99%. It must add to exactly 100%.']);
    }

    #[Test]
    public function the_designed_gate_accepts_an_allocation_of_exactly_100_00(): void
    {
        $scenario = $this->makeDesignedReadyScenario();

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'designed'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('designed', $scenario->fresh()->status);
    }

    #[Test]
    public function saving_an_allocation_of_60_percent_succeeds_because_the_sum_is_only_checked_at_the_transition(): void
    {
        // §A1.5: enforced "at the in_design → designed transition and NOT on every draft
        // save — a draft legitimately sums to 60% mid-entry". Patched spec 03 §5.2: "Save
        // allocation succeeds with any sum", and a screen that refused would be enforcing a
        // stricter rule than the server has.
        $scenario = $this->makeScenario(['status' => 'in_design']);

        $this->actingAs($this->actor)
            ->put(route('scenario.allocations.update', $scenario), [
                'allocations' => [
                    ['business_unit_id' => $this->units[0]->id, 'percent' => '60.00'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('60.00', $scenario->fresh()->allocations->first()->percent);
    }

    #[Test]
    public function a_percent_posted_without_decimals_is_accepted_and_stored_at_the_column_precision(): void
    {
        // The rule's own message promises "Use at most 2 decimal places." `decimal:2`
        // required EXACTLY two, so a stale tab or a direct PUT posting "60" was refused
        // with wording that contradicted the refusal — the frontend handoff's
        // [backend defect 3]. `decimal:0,2` accepts it and normalisePercent() stores the
        // same "60.00" the padded post would.
        $scenario = $this->makeScenario(['status' => 'in_design']);

        $this->actingAs($this->actor)
            ->put(route('scenario.allocations.update', $scenario), [
                'allocations' => [
                    ['business_unit_id' => $this->units[0]->id, 'percent' => '60'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('60.00', $scenario->fresh()->allocations->first()->percent);
    }

    #[Test]
    public function the_designed_gate_refuses_a_scenario_with_no_cause_no_mapping_and_no_narrative(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);

        $response = $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'designed']);

        // §A1.8's four requirements, with spec 02/03 §7's messages verbatim.
        $response->assertSessionHasErrors([
            'risk_taxonomy_node_id' => 'Choose the OP event type this scenario maps to.',
            'background' => 'Describe how this scenario materialises.',
            'rationale' => 'Say why this scenario was designed.',
            'causes' => "Select at least one cause. A scenario with no stated cause can't be validated.",
            'allocations' => 'Allocate this scenario to at least one business line.',
        ]);

        $this->assertSame('in_design', $scenario->fresh()->status);
    }

    #[Test]
    public function the_designed_gate_does_not_require_the_five_exposure_answers(): void
    {
        // PATCHED SPEC 03 §7 AND SPEC 02 §4.1: "None of the five exposure fields is gated,
        // and the UI must not gate them … requiring them would block a transition the server
        // allows — a screen that enforces a stricter rule than its backend is a screen that
        // lies about the rules." An earlier draft of spec 03 required them and addendum-1
        // read that draft as settled; the patched spec reverses it and §A1.8 never listed
        // them. They surface as SIGN-OFF WARNINGS instead, where a signer can act on them.
        $scenario = $this->makeDesignedReadyScenario();

        $this->assertNull($scenario->reputational_exposure);
        $this->assertNull($scenario->legal_compliance_exposure);
        $this->assertNull($scenario->insurance_risk);
        $this->assertNull($scenario->financial_risk);

        $gate = app(ScenarioLifecycle::class)->gate($scenario);

        $this->assertTrue($gate['ready'], 'the four unanswered exposures must not block the gate');
        $this->assertSame([], $gate['missing']);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'designed'])
            ->assertSessionHasNoErrors();

        $this->assertSame('designed', $scenario->fresh()->status);
    }

    #[Test]
    public function the_gate_is_null_for_a_status_that_has_nothing_to_be_ready_for(): void
    {
        $lifecycle = app(ScenarioLifecycle::class);

        foreach (['in_validation', 'validated', 'signed_off', 'archived'] as $status) {
            $this->assertNull(
                $lifecycle->gate($this->makeScenario(['status' => $status])),
                "gate should be null for {$status}"
            );
        }

        // A draft is editable, so it does get a gate — the readiness strip is a note and not
        // an alarm, but it is honest about what is missing.
        $this->assertIsArray($lifecycle->gate($this->makeScenario(['status' => 'draft'])));
    }

    /* ------------------------------------------------------------------ */
    /*  C1.3 — the reason and the status change are one transaction */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_return_without_a_reason_is_refused_with_spec_01s_message(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_validation']);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'returned_for_rework'])
            ->assertSessionHasErrors([
                'reason' => 'Say what has to change. A return with no reason gives the designer nothing to work from.',
            ]);

        $this->assertSame('in_validation', $scenario->fresh()->status);
    }

    #[Test]
    public function a_return_reason_shorter_than_ten_characters_is_refused(): void
    {
        // C1.3: "string 10–500 — the shape ADR 0026 A1.10 froze for ReopenKriReturnRequest".
        // The minimum is the point: a reason of "no" gives the designer nothing to work from.
        $scenario = $this->makeScenario(['status' => 'in_validation']);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'returned_for_rework', 'reason' => 'no'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('in_validation', $scenario->fresh()->status);
        $this->assertSame(0, $scenario->notes()->count());
    }

    #[Test]
    public function a_return_writes_its_reason_as_a_challenge_note_in_the_same_transaction(): void
    {
        // C1.3, and the reason spec 01 §5.3 called the two-request alternative "a real
        // defect": a failure between the writes leaves a returned scenario with no recorded
        // reason, and the trail D4 rests on would have a hole in it.
        $scenario = $this->makeScenario(['status' => 'in_validation']);
        $reason = 'The eighteen-month replacement assumption is not supported by the HR data.';

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'returned_for_rework', 'reason' => $reason])
            ->assertSessionHasNoErrors();

        $this->assertSame('returned_for_rework', $scenario->fresh()->status);

        $note = $scenario->notes()->sole();

        $this->assertSame('challenge', $note->kind);
        $this->assertSame($reason, $note->body);
        // Identity is not a field: the author is the authenticated user and the name is a
        // snapshot, because "an anonymous dissent cannot be followed up" (§A1.7).
        $this->assertSame($this->actor->id, $note->author_id);
        $this->assertSame($this->actor->name, $note->author_name);
    }

    #[Test]
    public function reopening_a_signed_off_scenario_also_records_its_reason_as_a_challenge(): void
    {
        $scenario = $this->makeScenario(['status' => 'signed_off']);

        $this->actingAs($this->actor)->post(route('scenario.status', $scenario), [
            'to' => 'returned_for_rework',
            'reason' => 'The sign-off predates the reorganisation that moved this unit.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('challenge', $scenario->notes()->sole()->kind);
    }

    #[Test]
    public function a_transition_that_does_not_need_a_reason_does_not_write_a_note(): void
    {
        $scenario = $this->makeScenario(['status' => 'draft']);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'in_design'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $scenario->notes()->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Server-stamped dates */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function validating_stamps_validated_on_server_side_and_it_is_never_a_posted_field(): void
    {
        // Spec 02 §4: "a designer who can type a validation date can type a date nobody
        // validated anything on". Stamped by the server, read-only everywhere else.
        $scenario = $this->makeScenario(['status' => 'in_validation']);

        $this->assertNull($scenario->validated_on);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'validated'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($scenario->fresh()->validated_on);

        // And a posted one is dropped: UpdateScenarioRequest has no rule for it, so it never
        // reaches validated(), and ScenarioService unsets it again as a backstop.
        $editable = $this->makeScenario(['status' => 'in_design']);

        $this->actingAs($this->actor)->put(route('scenario.update', $editable), [
            'name' => 'Renamed',
            'validated_on' => '1999-01-01',
        ])->assertSessionHasNoErrors();

        $this->assertNull($editable->fresh()->validated_on);
    }

    #[Test]
    public function designed_on_is_never_pre_filled_or_stamped_by_the_server(): void
    {
        // Spec 02 §5.9: "It is NOT pre-filled with today. A date field that silently
        // defaults to today is how a scenario ends up claiming a design date nobody agreed."
        $scenario = $this->makeDesignedReadyScenario();

        $this->assertNull($scenario->designed_on);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'designed'])
            ->assertSessionHasNoErrors();

        $this->assertNull($scenario->fresh()->designed_on, 'the transition must not invent a design date');
    }

    /* ------------------------------------------------------------------ */
    /*  Read-only statuses */
    /* ------------------------------------------------------------------ */

    #[Test]
    #[DataProvider('readOnlyStatusProvider')]
    public function a_read_only_scenario_refuses_content_edits_with_a_flash_and_not_a_403(string $status): void
    {
        // Spec 01 §5.5: from in_validation onward the record is read-only, because "a
        // validator reading a record the designer can still change is validating nothing".
        $scenario = $this->makeScenario(['status' => $status, 'background' => 'Original background']);

        $response = $this->actingAs($this->actor)
            ->put(route('scenario.update', $scenario), ['background' => 'Overwritten while frozen']);

        $response->assertSessionHas('error');
        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertSame('Original background', $scenario->fresh()->background);
    }

    /** @return list<array{0: string}> */
    public static function readOnlyStatusProvider(): array
    {
        return [['in_validation'], ['validated'], ['signed_off'], ['archived']];
    }

    #[Test]
    public function an_editable_status_accepts_a_content_edit(): void
    {
        foreach (['draft', 'in_design', 'designed', 'returned_for_rework'] as $status) {
            $scenario = $this->makeScenario(['status' => $status]);

            $this->actingAs($this->actor)
                ->put(route('scenario.update', $scenario), ['background' => "Written while {$status}"])
                ->assertSessionHasNoErrors();

            $this->assertSame("Written while {$status}", $scenario->fresh()->background);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  The transition list is server-filtered */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_transitions_prop_is_filtered_by_status_and_by_permission(): void
    {
        // Spec 01 §3: "the client holds NO lifecycle state machine … A client that re-derives
        // the machine from `can` will eventually offer a transition the controller refuses."
        $scenario = $this->makeScenario(['status' => 'draft']);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page->where('transitions', function ($transitions) {
                $targets = collect($transitions)->pluck('to')->all();

                $this->assertSame(['in_design', 'archived'], $targets);
                $this->assertSame('Start design', $transitions[0]['label']);
                $this->assertSame('Archive', $transitions[1]['label']);
                $this->assertSame('danger', $transitions[1]['variant']);
                // Spec 01 §5.2: an edge into `returned_for_rework` opens the reason modal and
                // nothing else does except the sign-off flow.
                $this->assertFalse($transitions[0]['requires_reason']);
                $this->assertNull($transitions[0]['opens_modal']);

                return true;
            }));

        // A designer holding scenario.update alone gets NO transition buttons: with §A1.10's
        // grants only risk-manager, CRO and super-admin hold scenario.validate. Silence there
        // would read as a broken button, so the readiness strip says it in words instead.
        $designer = $this->userWith(['scenario.view', 'scenario.update'], 'designer@example.test');

        $this->actingAs($designer)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page->where('transitions', []));
    }

    #[Test]
    public function every_transition_edge_has_a_button_label_in_config(): void
    {
        // Spec 01 §5.2: "A label the server does not send does not render: the shell has no
        // fallback vocabulary." So an edge without a word is an invisible button.
        $labels = (array) config('sm.transition_labels');

        foreach (Scenario::STATUS_TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $this->assertArrayHasKey(
                    "{$from}>{$to}",
                    $labels,
                    "The edge {$from} → {$to} has no button label, so its button would render with no words."
                );
            }
        }
    }

    #[Test]
    public function the_scenario_edit_route_redirects_to_the_workspace(): void
    {
        // C1.4: a redirect with no screen behind it, kept registered so both route guards
        // still see it and a bookmarked /edit link resolves instead of 404ing.
        $this->assertTrue(Route::has('scenario.edit'));

        $scenario = $this->makeScenario();

        $this->actingAs($this->actor)
            ->get(route('scenario.edit', $scenario))
            ->assertRedirect(route('scenario.show', ['scenario' => $scenario, 'tab' => 'overview']));
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** Give a scenario what C1.2's guard asks for, so a map test is not testing the guard. */
    private function prepareForSignOff(Scenario $scenario): void
    {
        $scenario->forceFill(['review_due_on' => '2027-11-10'])->save();

        $this->makeSignoff($scenario, [
            'source' => 'source_1',
            'decision' => 'approved',
            'is_independent_challenge' => true,
            'signatory_name' => 'B. Adeyemi',
        ]);
    }
}
