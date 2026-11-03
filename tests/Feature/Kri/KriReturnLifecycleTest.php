<?php

namespace Tests\Feature\Kri;

use App\Enums\KriContactRole;
use App\Events\KriBreachDetected;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\KriReturn;
use App\Models\MeasureBreach;
use App\Models\MeasureValue;
use App\Models\RiskAuditTrail;
use App\Models\User;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * ADR 0026 sections 4 and 6.4, A1.8, A1.10: what a champion does to a return.
 *
 * A DRAFT IS NOT A READING. It writes only kri_return_lines; it never reaches the
 * measure engine, so it cannot raise a breach, fire KriBreachDetected or count as
 * submitted. SUBMIT IS ONE TRANSACTION. Assertions are made on the database,
 * never on the redirect alone.
 *
 * Bands (KriTestCase defaults, higher is worse): green below 10, amber 10 to
 * under 50, red from 50.
 *
 * Note on status codes: draft, submit and reopen answer with a redirect and a
 * flash (the screen is an Inertia page), so "succeeds" is a redirect with
 * no session errors and "is refused for validation" is a 422 on a JSON request.
 */
class KriReturnLifecycleTest extends KriCycleTestCase
{
    /** Throws on the Nth MeasureValue insert while non-null (a listener cannot be unregistered). */
    public static ?int $explodeOnNth = null;

    public static int $inserts = 0;

    private KeyRiskIndicator $a;

    private KeyRiskIndicator $b;

    private KriReturn $return;

    private User $champion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->deptKri(null, ['name' => 'Alpha']);
        $this->b = $this->deptKri(null, ['name' => 'Bravo']);
        $this->return = $this->makeReturn();
        $this->champion = $this->makeStaff('Chidi Champion', ['kri.submit_return']);
        $this->makeContact($this->unit, $this->champion);

        // Every test: the application, and with it the model event dispatcher, is
        // rebuilt per test, so a hook registered once would be gone after the first.
        MeasureValue::creating(function () {
            if (self::$explodeOnNth !== null && ++self::$inserts === self::$explodeOnNth) {
                throw new \RuntimeException('simulated failure on the second reading');
            }
        });

