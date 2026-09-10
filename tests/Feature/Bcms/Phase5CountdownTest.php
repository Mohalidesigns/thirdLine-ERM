<?php

namespace Tests\Feature\Bcms;

use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\NotificationChannel;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\DeliveryStatus;
use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\NotificationDelivery;
use App\Models\Bcms\ReadinessTask;
use App\Models\Bcms\ReminderSchedule;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\BcmsSettings;
use App\Services\Bcms\Exercises\ExerciseDefinitionService;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
use App\Services\Bcms\Notification\ChannelRegistry;
use App\Services\Bcms\Reminders\AttendanceService;
use App\Services\Bcms\Reminders\ReadinessService;
use App\Services\Bcms\Reminders\ReminderAudienceResolver;
use App\Services\Bcms\Reminders\ReminderDispatcher;
use App\Services\Bcms\Reminders\ReminderScheduleBuilder;
use App\Support\Bcms\AudienceRule;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * GATE G1 — the T-10 countdown and the readiness checklists.
 *
 * This is the phase the prompt says must be flawless, because it is the demo
 * and because two failure modes end the feature:
 *
 *   (a) an alert that should have fired and did not — a compliance failure the
 *       customer discovers from an examiner;
 *   (b) alert fatigue, after which staff filter BCMS mail to junk and the
 *       rhythm the module exists to create dies.
 *
 * Every test below belongs to one of those. The idempotency and quiet-hours
 * tests are (a); the consolidation tests are (b).
 *
 * EVERY TEST RUNS AGAINST THE PHASE 0 MOCK ADAPTERS, deliberately. Track C owns
 * the real ones and they arrive at W11. What is asserted here is the
 * DISPATCHER's behaviour — audience, consolidation, timing, idempotency,
 * write-ahead — and never a gateway's. If swapping a real adapter in would
 * require changing anything this file tests, the abstraction is wrong.
 */
