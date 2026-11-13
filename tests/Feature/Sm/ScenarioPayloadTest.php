<?php

namespace Tests\Feature\Sm;

use App\Models\Sm\Scenario;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Inertia payload contract (spec 01 §4, spec 02 §8, spec 03 §2) and the partial-update
 * rule (spec 01 §6.1, coordinator ruling 3).
 *
 * THE FOUR RULES THIS PINS, all of which fail silently rather than loudly:
 *
 *   1. NULL IS NEVER COERCED. `insurance_risk`, `financial_risk` and both exposure scales
 *      arrive as `?bool` / `?string`, and every "not yet assessed" sentence is the SERVER'S
 *      wording. A payload that sends `false` for an unanswered question renders "No" on a
 *      governance screen — the exact defect §A1.2's nullable columns exist to prevent.
 *   2. DECIMALS ARRIVE AS STRINGS. A float through json_encode can land as `100` instead of
 *      `100.00`, and summing those in JS reintroduces the artefact §A1.5 told the server to
 *      avoid — a screen saying "Adds to 100%" while the server answers 422 destroys trust in
 *      both.
 *   3. ONLY THE ACTIVE TAB'S DATA SHIPS, with `qualitativeSummary` the one exception because
 *      the sign-off modal is shell-level.
 *   4. `PUT {scenario}` IS PARTIAL. Both tabs post to it and neither loads the other's fields,
 *      so a whole-object write from Qualitative would null `background` with a 200 response —
 *      standard §6's write-path rule in its most dangerous form.
 */
class ScenarioPayloadTest extends SmTestCase
{
    /* ------------------------------------------------------------------ */
    /*  1. NULL is never coerced */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_two_nullable_booleans_arrive_as_null_and_never_as_false(): void
    {
        // §A1.2 rows 23–24: `insurance_risk` and `financial_risk` are NULLABLE because the
        // workbook dropdowns offer a blank, and `->default(false)` "would render 'not yet
        // assessed' as 'No' on a governance screen". The model's own docblock records why the
        // cast is safe — `boolean` is in HasAttributes::$primitiveCastTypes and castAttribute()
        // returns null unchanged for those — so `?bool` survives the model layer. This test is
        // the other half: it must also survive the JSON boundary.
        $scenario = $this->makeScenario(['insurance_risk' => null, 'financial_risk' => null]);

        $this->assertNull($scenario->insurance_risk, 'the boolean cast must not turn NULL into false');
        $this->assertNull($scenario->financial_risk);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualitative.exposures.insurance_risk', null)
                ->where('qualitative.exposures.financial_risk', null)
                // The summary's LABELS are the server's wording for the absence, so the client
                // never has to derive it from a null.
                ->where('qualitativeSummary.insurance_risk_label', 'Not assessed')
                ->where('qualitativeSummary.financial_risk_label', 'Not assessed'));

        // And the value that reaches the wire is null, not false — a 0 here is indistinguishable
        // from "No" once it is in the browser. Read through `inertiaProps()` rather than a second
        // request with an `X-Inertia` header, which Inertia answers with a 409 version conflict
        // unless the matching `X-Inertia-Version` is sent too.
        $exposures = $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']))
            ->assertOk()
            ->inertiaProps('qualitative.exposures');