        self::$explodeOnNth = null;
        self::$inserts = 0;
    }

    protected function tearDown(): void
    {
        self::$explodeOnNth = null;
        self::$inserts = 0;

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function lines(?float $a, ?float $b, array $aExtra = [], array $bExtra = []): array
    {
        return $this->body($this->return, [
            ['line_id' => $this->lineFor($this->return, $this->a)->id, 'value' => $a, 'comment' => 'note a'] + $aExtra,
            ['line_id' => $this->lineFor($this->return, $this->b)->id, 'value' => $b, 'comment' => null] + $bExtra,
        ]);
    }

    private function draft(array $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->champion)->putJson(route('risk.kri.my-return.draft', $this->return), $body);
    }

    private function submit(array $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->champion)->postJson(route('risk.kri.my-return.submit', $this->return), $body);
    }

    private function reopen(array $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->champion)->postJson(route('risk.kri.my-return.reopen', $this->return), $body);
    }

    private function submitGreen(): void
    {
        $this->submit($this->lines(5, 12))->assertRedirect();
    }

    /* ------------------------------------------------------------------ */
    /*  Draft */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_draft_never_creates_a_reading_a_breach_or_a_breach_event_and_never_counts_as_submitted(): void
    {
        Event::fake([KriBreachDetected::class]);

        $this->draft($this->lines(75, 12))->assertRedirect();

        $line = $this->lineFor($this->return, $this->a);
        $this->assertEquals(75, $line->value);
        $this->assertSame('red', $line->band, 'the band on a line is for display');
        $this->assertNull($line->measure_value_id);
        $this->assertNull($line->measure_breach_id);

        $this->assertSame(0, MeasureValue::query()->count(), 'a draft is not a measure_value');
        $this->assertSame(0, MeasureBreach::query()->count(), 'so the nightly sweep has nothing to breach on');
        $this->assertSame(0, DB::table('kri_measurements')->count());
        Event::assertNotDispatched(KriBreachDetected::class);
        $this->assertSame(0, Issue::query()->count());

        $fresh = $this->return->fresh();
        $this->assertSame('draft', $fresh->status->value);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($this->a->fresh()->current_value, 'the KRI facade is untouched');
        $this->assertSame('green', $this->a->fresh()->current_status);
    }

    #[Test]
    public function the_nightly_breach_sweep_finds_nothing_in_a_draft(): void
    {
        $this->draft($this->lines(75, 99))->assertRedirect();

        $this->artisan('kri:check-breaches')->assertExitCode(0);

        $this->assertSame(0, MeasureBreach::query()->count());
    }

    #[Test]
    public function a_draft_may_be_incomplete_and_is_never_a_422_for_a_missing_value_or_explanation(): void
    {
        $this->draft($this->lines(75, null))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull($this->lineFor($this->return, $this->b)->value);
        $this->assertNull($this->lineFor($this->return, $this->a)->root_cause);
    }

    #[Test]
    public function explanation_text_is_stored_with_a_red_draft_and_ignored_with_any_other_value(): void
    {
        $red = ['red_band' => $this->explanation()];

        $this->draft($this->lines(75, 5, $red, $red))->assertRedirect();

        $this->assertSame($this->explanation()['root_cause'], $this->lineFor($this->return, $this->a)->root_cause);
        $this->assertSame('2026-12-15', $this->lineFor($this->return, $this->a)->timeline->toDateString());
        $this->assertNull($this->lineFor($this->return, $this->b)->root_cause, 'a green value ignores posted explanation');
        $this->assertNull($this->lineFor($this->return, $this->b)->action_point);
    }

    #[Test]
    public function every_save_bumps_lock_version_and_a_stale_version_is_a_422_that_writes_nothing(): void
    {
        $stale = $this->lines(5, 6);
        $this->assertSame(0, $stale['lock_version']);

        $this->draft($stale)->assertRedirect();
        $this->assertSame(1, (int) $this->return->fresh()->lock_version);

        // Someone else's earlier page, posted again.
        $this->draft($this->lines(7, 8) + [])->assertRedirect();
        $this->assertSame(2, (int) $this->return->fresh()->lock_version);

        $stale['lines'][0]['value'] = 40;
        $this->draft($stale)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lock_version']);

        $this->assertEquals(7, $this->lineFor($this->return, $this->a)->value, 'the stale save wrote nothing');
        $this->assertSame(2, (int) $this->return->fresh()->lock_version);
    }

    #[Test]
    public function a_stale_lock_version_on_submit_is_a_422_and_writes_no_reading(): void
    {
        $body = $this->lines(5, 12);
        $body['lock_version'] = 9;

        $this->submit($body)->assertStatus(422)->assertJsonValidationErrors(['lock_version']);

        $this->assertSame(0, MeasureValue::query()->count());
        $this->assertSame('draft', $this->return->fresh()->status->value);
    }

    #[Test]
    public function a_line_of_another_return_or_tenant_is_refused(): void
    {
        $other = $this->makeUnit('Operations', 'OPS');
        $this->deptKri($other);
        $otherReturn = $this->makeReturn($other);
        $foreignLine = \App\Models\KriReturnLine::query()->where('kri_return_id', $otherReturn->id)->firstOrFail();

        $body = $this->lines(5, 6);
        $body['lines'][0]['line_id'] = $foreignLine->id;

        $this->draft($body)->assertStatus(422)->assertJsonValidationErrors(['lines.0.line_id']);
        $this->assertNull($foreignLine->fresh()->value);
    }

    #[Test]
    public function a_non_numeric_value_is_a_422(): void
    {
        $body = $this->lines(5, 6);
        $body['lines'][0]['value'] = 'abc';

        $this->draft($body)->assertStatus(422)->assertJsonValidationErrors(['lines.0.value']);
    }

    /* ------------------------------------------------------------------ */
    /*  Submit */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function submit_records_every_reading_through_the_engine_and_locks_the_return(): void
    {
        $this->submit($this->lines(5, 12))->assertRedirect()->assertSessionHasNoErrors();

        $return = $this->return->fresh();
        $this->assertSame('submitted', $return->status->value);
        $this->assertSame($this->champion->id, (int) $return->submitted_by);
        $this->assertNotNull($return->submitted_at);

        $this->assertSame(2, MeasureValue::query()->count());
        $a = $this->lineFor($this->return, $this->a);
        $this->assertNotNull($a->measure_value_id);
        $value = MeasureValue::query()->findOrFail($a->measure_value_id);
        $this->assertEquals(5, $value->value);
        $this->assertSame('green', $value->rag_band);
        $this->assertSame($return->period_id, (int) $value->period_id, "the reading is filed against October's Period");
        $this->assertSame($this->champion->id, (int) $value->entered_by);
        $this->assertSame('note a', $value->note, 'the comment becomes the reading note');

        $this->assertSame('amber', $this->lineFor($this->return, $this->b)->band);
        $this->assertDatabaseHas('kri_measurements', ['kri_id' => $this->a->id, 'measurement_date' => '2026-10-31']);
        $this->assertSame('amber', $this->b->fresh()->current_status);

        $this->assertDatabaseHas('risk_audit_trail', ['entity_type' => 'kri_return', 'entity_id' => $return->id, 'action_type' => 'return_submitted']);
    }

    #[Test]
    public function submit_needs_every_kri_to_have_a_value(): void
    {
        $this->submit($this->lines(5, null))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.1.value']);

        $this->assertSame(0, MeasureValue::query()->count());
        $this->assertSame('draft', $this->return->fresh()->status->value);
    }

    #[Test]
    public function a_red_submit_announces_the_breach_after_commit_exactly_once(): void
    {
        Event::fake([KriBreachDetected::class]);

        $this->submit($this->lines(75, 5, ['red_band' => $this->explanation()]))->assertRedirect()->assertSessionHasNoErrors();

        // Proves the deferred announcement is delivered once the submit returns.
        Event::assertDispatchedTimes(KriBreachDetected::class, 1);
        $this->assertSame(1, MeasureBreach::query()->where('band_to', 'red')->count());
    }

    #[Test]
    public function a_stored_red_line_left_out_of_the_submit_body_is_still_checked(): void
    {
        // Gate 2 finding 3: the request validates only the lines it is sent, so a
        // red line saved as a draft and omitted from the submit body must be held
        // to the same rules when submit reads it back.
        $outsider = $this->makeStaff('Outside Owner');

        $this->draft($this->lines(75, 5, ['red_band' => $this->explanation() + ['owner_id' => $outsider->id]]))
            ->assertRedirect();
        $this->assertSame($outsider->id, (int) $this->lineFor($this->return, $this->a)->action_owner_id);

        $body = $this->lines(75, 5);
        $body['lines'] = [$body['lines'][1]];   // only the green line

        $this->submit($body)->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, MeasureValue::query()->count(), 'nothing is recorded');
        $this->assertSame('draft', $this->return->fresh()->status->value);
    }

    #[Test]
    public function a_stored_red_line_with_a_past_timeline_is_refused_at_submit(): void
    {
        // Gate 2 re-review: the timeline rule, applied to the stored line, not the body.
        $this->draft($this->lines(75, 5, ['red_band' => $this->explanation()]))->assertRedirect();

        \App\Models\KriReturnLine::query()
            ->whereKey($this->lineFor($this->return, $this->a)->id)
            ->update(['timeline' => '2026-10-01']);   // before the frozen Lagos today

        $body = $this->lines(75, 5);
        $body['lines'] = [$body['lines'][1]];

        $this->submit($body)->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, MeasureValue::query()->count());
        $this->assertSame('draft', $this->return->fresh()->status->value);
    }

    #[Test]
    public function submit_is_atomic_when_one_kri_fails_nothing_is_written(): void
    {
        Event::fake([KriBreachDetected::class]);
        // KRI Alpha (lower id) is red and explained: its reading, breach and Issue are written
        // first. Bravo's reading then fails.
        self::$explodeOnNth = 2;

        $this->submit($this->lines(75, 5, ['red_band' => $this->explanation()]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, MeasureValue::query()->count());
        $this->assertSame(0, MeasureBreach::query()->count());
        $this->assertSame(0, Issue::query()->count());
        $this->assertSame(0, DB::table('kri_measurements')->count());
        $this->assertSame(0, \App\Models\KriReturnLine::query()->whereNotNull('measure_value_id')->count());
        $this->assertSame('draft', $this->return->fresh()->status->value);
        $this->assertSame(0, RiskAuditTrail::query()->where('action_type', 'return_submitted')->count());
        Event::assertNotDispatched(KriBreachDetected::class);
        $this->assertSame('green', $this->a->fresh()->current_status);
    }

    #[Test]
    public function a_listener_that_throws_cannot_fail_a_submit_that_has_committed(): void
    {
        Event::listen(KriBreachDetected::class, function () {
            throw new \RuntimeException('listener down');
        });

        $this->submit($this->lines(75, 5, ['red_band' => $this->explanation()]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('submitted', $this->return->fresh()->status->value);
        $this->assertSame(1, Issue::query()->count());
    }

    #[Test]
    public function a_submitted_return_cannot_be_saved_or_submitted_again(): void
    {
        $this->submitGreen();
        $before = MeasureValue::query()->count();

        $this->draft($this->lines(40, 41))->assertSessionHas('error');
        $this->submit($this->lines(40, 41))->assertSessionHas('error');

        $this->assertSame($before, MeasureValue::query()->count());
        $this->assertEquals(5, $this->lineFor($this->return, $this->a)->value);
    }

    /* ------------------------------------------------------------------ */
    /*  Reopen */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array{0: string, 1: bool}> */
    public static function reasons(): array
    {
        return [
            'nine characters' => [str_repeat('x', 9), false],
            'ten characters' => [str_repeat('x', 10), true],
            'five hundred characters' => [str_repeat('x', 500), true],
            'five hundred and one' => [str_repeat('x', 501), false],
            'empty' => ['', false],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('reasons')]
    public function reopen_needs_a_reason_of_ten_to_five_hundred_characters(string $reason, bool $accepted): void
    {
        $this->submitGreen();

        $response = $this->reopen(['lock_version' => (int) $this->return->fresh()->lock_version, 'reason' => $reason]);

        if ($accepted) {
            $response->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('reopened', $this->return->fresh()->status->value);
        } else {
            $response->assertStatus(422)->assertJsonValidationErrors(['reason']);
            $this->assertSame('submitted', $this->return->fresh()->status->value);
        }
    }

    #[Test]
    public function a_reopened_return_is_audited_with_its_reason_and_counts_as_not_submitted(): void
    {
        $this->submitGreen();

        $this->reopen(['lock_version' => (int) $this->return->fresh()->lock_version, 'reason' => 'Treasury restated the October figure.'])->assertRedirect();

        $return = $this->return->fresh();
        $this->assertSame('reopened', $return->status->value);
        $this->assertSame(1, (int) $return->reopen_count);
        $this->assertSame($this->champion->id, (int) $return->reopened_by);
        $this->assertFalse($return->isSubmitted());
        $this->assertSame('reopened', $return->displayStatus(KriWorkingCalendar::today(), true));

        $audit = RiskAuditTrail::query()->where('action_type', 'return_reopened')->firstOrFail();
        $this->assertSame('Treasury restated the October figure.', $audit->change_reason);
        $this->assertSame('submitted', $audit->old_value);
        $this->assertSame('reopened', $audit->new_value);
    }

    #[Test]
    public function a_reopened_return_can_be_resubmitted_and_a_changed_value_is_audited_old_and_new(): void
    {
        $this->submitGreen();
        $this->reopen(['lock_version' => (int) $this->return->fresh()->lock_version, 'reason' => 'A correction is needed here.'])->assertRedirect();

        $this->submit($this->lines(6, 12))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('submitted', $this->return->fresh()->status->value);
        $this->assertSame(2, MeasureValue::query()->count(), 'the reading was updated in place, not duplicated');
        $row = RiskAuditTrail::query()->where('action_type', 'value_changed')->firstOrFail();
        $this->assertSame('kri:'.$this->a->kri_code, $row->field_changed);
        $this->assertEquals(5, (float) $row->old_value);
        $this->assertEquals(6, (float) $row->new_value);
    }

    #[Test]
    public function only_a_submitted_return_can_be_reopened_and_not_after_its_period_closes(): void
    {
        $this->reopen(['lock_version' => 0, 'reason' => 'Not submitted yet, why not.'])->assertSessionHas('error');
        $this->assertSame('draft', $this->return->fresh()->status->value);

        $this->submitGreen();
        \App\Models\Period::query()->whereKey($this->return->period_id)->update(['is_closed' => true]);

        $this->reopen(['lock_version' => (int) $this->return->fresh()->lock_version, 'reason' => 'The period is closed already.'])
            ->assertSessionHas('error');
        $this->assertSame('submitted', $this->return->fresh()->status->value);
    }

    /* ------------------------------------------------------------------ */
    /*  Who may */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function only_the_champion_of_that_exact_department_may_save_submit_or_reopen(): void
    {
        $someoneElse = $this->makeStaff('Not Champion', ['kri.submit_return']);
        $otherChampion = $this->makeStaff('Ops Champion', ['kri.submit_return']);
        $other = $this->makeUnit('Operations', 'OPS');
        $this->makeContact($other, $otherChampion);
        $teamLead = $this->makeStaff('Team Lead', ['kri.submit_return']);
        $this->makeContact($this->unit, $teamLead, KriContactRole::TeamLead);
        $noPermission = $this->makeStaff('No Permission');
        $this->makeContact($this->unit, $noPermission);

        foreach ([$someoneElse, $otherChampion, $teamLead, $noPermission] as $user) {
            $this->draft($this->lines(5, 6), $user)->assertForbidden();
            $this->submit($this->lines(5, 6), $user)->assertForbidden();
            $this->reopen(['lock_version' => 0, 'reason' => 'I am not allowed to.'], $user)->assertForbidden();
        }

        $this->assertNull($this->lineFor($this->return, $this->a)->value);
    }

    #[Test]
    public function a_risk_officer_holding_every_permission_still_cannot_submit_or_reopen_for_a_department(): void
    {
        $officer = $this->makeStaff('Risk Officer', ['kri.view', 'kri.send_reminder', 'kri.all_departments', 'kri.submit_return']);

        $this->submit($this->lines(5, 6), $officer)->assertForbidden();
    }

    #[Test]
    public function another_tenants_return_is_a_404_for_a_champion(): void
    {
        $theirUnit = $this->foreignDepartment();
        $theirReturn = $this->makeReturn($theirUnit, [], syncLines: false);

        $this->actingAs($this->champion)
            ->putJson(route('risk.kri.my-return.draft', $theirReturn), ['lock_version' => 0, 'lines' => []])
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  Derived status and days overdue */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_badge_is_derived_with_the_documented_precedence(): void
    {
        $return = $this->return;       // due 2026-11-09
        $today = KriWorkingCalendar::day('2026-11-05');

        $this->assertSame('not_started', $return->displayStatus($today, false));
        $this->assertSame('in_progress', $return->displayStatus($today, true));

        $late = KriWorkingCalendar::day('2026-11-10');
        $this->assertSame('overdue', $return->displayStatus($late, true));

        $return->status = \App\Enums\KriReturnStatus::Reopened;
        $this->assertSame('reopened', $return->displayStatus($today, true));
        $this->assertSame('overdue', $return->displayStatus($late, true), 'overdue outranks reopened');

        $return->status = \App\Enums\KriReturnStatus::Submitted;
        $this->assertSame('submitted', $return->displayStatus($late, true), 'submitted outranks everything');
    }

    #[Test]
    public function days_overdue_is_a_whole_number_of_lagos_calendar_days_after_the_due_date(): void
    {
        $return = $this->return;       // due Monday 2026-11-09

        $this->assertNull($return->daysOverdue(KriWorkingCalendar::day('2026-11-08')));
        $this->assertNull($return->daysOverdue(KriWorkingCalendar::day('2026-11-09')), 'the due day itself is not late');
        $this->assertSame(1, $return->daysOverdue(KriWorkingCalendar::day('2026-11-10')));
        $this->assertSame(3, $return->daysOverdue(KriWorkingCalendar::day('2026-11-12')));
        $this->assertSame(30, $return->daysOverdue(KriWorkingCalendar::day('2026-12-09')));

        $return->status = \App\Enums\KriReturnStatus::Submitted;
        $this->assertNull($return->daysOverdue(KriWorkingCalendar::day('2026-11-12')), 'a submitted return is never overdue');
    }

    #[Test]
    public function a_kri_no_longer_due_is_dropped_from_the_lines_and_a_discarded_value_is_audited(): void
    {
        $this->draft($this->lines(5, 6))->assertRedirect();
        $this->b->forceFill(['is_active' => false])->save();

        DB::transaction(fn () => app(\App\Services\Kri\KriCycleService::class)->syncLines(KriReturn::query()->findOrFail($this->return->id)));

        $this->assertSame(1, \App\Models\KriReturnLine::query()->where('kri_return_id', $this->return->id)->count());
        $this->assertDatabaseHas('risk_audit_trail', ['entity_type' => 'kri_return', 'action_type' => 'line_discarded', 'field_changed' => 'kri:'.$this->b->kri_code]);
    }
}
