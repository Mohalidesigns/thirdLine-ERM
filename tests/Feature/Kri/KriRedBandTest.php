<?php

namespace Tests\Feature\Kri;

use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\MeasureBreach;
use App\Models\MeasureValue;
use App\Models\Period;
use App\Services\Kri\KriRedBandService;
use App\Services\KriMeasureBridge;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * ADR 0026 section 6 and A1.11: a red reading or red target must be explained,
 * and the explanation becomes a root cause on the breach plus an Issue.
 *
 * Every refusal is asserted as a 422 on a JSON request with the fields posted
 * by hand, i.e. WITH THE UI BYPASSED, and every outcome on the database.
 *
 * Default KRI bands (KriTestCase, higher is worse): green below 10, amber 10 to
 * under 50, red from 50. October 2026 is the period 2026-10-15 falls in.
 */
class KriRedBandTest extends KriCycleTestCase
{
    private KeyRiskIndicator $indicator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indicator = $this->deptKri(null, ['name' => 'Settlement fails']);
    }

    private function record(float $value, array $extra = [], ?KeyRiskIndicator $kri = null, string $date = '2026-10-15')
    {
        return $this->actingAs($this->actor)->postJson(
            route('risk.kri.record-measurement', $kri ?? $this->indicator),
            ['measurement_date' => $date, 'value' => $value] + $extra
        );
    }

    /** @return array<string, string> */
    private function red(string $timeline = '2026-12-15'): array
    {
        return $this->explanation($timeline);
    }

    private function update(array $overrides = [], ?KeyRiskIndicator $kri = null)
    {
        $kri ??= $this->indicator;

        return $this->actingAs($this->actor)->putJson(route('risk.kri.update', $kri), array_merge([
            'kri_name' => $kri->name,
            'measurement_unit' => '%',
            'measurement_frequency' => 'monthly',
            'kri_owner_id' => $this->actor->id,
            'direction' => 'higher_is_worse',
            'green_threshold' => 10,
            'red_threshold' => 50,
            'target_value' => null,
            'is_active' => true,
            'business_unit_id' => $this->unit->id,
        ], $overrides));
    }

    private function kriIssues()
    {
        return Issue::query()->where('issue_source', 'kri_breach');
    }

    /* ------------------------------------------------------------------ */
    /*  RecordKriMeasurementRequest */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_red_reading_without_an_explanation_is_a_422_on_all_three_fields_and_writes_nothing(): void
    {
        $this->record(75)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['red_band.root_cause', 'red_band.action_point', 'red_band.timeline']);

        $this->assertSame(0, MeasureValue::query()->count());
        $this->assertSame(0, MeasureBreach::query()->count());
        $this->assertSame(0, $this->kriIssues()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function missingFields(): array
    {
        return ['root cause' => ['root_cause'], 'action point' => ['action_point'], 'timeline' => ['timeline']];
    }

    #[Test]
    #[DataProvider('missingFields')]
    public function each_of_the_three_fields_is_required_on_its_own(string $missing): void
    {
        $red = $this->red();
        unset($red[$missing]);

        $this->record(75, ['red_band' => $red])
            ->assertStatus(422)
            ->assertJsonValidationErrors(["red_band.{$missing}"]);

        $blank = $this->red();
        $blank[$missing] = '   ';

        $this->record(75, ['red_band' => $blank])->assertStatus(422)->assertJsonValidationErrors(["red_band.{$missing}"]);
        $this->assertSame(0, MeasureValue::query()->count());
    }

    #[Test]
    public function a_non_red_reading_saves_without_an_explanation(): void
    {
        $this->record(12)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, MeasureValue::query()->count());
        $this->assertSame('amber', MeasureValue::query()->firstOrFail()->rag_band);
        $this->assertSame(0, $this->kriIssues()->count());

        $this->record(5, [], null, '2026-09-15')->assertRedirect();
        $this->assertSame(2, MeasureValue::query()->count());
    }

    #[Test]
    public function an_explanation_posted_with_a_non_red_reading_is_ignored_and_not_stored(): void
    {
        $this->record(12, ['red_band' => $this->red()])->assertRedirect();

        $this->assertSame(0, $this->kriIssues()->count());
        $this->assertSame(0, MeasureBreach::query()->whereNotNull('root_cause')->count());
    }

    #[Test]
    public function a_timeline_in_the_past_is_refused_and_today_in_lagos_is_accepted(): void
    {
        // Today in Lagos is 2026-11-02.
        $this->record(75, ['red_band' => $this->red('2026-11-01')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['red_band.timeline']);

        $this->record(75, ['red_band' => $this->red('2026-11-02')])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-11-02', $this->kriIssues()->firstOrFail()->remediation_due_date->toDateString());
    }

    #[Test]
    public function an_owner_from_another_tenant_is_refused(): void
    {
        $foreign = $this->foreignUser();

        $this->record(75, ['red_band' => $this->red() + ['owner_id' => $foreign->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['red_band.owner_id']);
    }

    #[Test]
    public function a_red_reading_writes_the_explanation_the_breach_the_issue_and_the_graph_edge_together(): void
    {
        $this->record(75, ['red_band' => $this->red()])->assertRedirect()->assertSessionHasNoErrors();

        $breach = MeasureBreach::query()->where('band_to', 'red')->firstOrFail();
        $issue = $this->kriIssues()->firstOrFail();

        $this->assertSame($this->red()['root_cause'], $breach->root_cause);
        $this->assertSame($issue->id, (int) $breach->linked_issue_id);

        $this->assertSame('kri_breach', $issue->issue_source);
        $this->assertSame('KRI breach', $issue->issue_category);
        $this->assertSame('2026-12-15', $issue->remediation_due_date->toDateString(), 'the Issue due date IS the timeline');
        $this->assertSame($this->red()['root_cause'], $issue->observation);
        $this->assertSame($this->red()['action_point'], $issue->action_plan);
        $this->assertSame('high', $issue->priority);
        $this->assertSame('OPEN', $issue->issue_status);
        $this->assertSame($this->actor->id, (int) $issue->responsible_owner_id, 'Risk Officer path: kri_owner_id ?? owner_id');
        $this->assertSame($this->unit->id, (int) $issue->business_unit_id);
        $this->assertSame('Treasury', $issue->department);
        $this->assertSame('kri_department_contacts', $issue->escalation_path);
        $this->assertSame($this->actor->id, (int) $issue->created_by);
        $this->assertSame("KRI {$this->indicator->kri_code} in red — Treasury — ".$this->reportingMonth()->name, $issue->title);
        $this->assertStringContainsString(route('risk.kri.show', $this->indicator), $issue->description);

        // The graph edge: Issue derives_from KRI. openIssueFor() finds it only through the edge.
        $this->assertSame($issue->id, app(KriRedBandService::class)->openIssueFor($this->indicator)?->id);
        $this->assertDatabaseHas('risk_audit_trail', ['entity_type' => 'measure_breach', 'entity_id' => $breach->id, 'field_changed' => 'root_cause']);
    }

    #[Test]
    public function an_explicit_owner_wins_over_the_default(): void
    {
        $owner = $this->makeStaff('Named Owner');

        $this->record(75, ['red_band' => $this->red() + ['owner_id' => $owner->id]])->assertRedirect();

        $this->assertSame($owner->id, (int) $this->kriIssues()->firstOrFail()->responsible_owner_id);
    }

    #[Test]
    public function an_unassigned_kri_raises_an_issue_with_no_department_and_no_department_ladder(): void
    {
        $kri = $this->deptKri(null, ['business_unit_id' => null]);

        $this->record(75, ['red_band' => $this->red()], $kri)->assertRedirect();

        $issue = $this->kriIssues()->firstOrFail();
        $this->assertNull($issue->business_unit_id);
        $this->assertNull($issue->escalation_path, 'no department, so no contacts to climb');
        $this->assertStringContainsString('Unassigned', $issue->title);
    }

    #[Test]
    public function the_issue_is_not_created_when_the_edge_cannot_be_written_and_the_whole_save_rolls_back(): void
    {
        DB::table('object_relationship_types')->where('code', 'derives_from')->update(['code' => 'derives_from_disabled']);

        try {
            $this->withoutExceptionHandling();
            $this->record(75, ['red_band' => $this->red()]);
            $this->fail('a missing edge must abort the save');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('graph', $e->getMessage());
        }

        $this->assertSame(0, $this->kriIssues()->count());
        $this->assertSame(0, MeasureValue::query()->count(), 'the reading rolled back with it');
        $this->assertSame(0, MeasureBreach::query()->count());
    }

    #[Test]
    public function a_red_kri_with_an_open_issue_reuses_it_and_leaves_status_and_priority_alone(): void
    {
        $this->record(75, ['red_band' => $this->red()], null, '2026-09-15')->assertRedirect();
        $issue = $this->kriIssues()->firstOrFail();
        $issue->forceFill(['issue_status' => 'IN_PROGRESS', 'priority' => 'critical'])->save();

        $same = ['root_cause' => 'New root cause after investigation.', 'action_point' => 'A revised action.', 'timeline' => '2026-12-15'];
        $this->record(80, ['red_band' => $same])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $this->kriIssues()->count(), 'never a second Issue');
        $issue->refresh();
        $this->assertSame('New root cause after investigation.', $issue->observation);
        $this->assertSame('A revised action.', $issue->action_plan);
        $this->assertSame('IN_PROGRESS', $issue->issue_status);
        $this->assertSame('critical', $issue->priority);
        $this->assertSame($issue->id, (int) MeasureBreach::query()->where('period_id', $this->reportingMonth()->id)->where('band_to', 'red')->firstOrFail()->linked_issue_id);
    }

    #[Test]
    public function changing_the_timeline_of_the_open_issue_needs_a_reason_and_is_audited_with_it(): void
    {
        $this->record(75, ['red_band' => $this->red('2026-12-15')], null, '2026-09-15')->assertRedirect();
        $issue = $this->kriIssues()->firstOrFail();

        $this->record(80, ['red_band' => $this->red('2027-01-31')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['red_band.timeline_change_reason']);
        $this->assertSame('2026-12-15', $issue->fresh()->remediation_due_date->toDateString());

        $this->record(80, ['red_band' => $this->red('2027-01-31') + ['timeline_change_reason' => 'Vendor patch slipped a month.']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('2027-01-31', $issue->fresh()->remediation_due_date->toDateString());
        $this->assertSame(1, $this->kriIssues()->count());
        $this->assertDatabaseHas('risk_audit_trail', [
            'entity_type' => 'issue', 'entity_id' => $issue->id, 'field_changed' => 'remediation_due_date',
            'change_reason' => 'Vendor patch slipped a month.',
        ]);
    }

    #[Test]
    public function an_overdue_action_can_be_confirmed_without_moving_its_date(): void
    {
        $this->record(75, ['red_band' => $this->red('2026-12-15')], null, '2026-09-15')->assertRedirect();
        $issue = $this->kriIssues()->firstOrFail();
        $issue->forceFill(['remediation_due_date' => '2026-10-20', 'issue_status' => 'OVERDUE'])->save();

        // 2026-10-20 is in the past, but it equals the Issue's own date: no reason, no 422.
        $this->record(80, ['red_band' => $this->red('2026-10-20')])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('OVERDUE', $issue->fresh()->issue_status);
        $this->assertSame('2026-10-20', $issue->fresh()->remediation_due_date->toDateString());

        // ...but a DIFFERENT past date is still refused.
        $this->record(80, ['red_band' => $this->red('2026-10-21')])->assertStatus(422)->assertJsonValidationErrors(['red_band.timeline']);
    }

    #[Test]
    public function going_back_to_green_leaves_the_issue_open_and_the_explanation_in_place(): void
    {
        $this->record(75, ['red_band' => $this->red()])->assertRedirect();
        $issue = $this->kriIssues()->firstOrFail();

        $this->record(5)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('OPEN', $issue->fresh()->issue_status, 'green this month does not prove the action was done');
        $breach = MeasureBreach::query()->where('band_to', 'red')->firstOrFail();
        $this->assertSame('resolved', $breach->status, 'the breach follows the engine rules');
        $this->assertSame($this->red()['root_cause'], $breach->root_cause);
        $this->assertSame($issue->id, (int) $breach->linked_issue_id);
        $this->assertSame($issue->id, app(KriRedBandService::class)->openIssueFor($this->indicator)?->id);
    }

    #[Test]
    public function a_closed_issue_is_not_reused_and_the_next_red_raises_a_new_one(): void
    {
        $this->record(75, ['red_band' => $this->red()], null, '2026-09-15')->assertRedirect();
        $this->kriIssues()->firstOrFail()->forceFill(['issue_status' => 'CLOSED'])->save();

        $this->assertNull(app(KriRedBandService::class)->openIssueFor($this->indicator));
        $this->record(80, ['red_band' => $this->red()])->assertRedirect();

        $this->assertSame(2, $this->kriIssues()->count());
        $this->assertSame(1, $this->kriIssues()->where('issue_status', 'OPEN')->count());
    }

    #[Test]
    public function pending_closure_and_overdue_issues_still_count_as_open(): void
    {
        $this->record(75, ['red_band' => $this->red()], null, '2026-09-15')->assertRedirect();
        $issue = $this->kriIssues()->firstOrFail();

        foreach (['PENDING_CLOSURE', 'OVERDUE', 'IN_PROGRESS'] as $status) {
            $issue->forceFill(['issue_status' => $status])->save();
            $this->assertSame($issue->id, app(KriRedBandService::class)->openIssueFor($this->indicator)?->id, $status);
        }

        $issue->forceFill(['issue_status' => 'CANCELLED'])->save();
        $this->assertNull(app(KriRedBandService::class)->openIssueFor($this->indicator));
    }

    /* ------------------------------------------------------------------ */
    /*  The engine edges, through the request */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array{0: string, 1: float, 2: string}> direction, value, band */
    public static function edges(): array
    {
        return [
            'higher: just under green boundary' => ['higher', 9.99, 'green'],
            'higher: ON the green boundary is amber' => ['higher', 10.0, 'amber'],
            'higher: just under red boundary' => ['higher', 49.99, 'amber'],
            'higher: ON the red boundary is red' => ['higher', 50.0, 'red'],
            'lower: just over green boundary' => ['lower', 20.01, 'green'],
            'lower: ON the green boundary is amber' => ['lower', 20.0, 'amber'],
            'lower: just over red boundary' => ['lower', 10.01, 'amber'],
            'lower: ON the red boundary is red' => ['lower', 10.0, 'red'],
        ];
    }

    #[Test]
    #[DataProvider('edges')]
    public function the_request_and_the_engine_agree_with_the_truth_table_on_the_boundaries(string $direction, float $value, string $band): void
    {
        $kri = $direction === 'higher'
            ? $this->indicator
            : $this->deptKri(null, [
                'threshold_direction' => 'lower_worse', 'direction' => 'lower_is_worse',
                'green_threshold_max' => null, 'green_threshold_min' => 20,
                'amber_threshold_min' => 10, 'amber_threshold_max' => 20,
                'red_threshold_min' => null, 'red_threshold_max' => 10,
            ]);

        $response = $this->record($value, [], $kri);

        if ($band === 'red') {
            $response->assertStatus(422)->assertJsonValidationErrors(['red_band.root_cause']);
            $this->assertSame(0, MeasureValue::query()->count());

            $this->record($value, ['red_band' => $this->red()], $kri)->assertRedirect()->assertSessionHasNoErrors();
        } else {
            $response->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame($band, MeasureValue::query()->firstOrFail()->rag_band);
        $period = Period::query()->findOrFail(MeasureValue::query()->firstOrFail()->period_id);
        $this->assertSame($band, app(\App\Support\Kri\KriBandRule::class)->bandForReading($kri->fresh(), $period, $value));
    }

    /* ------------------------------------------------------------------ */
    /*  UpdateKriRequest (the Edit form's Target Value) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_red_target_without_an_explanation_is_a_422_even_when_the_ui_is_bypassed(): void
    {
        $this->update(['target_value' => 60])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['red_band.root_cause', 'red_band.action_point', 'red_band.timeline']);

        $this->assertNull($this->indicator->fresh()->target_value, 'nothing saved');
        $this->assertSame(0, $this->kriIssues()->count());
    }

    #[Test]
    public function the_red_decision_uses_the_boundaries_being_posted_not_the_stored_ones(): void
    {
        // 40 is amber against 10/50 but red against a posted red boundary of 30.
        $this->update(['target_value' => 40])->assertRedirect();
        $this->update(['target_value' => 40, 'red_threshold' => 30])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['red_band.root_cause']);
    }

    #[Test]
    public function a_non_red_target_saves_without_an_explanation(): void
    {
        $this->update(['target_value' => 20])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(20, $this->indicator->fresh()->target_value);
        $this->assertSame(0, $this->kriIssues()->count());
        $this->assertSame(0, MeasureValue::query()->count(), 'a target is not a reading');
    }

    #[Test]
    public function a_red_target_with_an_explanation_saves_the_kri_and_raises_the_issue_in_one_transaction(): void
    {
        $this->update(['target_value' => 60, 'red_band' => $this->red()])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(60, $this->indicator->fresh()->target_value);
        $issue = $this->kriIssues()->firstOrFail();
        $this->assertSame('2026-12-15', $issue->remediation_due_date->toDateString());
        $this->assertSame($this->unit->id, (int) $issue->business_unit_id);
        $this->assertSame(0, MeasureValue::query()->count(), 'no reading, no breach');
        $this->assertSame(0, MeasureBreach::query()->count());
        $this->assertSame($issue->id, app(KriRedBandService::class)->openIssueFor($this->indicator)?->id);
    }

    #[Test]
    public function the_edit_form_explanation_lands_on_the_most_recent_open_red_breach_too(): void
    {
        app(KriMeasureBridge::class)->recordMeasurement($this->indicator, '2026-10-15', 75.0);
        $breach = MeasureBreach::query()->where('band_to', 'red')->firstOrFail();
        $this->assertNull($breach->root_cause);

        $this->update(['target_value' => 60, 'red_band' => $this->red()])->assertRedirect();

        $breach->refresh();
        $this->assertSame($this->red()['root_cause'], $breach->root_cause);
        $this->assertSame($this->kriIssues()->firstOrFail()->id, (int) $breach->linked_issue_id);
    }

    #[Test]
    public function the_edit_form_does_not_re_ask_when_an_unrelated_field_is_saved_on_an_explained_red_kri(): void
    {
        $this->update(['target_value' => 60, 'red_band' => $this->red()])->assertRedirect();
        $issue = $this->kriIssues()->firstOrFail();
        $stamp = $issue->fresh()->updated_at;

        // A1.11: red, same target, an open Issue exists -> optional.
        $this->update(['target_value' => 60, 'kri_name' => 'Renamed only'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Renamed only', $this->indicator->fresh()->name);
        $this->assertSame(1, $this->kriIssues()->count());
        $this->assertSame($this->red()['root_cause'], $issue->fresh()->observation, 'absent red_band touches no Issue');
        $this->assertEquals($stamp, $issue->fresh()->updated_at);
    }

    #[Test]
    public function rule_b_a_changed_target_while_red_re_asks(): void
    {
        $this->update(['target_value' => 60, 'red_band' => $this->red()])->assertRedirect();

        $this->update(['target_value' => 70])->assertStatus(422)->assertJsonValidationErrors(['red_band.root_cause']);
        $this->assertEquals(60, $this->indicator->fresh()->target_value);

        // A decimal-precision no-op (60 vs 60.0000004) is not a change.
        $this->update(['target_value' => 60.0000004])->assertRedirect()->assertSessionHasNoErrors();
    }

    #[Test]
    public function rule_a_moving_into_red_by_changing_the_boundaries_re_asks_even_with_an_open_issue(): void
    {
        $this->update(['target_value' => 60, 'red_band' => $this->red()])->assertRedirect();
        $this->update(['target_value' => 40])->assertRedirect();   // moved out of red (no explanation owed)
        $this->assertSame(1, $this->kriIssues()->count());

        // Same target 40; the red boundary moves down to 30: amber -> red.
        $this->update(['target_value' => 40, 'red_threshold' => 30])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['red_band.root_cause']);

        // Back into red by moving the target itself.
        $this->update(['target_value' => 60])->assertStatus(422);
    }

    #[Test]
    public function rule_c_a_red_target_with_no_open_issue_re_asks_even_if_nothing_else_changed(): void
    {
        // Red target stored directly, as by an older version: no Issue exists.
        $this->indicator->forceFill(['target_value' => 60])->save();
        $this->assertNull(app(KriRedBandService::class)->openIssueFor($this->indicator));

        $this->update(['target_value' => 60, 'kri_name' => 'Renamed only'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['red_band.root_cause']);
    }

    #[Test]
    public function when_the_explanation_is_optional_but_started_all_three_fields_are_required(): void
    {
        $this->update(['target_value' => 60, 'red_band' => $this->red()])->assertRedirect();

        $this->update(['target_value' => 60, 'red_band' => ['root_cause' => 'Only this.']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['red_band.action_point', 'red_band.timeline']);
    }

    #[Test]
    public function when_the_explanation_is_optional_and_complete_it_updates_the_reused_issue(): void
    {
        $this->update(['target_value' => 60, 'red_band' => $this->red()])->assertRedirect();

        $this->update(['target_value' => 60, 'red_band' => ['root_cause' => 'Better understood now.', 'action_point' => 'Sharper action.', 'timeline' => '2026-12-15']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $this->kriIssues()->count());
        $this->assertSame('Better understood now.', $this->kriIssues()->firstOrFail()->observation);
    }

    #[Test]
    public function moving_the_target_out_of_red_saves_and_leaves_the_issue_open(): void
    {
        $this->update(['target_value' => 60, 'red_band' => $this->red()])->assertRedirect();

        $this->update(['target_value' => 20])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(20, $this->indicator->fresh()->target_value);
        $this->assertSame('OPEN', $this->kriIssues()->firstOrFail()->issue_status);
    }

    #[Test]
    public function the_department_is_saved_and_a_department_of_another_tenant_is_refused(): void
    {
        $other = $this->makeUnit('Operations', 'OPS');
        $this->update(['business_unit_id' => $other->id])->assertRedirect();
        $this->assertSame($other->id, (int) $this->indicator->fresh()->business_unit_id);

        $this->update(['business_unit_id' => null])->assertRedirect();
        $this->assertNull($this->indicator->fresh()->business_unit_id);

        $foreign = $this->foreignDepartment();
        $this->update(['business_unit_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors(['business_unit_id']);
    }

    #[Test]
    public function a_kri_can_be_created_into_a_department(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.kri.store'), $this->validKri(['business_unit_id' => $this->unit->id]))
            ->assertRedirect();

        $this->assertSame($this->unit->id, (int) KeyRiskIndicator::query()->where('kri_name', 'Failed settlement rate')->firstOrFail()->business_unit_id);

        $this->actingAs($this->actor)
            ->postJson(route('risk.kri.store'), $this->validKri(['business_unit_id' => $this->foreignDepartment()->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['business_unit_id']);
    }

    /* ------------------------------------------------------------------ */
    /*  The champion's submit */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_champion_submit_with_a_red_value_needs_the_explanation_and_the_owner_must_belong_to_the_department(): void
    {
        $champion = $this->makeStaff('Chidi Champion', ['kri.submit_return']);
        $this->makeContact($this->unit, $champion);
        $return = $this->makeReturn();
        $lineId = $this->lineFor($return, $this->indicator)->id;
        $post = fn (array $line) => $this->actingAs($champion)->postJson(
            route('risk.kri.my-return.submit', $return),
            ['lock_version' => (int) $return->fresh()->lock_version, 'lines' => [['line_id' => $lineId, 'value' => 75] + $line]]
        );

        $post([])->assertStatus(422)->assertJsonValidationErrors(['lines.0.red_band.root_cause', 'lines.0.red_band.action_point', 'lines.0.red_band.timeline']);

        $outsider = $this->makeStaff('Outsider');
        $post(['red_band' => $this->red() + ['owner_id' => $outsider->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['lines.0.red_band.owner_id']);

        $this->assertSame(0, MeasureValue::query()->count());

        $post(['red_band' => $this->red()])->assertRedirect()->assertSessionHasNoErrors();

        $issue = $this->kriIssues()->firstOrFail();
        $line = $this->lineFor($return, $this->indicator);
        $this->assertSame($champion->id, (int) $issue->responsible_owner_id, 'champion path default owner is the champion');
        $this->assertSame($issue->id, (int) $line->issue_id);
        $this->assertNotNull($line->measure_breach_id);
        $this->assertStringContainsString(route('risk.kri.returns.show', $return), $issue->description);
        $this->assertStringContainsString($this->reportingMonth()->name, $issue->title);
    }

    #[Test]
    public function a_champion_resubmitting_red_reuses_the_issue_and_a_changed_timeline_needs_a_reason(): void
    {
        $champion = $this->makeStaff('Chidi Champion', ['kri.submit_return']);
        $this->makeContact($this->unit, $champion);
        $return = $this->makeReturn();
        $lineId = $this->lineFor($return, $this->indicator)->id;
        $send = fn (string $route, array $extra) => $this->actingAs($champion)->postJson(
            route($route, $return),
            ['lock_version' => (int) $return->fresh()->lock_version] + $extra
        );

        $send('risk.kri.my-return.submit', ['lines' => [['line_id' => $lineId, 'value' => 75, 'red_band' => $this->red()]]])->assertRedirect();
        $send('risk.kri.my-return.reopen', ['reason' => 'Figures were restated by Treasury.'])->assertRedirect();

        $changed = ['line_id' => $lineId, 'value' => 80, 'red_band' => $this->red('2027-02-28')];
        $send('risk.kri.my-return.submit', ['lines' => [$changed]])
            ->assertStatus(422)->assertJsonValidationErrors(['lines.0.red_band.timeline_change_reason']);

        $changed['red_band']['timeline_change_reason'] = 'Waiting for a vendor release.';
        $send('risk.kri.my-return.submit', ['lines' => [$changed]])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $this->kriIssues()->count());
        $this->assertSame('2027-02-28', $this->kriIssues()->firstOrFail()->remediation_due_date->toDateString());
    }
}