        $this->assertNull($exposures['insurance_risk']);
        $this->assertNull($exposures['financial_risk']);
        $this->assertNotFalse($exposures['insurance_risk'], 'null must not be coerced to false on the way out');
        $this->assertStringContainsString(
            '"insurance_risk":null',
            (string) json_encode($exposures),
            'the encoded payload must carry null, not false'
        );
    }

    #[Test]
    public function answered_booleans_stay_booleans_and_are_not_confused_with_the_unanswered_ones(): void
    {
        $scenario = $this->makeScenario(['insurance_risk' => false, 'financial_risk' => true]);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']))
            ->assertInertia(fn ($page) => $page
                // `false` is an ANSWER and must arrive as false, not as null — collapsing the
                // two in the other direction is the same defect wearing a different hat.
                ->where('qualitative.exposures.insurance_risk', false)
                ->where('qualitative.exposures.financial_risk', true)
                ->where('qualitativeSummary.insurance_risk_label', 'No')
                ->where('qualitativeSummary.financial_risk_label', 'Yes'));
    }

    #[Test]
    public function eligible_to_transfer_is_never_null_because_its_column_is_not_nullable(): void
    {
        // §A1.2 row 25 and spec 01 §4: `eligible_to_transfer_label` is "never null — the column
        // is NOT NULL". Patched spec 03 §6.4 overrules the earlier three-state radio on exactly
        // those grounds: "the schema has no third state to render, and a UI that offered one
        // would be collecting a value it cannot store."
        $scenario = $this->makeScenario();

        $this->assertFalse((bool) $scenario->eligible_to_transfer);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page
                ->where('qualitativeSummary.eligible_to_transfer_label', 'No'));

        // Posting null is refused rather than coerced to false, so the two-state contract holds
        // on the write path too.
        $this->actingAs($this->actor)
            ->put(route('scenario.update', $scenario), ['eligible_to_transfer' => null])
            ->assertSessionHasErrors(['eligible_to_transfer' => 'Eligible to transfer must be Yes or No.']);
    }

    #[Test]
    public function both_exposure_scales_arrive_with_their_unset_label_and_null_value(): void
    {
        // Spec 03 §6.5: null renders the `unset_label` — "Impact: not yet assessed" for the
        // reputational scale, "Not yet assessed" for the legal one. NEVER the lowest grade,
        // never `—`, never "None": those three each lose a different distinction.
        $scenario = $this->makeScenario();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']))
            ->assertInertia(fn ($page) => $page
                ->where('qualitative.exposures.reputational_exposure.value', null)
                ->where('qualitative.exposures.reputational_exposure.unset_label', 'Impact: not yet assessed')
                ->where('qualitative.exposures.legal_compliance_exposure.value', null)
                ->where('qualitative.exposures.legal_compliance_exposure.unset_label', 'Not yet assessed')
                // The summary carries the server's words, so the sign-off modal recap can read
                // identically whichever tab it was opened from.
                ->where('qualitativeSummary.reputational_label', 'Impact: not yet assessed')
                ->where('qualitativeSummary.legal_label', 'Not yet assessed')
                // Six affirmative options each; the unset state is NOT among them, it is
                // `unset_label` posting null (spec 03 §2).
                ->where('qualitative.exposures.reputational_exposure.options', function ($options) {
                    $this->assertCount(6, $options);
                    $this->assertSame('no_impact', $options[0]['value']);
                    $this->assertSame('severe', $options[5]['value']);
                    $this->assertNull($options[0]['description'], 'no_impact carries no description in the artefact');
                    $this->assertSame(
                        'Dramatic loss of stakeholder confidence undermining business viability.  Extensive negative public exposure',
                        $options[5]['description']
                    );

                    return true;
                })
                ->where('qualitative.exposures.legal_compliance_exposure.options', function ($options) {
                    $this->assertCount(6, $options);
                    $this->assertSame('no', $options[0]['value'], "the legal scale's zero is 'no', not 'no_impact'");
                    foreach ($options as $option) {
                        $this->assertNull($option['description'], 'the legal scale ships labels only (spec 03 §6.3)');
                    }

                    return true;
                }));
    }

    #[Test]
    public function an_answered_scale_arrives_with_its_value_and_never_with_a_derived_default(): void
    {
        $scenario = $this->makeScenario([
            'reputational_exposure' => 'no_impact',
            'legal_compliance_exposure' => 'no',
        ]);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']))
            ->assertInertia(fn ($page) => $page
                ->where('qualitative.exposures.reputational_exposure.value', 'no_impact')
                ->where('qualitative.exposures.legal_compliance_exposure.value', 'no')
                // "No impact" and "No" are ANSWERS and must not render as the unset label.
                ->where('qualitativeSummary.reputational_label', 'No impact')
                ->where('qualitativeSummary.legal_label', 'No'));
    }

    /* ------------------------------------------------------------------ */
    /*  2. Decimals arrive as strings */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function allocation_percents_and_the_total_arrive_as_decimal_strings(): void
    {
        // Spec 01 §4.5: "`100.00` can arrive as `100` and `33.33` as `33.32999999999999829…`;
        // comparing or summing those in JS reintroduces exactly the artefact the server was told
        // to avoid."
        $scenario = $this->makeDesignedReadyScenario();
        $this->allocate($scenario, [[0, '33.33'], [1, '66.67']]);
        $scenario = $scenario->fresh('allocations');

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']))
            ->assertInertia(fn ($page) => $page
                ->where('qualitative.allocation.saved_total', '100.00')
                ->where('qualitative.allocation.lines', function ($lines) {
                    $this->assertCount(2, $lines);

                    foreach ($lines as $line) {
                        $this->assertIsString($line['percent'], 'a percent must be a decimal string, not a JSON float');
                        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $line['percent']);
                        // A server-supplied stable key, because React keys and the undo
                        // affordance both need identity that survives a re-render and an array
                        // index does not (spec 03 §2).
                        $this->assertIsString($line['key']);
                        $this->assertIsInt($line['business_unit_id']);
                        $this->assertNotSame('', $line['label']);
                    }

                    return true;
                })
                ->where('qualitativeSummary.allocation_total', '100.00')
                ->where('qualitativeSummary.allocation_lines', 2));
    }

    #[Test]
    public function an_empty_allocation_reports_null_and_never_zero(): void
    {
        // Spec 03 §5.4: "It never reads 0%. Standard §5: a rate over nothing is undefined, not
        // zero … '0% allocated' is a claim that the scenario has been allocated and nothing
        // landed anywhere — a different and false statement."
        $scenario = $this->makeScenario();

        $this->assertSame(0, $scenario->allocations()->count());

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']))
            ->assertInertia(fn ($page) => $page
                ->where('qualitative.allocation.saved_total', null)
                ->where('qualitative.allocation.lines', [])
                ->where('qualitativeSummary.allocation_total', null)
                ->where('qualitativeSummary.allocation_lines', 0));
    }

    /* ------------------------------------------------------------------ */
    /*  3. Only the active tab's data ships */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function only_the_active_tabs_payload_shipips_but_the_qualitative_summary_ships_on_both(): void
    {
        $scenario = $this->makeDesignedReadyScenario();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'overview']))
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'overview')
                ->has('values')
                ->where('qualitative', null)
                // The Overview tab's own option lists.
                ->has('businessUnits')->has('opEventTypes')->has('natures')->has('weightings')
                ->has('currencies')->has('fxRates')
                // The summary is the ONE exception: the sign-off modal is shell-level and can be
                // opened from either tab, and without it the recap would be empty on Overview.
                ->has('qualitativeSummary.causes_selected')
                ->has('qualitativeSummary.allocation_total'));

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']))
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'qualitative')
                ->has('qualitative.causes.checklist')
                ->has('qualitative.allocation.lines')
                ->where('values', null)
                ->has('qualitativeSummary.causes_selected')
                // And the Overview-only option lists do not ship: dead bytes on the slowest link
                // in the product (spec 01 §0.4).
                ->missing('businessUnits')
                ->missing('opEventTypes')
                ->missing('currencies')
                ->missing('fxRates'));
    }

    #[Test]
    public function an_unknown_or_deferred_tab_resolves_to_overview_and_says_so(): void
    {
        // Spec 01 §5.1: an unknown or deferred `?tab=` is IGNORED and resolves to overview, so a
        // bookmark from a later phase lands somewhere useful rather than 404ing — and the shell
        // is told once, because silently showing a different section is how a user concludes the
        // link is broken.
        $scenario = $this->makeScenario();

        // The sentence ships as `flash.info` on THIS response and is gone afterwards: the
        // controller uses `session()->now()`, which the platform's shared `flash` prop reads
        // at render time but `save()` ages out before the next request — so it is asserted on
        // the Inertia payload (the channel the user actually sees), never with
        // `assertSessionHas()`, which reads the post-request session and would always miss it.
        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'stress']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'overview')
                ->has('values')
                ->where('flash.info', "That section isn't part of this release yet. Showing Overview & Governance."));

        // Asking for a tab that IS live says nothing — and the previous sentence does not
        // linger: `now()` guarantees it was a one-response message, which is the whole reason
        // the controller chose it over `flash()`.
        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'qualitative')
                ->where('flash.info', null));
    }

    #[Test]
    public function exactly_six_tabs_are_live_and_the_deferred_ones_are_absent_not_disabled(): void
    {
        // Phase 2: Overview, Qualitative, Frequency, Impact, Insurance, and Scorecard are live.
        $scenario = $this->makeScenario();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page
                ->where('tabs', function ($tabs) {
                    $this->assertCount(6, $tabs);
                    $this->assertSame(
                        ['overview', 'qualitative', 'frequency', 'impact', 'insurance', 'scorecard'],
                        collect($tabs)->pluck('key')->all()
                    );
                    $this->assertTrue(collect($tabs)->every(fn ($tab) => $tab['live'] === true));

                    return true;
                })
                // The disclosure is a server-sent string so the copy can change per phase without
                // a front-end release.
                ->where('roadmapNote', config('sm.roadmap_note')));
    }

    /* ------------------------------------------------------------------ */
    /*  4. The partial update */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_qualitative_shaped_partial_save_leaves_the_overview_fields_untouched(): void
    {
        // COORDINATOR RULING 3, and spec 01 §6.1's explicit ask: "a test that a Qualitative save
        // leaves `background` untouched". The Qualitative tab posts FIVE keys to `PUT
        // {scenario}` and never loads the other twelve, so a whole-object write would null them
        // — silently, on save, with a 200 response.
        $scenario = $this->makeScenario([
            'status' => 'in_design',
            'name' => 'Loss of key staff — succession planning failure',
            'background' => 'Two analysts leave each year and the pipeline is eighteen months.',
            'rationale' => 'A loss event in the treasury back office traced to one unmatched handover.',
            'nature' => 'group',
            'weighting' => 'medium_high',
            'currency' => 'NGN',
            'risk_taxonomy_node_id' => $this->opNodes[4]->id,
            'designed_on' => '2026-11-01',
        ]);

        $this->actingAs($this->actor)
            ->put(route('scenario.update', $scenario), [
                'reputational_exposure' => 'moderate',
                'legal_compliance_exposure' => 'minor',
                'insurance_risk' => false,
                'financial_risk' => null,
                'eligible_to_transfer' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Saved.');

        $fresh = $scenario->fresh();

        // What was posted landed.
        $this->assertSame('moderate', $fresh->reputational_exposure);
        $this->assertSame('minor', $fresh->legal_compliance_exposure);
        $this->assertFalse((bool) $fresh->insurance_risk);
        $this->assertNull($fresh->financial_risk, 'a posted null must stay null, not become false');
        $this->assertTrue((bool) $fresh->eligible_to_transfer);

        // And nothing else moved.
        $this->assertSame('Loss of key staff — succession planning failure', $fresh->name);
        $this->assertSame('Two analysts leave each year and the pipeline is eighteen months.', $fresh->background);
        $this->assertSame('A loss event in the treasury back office traced to one unmatched handover.', $fresh->rationale);
        $this->assertSame('group', $fresh->nature);
        $this->assertSame('medium_high', $fresh->weighting);
        $this->assertSame('NGN', $fresh->currency);
        $this->assertSame($this->opNodes[4]->id, $fresh->risk_taxonomy_node_id);
        $this->assertSame('2026-11-01', $fresh->designed_on?->toDateString());
    }

    #[Test]
    public function an_overview_shaped_partial_save_leaves_the_exposure_answers_untouched(): void
    {
        // The same rule in the other direction: the Overview tab does not load the exposures
        // either, so its save must not clear them.
        $scenario = $this->makeScenario([
            'status' => 'in_design',
            'reputational_exposure' => 'severe',
            'legal_compliance_exposure' => 'major',
            'insurance_risk' => true,
            'financial_risk' => null,
        ]);

        $this->actingAs($this->actor)
            ->put(route('scenario.update', $scenario), ['name' => 'Renamed on the Overview tab'])
            ->assertSessionHasNoErrors();

        $fresh = $scenario->fresh();

        $this->assertSame('Renamed on the Overview tab', $fresh->name);
        $this->assertSame('severe', $fresh->reputational_exposure);
        $this->assertSame('major', $fresh->legal_compliance_exposure);
        $this->assertTrue((bool) $fresh->insurance_risk);
        $this->assertNull($fresh->financial_risk);
    }

    #[Test]
    public function posting_an_empty_body_changes_nothing_and_is_not_an_error(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design', 'background' => 'Kept']);

        $this->actingAs($this->actor)
            ->put(route('scenario.update', $scenario), [])
            ->assertSessionHasNoErrors();

        $this->assertSame('Kept', $scenario->fresh()->background);
    }

    /* ------------------------------------------------------------------ */
    /*  The remaining prop contract */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_scenario_prop_uses_the_frozen_column_spellings_and_does_no_renaming(): void
    {
        // Addendum-4: props and 422 keys use `designed_on` / `validated_on` / `review_due_on`,
        // NOT `design_date` / `validation_date` / `review_due_date`, "with the presenter doing no
        // renaming". One vocabulary across the payload, the 422 keys and the gate anchors, or the
        // front end ends up holding a mapping between three naming schemes.
        $scenario = $this->makeScenario([
            'designed_on' => '2026-11-09',
            'validated_on' => '2026-11-10',
            'review_due_on' => '2027-11-10',
            'business_unit_id' => $this->units[0]->id,
            'risk_taxonomy_node_id' => $this->opNodes[4]->id,
        ]);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page
                ->where('scenario.designed_on', '2026-11-09')
                ->where('scenario.validated_on', '2026-11-10')
                ->where('scenario.review_due_on', '2027-11-10')
                ->where('scenario.business_unit.id', $this->units[0]->id)
                ->where('scenario.business_unit.name', 'Retail Banking')
                ->has('scenario.op_event_type.id')
                ->has('scenario.op_event_type.name')
                ->has('scenario.op_event_type.basel_level_1')
                ->has('values.designed_on')
                ->has('values.business_unit_id')
                // The plan's spellings must NOT appear anywhere.
                ->missing('scenario.design_date')
                ->missing('scenario.validation_date')
                ->missing('scenario.review_due_date')
                ->missing('scenario.entity')
                ->missing('scenario.entity_id')
                ->missing('values.design_date')
                ->missing('values.weighting_factor')
                ->missing('values.description'));
    }

    #[Test]
    public function the_op_event_type_prop_has_no_code_field_because_the_table_has_no_code_column(): void
    {
        // §A1.9: `risk_taxonomies` has no `code` column, so the OP number is part of `name` and
        // there is nothing to concatenate and nothing for the client to format (spec 04 §2).
        $node = $this->opNodes[6];

        $this->assertStringStartsWith('OP7 ', $node->name);

        $scenario = $this->makeScenario(['risk_taxonomy_node_id' => $node->id]);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page
                ->where('scenario.op_event_type.name', $node->name)
                ->where('scenario.op_event_type.basel_level_1', 'Clients, products & business practices')
                ->missing('scenario.op_event_type.code'));
    }

    #[Test]
    public function the_create_payloads_op_event_types_carry_basel_level_1_exactly_as_the_workspace_does(): void
    {
        // F20 / spec 02 §5.4: `basel_level_1` is the GROUPING SOURCE for the OP picker, and the
        // create form and the workspace Overview tab render the SAME control off the SAME
        // catalogue. Two item shapes for one control is how the picker groups on one screen and
        // silently falls back to a flat list on the other — and nothing else in the suite would
        // catch it, because both screens pass their own assertions. This pins the parity, key
        // order included, so the two cannot drift apart.
        $keys = ['id', 'name', 'parent', 'basel_level_1'];
        $op7 = $this->opNodes[6]->name;

        $this->actingAs($this->actor)
            ->get(route('scenario.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('opEventTypes', function ($rows) use ($keys, $op7) {
                $this->assertNotEmpty($rows, 'the seeded OP1–OP17 tree is the list');

                foreach ($rows as $row) {
                    $this->assertSame($keys, array_keys($row));
                }

                $node = collect($rows)->firstWhere('name', $op7);

                $this->assertNotNull($node, 'OP7 is in the picker');
                $this->assertSame('Clients, products & business practices', $node['basel_level_1']);

                return true;
            }));

        $scenario = $this->makeScenario();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'overview']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('opEventTypes', function ($rows) use ($keys, $op7) {
                foreach ($rows as $row) {
                    $this->assertSame($keys, array_keys($row), 'the workspace item shape');
                }

                $node = collect($rows)->firstWhere('name', $op7);

                $this->assertNotNull($node);
                $this->assertSame('Clients, products & business practices', $node['basel_level_1']);

                return true;
            }));
    }

    #[Test]
    public function the_cause_checklist_prop_carries_the_frozen_codes_with_configs_wording(): void
    {
        $scenario = $this->makeScenario();
        $this->selectCauses($scenario, ['people_other' => 'The contractor who holds the only key.']);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']))
            ->assertInertia(fn ($page) => $page
                ->where('qualitative.causes.checklist', function ($checklist) {
                    $this->assertCount(23, $checklist);

                    foreach ($checklist as $item) {
                        // `cause_group`, not `group`: the singular form is the COLUMN value and
                        // `group_label` carries the workbook's plural wording (spec 03 §4.1).
                        $this->assertArrayHasKey('cause_group', $item);
                        $this->assertArrayNotHasKey('group', $item);
                        $this->assertArrayHasKey('group_label', $item);
                        $this->assertArrayHasKey('requires_note', $item);
                        $this->assertArrayHasKey('sort', $item);
                        $this->assertArrayHasKey('side', $item);
                        $this->assertArrayHasKey('label', $item);
                    }

                    $processes = collect($checklist)->firstWhere('code', 'process_other');
                    $this->assertSame('process', $processes['cause_group']);
                    $this->assertSame('Processes', $processes['group_label']);
                    $this->assertTrue($processes['requires_note']);

                    return true;
                })
                // An object keyed by code, so a deselect-and-reselect cannot reorder the payload
                // and every error key is stable (spec 03 §2).
                ->where('qualitative.causes.selected', [
                    'people_other' => ['note' => 'The contractor who holds the only key.'],
                ]));
    }

    #[Test]
    public function the_allocation_options_are_business_units_only_with_no_kind_discriminator(): void
    {
        // §A1.5 refused `business_processes` and the coordinator upheld it, so
        // `allocation.options[].kind` came out of spec 03 §2 (addendum-4). A mixed list would put
        // two org trees on one screen.
        $scenario = $this->makeScenario();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']))
            ->assertInertia(fn ($page) => $page
                ->where('qualitative.allocation.options', function ($options) {
                    $this->assertCount(3, $options);

                    foreach ($options as $option) {
                        $this->assertSame(['id', 'name'], array_keys($option));
                        $this->assertArrayNotHasKey('kind', $option);
                    }

                    return true;
                }));
    }

    #[Test]
    public function the_review_object_is_server_computed_in_lagos_and_never_a_browser_subtraction(): void
    {
        // Spec 01 §4.2/§4.3: computed server-side in Africa/Lagos against the day boundary
        // (standard §14). `null` means "no review date set" and renders `—`; it never renders 0.
        $undated = $this->makeScenario();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $undated))
            ->assertInertia(fn ($page) => $page->where('review', ['due_on' => null, 'days_overdue' => null]));

        $overdue = $this->makeScenario(['review_due_on' => \App\Support\Kri\KriWorkingCalendar::today()->subDays(3)->toDateString()]);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $overdue))
            ->assertInertia(fn ($page) => $page->where('review.days_overdue', 3));

        $future = $this->makeScenario(['review_due_on' => \App\Support\Kri\KriWorkingCalendar::today()->addDays(5)->toDateString()]);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $future))
            ->assertInertia(fn ($page) => $page->where('review.days_overdue', 0));
    }

    #[Test]
    public function suggested_review_due_on_is_server_computed_from_the_tenants_cadence(): void
    {
        // Addendum-3 and spec 01 §5.6.4: "the browser never computes it (no magic +12 months in
        // client code — standard §5)". A tenant that changes its setting gets a different
        // suggestion with no front-end release.
        $scenario = $this->makeScenario();

        $expected = \App\Support\Kri\KriWorkingCalendar::today()->addMonths(12)->toDateString();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page->where('suggestedReviewDueOn', $expected));

        // Per tenant, through organizations.settings — not config(), which a queue worker holds
        // across tenants (addendum-3's refusal of config-only).
        $this->organization->forceFill([
            'settings' => json_encode(['sm_review_cadence_months' => 6]),
        ])->save();

        $sixMonths = \App\Support\Kri\KriWorkingCalendar::today()->addMonths(6)->toDateString();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page->where('suggestedReviewDueOn', $sixMonths));
    }

    #[Test]
    public function the_urls_prop_mirrors_the_frozen_route_table(): void
    {
        // Spec 01 §4: "`urls` mirrors §A1.11's route table exactly — `scenario` (PUT), `causes`
        // (PUT), `allocations` (PUT), `status` (POST), `signoffs` (POST), `notes` (POST). The
        // Qualitative tab writes THREE endpoints, not one."
        $scenario = $this->makeScenario();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page->where('urls', function ($urls) use ($scenario) {
                // AssertableInertia hands nested props over as a Collection, whose
                // offsetExists() reports false for a key holding NULL — so `arrayHasKey` on it
                // would "prove" `notesEarlier` and `fxRates` are absent when they are present and
                // null, which is exactly what this test is about. `collect()->all()` unwraps to
                // the underlying array with nulls intact (a bare `(array)` cast would yield the
                // object's properties instead).
                $urls = collect($urls)->all();

                $this->assertSame(route('scenario.index'), $urls['index']);
                $this->assertSame(route('scenario.update', $scenario), $urls['scenario']);
                $this->assertSame(route('scenario.causes.update', $scenario), $urls['causes']);
                $this->assertSame(route('scenario.allocations.update', $scenario), $urls['allocations']);
                $this->assertSame(route('scenario.status', $scenario), $urls['status']);
                $this->assertSame(route('scenario.signoffs.store', $scenario), $urls['signoffs']);
                $this->assertSame(route('scenario.notes.store', $scenario), $urls['notes']);
                $this->assertSame(route('scenario.risk-search'), $urls['riskSearch']);

                // Both present-but-null: `notesEarlier` because there are no earlier notes to
                // load, `fxRates` because Phase 1 has no FX-rates screen — and "a link that 403s
                // or 404s must not appear" (spec 02 §5.6), so the prop is null rather than a
                // dead href.
                $this->assertArrayHasKey('notesEarlier', $urls);
                $this->assertNull($urls['notesEarlier']);
                $this->assertArrayHasKey('fxRates', $urls);
                $this->assertNull($urls['fxRates']);

                return true;
            }));
    }

    #[Test]
    public function the_risk_search_caps_at_twenty_and_needs_two_characters(): void
    {
        // Spec 02 §5.7: "≥ 2 characters … results capped at 20". An on-demand endpoint rather
        // than a shipped list, because a register is thousands of rows and shipping it would cost
        // 60 KB+ — five seconds at 100 kbps for a list most users never open.
        $this->actingAs($this->actor)
            ->getJson(route('scenario.risk-search', ['q' => 'a']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q' => 'Type at least two characters to search the register.']);

        foreach (range(1, 25) as $n) {
            \App\Models\Risk::create([
                'organization_id' => $this->organization->id,
                'risk_code' => sprintf('RK-SM-%04d', $n),
                'title' => 'Talent retention failure '.$n,
                'description' => 'Fixture.',
                'category_id' => $this->category->id,
                'status' => 'active',
            ]);
        }

        $response = $this->actingAs($this->actor)
            ->getJson(route('scenario.risk-search', ['q' => 'Talent retention']))
            ->assertOk();

        $this->assertCount(20, $response->json('data'));
        $this->assertSame(
            ['id', 'risk_code', 'title', 'url'],
            array_keys($response->json('data.0'))
        );
    }

    #[Test]
    public function the_risk_search_treats_a_wildcard_in_the_term_as_a_literal_character(): void
    {
        // F-4, on the house pattern (`QueryShaper`: `addcslashes($operand, '%_\\')`): what the
        // user typed is a SUBSTRING TO FIND, not a pattern to run. Unescaped, a `%` in a
        // type-ahead matches every row in the register and a `_` matches every row with a
        // character in it — the whole register returned as though it were a search result, and
        // the twenty-row cap hides the difference from anybody watching the screen.
        foreach ([
            ['RK-SM-0001', 'Annual bonus clawback at 100%'],
            ['RK-SM-0002', 'Talent retention failure'],
        ] as [$code, $title]) {
            \App\Models\Risk::create([
                'organization_id' => $this->organization->id,
                'risk_code' => $code,
                'title' => $title,
                'description' => 'Fixture.',
                'category_id' => $this->category->id,
                'status' => 'active',
            ]);
        }

        // The `%` in "100%" is a character in the title, so it finds that row and only that row.
        $response = $this->actingAs($this->actor)
            ->getJson(route('scenario.risk-search', ['q' => '100%']))
            ->assertOk();

        $this->assertSame(['RK-SM-0001'], collect($response->json('data'))->pluck('risk_code')->all());

        // A term made only of wildcards finds nothing, rather than everything.
        foreach (['%%', '__', '%_'] as $wildcards) {
            $this->assertSame(
                [],
                $this->actingAs($this->actor)
                    ->getJson(route('scenario.risk-search', ['q' => $wildcards]))
                    ->assertOk()
                    ->json('data'),
                "the term {$wildcards} is two literal characters no row contains"
            );
        }
    }

    #[Test]
    public function the_signoff_vocabularies_arrive_as_data_so_the_client_hardcodes_none_of_them(): void
    {
        // Spec 01 §5.6.2 and spec 02 §6: the modal renders one row per SOURCE and the panel
        // one bucket per source including a source that never signed ("Source 3 — no sign-off
        // recorded —"). Neither is derivable from the `signoffs` rows, which are EMPTY in the
        // pre-sign-off case the modal exists for. Spec 03 §2's principle: the front end
        // hard-codes none of this vocabulary — labels come from config so a tenant renaming
        // "Source 1" needs no release, values are the frozen consts.
        $scenario = $this->makeScenario();

        $expectedSources = collect((array) config('sm.sources'))
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
        $expectedDecisions = collect((array) config('sm.decision_labels', Scenario::DECISION_LABELS))
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();

        $this->assertCount(3, $expectedSources, 'the frozen source vocabulary has exactly three entries');
        $this->assertCount(2, $expectedDecisions, '§A1.6 refused a third decision value');

        // Ships on BOTH tabs — the sign-off modal is shell-level (spec 01 §4).
        foreach (['overview', 'qualitative'] as $tab) {
            $this->actingAs($this->actor)
                ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => $tab]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('signoffSources', $expectedSources)
                    ->where('signoffDecisions', $expectedDecisions));
        }
    }
}