class Phase5CountdownTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $facilitator;

    private User $owner;

    private ExerciseProgramme $programme;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $this->facilitator = $this->user('facilitator@khb.test');
        $this->owner = $this->user('owner@khb.test');

        $this->programme = app(ExerciseProgrammeService::class)->create(
            (int) now()->year, 'Exercise programme', [], $this->owner->id,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 1 — ten days out, one notification per participant per day
    /*  for ten consecutive days.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_exercise_ten_days_out_sends_one_alert_a_day_for_ten_days(): void
    {
        $occurrence = $this->occurrenceInDays(10);
        $alice = $this->participant('alice@khb.test');
        $contact = $this->contactFor($alice, $occurrence);

        // Alice owns a readiness item, which is the ordinary case: the daily
        // band's emailed half is for people who still owe something, and the
        // low-noise half is in-app only. A participant who owed nothing would
        // correctly get no email at all, which is the fatigue guard working
        // rather than a gap.
        ReadinessTask::query()->where('occurrence_id', $occurrence->getKey())
            ->update(['owner_id' => $alice->id]);

        // T-2 is the ESCALATION rung and goes to the owner and the line
        // manager, not to the participant — by T-2 they have had six daily
        // reminders and a seventh is the definition of alert fatigue. The owner
        // therefore needs a contact, or that day legitimately reaches nobody.
        $this->contactFor($this->owner, $occurrence, participant: false);

        $days = [];

        // Walk the ten days one at a time, the way the hourly tick actually
        // does. Dispatching them all at once would prove nothing about the
        // day-by-day behaviour, which is the feature.
        for ($day = 10; $day >= 1; $day--) {
            $at = $occurrence->scheduled_date->copy()->subDays($day)->setTime(8, 0);

            Carbon::setTestNow($at);

            $before = NotificationDelivery::query()->count();
            app(ReminderDispatcher::class)->dispatchDue($at);
            $days[$day] = NotificationDelivery::query()->count() - $before;
        }

        Carbon::setTestNow();

        $this->assertCount(10, $days, 'Ten days should each have been walked.');

        // TEN CONSECUTIVE DAYS, each carrying at least one alert. Which
        // audience it reaches varies by rung — T-2 is the escalation and goes
        // to managers rather than participants — but no day in the run-up is
        // silent, which is what the countdown promises.
        foreach ($days as $day => $sent) {
            $this->assertGreaterThan(0, $sent, "T-{$day} sent nothing at all.");
        }

        // One MESSAGE per person per day. A day may write more than one
        // delivery row when a rung asks for two channels — the fatigue guard is
        // about messages, not about rows — so the count is per channel per day.
        foreach (range(1, 10) as $day) {
            $on = $occurrence->scheduled_date->copy()->subDays($day)->toDateString();

            $perChannel = NotificationDelivery::query()
                ->where('recipient_contact_id', $contact->getKey())
                ->whereDate('created_at', $on)
                ->selectRaw('channel, COUNT(*) as total')
                ->groupBy('channel')
                ->pluck('total', 'channel');

            foreach ($perChannel as $channel => $total) {
                $this->assertSame(
                    1,
                    (int) $total,
                    "T-{$day} sent {$total} {$channel} messages to one person; the guard is one per day.",
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 2 — content varies with open readiness tasks.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_daily_digest_says_what_is_outstanding_and_says_something_else_when_nothing_is(): void
    {
        $occurrence = $this->occurrenceInDays(10);

        $behind = $this->participant('behind@khb.test');
        $this->contactFor($behind, $occurrence);

        // Three of seven outstanding for one person, nothing for the other.
        $tasks = ReadinessTask::query()->where('occurrence_id', $occurrence->getKey())->get();
        $this->assertGreaterThanOrEqual(4, $tasks->count());

        $tasks->each(fn (ReadinessTask $t) => $t->update(['owner_id' => $behind->id]));
        $tasks->take($tasks->count() - 3)->each(
            fn (ReadinessTask $t) => $t->update(['status' => 'complete', 'completed_at' => now()])
        );

        $recorder = $this->recordingChannel(ChannelKey::Email);

        // Tick day by day, the way the hourly command does. Sweeping ten days
        // in one call would drain the T-10 formal notice into the same batch
        // and assert against the wrong message.
        $body = null;

        for ($day = 10; $day >= 4; $day--) {
            $at = $occurrence->scheduled_date->copy()->subDays($day)->setTime(8, 0);

            Carbon::setTestNow($at);
            app(ReminderDispatcher::class)->dispatchDue($at);
            Carbon::setTestNow();

            if ($day === 6) {
                $digest = collect($recorder->sent)
                    ->last(fn (array $s) => ($s['message']->metadata['kind'] ?? null) === 'digest');

                $body = $digest === null ? null : $digest['message']->wireBody();
            }
        }

        $this->assertNotNull($body, 'The daily band sent no digest.');

        // Blueprint §5.4's sentence, near enough: the count, the days, the name.
        $this->assertStringContainsString('3 of '.$tasks->count().' readiness items outstanding', $body);
        $this->assertStringContainsString('6 days to', $body);
        $this->assertStringContainsString('THIS IS AN EXERCISE', $body);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 3 — the T-2 escalation.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_t_minus_two_escalation_goes_to_the_manager_and_the_programme_owner_not_the_latecomer(): void
    {
        $occurrence = $this->occurrenceInDays(10);

        $latecomer = $this->participant('late@khb.test');
        $manager = $this->user('manager@khb.test');

        $latecomerContact = $this->contactFor($latecomer, $occurrence);
        $latecomerContact->update(['manager_user_id' => $manager->id]);
        $this->contactFor($manager, $occurrence, participant: false);
        $this->contactFor($this->owner, $occurrence, participant: false);

        ReadinessTask::query()->where('occurrence_id', $occurrence->getKey())
            ->update(['owner_id' => $latecomer->id, 'is_blocking' => true, 'status' => 'open']);

        $escalation = ReminderSchedule::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('template_key', 'exercise.escalation')
            ->sole();

        $recipients = app(ReminderAudienceResolver::class)->resolve($occurrence, $escalation);
        $emails = $recipients->pluck('email')->all();

        $this->assertContains($manager->email, $emails, 'The line manager was not escalated to.');
        $this->assertContains($this->owner->email, $emails, 'The programme owner was not escalated to.');

        // NOT the person who is late. By T-2 they have had six daily
        // reminders; a seventh is the definition of alert fatigue. Escalation
        // means somebody else now knows.
        $this->assertNotContains($latecomer->email, $emails);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 4 — re-running the dispatcher sends nothing twice.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function running_the_dispatcher_three_times_sends_exactly_once(): void
    {
        $occurrence = $this->occurrenceInDays(10);
        $this->contactFor($this->participant('alice@khb.test'), $occurrence);

        $at = $occurrence->scheduled_date->copy()->subDays(10)->setTime(8, 0);
        Carbon::setTestNow($at);

        $first = app(ReminderDispatcher::class)->dispatchDue($at);
        $afterOne = NotificationDelivery::query()->count();

        app(ReminderDispatcher::class)->dispatchDue($at);
        app(ReminderDispatcher::class)->dispatchDue($at);

        Carbon::setTestNow();

        $this->assertGreaterThan(0, $afterOne, 'The first run sent nothing, so this proves nothing.');
        $this->assertSame($afterOne, NotificationDelivery::query()->count(), 'A re-run sent something twice.');
        $this->assertGreaterThan(0, $first['claimed']);
    }

    #[Test]
    public function two_workers_racing_on_the_same_row_produce_one_send(): void
    {
        $occurrence = $this->occurrenceInDays(10);
        $this->contactFor($this->participant('alice@khb.test'), $occurrence);

        $schedule = ReminderSchedule::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->orderBy('send_at')
            ->first();

        // The claim is a CONDITIONAL update, so the second one matches zero
        // rows. That is the property Gate G1 rests on, and it belongs to the
        // data rather than to how carefully the query was written (ADR 0005).
        $first = ReminderSchedule::query()->whereKey($schedule->getKey())
            ->where('status', 'pending')->update(['status' => 'sent']);
        $second = ReminderSchedule::query()->whereKey($schedule->getKey())
            ->where('status', 'pending')->update(['status' => 'sent']);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 5 — the fatigue guard.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_person_in_five_overlapping_exercises_gets_one_digest_listing_five(): void
    {
        $busy = $this->participant('busy@khb.test');
        $contact = null;
        $occurrences = [];

        for ($i = 1; $i <= 5; $i++) {
            $occurrence = $this->occurrenceInDays(10, name: 'Exercise '.$i);
            $contact = $this->contactFor($busy, $occurrence);
            $occurrences[] = $occurrence;

            ReadinessTask::query()->where('occurrence_id', $occurrence->getKey())
                ->update(['owner_id' => $busy->id]);
        }

        // Tick day by day up to the day under test, the way the hourly command
        // does. The T-10 formal notices are DISCRETE by design — a notice
        // carries per-exercise objectives, scope, roles and a checklist, which
        // do not consolidate into a line — so they are drained first and are
        // not what the fatigue guard is about.
        for ($day = 10; $day >= 7; $day--) {
            $tick = $occurrences[0]->scheduled_date->copy()->subDays($day)->setTime(8, 0);
            Carbon::setTestNow($tick);
            app(ReminderDispatcher::class)->dispatchDue($tick);
            Carbon::setTestNow();
        }

        $recorder = $this->recordingChannel(ChannelKey::Email);

        $at = $occurrences[0]->scheduled_date->copy()->subDays(6)->setTime(8, 0);
        Carbon::setTestNow($at);
        app(ReminderDispatcher::class)->dispatchDue($at);
        Carbon::setTestNow();

        // ONE MESSAGE for five exercises. This is the single difference between
        // a system people respect and one they filter to junk.
        $this->assertCount(1, $recorder->sent, 'Five exercises produced '.count($recorder->sent).' emails.');

        $body = $recorder->sent[0]['message']->wireBody();

        foreach (range(1, 5) as $i) {
            $this->assertStringContainsString('Exercise '.$i, $body, "The digest omitted exercise {$i}.");
        }

        // FIVE EVIDENCE ROWS, ONE SEND. "Show me the reminders for this
        // exercise" has to be complete even when the message also mentioned
        // four others; the shared provider message id is what shows they were
        // one send.
        $deliveries = NotificationDelivery::query()
            ->where('recipient_contact_id', $contact->getKey())
            ->where('channel', ChannelKey::Email->value)
            ->whereDate('created_at', $at->toDateString())
            ->get();

        $this->assertCount(5, $deliveries);
        $this->assertCount(1, $deliveries->pluck('provider_message_id')->unique()->filter());
        $this->assertSame(4, $deliveries->filter(fn ($d) => ($d->raw_response['consolidated_into'] ?? null) !== null)->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 6 — quiet hours defer, never drop.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_reminder_inside_quiet_hours_is_deferred_and_then_sent(): void
    {
        app(BcmsSettings::class)->update([
            'quiet_hours_start' => '22:00', 'quiet_hours_end' => '06:00',
        ], $this->organization->id);

        $occurrence = $this->occurrenceInDays(10);
        $this->contactFor($this->participant('alice@khb.test'), $occurrence);

        $schedule = ReminderSchedule::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->orderBy('send_at')
            ->first();

        // 23:00 Lagos is inside the window.
        $night = Carbon::parse($schedule->send_at)->setTimezone('Africa/Lagos')->setTime(23, 0)->setTimezone('UTC');

        $schedule->update(['send_at' => $night]);

        Carbon::setTestNow($night);
        $result = app(ReminderDispatcher::class)->dispatchDue($night);
        Carbon::setTestNow();

        $this->assertSame(1, $result['deferred']);
        $this->assertSame(0, NotificationDelivery::query()->count(), 'A quiet-hours reminder was sent anyway.');

        $schedule->refresh();

        // DEFERRED, NEVER DROPPED. Still pending, with a later time and a
        // reason — a dropped reminder is failure mode (a) wearing a feature's
        // clothes.
        $this->assertSame('pending', $schedule->status);
        $this->assertTrue($schedule->send_at->gt($night));
        $this->assertStringContainsString('quiet hours', (string) $schedule->skip_reason);

        $later = Carbon::parse($schedule->send_at)->addMinute();
        Carbon::setTestNow($later);
        app(ReminderDispatcher::class)->dispatchDue($later);
        Carbon::setTestNow();

        $this->assertGreaterThan(0, NotificationDelivery::query()->count(), 'The deferred reminder never went out.');
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 7 — the tenant's timezone, across a UTC day boundary.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_africa_lagos_tenant_gets_its_seven_thirty_in_lagos(): void
    {
        app(BcmsSettings::class)->update([
            'timezone' => 'Africa/Lagos', 'reminder_send_time' => '07:30',
        ], $this->organization->id);

        $occurrence = $this->occurrenceInDays(10);

        foreach (ReminderSchedule::query()->where('occurrence_id', $occurrence->getKey())->get() as $row) {
            if ($row->template_key === 'exercise.go_live') {
                continue; // Measured from the start time, not the send time.
            }

            $local = $row->send_at->copy()->setTimezone('Africa/Lagos');

            $this->assertSame('07:30', $local->format('H:i'), 'A reminder is not at 07:30 Lagos.');
        }

        // The UTC day boundary is the case that catches a naive implementation:
        // 07:30 Lagos is 06:30 UTC on the SAME day, and a tenant an hour the
        // other side of UTC would be on the previous one.
        $sample = ReminderSchedule::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('template_key', 'exercise.notice')
            ->sole();

        $this->assertSame('06:30', $sample->send_at->copy()->setTimezone('UTC')->format('H:i'));

        // A tenant west of UTC, where local morning is the previous UTC day.
        app(BcmsSettings::class)->update(['timezone' => 'America/New_York'], $this->organization->id);
        app(BcmsSettings::class)->forget($this->organization->id);

        $second = $this->occurrenceInDays(10, name: 'New York exercise');

        $notice = ReminderSchedule::query()
            ->where('occurrence_id', $second->getKey())
            ->where('template_key', 'exercise.notice')
            ->sole();

        $this->assertSame('07:30', $notice->send_at->copy()->setTimezone('America/New_York')->format('H:i'));
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 8 — readiness gating and the override.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function gating_blocks_the_start_and_the_override_needs_a_reason_and_is_audited(): void
    {
        $occurrence = $this->occurrenceInDays(10, gating: true);

        $gate = app(ReadinessService::class)->gate($occurrence);

        $this->assertFalse($gate['allowed']);
        $this->assertNotEmpty($gate['blocking']);
        $this->assertStringContainsString('cannot start', (string) $gate['reason']);

        $blocking = ReadinessTask::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('is_blocking', true)
            ->get();

        // An override with no reason is refused. The sentence IS the record.
        try {
            app(ReadinessService::class)->override($blocking->first(), $this->facilitator, '   ');
            $this->fail('An override was accepted with no reason.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('has to say why', $e->getMessage());
        }

        foreach ($blocking as $task) {
            app(ReadinessService::class)->override($task, $this->facilitator, 'The vendor confirmed by phone; the '
                .'written confirmation is following and the exercise window cannot move.');
        }

        $this->assertTrue(app(ReadinessService::class)->gate($occurrence->refresh())['allowed']);
        $this->assertSame(0, (int) $occurrence->blocking_tasks_open);

        // An override is not a completion and does not look like one.
        $this->assertSame('waived', $blocking->first()->refresh()->status);
        $this->assertNotNull($blocking->first()->override_reason);

        $audits = DB::table('bcms_audit_logs')
            ->where('auditable_id', $occurrence->getKey())
            ->where('event', 'readiness_override')
            ->count();

        $this->assertSame($blocking->count(), $audits, 'Every override should be its own audit record.');
    }

    #[Test]
    public function an_ungated_exercise_still_reports_what_is_open(): void
    {
        $occurrence = $this->occurrenceInDays(10, gating: false);

        $gate = app(ReadinessService::class)->gate($occurrence);

        // A facilitator about to run an exercise with four blocking items open
        // should see that whether or not the system will stop them.
        $this->assertTrue($gate['allowed']);
        $this->assertNotEmpty($gate['blocking']);
        $this->assertStringContainsString('not gated', (string) $gate['reason']);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 9 — rescheduling compresses the ladder and voids the rest.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function moving_an_exercise_from_twenty_days_out_to_five_rebuilds_a_compressed_ladder(): void
    {
        $occurrence = $this->occurrenceInDays(20);

        $before = ReminderSchedule::query()->where('occurrence_id', $occurrence->getKey())->get();
        $this->assertGreaterThan(0, $before->count());

        // One rung has already gone out. It must survive untouched — it
        // happened.
        $sent = $before->sortBy('send_at')->first();
        $sent->update(['status' => 'sent', 'dispatched_at' => now()]);

        $occurrence->update(['scheduled_date' => now()->addDays(5)->toDateString()]);
        app(ReminderScheduleBuilder::class)->build($occurrence->refresh());

        $after = ReminderSchedule::query()->where('occurrence_id', $occurrence->getKey())->get();

        $this->assertSame('sent', $sent->refresh()->status, 'A sent reminder was rewritten by a regeneration.');

        $voided = $after->where('status', 'voided');
        $pending = $after->where('status', 'pending');

        $this->assertGreaterThan(0, $voided->count(), 'The obsolete rows were not voided.');
        $this->assertGreaterThan(0, $pending->count(), 'The compressed ladder is empty.');

        // Voided, not deleted: the audit shows what was planned as well as what
        // happened (ADR 0005).
        $voided->each(fn (ReminderSchedule $r) => $this->assertNotNull($r->skip_reason));

        // Every live rung is inside the new, shorter run-up — not ten sends
        // suddenly in the past.
        $newDate = $occurrence->refresh()->scheduled_date;

        foreach ($pending as $row) {
            $this->assertGreaterThanOrEqual(
                -5,
                (int) $row->day_offset,
                'A rung survived that is outside the compressed lead time.',
            );
            $this->assertTrue(
                $row->send_at->lte($newDate->copy()->addDays(15)),
                'T'.$row->day_offset.' is at '.$row->send_at.' but the exercise is '.$newDate->toDateString(),
            );
        }

        // Two: the ladder was armed when the occurrence was created, and
        // rebuilt when it moved. Every rebuild is its own audit record, which
        // is the point — "what did this exercise plan to send, and when did
        // that change" is a question with a paper trail.
        $this->assertSame(
            2,
            DB::table('bcms_audit_logs')
                ->where('auditable_id', $occurrence->getKey())
                ->where('event', 'reminder_ladder_rebuilt')
                ->count(),
        );
    }

    #[Test]
    public function an_occurrence_that_loses_its_date_voids_its_ladder_rather_than_counting_down_to_nothing(): void
    {
        $occurrence = $this->occurrenceInDays(10);

        $occurrence->update(['scheduled_date' => null, 'status' => OccurrenceStatus::NeedsScheduling]);

        $result = app(ReminderScheduleBuilder::class)->build($occurrence->refresh());

        $this->assertGreaterThan(0, $result['voided']);
        $this->assertSame(0, $result['created']);
        $this->assertNotNull($result['skipped_reason']);
        $this->assertSame(
            0,
            ReminderSchedule::query()->where('occurrence_id', $occurrence->getKey())->where('status', 'pending')->count(),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 10 — a worker dying mid-dispatch.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_channel_that_dies_mid_send_leaves_a_row_the_watchdog_can_find(): void
    {
        $occurrence = $this->occurrenceInDays(10);
        $this->contactFor($this->participant('alice@khb.test'), $occurrence);

        // A channel that throws is an adapter fault, not a provider failure —
        // the contract says a provider failure returns a receipt. Either way
        // the delivery row is already written.
        app(ChannelRegistry::class)->swap(ChannelKey::Email, new class implements NotificationChannel
        {
            public function key(): ChannelKey
            {
                return ChannelKey::Email;
            }

            public function provider(): string
            {
                return 'exploding';
            }

            public function supports(Recipient $to): bool
            {
                return true;
            }

            public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt
            {
                throw new \RuntimeException('The worker was killed.');
            }

            public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int
            {
                return null;
            }
        });

        $at = $occurrence->scheduled_date->copy()->subDays(10)->setTime(8, 0);
        Carbon::setTestNow($at);

        try {
            app(ReminderDispatcher::class)->dispatchDue($at);
        } catch (\RuntimeException) {
            // Which is what a killed worker looks like from here.
        }

        Carbon::setTestNow();

        // STANDING RULE 8. The row was written BEFORE the provider was called,
        // so the crash left evidence rather than silence. The reverse leaves an
        // alert nobody knows was lost.
        $stuck = NotificationDelivery::query()->where('status', DeliveryStatus::Queued->value)->get();

        $this->assertCount(1, $stuck, 'The write-ahead row is missing, so the alert was lost silently.');
        $this->assertNotNull($stuck->first()->reminder_schedule_id);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 11 — the watchdog.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_watchdog_fires_when_the_scheduler_has_stopped(): void
    {
        $occurrence = $this->occurrenceInDays(10);

        // A row whose time has passed and which is still pending: either the
        // scheduler is not running or the dispatcher is throwing. ADR 0005 is
        // explicit that this is a defect to report, never a row to drop.
        ReminderSchedule::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->orderBy('send_at')
            ->limit(1)
            ->update(['send_at' => now()->subHours(3)]);

        $this->artisan('bcms:watchdog', ['--minutes' => 30])
            ->expectsOutputToContain('reminder(s) overdue')
            ->assertExitCode(1);

        // It tells the tenant's administrators, in-app rather than through the
        // notification path that is the thing being reported as broken.
        $admin = $this->user('admin@khb.test');
        $this->giveDirectPermission($admin, 'bcms.admin');

        $this->artisan('bcms:watchdog', ['--minutes' => 30])->assertExitCode(1);

        $this->assertSame(
            1,
            DB::table('notifications_log')
                ->where('user_id', $admin->id)
                ->where('type', 'bcms.watchdog.stalled')
                ->count(),
        );
    }

    #[Test]
    public function the_watchdog_stays_quiet_when_everything_is_healthy(): void
    {
        // Far enough out that no rung is due yet. Ten days out puts the T-10
        // notice at 07:30 TODAY, so whether the suite passes would depend on
        // the wall clock — which is the sort of flake that gets a real
        // watchdog failure dismissed as "that test again".
        $this->occurrenceInDays(30);

        $this->artisan('bcms:watchdog')
            ->expectsOutputToContain('healthy')
            ->assertExitCode(0);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 12 — the inspectable plan, before any send.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_plan_is_accurate_before_a_single_alert_has_gone_out(): void
    {
        $occurrence = $this->occurrenceInDays(10);

        foreach (['a', 'b', 'c'] as $name) {
            $this->contactFor($this->participant($name.'@khb.test'), $occurrence);
        }

        $plan = app(ReminderScheduleBuilder::class)->plan($occurrence, app(ReminderAudienceResolver::class));

        // "Here are the fourteen alerts this will send, to these people" —
        // which is only answerable because the rows are materialised in advance
        // (ADR 0005), and is the thing that makes a customer believe it.
        $this->assertSame(0, $plan['sent']);
        $this->assertSame($plan['total_sends'], $plan['pending']);
        $this->assertGreaterThanOrEqual(10, $plan['total_sends']);
        $this->assertGreaterThan(0, $plan['total_recipients']);
        $this->assertSame(0, NotificationDelivery::query()->count(), 'Inspecting the plan sent something.');

        $notice = collect($plan['entries'])->firstWhere('day_offset', -10);

        $this->assertNotNull($notice);
        $this->assertSame(3, $notice['recipient_count']);
        $this->assertSame('Formal notice', $notice['label']);
        $this->assertNotEmpty($notice['purpose']);
        $this->assertContains('email', $notice['channels']);
    }

    #[Test]
    public function an_occurrence_with_no_date_reports_no_plan_rather_than_an_empty_one(): void
    {
        $occurrence = $this->occurrenceInDays(10);
        $occurrence->update(['scheduled_date' => null]);
        app(ReminderScheduleBuilder::class)->build($occurrence->refresh());

        $plan = app(ReminderScheduleBuilder::class)->plan($occurrence, app(ReminderAudienceResolver::class));

        // Null, not zero. "Not planned yet" and "plans to send nothing" are
        // different states and only one of them is a problem.
        $this->assertSame(0, $plan['pending']);
        $this->assertGreaterThan(0, $plan['voided']);
    }

    /* ------------------------------------------------------------------ */
    /*  The unannounced case, and attendance.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_unannounced_exercise_warns_nobody_but_still_chases_its_aar(): void
    {
        $occurrence = $this->occurrenceInDays(10, unannounced: true);

        $rows = ReminderSchedule::query()->where('occurrence_id', $occurrence->getKey())->get();

        // The point of a surprise call-tree test is that nobody knows.
        $this->assertSame(0, $rows->where('template_key', 'exercise.notice')->count());
        $this->assertSame(0, $rows->where('template_key', 'exercise.countdown')->count());
        $this->assertSame(0, $rows->where('template_key', 'exercise.final_brief')->count());

        // The facilitator still gets their readiness ladder, and the AAR
        // chasers still have to happen afterwards.
        $this->assertGreaterThan(0, $rows->where('template_key', 'exercise.aar_due')->count());
        $this->assertGreaterThan(0, $rows->where('day_offset', '>', 0)->count());
    }

    #[Test]
    public function declining_an_exercise_requires_a_deputy_who_becomes_a_participant(): void
    {
        $occurrence = $this->occurrenceInDays(10);
        $declining = $this->participant('declining@khb.test');
        $deputy = $this->user('deputy@khb.test');

        try {
            app(AttendanceService::class)->decline($occurrence, $declining, null);
            $this->fail('A decline was accepted with no deputy.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('nominated deputy', $e->getMessage());
        }

        app(AttendanceService::class)->decline($occurrence, $declining, $deputy, 'On leave.');

        $this->assertSame('declined', ExerciseParticipant::query()
            ->where('occurrence_id', $occurrence->getKey())->where('user_id', $declining->id)->sole()->invitation_status);

        // The deputy is a PARTICIPANT ROW, so the ladder resolves them, the
        // roll-call counts them and the AAR lists them — not an invisible
        // column somewhere.
        $deputyRow = ExerciseParticipant::query()
            ->where('occurrence_id', $occurrence->getKey())->where('user_id', $deputy->id)->sole();

        $this->assertSame('deputy', $deputyRow->role);
        $this->assertSame('pending', $deputyRow->invitation_status);
    }

    /* ------------------------------------------------------------------ */
    /*  Tenancy.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function another_tenants_reminders_are_neither_visible_nor_dispatched(): void
    {
        $occurrence = $this->occurrenceInDays(10);
        $this->contactFor($this->participant('alice@khb.test'), $occurrence);

        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);

        $this->assertSame(0, ReminderSchedule::query()->count());

        $at = $occurrence->scheduled_date->copy()->subDays(10)->setTime(8, 0);
        Carbon::setTestNow($at);
        $result = app(ReminderDispatcher::class)->dispatchDue($at);
        Carbon::setTestNow();

        $this->assertSame(0, $result['claimed'], 'A dispatcher run in one tenant claimed another tenant\'s rows.');

        TenantContext::set($this->organization->id);

        $this->assertGreaterThan(0, ReminderSchedule::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function user(string $email): User
    {
        return User::query()->firstOrCreate(['email' => $email], [
            'name' => Str::title(Str::before($email, '@')),
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id, 'is_active' => true,
        ]);
    }

    private function giveDirectPermission(User $user, string $permission): void
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate('bcms-'.md5($permission.$user->email), 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        $user->assignRole($role);
    }

    private function participant(string $email): User
    {
        return $this->user($email);
    }

    /**
     * A contact for a user, optionally added to the occurrence's participants.
     */
    private function contactFor(User $user, ExerciseOccurrence $occurrence, bool $participant = true): Contact
    {
        $contact = Contact::query()->firstOrCreate(
            ['organization_id' => $this->organization->id, 'employee_id' => 'E-'.$user->id],
            [
                'user_id' => $user->id,
                'full_name' => $user->name,
                'email' => $user->email,
                'mobile_primary' => '+23480000'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
                'business_unit_id' => $this->unit->id,
                'source' => 'manual',
                'is_active' => true,
            ]
        );

        if ($participant) {
            ExerciseParticipant::query()->updateOrCreate(
                ['occurrence_id' => $occurrence->getKey(), 'user_id' => $user->id],
                [
                    'organization_id' => $this->organization->id,
                    'contact_id' => $contact->getKey(),
                    'role' => 'participant',
                    'business_unit_id' => $this->unit->id,
                    'invitation_status' => 'pending',
                ]
            );
        }

        return $contact;
    }

    /**
     * An occurrence N days out, with its ladder and checklist armed.
     */
    private function occurrenceInDays(
        int $days,
        bool $gating = false,
        bool $unannounced = false,
        ?string $name = null,
    ): ExerciseOccurrence {
        $type = ExerciseType::query()->where('code', $unannounced ? 'CALLTREE' : 'TABLETOP')->sole();

        $definition = app(ExerciseDefinitionService::class)->create(
            $this->programme,
            $type,
            $name ?? 'Exercise '.Str::random(4),
            [
                'frequency_per_year' => 1,
                'business_unit_id' => $this->unit->id,
                'owner_id' => $this->owner->id,
                'facilitator_id' => $this->facilitator->id,
                'lead_time_days' => 10,
                'daily_reminder_enabled' => ! $unannounced,
                'unannounced' => $unannounced,
                'readiness_gating' => $gating,
                'status' => 'active',
                'default_audience_rule' => AudienceRule::make('org_node', ['id' => $this->unit->id])->toArray(),
            ],
            $this->owner->id,
        );

        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addDays($days)->toDateString(),
            'scheduled_start' => now()->addDays($days)->setTime(9, 0),
            'scheduled_end' => now()->addDays($days)->setTime(11, 0),
            'status' => OccurrenceStatus::Planned,
            'facilitator_id' => $this->facilitator->id,
        ]);

        app(ReadinessService::class)->materialise($occurrence);
        app(ReminderScheduleBuilder::class)->build($occurrence->refresh(), $definition);

        return $occurrence->refresh();
    }

    /**
     * Swap one channel for a recorder.
     *
     * The mocks already record; this returns the recorder so a test can read
     * the rendered body, which is what the content assertions need.
     */
    private function recordingChannel(ChannelKey $key): object
    {
        $recorder = new class($key) implements NotificationChannel
        {
            /** @var list<array{to: Recipient, message: RenderedMessage}> */
            public array $sent = [];

            public function __construct(private ChannelKey $key) {}

            public function key(): ChannelKey
            {
                return $this->key;
            }

            public function provider(): string
            {
                return 'recorder';
            }

            public function supports(Recipient $to): bool
            {
                return true;
            }

            public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt
            {
                $this->sent[] = ['to' => $to, 'message' => $message];

                return DeliveryReceipt::sent('recorder', 'rec-'.count($this->sent));
            }

            public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int
            {
                return null;
            }
        };

        app(ChannelRegistry::class)->swap($key, $recorder);

        return $recorder;
    }
}
