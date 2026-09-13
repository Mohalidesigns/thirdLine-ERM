<?php

namespace Tests\Feature\Rcsa;

use App\Models\BusinessProcess;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Services\Rcsa\RcsaUniverseService;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Creating, numbering, duplicating, publishing and bulk-editing universe rows.
 */
class UniverseWritesTest extends UniverseTestCase
{
    /* ------------------------------------------------------------------ */
    /*  Creating */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_risk_is_created_with_its_controls_in_one_go(): void
    {
        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload())
            ->assertRedirect();

        $risk = RcsaRegisterRisk::sole();

        $this->assertSame('RETAIL-R1', $risk->risk_no);
        $this->assertSame(RcsaRegisterRisk::DRAFT, $risk->status, 'A new row is a draft until someone publishes it.');
        $this->assertSame($this->actor->id, $risk->created_by);
        $this->assertCount(1, $risk->controls);
        $this->assertTrue($risk->controls->first()->is_key);
        $this->assertNotNull($risk->row_hash);
    }

    #[Test]
    public function the_risk_number_counts_from_the_highest_used_not_the_row_count(): void
    {
        $service = app(RcsaUniverseService::class);

        $this->assertSame('RETAIL-R1', $service->nextRiskNo($this->retail));

        $first = $this->makeRisk(['risk_no' => 'RETAIL-R1']);
        $this->makeRisk(['risk_no' => 'RETAIL-R2']);
        $third = $this->makeRisk(['risk_no' => 'RETAIL-R3']);

        $this->assertSame('RETAIL-R4', $service->nextRiskNo($this->retail));

        // Deleting the middle row leaves two rows and a highest of 3. Counting
        // would generate R3 and collide with the row that still holds it.
        $this->makeRisk(['risk_no' => 'RETAIL-R99']);
        $first->delete();

        $this->assertSame('RETAIL-R100', $service->nextRiskNo($this->retail));

        // A soft-deleted number is not reissued: the unique index still holds
        // it, and an audit entry naming "RETAIL-R3" must mean one thing.
        $third->delete();
        $this->assertSame('RETAIL-R100', $service->nextRiskNo($this->retail));

        // Each unit numbers its own risks from 1.
        $this->assertSame('TREAS-R1', $service->nextRiskNo($this->treasury));
    }

    #[Test]
    public function a_risk_number_the_user_types_is_kept_and_must_be_free_in_that_unit(): void
    {
        $this->makeRisk(['risk_no' => 'RETAIL-LEGACY-7']);

        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload(['risk_no' => 'RETAIL-LEGACY-7']))
            ->assertSessionHasErrors('risk_no');

        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload(['risk_no' => 'RETAIL-LEGACY-8']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(RcsaRegisterRisk::where('risk_no', 'RETAIL-LEGACY-8')->exists());
    }

    #[Test]
    public function the_same_number_is_allowed_in_a_different_business_unit(): void
    {
        $this->makeRisk(['risk_no' => 'R1']);

        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload([
                'business_unit_id' => $this->treasury->id,
                'process_id' => null,
                'sub_process_id' => null,
                'risk_no' => 'R1',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, RcsaRegisterRisk::where('risk_no', 'R1')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Validation */
    /* ------------------------------------------------------------------ */

    /**
     * Found by driving the real screen, not by this suite.
     *
     * The Add Risk panel seeds one empty control row so there is something to
     * type into. `controls.*.description` is required, so an untouched row
     * failed validation on EVERY save — and the error was on a step the user
     * was not looking at, so Save appeared to do nothing at all. The panel now
     * strips blank rows before sending; this pins the same rule on the server,
     * so a client that forgets cannot bring the form to a halt again.
     *
     * There is no JavaScript test runner in this repository, so nothing here
     * can catch the client half regressing. This is the half that can be held.
     */
    #[Test]
    public function an_untouched_control_row_does_not_block_the_save(): void
    {
        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload([
                'controls' => [
                    ['description' => '', 'control_type' => '', 'frequency' => '', 'control_owner_id' => '', 'is_key' => false],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $risk = RcsaRegisterRisk::sole();

        $this->assertCount(0, $risk->controls, 'An empty repeater slot is not a control.');
    }

    #[Test]
    public function a_half_filled_control_row_is_still_an_error(): void
    {
        // A row with a type but no description is not an untouched row — the
        // user started it and left it incomplete, and blanking it silently
        // would lose what they typed without saying so.
        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload([
                'controls' => [['description' => '', 'control_type' => 'preventive']],
            ]))
            ->assertSessionHasErrors('controls.0.description');
    }

    #[Test]
    public function a_risk_statement_too_short_to_be_understood_is_refused(): void
    {
        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload(['potential_risk' => 'System failure']))
            ->assertSessionHasErrors('potential_risk');
    }

    #[Test]
    public function the_others_category_requires_the_free_text_column(): void
    {
        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload(['risk_category' => 'Others']))
            ->assertSessionHasErrors('secondary_categories');

        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload([
                'risk_category' => 'Others',
                'secondary_categories' => ['Conduct', 'AML'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['Conduct', 'AML'], RcsaRegisterRisk::sole()->secondary_categories);
    }

    #[Test]
    public function a_sub_process_must_belong_to_the_chosen_process(): void
    {
        $unrelated = BusinessProcess::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->retail->id,
            'code' => 'PAYMENTS',
            'name' => 'Payments',
            'is_active' => true,
        ]);

        // Columns C and D naming two unrelated processes would print a
        // hierarchy in the export that does not exist.
        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload(['sub_process_id' => $unrelated->id]))
            ->assertSessionHasErrors('sub_process_id');

        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload([
                'process_id' => null,
                'sub_process_id' => $this->kyc->id,
            ]))
            ->assertSessionHasErrors('sub_process_id');
    }

    #[Test]
    public function a_foreign_business_unit_id_is_refused(): void
    {
        // The cross-tenant binding defect: `exists:business_units,id` alone
        // would accept another bank's unit and create the row pointing at it.
        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload([
                'business_unit_id' => $this->foreignUnit->id,
                'process_id' => null,
                'sub_process_id' => null,
            ]))
            ->assertSessionHasErrors('business_unit_id');

        $this->assertSame(0, RcsaRegisterRisk::count());
    }

    #[Test]
    public function an_unapproved_risk_category_is_refused(): void
    {
        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.store'), $this->riskPayload(['risk_category' => 'Made Up']))
            ->assertSessionHasErrors('risk_category');
    }

    /* ------------------------------------------------------------------ */
    /*  Editing */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function editing_a_published_row_bumps_its_version_and_editing_a_draft_does_not(): void
    {
        $draft = $this->makeRisk(['risk_no' => 'RETAIL-R1']);

        $this->actingAs($this->actor)->put(
            route('rcsa.universe.update', $draft),
            $this->riskPayload(['risk_no' => 'RETAIL-R1', 'potential_risk' => 'A materially reworded risk statement for the draft.'])
        )->assertSessionHasNoErrors();

        $this->assertSame(1, $draft->fresh()->version);

        $published = $this->makeRisk(['risk_no' => 'RETAIL-R2', 'status' => RcsaRegisterRisk::PUBLISHED]);

        $this->actingAs($this->actor)->put(
            route('rcsa.universe.update', $published),
            $this->riskPayload(['risk_no' => 'RETAIL-R2', 'potential_risk' => 'A materially reworded risk statement for the published row.'])
        )->assertSessionHasNoErrors();

        $this->assertSame(2, $published->fresh()->version);
    }

    #[Test]
    public function the_controls_repeater_creates_updates_and_removes(): void
    {
        $this->actingAs($this->actor)->post(route('rcsa.universe.store'), $this->riskPayload([
            'controls' => [
                ['description' => 'First control, as originally written.'],
                ['description' => 'Second control, to be removed.'],
            ],
        ]));

        $risk = RcsaRegisterRisk::sole();
        $this->assertCount(2, $risk->controls);

        $first = $risk->controls->first();

        $this->actingAs($this->actor)->put(route('rcsa.universe.update', $risk), $this->riskPayload([
            'risk_no' => $risk->risk_no,
            'controls' => [
                ['id' => $first->id, 'description' => 'First control, reworded after review.'],
                ['description' => 'A third control, newly added.'],
            ],
        ]))->assertSessionHasNoErrors();

        $controls = $risk->fresh()->controls;

        $this->assertCount(2, $controls);
        $this->assertSame('First control, reworded after review.', $controls->firstWhere('id', $first->id)->description);
        $this->assertTrue($controls->contains('description', 'A third control, newly added.'));
        $this->assertFalse($controls->contains('description', 'Second control, to be removed.'));
    }

    /* ------------------------------------------------------------------ */
    /*  Publish, retire, delete */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function publishing_records_who_and_when_and_only_published_rows_are_assessable(): void
    {
        $draft = $this->makeRisk(['risk_no' => 'RETAIL-R1']);
        $other = $this->makeRisk(['risk_no' => 'RETAIL-R2']);

        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.publish'), ['ids' => [$draft->id]])
            ->assertRedirect();

        $draft->refresh();

        $this->assertSame(RcsaRegisterRisk::PUBLISHED, $draft->status);
        $this->assertSame($this->actor->id, $draft->published_by);
        $this->assertNotNull($draft->published_at);

        // Rule 1 of the process flow: a cycle populates from APPROVED data only.
        $assessable = RcsaRegisterRisk::query()->assessable()->pluck('id')->all();

        $this->assertSame([$draft->id], $assessable);
        $this->assertNotContains($other->id, $assessable);
    }

    #[Test]
    public function retiring_withdraws_a_row_without_destroying_its_history(): void
    {
        $risk = $this->makeRisk(['risk_no' => 'RETAIL-R1', 'status' => RcsaRegisterRisk::PUBLISHED]);

        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.retire'), ['ids' => [$risk->id]])
            ->assertRedirect();

        $this->assertSame(RcsaRegisterRisk::RETIRED, $risk->fresh()->status);
        $this->assertNull($risk->fresh()->deleted_at, 'Retiring is not deleting.');
        $this->assertSame(0, RcsaRegisterRisk::query()->assessable()->count());
    }

    #[Test]
    public function a_published_risk_is_retired_rather_than_deleted(): void
    {
        $risk = $this->makeRisk(['risk_no' => 'RETAIL-R1', 'status' => RcsaRegisterRisk::PUBLISHED]);

        $this->actingAs($this->actor)
            ->delete(route('rcsa.universe.destroy', $risk))
            ->assertSessionHas('error');

        $this->assertNotNull($risk->fresh(), 'A published row must survive a delete attempt.');

        $draft = $this->makeRisk(['risk_no' => 'RETAIL-R2']);

        $this->actingAs($this->actor)
            ->delete(route('rcsa.universe.destroy', $draft))
            ->assertSessionHas('success');

        $this->assertSoftDeleted($draft);
    }

    /* ------------------------------------------------------------------ */
    /*  Duplicate and bulk edit */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function duplicating_copies_the_controls_renumbers_and_arrives_as_a_draft(): void
    {
        $this->actingAs($this->actor)->post(route('rcsa.universe.store'), $this->riskPayload());

        $source = RcsaRegisterRisk::sole();
        app(RcsaUniverseService::class)->publish([$source->id], $this->actor);

        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.duplicate', $source), ['business_unit_id' => $this->treasury->id])
            ->assertRedirect();

        $copy = RcsaRegisterRisk::where('business_unit_id', $this->treasury->id)->sole();

        $this->assertSame('TREAS-R1', $copy->risk_no);
        $this->assertSame($source->potential_risk, $copy->potential_risk);
        $this->assertCount(1, $copy->controls);
        // A copy that arrived published would enter the next cycle without
        // anyone approving it.
        $this->assertSame(RcsaRegisterRisk::DRAFT, $copy->status);
        // Its placement is the destination's, not the source's.
        $this->assertNull($copy->process_id);
    }

    #[Test]
    public function bulk_edit_changes_only_owner_category_and_status(): void
    {
        $a = $this->makeRisk(['risk_no' => 'RETAIL-R1']);
        $b = $this->makeRisk(['risk_no' => 'RETAIL-R2']);
        $statement = $a->potential_risk;

        $this->actingAs($this->actor)->post(route('rcsa.universe.bulk-update'), [
            'ids' => [$a->id, $b->id],
            'owner_id' => $this->actor->id,
            'risk_category' => 'Operational',
            // A field outside the allow-list, sent deliberately: a bulk
            // endpoint that forwards whatever it is given rewrites risk
            // statements sixty at a time with nothing on screen to show it.
            'potential_risk' => 'Overwritten by a field nobody allowed.',
        ])->assertRedirect();

        foreach ([$a, $b] as $risk) {
            $risk->refresh();
            $this->assertSame($this->actor->id, $risk->owner_id);
            $this->assertSame('Operational', $risk->risk_category);
            $this->assertSame($statement, $risk->potential_risk);
        }
    }

    #[Test]
    public function bulk_publishing_goes_through_the_action_that_records_the_actor(): void
    {
        $a = $this->makeRisk(['risk_no' => 'RETAIL-R1']);
        $b = $this->makeRisk(['risk_no' => 'RETAIL-R2']);

        $this->actingAs($this->actor)->post(route('rcsa.universe.bulk-update'), [
            'ids' => [$a->id, $b->id],
            'status' => RcsaRegisterRisk::PUBLISHED,
        ])->assertRedirect();

        foreach ([$a, $b] as $risk) {
            $risk->refresh();
            $this->assertSame(RcsaRegisterRisk::PUBLISHED, $risk->status);
            // A bare column write would have left these null, and the audit
            // trail would not say who approved the row.
            $this->assertSame($this->actor->id, $risk->published_by);
            $this->assertNotNull($risk->published_at);
        }
    }

    #[Test]
    public function bulk_edit_refuses_another_tenants_ids(): void
    {
        $mine = $this->makeRisk(['risk_no' => 'RETAIL-R1']);

        $foreign = TenantContext::bypass(fn () => RcsaRegisterRisk::create([
            'organization_id' => $this->otherOrg->id,
            'business_unit_id' => $this->foreignUnit->id,
            'risk_no' => 'FOREIGN-R1',
            'potential_risk' => 'A risk belonging to an entirely different bank.',
            'risk_category' => 'Operational',
        ]));

        $this->actingAs($this->actor)->post(route('rcsa.universe.bulk-update'), [
            'ids' => [$mine->id, $foreign->id],
            'risk_category' => 'Market',
        ])->assertSessionHasErrors('ids.1');

        $this->assertSame(
            'Operational',
            TenantContext::bypass(fn () => $foreign->fresh()->risk_category)
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Inline process creation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_process_can_be_created_without_leaving_the_panel(): void
    {
        $this->actingAs($this->actor)->post(route('rcsa.universe.processes.store'), [
            'business_unit_id' => $this->retail->id,
            'name' => 'Card Issuance',
        ])->assertSessionHasNoErrors();

        $process = BusinessProcess::where('name', 'Card Issuance')->sole();

        $this->assertNull($process->parent_id);
        $this->assertSame($this->retail->id, $process->business_unit_id);
        $this->assertNotEmpty($process->code, 'business_processes.code is not nullable.');

        $this->actingAs($this->actor)->post(route('rcsa.universe.processes.store'), [
            'business_unit_id' => $this->retail->id,
            'parent_id' => $process->id,
            'name' => 'PIN Mailer Handling',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            $process->id,
            BusinessProcess::where('name', 'PIN Mailer Handling')->sole()->parent_id
        );
    }

    /**
     * The row hash is what the import will use to spot a duplicate.
     */
    #[Test]
    public function the_row_hash_identifies_the_same_risk_in_the_same_place(): void
    {
        $a = $this->makeRisk(['risk_no' => 'RETAIL-R1', 'potential_risk' => 'Accounts opened without complete KYC.']);
        $b = $this->makeRisk(['risk_no' => 'RETAIL-R2', 'potential_risk' => '  accounts   opened WITHOUT complete KYC.  ']);

        $this->assertSame($a->row_hash, $b->row_hash, 'Casing and spacing are not a different risk.');

        // A different unit is a different row, even with identical wording.
        $c = $this->makeRisk([
            'risk_no' => 'TREAS-R1',
            'business_unit_id' => $this->treasury->id,
            'process_id' => null,
            'potential_risk' => 'Accounts opened without complete KYC.',
        ]);

        $this->assertNotSame($a->row_hash, $c->row_hash);

        // And the hash follows an edit, so a reworded row is not still matched
        // against what it used to say.
        $before = $a->row_hash;
        $a->update(['potential_risk' => 'Accounts are opened with expired identity documents.']);

        $this->assertNotSame($before, $a->fresh()->row_hash);
    }
}
