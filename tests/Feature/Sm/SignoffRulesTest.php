<?php

namespace Tests\Feature\Sm;

use App\Models\Sm\Scenario;
use App\Models\Sm\Signoff;
use PHPUnit\Framework\Attributes\Test;

/**
 * The sign-off rules (§A1.6, spec 01 §4.6/§4.7/§5.6, Correction 1 §C1.2 and §C1.5).
 *
 * THE TABLE IS APPEND-ONLY AND HAS NO UNIQUE KEY ON (scenario, source), deliberately:
 * sign-off freezes and post-sign-off edits require revalidation, so source 1 signs, the
 * scenario is returned, and source 1 signs AGAIN — a unique key would force an UPDATE over
 * the record this table exists to protect. The consequence is that "who signed this" is a
 * QUERY, not a row, and these tests pin the query.
 */
class SignoffRulesTest extends SmTestCase
{
    /* ------------------------------------------------------------------ */
    /*  Latest row per source */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_current_sign_off_for_a_source_is_the_latest_row_by_signed_at(): void
    {
        $scenario = $this->makeScenario(['status' => 'validated']);

        $first = $this->makeSignoff($scenario, [
            'source' => 'source_1',
            'decision' => 'approved',
            'signatory_name' => 'A. Okonkwo',
            'signed_at' => now()->subDays(30),
        ]);

        $second = $this->makeSignoff($scenario, [
            'source' => 'source_1',
            'decision' => 'returned_for_rework',
            'signatory_name' => 'A. Okonkwo',
            'signed_at' => now()->subDay(),
        ]);

        $current = $scenario->currentSignoffs();

        $this->assertCount(1, $current, 'one source, one current row');
        $this->assertSame($second->id, $current['source_1']->id);
        $this->assertNotSame($first->id, $current['source_1']->id);
        // The superseded row is NOT deleted: it is history, and Phase 3's Sign-off & audit
        // tab is where it gets a surface (spec 01 §9.8).
        $this->assertSame(2, $scenario->signoffs()->count());
    }

    #[Test]
    public function a_row_with_a_null_signed_at_does_not_count_toward_the_lifecycle(): void
    {
        // §A1.6: `signed_at` is nullable per standard §13, Phase 1 writes a row only from the
        // sign action so it is non-null on every row Phase 1 creates, and ONLY NON-NULL ROWS
        // COUNT. The filter is asserted rather than assumed because `requested_at` arrives in
        // Phase 3 and a null-`signed_at` row must not then start counting.
        $scenario = $this->makeScenario(['status' => 'validated']);

        $requested = $this->makeSignoff($scenario, ['source' => 'source_1', 'signed_at' => null]);

        $this->assertSame([], $scenario->currentSignoffs());
        $this->assertDatabaseHas('sm_scenario_signoffs', ['id' => $requested->id]);
    }

    #[Test]
    public function two_rows_for_one_source_signed_in_the_same_second_resolve_deterministically(): void
    {
        // Ties broken by id descending, so "latest" is the same answer on MariaDB 10.4 and
        // MySQL 8.0.46. A governance record that reads differently in CI than in production
        // is worse than one that reads wrongly but consistently.
        $scenario = $this->makeScenario(['status' => 'validated']);
        $at = now();

        $older = $this->makeSignoff($scenario, ['source' => 'source_1', 'signed_at' => $at, 'decision' => 'returned_for_rework']);
        $newer = $this->makeSignoff($scenario, ['source' => 'source_1', 'signed_at' => $at, 'decision' => 'approved']);

        $this->assertSame($newer->id, $scenario->currentSignoffs()['source_1']->id);
        $this->assertNotSame($older->id, $scenario->currentSignoffs()['source_1']->id);
    }

    #[Test]
    public function the_payload_marks_is_current_so_the_client_never_sorts_or_picks_latest_itself(): void
    {
        // Spec 01 §4.6: "The client never sorts or picks 'latest' itself — with signed_at
        // nullable and only non-null rows counting toward the lifecycle, that logic is the
        // server's, and duplicating it in JS is how two surfaces come to disagree about who
        // signed."
        $scenario = $this->makeScenario(['status' => 'validated']);

        $this->makeSignoff($scenario, ['source' => 'source_1', 'signed_at' => now()->subDays(10)]);
        $current = $this->makeSignoff($scenario, ['source' => 'source_1', 'signed_at' => now()]);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page->where('signoffs', function ($rows) use ($current) {
                $this->assertCount(2, $rows);

                $flags = collect($rows)->keyBy('id')->map(fn ($row) => $row['is_current']);

                $this->assertTrue($flags[$current->id]);
                $this->assertFalse($flags->except($current->id)->first());

                return true;
            }));
    }

    /* ------------------------------------------------------------------ */
    /*  The signed_off guard (C1.2) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function signing_off_needs_at_least_one_current_approved_sign_off(): void
    {
        // §A1.8's guard. Note it reads CURRENT rows: a source that approved, was returned,
        // and has not signed again has no current approval, and counting the superseded row
        // would sign off a scenario on evidence that was withdrawn.
        $scenario = $this->makeScenario(['status' => 'validated', 'review_due_on' => '2027-11-10']);

        $this->makeSignoff($scenario, [
            'source' => 'source_1',
            'decision' => 'approved',
            'is_independent_challenge' => true,
            'signed_at' => now()->subDays(20),
        ]);
        $this->makeSignoff($scenario, [
            'source' => 'source_1',
            'decision' => 'returned_for_rework',
            'signed_at' => now(),
        ]);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'signed_off'])
            ->assertSessionHas('error', "This scenario can't be signed off yet: no sign-off source has approved it.");

        $this->assertSame('validated', $scenario->fresh()->status);
    }

    #[Test]
    public function signing_off_needs_at_least_one_independent_challenge_and_the_server_refuses_without_one(): void
    {
        // Spec 01 §5.6.3: "AT LEAST ONE CHECKED ROW IS REQUIRED TO SIGN OFF … a control that
        // only exists in the UI is not a control: the server must refuse the sign-off too."
        $scenario = $this->makeScenario(['status' => 'validated', 'review_due_on' => '2027-11-10']);

        $this->makeSignoff($scenario, ['source' => 'source_1', 'decision' => 'approved', 'is_independent_challenge' => false]);
        $this->makeSignoff($scenario, ['source' => 'source_2', 'decision' => 'approved', 'is_independent_challenge' => false]);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'signed_off'])
            ->assertSessionHas(
                'error',
                'At least one signer must be independent of the team that designed this scenario. Tick the box beside that signer.'
            );

        $this->assertSame('validated', $scenario->fresh()->status);

        // And with one independent signer it goes through.
        $this->makeSignoff($scenario, ['source' => 'source_3', 'decision' => 'approved', 'is_independent_challenge' => true]);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'signed_off'])
            ->assertSessionHasNoErrors();

        $this->assertSame('signed_off', $scenario->fresh()->status);
    }

    #[Test]
    public function signing_off_needs_a_review_date_even_though_the_modal_already_asks_for_one(): void
    {
        // C1.2. Spec 01 §5.6.4 required `review_due_on` in the modal and flagged the mismatch
        // itself: "a UI-only rule, which a stale tab or a direct POST bypasses". A requirement
        // that exists only in JSX is not a requirement, and the result of skipping it is a
        // signed-off scenario that never comes back for its annual refresh — precisely the gap
        // plan §3.3 capability 10 exists to close, and the reason §A1.2 added
        // `sm_scenarios_org_review_due_idx`.
        $scenario = $this->makeScenario(['status' => 'validated']);

        $this->assertNull($scenario->review_due_on);

        $this->makeSignoff($scenario, ['decision' => 'approved', 'is_independent_challenge' => true]);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'signed_off'])
            ->assertSessionHas('error', 'Set the next review date before signing off.');

        $this->assertSame('validated', $scenario->fresh()->status);
        $this->assertNull($scenario->fresh()->review_due_on, 'a refused transition must not stamp anything');
    }

    #[Test]
    public function an_author_cannot_sign_their_own_scenario_off_even_with_a_co_signer_they_also_are(): void
    {
        // The combined effect of C1.5 and C1.2, which is the point of both: D4's
        // bias-mitigation trail rests on independent challenge MEANING something.
        $author = $this->userWith(self::ALL_PERMISSIONS, 'author@example.test');
        $scenario = $this->makeScenario(['status' => 'validated', 'review_due_on' => '2027-11-10', 'created_by' => $author->id]);

        $this->actingAs($author)->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload([
            'signoffs' => [
                ['source' => 'source_1', 'signatory_name' => $author->name, 'role' => 'Head of Operational Risk', 'user_id' => $author->id, 'decision' => 'approved', 'is_independent_challenge' => true],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertFalse(
            (bool) $scenario->signoffs()->sole()->is_independent_challenge,
            'C1.5 must force the flag false for the scenario author'
        );

        $this->actingAs($author)
            ->post(route('scenario.status', $scenario), ['to' => 'signed_off'])
            ->assertSessionHas('error');

        $this->assertSame('validated', $scenario->fresh()->status);
    }

    /* ------------------------------------------------------------------ */
    /*  C1.5 — the author self-challenge rule */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_author_claiming_independence_is_recorded_with_the_flag_forced_false_and_a_flash_not_a_422(): void
    {
        // C1.5: "forced to false WITH A FLASH MESSAGE, not a 422, because the sign-off itself
        // is legitimate and only the claim of independence is refused." A 422 would throw away
        // a real approval to police a checkbox.
        $author = $this->userWith(self::ALL_PERMISSIONS, 'author2@example.test');
        $scenario = $this->makeScenario(['status' => 'validated', 'created_by' => $author->id]);

        $this->actingAs($author)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload([
                'signoffs' => [
                    ['source' => 'source_1', 'signatory_name' => $author->name, 'role' => 'Head of Operational Risk', 'user_id' => $author->id, 'decision' => 'approved', 'is_independent_challenge' => true],
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning');

        $row = $scenario->signoffs()->sole();

        // The sign-off LANDED — only the independence claim was refused.
        $this->assertSame('approved', $row->decision);
        $this->assertFalse((bool) $row->is_independent_challenge);
        $this->assertStringContainsString("isn't independent", (string) session('warning'));
        $this->assertStringContainsString($author->name, (string) session('warning'));
    }

    #[Test]
    public function a_signer_who_is_not_the_author_keeps_the_independence_flag(): void
    {
        $author = $this->userWith(['scenario.view'], 'author3@example.test');
        $challenger = $this->userWith(self::ALL_PERMISSIONS, 'challenger@example.test');

        $scenario = $this->makeScenario(['status' => 'validated', 'created_by' => $author->id]);

        $this->actingAs($challenger)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload([
                'signoffs' => [
                    ['source' => 'source_1', 'signatory_name' => $challenger->name, 'role' => 'Second Line — Independent Challenge', 'user_id' => $challenger->id, 'decision' => 'approved', 'is_independent_challenge' => true],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $scenario->signoffs()->sole()->is_independent_challenge);
        $this->assertNull(session('warning'));
    }

    #[Test]
    public function a_null_user_id_stays_recordable_and_passes_because_the_actor_is_not_the_author(): void
    {
        // RE-PINNED BY C2.1, WHICH SUPERSEDES THE REASON THIS TEST USED TO GIVE. `user_id` is
        // nullable (§A1.6) so an external committee member or a group CRO without an account
        // can sign, and null must stay RECORDABLE: refusing these rows "would make an external
        // challenge unrecordable, which is the one challenge most worth having". That half
        // stands. What Correction 1 got wrong is the other half — that a null `user_id` is "not
        // the author by definition, so it passes". C2.1 separates the two claims: null is no
        // longer UNPOLICED, and the rule now keys on the ACTING user when the row names no
        // signer, because `SignoffModal.jsx` posts no `user_id` at all.
        //
        // So this pass-through holds for one reason and one reason only: THE POSTER IS NOT THE
        // AUTHOR. The next test is the same payload posted by the author, and it does not pass.
        $author = $this->userWith(['scenario.view'], 'author4@example.test');
        $scenario = $this->makeScenario(['status' => 'validated', 'created_by' => $author->id]);

        // The ACTING user holds scenario.sign_off and is SCRIBING a challenge made by somebody
        // with no account at all — the legitimate case the nullable column exists for.
        $this->assertNotSame(
            (int) $author->id,
            (int) $this->actor->id,
            'the actor is the scribe, not the author: that is what makes this row independent'
        );

        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload([
                'signoffs' => [
                    ['source' => 'source_1', 'signatory_name' => 'External Committee Member', 'role' => 'Group CRO', 'user_id' => null, 'decision' => 'approved', 'is_independent_challenge' => true],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $row = $scenario->signoffs()->sole();

        $this->assertNull($row->user_id);
        $this->assertTrue((bool) $row->is_independent_challenge);
        $this->assertNull(session('warning'));
    }

    #[Test]
    public function an_author_posting_the_shape_the_ui_actually_sends_cannot_claim_independence_or_sign_off(): void
    {
        // C2.1 REQUIRED TEST (a), AND THE BLOCKER IT CLOSES. `SignoffModal.jsx` posts
        // `{source, signatory_name, role, decision, is_independent_challenge}` and NO `user_id`,
        // so Correction 1's `$signerId !== null && …` comparison made `$isAuthor` ALWAYS FALSE
        // through the only write surface that exists: an author self-ticked independent
        // challenge, the row stored true, and C1.2's guard was satisfied. `RiskPermissionCatalog`
        // names C1.5 as "the only STRUCTURAL check" against one person creating, designing,
        // validating and signing off their own scenario — so shipped, there was none.
        $author = $this->userWith(self::ALL_PERMISSIONS, 'author5@example.test');
        $scenario = $this->makeScenario([
            'status' => 'validated',
            'review_due_on' => '2027-11-10',
            'created_by' => $author->id,
        ]);

        $payload = $this->signoffPayload([
            'signoffs' => [
                // THE UI'S ACTUAL SHAPE: no `user_id` key at all, not even a null one.
                ['source' => 'source_1', 'signatory_name' => $author->name, 'role' => 'Head of Operational Risk', 'decision' => 'approved', 'is_independent_challenge' => true],
            ],
        ]);

        $this->assertArrayNotHasKey('user_id', $payload['signoffs'][0], 'this is what the modal sends');

        $this->actingAs($author)
            ->post(route('scenario.signoffs.store', $scenario), $payload)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning');

        $row = $scenario->signoffs()->sole();

        // The sign-off LANDED — only the independence claim was refused. A flash, not a 422.
        $this->assertSame('approved', $row->decision);
        $this->assertNull($row->user_id);
        $this->assertFalse((bool) $row->is_independent_challenge, 'C1.5 keys on the ACTING user when the row names no signer');
        $this->assertStringContainsString("isn't independent", (string) session('warning'));
        $this->assertStringContainsString($author->name, (string) session('warning'));

        // C1.2 THEN DOES ITS JOB, which is the point of both rules together: the author's
        // self-ticked challenge no longer satisfies "at least one independent challenge", so an
        // author cannot sign their own scenario off. FAILS CLOSED — sign-off gets harder, never
        // falsely easier (C2.1).
        $this->actingAs($author)
            ->post(route('scenario.status', $scenario), ['to' => 'signed_off'])
            ->assertSessionHas('error', 'At least one signer must be independent of the team that designed this scenario. Tick the box beside that signer.');

        $this->assertSame('validated', $scenario->fresh()->status);
    }

    #[Test]
    public function a_non_author_actor_posting_the_ui_shape_records_a_genuine_external_challenge(): void
    {
        // C2.1 REQUIRED TEST (b) on the UI's own payload shape: the correction polices WHO IS
        // POSTING, not whether the signer has an account. A second-line officer scribing an
        // external committee member's challenge stores the flag, because the scribe did not
        // design the scenario.
        $author = $this->userWith(['scenario.view'], 'author6@example.test');
        $scribe = $this->userWith(self::ALL_PERMISSIONS, 'scribe@example.test');

        $scenario = $this->makeScenario(['status' => 'validated', 'created_by' => $author->id]);

        $payload = $this->signoffPayload([
            'signoffs' => [
                ['source' => 'source_1', 'signatory_name' => 'External Committee Member', 'role' => 'Group CRO', 'decision' => 'approved', 'is_independent_challenge' => true],
            ],
        ]);

        $this->assertArrayNotHasKey('user_id', $payload['signoffs'][0]);

        $this->actingAs($scribe)
            ->post(route('scenario.signoffs.store', $scenario), $payload)
            ->assertSessionHasNoErrors();

        $row = $scenario->signoffs()->sole();

        $this->assertNull($row->user_id);
        $this->assertTrue((bool) $row->is_independent_challenge);
        $this->assertNull(session('warning'));
    }

    #[Test]
    public function an_author_scribing_a_named_signer_who_is_not_the_author_keeps_the_independence_flag(): void
    {
        // C2.1 REQUIRED TEST (c), and the half of the correction that is NOT a narrowing: when
        // the row DOES name a signer, the comparison is against that signer exactly as
        // Correction 1 froze it. The two checks are alternatives over a nullable field, not a
        // replacement of one by the other — the author here is only the scribe.
        $author = $this->userWith(self::ALL_PERMISSIONS, 'author7@example.test');
        $challenger = $this->userWith([], 'challenger2@example.test');

        $scenario = $this->makeScenario(['status' => 'validated', 'created_by' => $author->id]);

        $this->actingAs($author)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload([
                'signoffs' => [
                    ['source' => 'source_1', 'signatory_name' => $challenger->name, 'role' => 'Second Line — Independent Challenge', 'user_id' => $challenger->id, 'decision' => 'approved', 'is_independent_challenge' => true],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $row = $scenario->signoffs()->sole();

        $this->assertSame($challenger->id, $row->user_id);
        $this->assertTrue((bool) $row->is_independent_challenge);
        $this->assertNull(session('warning'));
    }

    /* ------------------------------------------------------------------ */
    /*  C2.2 — the status guard on RECORDING sign-offs */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_sign_off_post_on_a_signed_off_scenario_is_refused_with_a_flash_and_writes_nothing(): void
    {
        // F-2/C2.2. `storeNotes` refused an archived record and `storeSignoffs` refused NOTHING,
        // so a frozen scenario could still gain rows and a new review date — breaking spec 01
        // §5.5's "the figures it carries are the ones recorded at sign-off" from the side nobody
        // watches. A flash and never a 403 (standard §3): the user is allowed to try.
        $scenario = $this->makeScenario(['status' => 'signed_off', 'review_due_on' => '2027-11-10']);

        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload(['review_due_on' => '2030-01-01']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'error',
                'This scenario is signed off and frozen. Reopen it for revalidation to record a new sign-off.'
            );

        $this->assertSame(0, $scenario->signoffs()->count(), 'no row landed');

        // THE DATE WRITE RIDES THE SAME REFUSAL: it is part of one act, not a second endpoint,
        // and the posted 2030 date must not have replaced the one recorded at sign-off.
        $this->assertSame('2027-11-10', $scenario->fresh()->review_due_on?->toDateString());
    }

    #[Test]
    public function a_sign_off_post_on_an_archived_scenario_is_refused_with_a_flash(): void
    {
        // `archived` is terminal (Addendum-4) and is also the one status from which `signed_off`
        // is unreachable, so both clauses of C2.2's guard fire on it.
        $scenario = $this->makeScenario(['status' => 'archived']);

        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'error',
                'This scenario is archived. Sign-offs can no longer be recorded against it.'
            );

        $this->assertSame(0, $scenario->signoffs()->count());
        $this->assertNull($scenario->fresh()->review_due_on, 'the review date is refused along with the rows');
    }

    #[Test]
    public function the_legitimate_validated_to_signed_off_path_the_guard_trap_would_have_broken_still_works(): void
    {
        // C2.2's TRAP, PINNED AS A TEST rather than left as a comment. `validated` IS read-only
        // to content edits, so a guard built on `ScenarioService::refuseIfReadOnly()` — or on the
        // DIRECT edge alone — reads as "refuse it" and would break sign-off outright. Sign-off is
        // the one write a read-only status permits, by design.
        $author = $this->userWith(['scenario.view'], 'author8@example.test');
        $scenario = $this->makeScenario(['status' => 'validated', 'created_by' => $author->id]);

        $this->assertTrue($scenario->isReadOnly(), 'read-only to CONTENT edits');

        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertNull(session('error'), 'a read-only status is not a frozen one');
        $this->assertSame(2, $scenario->signoffs()->count());
        $this->assertSame('2027-11-10', $scenario->fresh()->review_due_on?->toDateString());

        // And the second write of the two-step flow still completes, because source 2's
        // independence claim survived C2.1 — the actor here is the scribe, not the author.
        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'signed_off'])
            ->assertSessionHasNoErrors();

        $this->assertSame('signed_off', $scenario->fresh()->status);
    }

    /* ------------------------------------------------------------------ */
    /*  Snapshots and the two-value decision */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_decision_vocabulary_is_exactly_two_values(): void
    {
        // §A1.6 refused `approved_with_conditions`: no transition branches on it, and a
        // condition is a notes row of kind `assumption`. "A third value nothing reads is how a
        // workflow acquires a state nobody can leave." The coordinator's Phase 1 ruling upheld
        // two, and a signer who declines without returning records a DISSENT NOTE instead.
        $this->assertSame(['approved', 'returned_for_rework'], Scenario::DECISIONS);

        $scenario = $this->makeScenario(['status' => 'validated']);

        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload([
                'signoffs' => [
                    ['source' => 'source_1', 'signatory_name' => 'A. Okonkwo', 'role' => 'Head of Operational Risk', 'decision' => 'approved_with_conditions'],
                ],
            ]))
            ->assertSessionHasErrors('signoffs.0.decision');
    }

    #[Test]
    public function the_signatory_name_and_role_are_required_snapshots_and_the_user_is_optional(): void
    {
        // §A1.6, spec 01 §4.7: `user_id` is nullOnDelete, so "a sign-off whose signatory has
        // left must still say who signed it. 'Signed by (deleted user) 4' is not evidence."
        // The typed text is the evidence and picking a user only pre-fills it.
        $scenario = $this->makeScenario(['status' => 'validated']);

        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload([
                'signoffs' => [['source' => 'source_1', 'decision' => 'approved']],
            ]))
            ->assertSessionHasErrors([
                'signoffs.0.signatory_name',
                'signoffs.0.role',
            ]);

        // And `role` is FREE TEXT, never a platform role name: §A1.6 refuses Spatie names
        // because renaming one "would silently falsify every historical sign-off storing it".
        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload([
                'signoffs' => [[
                    'source' => 'source_1',
                    'signatory_name' => 'A. Okonkwo',
                    'role' => 'Second Line — Independent Challenge',
                    'decision' => 'approved',
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $row = $scenario->signoffs()->sole();

        $this->assertSame('A. Okonkwo', $row->signatory_name);
        $this->assertSame('Second Line — Independent Challenge', $row->role);
        $this->assertNull($row->user_id);
    }

    #[Test]
    public function source_one_must_sign(): void
    {
        // Spec 01 §5.6.2: Source 1 is the workbook's first signature block, and a scenario
        // signed only by sources 2 and 3 has nobody accountable for the design itself.
        $scenario = $this->makeScenario(['status' => 'validated']);

        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload([
                'signoffs' => [
                    ['source' => 'source_2', 'signatory_name' => 'B. Adeyemi', 'role' => 'Independent', 'decision' => 'approved', 'is_independent_challenge' => true],
                ],
            ]))
            ->assertSessionHasErrors(['signoffs' => 'Source 1 must sign before this scenario can be signed off.']);

        $this->assertSame(0, $scenario->signoffs()->count());
    }

    #[Test]
    public function recording_sign_offs_does_not_move_the_status_because_those_are_two_separate_writes(): void
    {
        // Spec 01 §5.6.7–§5.6.9: "recording a sign-off and moving the status are genuinely
        // separate acts, and a signer who is not the person that moves the status is a normal
        // case, not an error." So this endpoint appends rows and nothing else.
        $scenario = $this->makeScenario(['status' => 'validated']);

        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('validated', $scenario->fresh()->status, 'the first write must not sign anything off');
        $this->assertSame(2, $scenario->signoffs()->count());
        // The review date the modal collected IS written, so the second write's C1.2 guard is
        // satisfiable in one flow.
        $this->assertSame('2027-11-10', $scenario->fresh()->review_due_on?->toDateString());
    }

    /* ------------------------------------------------------------------ */
    /*  warnings_acknowledged (addendum-1, C1.1) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function warnings_acknowledged_is_always_an_array_and_never_null(): void
    {
        // Addendum-1: "the sign action ALWAYS writes an array, `[]` when nothing was
        // outstanding. NULL is never written by Phase 1 and reads as `[]`."
        $clean = $this->makeDesignedReadyScenario(['status' => 'validated']);
        $this->cleanWarnings($clean);
        // The one warning that CAN fire on a legitimately designed scenario, so it is
        // satisfied here and `[]` really does mean "nothing outstanding".
        $this->addChallengeNote($clean);

        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $clean), $this->signoffPayload())
            ->assertSessionHasNoErrors();

        foreach ($clean->signoffs as $row) {
            $raw = $row->getRawOriginal('warnings_acknowledged');

            $this->assertNotNull($raw, 'the column must never be written as NULL');
            $this->assertSame([], json_decode((string) $raw, true));
            $this->assertSame([], $row->warningCodes());
        }
    }

    #[Test]
    public function warnings_acknowledged_is_a_snapshot_that_a_later_note_does_not_rewrite(): void
    {
        // THE LOAD-BEARING HALF OF ADDENDUM-1. Spec 01 §5.5 lets a note be added to a
        // `signed_off` scenario, so a live-derived `no_challenge_recorded` would VANISH the
        // moment somebody appended a challenge note a month after sign-off — falsifying what
        // was outstanding when the signer signed. A derived list there is not a simplification,
        // it is a record that quietly rewrites itself.
        $scenario = $this->makeDesignedReadyScenario(['status' => 'validated']);
        $this->cleanWarnings($scenario);

        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload())
            ->assertSessionHasNoErrors();

        $row = $scenario->signoffs()->latest('id')->first();

        $this->assertContains('no_challenge_recorded', $row->warningCodes());

        // A month later somebody appends the challenge that was missing.
        $this->actingAs($this->actor)->post(route('scenario.notes.store', $scenario), [
            'kind' => 'challenge',
            'body' => 'Second line challenged the eighteen-month replacement assumption.',
        ])->assertSessionHasNoErrors();

        $stored = $row->fresh()->warningCodes();

        $this->assertContains(
            'no_challenge_recorded',
            $stored,
            'the snapshot must still say what was outstanding WHEN THE SIGNER SIGNED'
        );
    }

    #[Test]
    public function the_eight_warning_codes_are_the_corrected_set_and_config_agrees_with_the_const(): void
    {
        // C1.1 swapped `eligible_to_transfer_not_assessed` — a condition §A1.2's NOT NULL
        // column makes unreachable — for `financial_risk_not_assessed`, which IS nullable and
        // which spec 01 §5.6.5 bullet 5 warns on. The count stays at eight.
        $this->assertSame([
            'no_challenge_recorded',
            'no_cause_selected',
            'allocation_missing',
            'allocation_not_100',
            'reputational_not_assessed',
            'legal_not_assessed',
            'insurance_risk_not_assessed',
            'financial_risk_not_assessed',
        ], Signoff::WARNING_CODES);

        $this->assertNotContains('eligible_to_transfer_not_assessed', Signoff::WARNING_CODES);

        // The config map is asserted against the const (addendum-3), so one without the other
        // fails — which is the mechanism working.
        $this->assertSame(
            Signoff::WARNING_CODES,
            array_keys((array) config('sm.signoff.warning_messages'))
        );
    }

    #[Test]
    public function financial_risk_warns_when_null_and_eligible_to_transfer_never_does(): void
    {
        $scenario = $this->makeDesignedReadyScenario([
            'status' => 'validated',
            'reputational_exposure' => 'moderate',
            'legal_compliance_exposure' => 'minor',
            'insurance_risk' => false,
            'financial_risk' => null,
            'eligible_to_transfer' => false,
        ]);

        $this->addChallengeNote($scenario);

        $codes = \App\Services\Sm\SignoffWarnings::for($scenario->fresh(['causes', 'allocations', 'notes']));

        // `financial_risk` is nullable (§A1.2 row 24), so NULL is "not yet assessed" and warns.
        $this->assertContains('financial_risk_not_assessed', $codes);
        $this->assertNotContains('insurance_risk_not_assessed', $codes, 'false is an ANSWER, not an absence');

        // `eligible_to_transfer` is NOT NULL default false (§A1.2 row 25), so it has no
        // unanswered state and produces NO code. Spec 01 §5.6.5's sixth bullet is UI-only and
        // a ninth code was refused: `false` is the state of every scenario nobody considered,
        // so recording it would teach signers to skip the list.
        $this->assertNotContains('eligible_to_transfer_not_assessed', $codes);
        $this->assertCount(1, $codes);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** Clear every warning except `no_challenge_recorded`, so a test asserts one thing. */
    private function cleanWarnings(Scenario $scenario): void
    {
        $scenario->forceFill([
            'reputational_exposure' => 'moderate',
            'legal_compliance_exposure' => 'minor',
            'insurance_risk' => false,
            'financial_risk' => false,
            'eligible_to_transfer' => false,
        ])->save();
    }

    private function addChallengeNote(Scenario $scenario): void
    {
        $scenario->notes()->create([
            'organization_id' => $scenario->organization_id,
            'kind' => 'challenge',
            'author_id' => $this->actor->id,
            'author_name' => $this->actor->name,
            'body' => 'Second line challenged the replacement assumption.',
        ]);
    }
}
