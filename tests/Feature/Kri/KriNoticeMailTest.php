<?php

namespace Tests\Feature\Kri;

use App\Contracts\DirectoryProfiles;
use App\Enums\KriContactRole;
use App\Jobs\DispatchKriReturnNotice;
use App\Jobs\SendKriReturnNoticeMail;
use App\Mail\KriReturnMailView;
use App\Mail\KriReturnReminder;
use App\Mail\KriReturnRequested;
use App\Models\KriReturn;
use App\Models\KriReturnNotice;
use App\Models\KriReturnNoticeRecipient;
use App\Models\User;
use App\Services\Kri\KriNoticeService;
use App\Support\DirectoryProfile;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0026 section 5 and Amendment 1 A1.13: the request and the manual
 * reminders as ROWS, delivered by two jobs.
 *
 * The flow under test: a notice row is inserted `queued` with no recipients,
 * DispatchKriReturnNotice resolves who receives it NOW, and one
 * SendKriReturnNoticeMail per recipient is the retry unit. `sent` means
 * "accepted by the mail transport" and nothing more.
 */
class KriNoticeMailTest extends KriCycleTestCase
{
    /** @var array<string, User> */
    private array $people = [];

    private KriReturn $return;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deptKri();
        $this->deptKri(null, ['name' => 'Second indicator']);
        $this->return = $this->makeReturn();

        foreach ([
            'champion' => ['Chidi Champion', KriContactRole::RiskChampion],
            'lead' => ['Tola Lead', KriContactRole::TeamLead],
            'head' => ['Uche Head', KriContactRole::UnitHead],
            'group' => ['Gbenga Group', KriContactRole::GroupHead],
        ] as $key => [$name, $role]) {
            $this->people[$key] = $this->makeStaff($name);
            $this->makeContact($this->unit, $this->people[$key], $role);
        }
    }

    private function notices(): KriNoticeService
    {
        return app(KriNoticeService::class);
    }

    /** Queue the one request for the return and return its id. */
    private function queueRequest(?KriReturn $return = null): int
    {
        return (int) $this->notices()->queueRequest($return ?? $this->return, KriWorkingCalendar::today());
    }

    /** What DispatchKriReturnNotice does, run inline (the queue is `sync`). */
    private function dispatchNotice(int $noticeId): void
    {
        (new DispatchKriReturnNotice($noticeId))->handle($this->notices());
    }

    private function officer(): User
    {
        return $this->makeStaff('Risk Officer Reminder', ['kri.view', 'kri.send_reminder', 'kri.all_departments']);
    }

    /** @return list<string> */
    private function rolesOn(KriReturnNotice $notice): array
    {
        return KriReturnNoticeRecipient::query()
            ->where('kri_return_notice_id', $notice->id)
            ->orderBy('id')
            ->pluck('contact_role')
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  The request, end to end */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_request_is_resolved_at_send_time_and_reaches_champions_only(): void
    {
        Mail::fake();

        $id = $this->queueRequest();
        $notice = KriReturnNotice::query()->findOrFail($id);

        $this->assertSame('queued', $notice->status);
        $this->assertNull($notice->recipients_resolved_at);

        $this->dispatchNotice($id);

        $notice->refresh();
        $this->assertSame('sent', $notice->status);
        $this->assertNotNull($notice->recipients_resolved_at);
        $this->assertNotNull($notice->completed_at);
        $this->assertSame(['risk_champion'], $this->rolesOn($notice));

        $row = KriReturnNoticeRecipient::query()->where('kri_return_notice_id', $id)->firstOrFail();
        $this->assertSame('sent', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->sent_at);
        $this->assertSame($this->people['champion']->email, $row->email, 'the address is a snapshot taken at resolution');
        $this->assertSame('Chidi Champion', $row->name);

        Mail::assertSent(KriReturnRequested::class, 1);
        Mail::assertSent(KriReturnRequested::class, fn ($mail) => $mail->hasTo($this->people['champion']->email));
        Mail::assertNotSent(KriReturnReminder::class);
    }

    #[Test]
    public function a_contact_changed_after_the_request_was_queued_is_honoured(): void
    {
        Mail::fake();
        $id = $this->queueRequest();

        // Between persisting the notice and the job running, the champion is replaced.
        \App\Models\KriDepartmentContact::query()->where('user_id', $this->people['champion']->id)->delete();
        $replacement = $this->makeStaff('Replacement Champion');
        $this->makeContact($this->unit, $replacement, KriContactRole::RiskChampion);

        $this->dispatchNotice($id);

        Mail::assertSent(KriReturnRequested::class, 1);
        Mail::assertSent(KriReturnRequested::class, fn ($mail) => $mail->hasTo($replacement->email));
    }

    #[Test]
    public function queuing_the_send_job_twice_for_one_recipient_sends_one_mail(): void
    {
        Mail::fake();
        Queue::fake();

        $id = $this->queueRequest();
        $pending = $this->notices()->resolveRecipients($id);
        $this->assertCount(1, $pending);

        (new SendKriReturnNoticeMail($pending[0]))->handle($this->notices());
        (new SendKriReturnNoticeMail($pending[0]))->handle($this->notices());

        Mail::assertSent(KriReturnRequested::class, 1);
        $row = KriReturnNoticeRecipient::query()->findOrFail($pending[0]);
        $this->assertSame('sent', $row->status);
        $this->assertSame(1, (int) $row->attempts, 'the duplicate found the row no longer pending and did nothing');
    }

    #[Test]
    public function the_dispatch_job_run_twice_resolves_nobody_twice(): void
    {
        Mail::fake();

        $id = $this->queueRequest();
        $this->dispatchNotice($id);
        $this->dispatchNotice($id);

        $this->assertSame(1, KriReturnNoticeRecipient::query()->where('kri_return_notice_id', $id)->count());
        Mail::assertSent(KriReturnRequested::class, 1);
    }

    #[Test]
    public function a_duplicate_request_is_refused_by_the_key_not_by_a_check_alone(): void
    {
        $this->queueRequest();

        // The service says "already has its request"...
        $this->assertNull($this->notices()->queueRequest($this->return, KriWorkingCalendar::today()));

        // ...and the database would refuse it even if two servers raced past that check.
        $this->expectException(UniqueConstraintViolationException::class);

        KriReturnNotice::query()->create([
            'organization_id' => $this->organization->id,
            'kri_return_id' => $this->return->id,
            'business_unit_id' => $this->unit->id,
            'kind' => 'request',
            'sequence' => 1,
            'tier' => 1,
            'idempotency_key' => KriReturnNotice::requestKey($this->return->id),
            'sent_on' => '2026-11-02',
            'status' => 'queued',
            'queued_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Failure and retry */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function when_the_transport_throws_the_row_stays_pending_then_fails_after_its_tries(): void
    {
        Queue::fake();
        $id = $this->queueRequest();
        $pending = $this->notices()->resolveRecipients($id);
        $recipientId = $pending[0];

        // A REAL transport that refuses the message, so the mailable is rendered and the
        // failure comes from where production's would: the transport.
        Mail::extend('refusing-test', fn () => new class extends \Symfony\Component\Mailer\Transport\AbstractTransport
        {
            protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
            {
                throw new \RuntimeException('451 4.7.1 greylisted');
            }

            public function __toString(): string
            {
                return 'refusing-test';
            }
        });
        config(['mail.mailers.refusing-test' => ['transport' => 'refusing-test'], 'mail.default' => 'refusing-test']);

        $job = new SendKriReturnNoticeMail($recipientId);
        $this->assertSame(3, $job->tries);

        foreach ([1, 2, 3] as $attempt) {
            try {
                $job->handle($this->notices());
                $this->fail('the transport failure must be rethrown so the queue retries');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('greylisted', $e->getMessage());
            }

            $row = KriReturnNoticeRecipient::query()->findOrFail($recipientId);
            $this->assertSame('pending', $row->status, "attempt {$attempt}: a failed send never marks the row sent, nor failed yet");
            $this->assertSame($attempt, (int) $row->attempts, 'the attempt and the error commit even though the job failed');
            $this->assertStringContainsString('greylisted', (string) $row->error);
            $this->assertNull($row->sent_at);
            $this->assertNull($row->failed_at);
            $this->assertNotSame('failed', KriReturnNotice::query()->findOrFail($id)->status);
        }

        // The queue calls failed() once, after the last attempt.
        $job->failed(new \RuntimeException('451 4.7.1 greylisted'));

        $row = KriReturnNoticeRecipient::query()->findOrFail($recipientId);
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->failed_at);
        $this->assertSame('failed', KriReturnNotice::query()->findOrFail($id)->status, 'every recipient failed, so the notice failed');
    }

    #[Test]
    public function the_failure_handler_never_turns_a_row_another_copy_has_sent_into_failed(): void
    {
        Mail::fake();
        Queue::fake();
        $id = $this->queueRequest();
        $pending = $this->notices()->resolveRecipients($id);

        (new SendKriReturnNoticeMail($pending[0]))->handle($this->notices());
        (new SendKriReturnNoticeMail($pending[0]))->failed(new \RuntimeException('late'));

        $this->assertSame('sent', KriReturnNoticeRecipient::query()->findOrFail($pending[0])->status);
    }

    #[Test]
    public function a_notice_with_some_failed_and_some_sent_recipients_is_partially_failed(): void
    {
        Mail::fake();
        Queue::fake();
        $this->makeContact($this->unit, $this->makeStaff('Second Champion'), KriContactRole::RiskChampion);

        $id = $this->queueRequest();
        $pending = $this->notices()->resolveRecipients($id);
        $this->assertCount(2, $pending);

        (new SendKriReturnNoticeMail($pending[0]))->handle($this->notices());
        $this->assertSame('dispatching', KriReturnNotice::query()->findOrFail($id)->status, 'nothing is rolled up while a recipient is still pending');

        (new SendKriReturnNoticeMail($pending[1]))->failed(new \RuntimeException('550 mailbox unavailable'));

        $this->assertSame('partially_failed', KriReturnNotice::query()->findOrFail($id)->status);
    }

    #[Test]
    public function the_dispatch_job_finishes_what_a_dead_first_attempt_left_pending(): void
    {
        // The first attempt resolved and committed, then the worker died before queueing the sends.
        Queue::fake();
        $id = $this->queueRequest();
        $this->notices()->resolveRecipients($id);

        // Its retry finds the notice already `dispatching` and must still queue the sends.
        $this->dispatchNotice($id);

        Queue::assertPushed(SendKriReturnNoticeMail::class, 1);
        Queue::assertPushed(SendKriReturnNoticeMail::class, fn ($job) => $job->queue === 'notifications');
    }

    #[Test]
    public function both_jobs_run_on_the_notifications_queue_with_bounded_retries(): void
    {
        $dispatch = new DispatchKriReturnNotice(1);
        $send = new SendKriReturnNoticeMail(1);

        $this->assertSame('notifications', $dispatch->queue);
        $this->assertSame('notifications', $send->queue);
        $this->assertSame(3, $dispatch->tries);
        $this->assertSame(3, $send->tries);
        $this->assertSame([30, 120], $dispatch->backoff());
        $this->assertSame([60, 300], $send->backoff());
    }

    /* ------------------------------------------------------------------ */
    /*  A1.13: the return was submitted first */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_return_submitted_after_the_recipients_were_resolved_skips_every_recipient_and_cancels_the_notice(): void
    {
        Mail::fake();
        Queue::fake();
        $this->makeContact($this->unit, $this->makeStaff('Second Champion'), KriContactRole::RiskChampion);

        $id = $this->queueRequest();
        $pending = $this->notices()->resolveRecipients($id);
        $this->assertCount(2, $pending);

        // Submitted between dispatch and send.
        $this->return->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $this->people['champion']->id])->save();

        (new SendKriReturnNoticeMail($pending[0]))->handle($this->notices());

        Mail::assertNothingSent();
        $rows = KriReturnNoticeRecipient::query()->where('kri_return_notice_id', $id)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('skipped', $row->status);
            $this->assertSame('return_submitted', $row->skip_reason);
        }
        $this->assertSame('cancelled', KriReturnNotice::query()->findOrFail($id)->status);
        $this->assertNotNull(KriReturnNotice::query()->findOrFail($id)->completed_at);
    }

    #[Test]
    public function a_return_submitted_before_the_dispatch_job_resolves_cancels_the_notice_with_no_recipients(): void
    {
        Mail::fake();
        $id = $this->queueRequest();
        $this->return->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        $this->dispatchNotice($id);

        $this->assertSame('cancelled', KriReturnNotice::query()->findOrFail($id)->status);
        $this->assertSame(0, KriReturnNoticeRecipient::query()->where('kri_return_notice_id', $id)->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_return_submitted_after_one_mail_went_out_skips_the_rest_but_the_notice_is_not_cancelled(): void
    {
        Mail::fake();
        Queue::fake();
        $this->makeContact($this->unit, $this->makeStaff('Second Champion'), KriContactRole::RiskChampion);

        $id = $this->queueRequest();
        $pending = $this->notices()->resolveRecipients($id);

        (new SendKriReturnNoticeMail($pending[0]))->handle($this->notices());
        $this->return->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
        (new SendKriReturnNoticeMail($pending[1]))->handle($this->notices());

        Mail::assertSent(KriReturnRequested::class, 1);
        $this->assertSame('sent', KriReturnNoticeRecipient::query()->findOrFail($pending[0])->status);
        $this->assertSame('skipped', KriReturnNoticeRecipient::query()->findOrFail($pending[1])->status);
        $this->assertSame('return_submitted', KriReturnNoticeRecipient::query()->findOrFail($pending[1])->skip_reason);
        $this->assertSame('sent', KriReturnNotice::query()->findOrFail($id)->status, 'something did go out, so it is not "cancelled"');
    }

    #[Test]
    public function a_reopened_return_is_chased_again(): void
    {
        Mail::fake();
        $this->return->forceFill(['status' => 'reopened', 'reopened_at' => now()])->save();

        $id = $this->queueRequest();
        $this->dispatchNotice($id);

        Mail::assertSent(KriReturnRequested::class, 1);
    }

    /* ------------------------------------------------------------------ */
    /*  Who is skipped, who is not */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function inactive_users_and_users_without_an_email_are_recorded_as_skipped_not_hidden(): void
    {
        Mail::fake();

        $dormant = $this->makeStaff('Dormant Champion', [], false);
        $mute = $this->makeStaff('Mute Champion');
        $mute->forceFill(['email' => ''])->save();
        $this->makeContact($this->unit, $dormant, KriContactRole::RiskChampion);
        $this->makeContact($this->unit, $mute, KriContactRole::RiskChampion);

        $id = $this->queueRequest();
        $this->dispatchNotice($id);

        $rows = KriReturnNoticeRecipient::query()->where('kri_return_notice_id', $id)->get()->keyBy('name');
        $this->assertCount(3, $rows);

        $this->assertSame('skipped', $rows['Dormant Champion']->status);
        $this->assertSame('inactive_user', $rows['Dormant Champion']->skip_reason);
        $this->assertSame('skipped', $rows['Mute Champion']->status);
        $this->assertSame('no_email', $rows['Mute Champion']->skip_reason);
        $this->assertSame('sent', $rows['Chidi Champion']->status);

        Mail::assertSent(KriReturnRequested::class, 1);
        $this->assertSame('sent', KriReturnNotice::query()->findOrFail($id)->status);
    }

    #[Test]
    public function a_notice_whose_only_contacts_are_skipped_ends_with_no_recipients(): void
    {
        Mail::fake();
        \App\Models\KriDepartmentContact::query()->delete();
        $this->makeContact($this->unit, $this->makeStaff('Dormant Champion', [], false), KriContactRole::RiskChampion);

        $id = $this->queueRequest();
        $this->dispatchNotice($id);

        $this->assertSame('no_recipients', KriReturnNotice::query()->findOrFail($id)->status);
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_user_whose_directory_account_is_disabled_is_still_sent_to(): void
    {
        // ADR 0026 section 9: directory signals are display flags. Delivery skips
        // ONLY on users.is_active = false or a missing email.
        Mail::fake();
        $this->app->instance(DirectoryProfiles::class, new class implements DirectoryProfiles
        {
            public function forUsers(int $organizationId, array $userIds): array
            {
                return array_fill_keys($userIds, new DirectoryProfile(synced: true, department: 'Elsewhere', accountEnabled: false));
            }
        });

        $id = $this->queueRequest();
        $this->dispatchNotice($id);

        $row = KriReturnNoticeRecipient::query()->where('kri_return_notice_id', $id)->firstOrFail();
        $this->assertSame('sent', $row->status);
        Mail::assertSent(KriReturnRequested::class, fn ($mail) => $mail->hasTo($this->people['champion']->email));
    }

    #[Test]
    public function a_person_holding_two_roles_is_one_recipient_keeping_their_lowest_tier(): void
    {
        Mail::fake();
        $this->makeContact($this->unit, $this->people['champion'], KriContactRole::UnitHead);
        $this->makeContact($this->unit, $this->people['champion'], KriContactRole::GroupHead);

        $result = $this->notices()->remind([$this->return->id], $this->officer());
        // Reminder 1 -> champions only.
        $this->assertSame('queued', $result[0]['outcome']);

        $notice = KriReturnNotice::query()->where('kind', 'reminder')->firstOrFail();
        $this->assertSame(['risk_champion'], $this->rolesOn($notice));

        // Reach level 4 and count again: the champion is still ONE row, still a champion.
        $this->notices()->resolveRecipients($this->forceNotice(4));
        $levelFour = KriReturnNotice::query()->where('sequence', 4)->firstOrFail();
        $roles = $this->rolesOn($levelFour);
        $this->assertSame(1, count(array_keys($roles, 'risk_champion')));
        $this->assertSame(4, count($roles), 'four distinct people, not six rows');
        $this->assertSame(1, KriReturnNoticeRecipient::query()->where('kri_return_notice_id', $levelFour->id)->where('user_id', $this->people['champion']->id)->count());
    }

    /** Insert a queued reminder at an arbitrary sequence, bypassing the daily rule. */
    private function forceNotice(int $sequence): int
    {
        return (int) KriReturnNotice::query()->create([
            'organization_id' => $this->organization->id,
            'kri_return_id' => $this->return->id,
            'business_unit_id' => $this->unit->id,
            'kind' => 'reminder',
            'sequence' => $sequence,
            'tier' => \App\Support\Kri\KriContactLadder::tierForSequence($sequence),
            'idempotency_key' => 'test-forced-'.$sequence,
            'sent_on' => '2026-11-20',
            'status' => 'queued',
            'queued_at' => now(),
        ])->id;
    }

    /* ------------------------------------------------------------------ */
    /*  Manual reminders */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function two_remind_calls_in_one_day_produce_one_notice_and_one_email(): void
    {
        Mail::fake();
        $officer = $this->officer();

        $first = $this->notices()->remind([$this->return->id], $officer);
        $second = $this->notices()->remind([$this->return->id], $officer);

        $this->assertSame('queued', $first[0]['outcome']);
        $this->assertSame(1, $first[0]['tier']);
        $this->assertSame('Treasury', $first[0]['department']);
        $this->assertSame('already_reminded_today', $second[0]['outcome']);
        $this->assertNull($second[0]['tier']);

        $notices = KriReturnNotice::query()->where('kind', 'reminder')->get();
        $this->assertCount(1, $notices);
        $this->assertSame('reminder:bu'.$this->unit->id.':2026-11-02', $notices[0]->idempotency_key);
        $this->assertSame($officer->id, (int) $notices[0]->requested_by);
        $this->assertSame(1, (int) $notices[0]->sequence);
        Mail::assertSent(KriReturnReminder::class, 1);
    }

    #[Test]
    public function the_once_a_day_rule_is_a_database_key_so_two_clicks_cannot_race_past_it(): void
    {
        $this->notices()->remind([$this->return->id], $this->officer());

        $this->expectException(UniqueConstraintViolationException::class);

        KriReturnNotice::query()->create([
            'organization_id' => $this->organization->id,
            'kri_return_id' => $this->return->id,
            'business_unit_id' => $this->unit->id,
            'kind' => 'reminder',
            'sequence' => 2,
            'tier' => 2,
            'idempotency_key' => KriReturnNotice::reminderKey($this->unit->id, '2026-11-02'),
            'sent_on' => '2026-11-02',
            'status' => 'queued',
            'queued_at' => now(),
        ]);
    }

    #[Test]
    public function the_day_is_the_lagos_day_so_a_reminder_after_midnight_in_lagos_is_a_new_day(): void
    {
        Mail::fake();
        $officer = $this->officer();

        // 22:30 UTC on 2 Nov is 23:30 on 2 Nov in Lagos.
        $this->freezeAt('2026-11-02 22:30:00');
        $this->assertSame('queued', $this->notices()->remind([$this->return->id], $officer)[0]['outcome']);

        // 23:30 UTC is 00:30 on 3 Nov in Lagos: a new day, although the UTC date has not changed.
        $this->freezeAt('2026-11-02 23:30:00');
        $this->assertSame('queued', $this->notices()->remind([$this->return->id], $officer)[0]['outcome']);

        $this->assertSame(
            ['reminder:bu'.$this->unit->id.':2026-11-02', 'reminder:bu'.$this->unit->id.':2026-11-03'],
            KriReturnNotice::query()->where('kind', 'reminder')->orderBy('id')->pluck('idempotency_key')->all()
        );
    }

    #[Test]
    public function at_most_one_reminder_per_department_per_day_even_across_two_periods(): void
    {
        Mail::fake();
        $september = app(\App\Services\PeriodService::class)->resolve('2026-09-30', 'month', $this->organization->id);
        $earlier = $this->makeReturn($this->unit, ['period_id' => $september->id, 'due_on' => '2026-10-08'], syncLines: false);

        $results = $this->notices()->remind([$this->return->id, $earlier->id], $this->officer());

        $this->assertSame(['queued', 'already_reminded_today'], array_column($results, 'outcome'));
        $this->assertSame(1, KriReturnNotice::query()->where('kind', 'reminder')->count());
        Mail::assertSent(KriReturnReminder::class, 1);
    }

    #[Test]
    public function the_tier_climbs_with_each_reminder_champions_then_team_leads_then_unit_heads_then_group_heads(): void
    {
        Mail::fake();
        $officer = $this->officer();

        $expected = [
            1 => ['risk_champion'],
            2 => ['risk_champion', 'team_lead'],
            3 => ['risk_champion', 'team_lead', 'unit_head'],
            4 => ['risk_champion', 'team_lead', 'unit_head', 'group_head'],
            5 => ['risk_champion', 'team_lead', 'unit_head', 'group_head'],
        ];

        foreach ($expected as $sequence => $roles) {
            // One manual reminder per department per day: a new Lagos day each time.
            $this->freezeAt(sprintf('2026-11-%02d 06:00:00', 2 + $sequence));

            $result = $this->notices()->remind([$this->return->id], $officer);

            $this->assertSame('queued', $result[0]['outcome'], "reminder {$sequence}");
            $this->assertSame(min($sequence, 4), $result[0]['tier'], "reminder {$sequence} tier");

            $notice = KriReturnNotice::query()->where('kind', 'reminder')->where('sequence', $sequence)->firstOrFail();
            $this->assertSame(min($sequence, 4), (int) $notice->tier);
            $this->assertSame($roles, $this->rolesOn($notice), "reminder {$sequence} reaches exactly these roles");
        }

        // 1 + 2 + 3 + 4 + 4 recipients, one mail each.
        Mail::assertSent(KriReturnReminder::class, 14);
        Mail::assertNotSent(KriReturnRequested::class);
    }

    #[Test]
    public function a_reminder_to_a_department_with_no_champion_still_uses_its_daily_slot_and_sequence(): void
    {
        Mail::fake();
        \App\Models\KriDepartmentContact::query()->where('role', 'risk_champion')->delete();
        $officer = $this->officer();

        $this->assertSame('queued', $this->notices()->remind([$this->return->id], $officer)[0]['outcome']);
        $first = KriReturnNotice::query()->where('kind', 'reminder')->firstOrFail();
        $this->assertSame('no_recipients', $first->status);
        Mail::assertNothingSent();

        $this->assertSame('already_reminded_today', $this->notices()->remind([$this->return->id], $officer)[0]['outcome']);

        $this->freezeAt('2026-11-03 06:00:00');
        $this->assertSame(2, $this->notices()->remind([$this->return->id], $officer)[0]['tier'], 'the next reminder is #2, not #1');
    }

    #[Test]
    public function a_submitted_return_is_not_reminded(): void
    {
        Mail::fake();
        $this->return->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        $result = $this->notices()->remind([$this->return->id], $this->officer());

        $this->assertSame('submitted', $result[0]['outcome']);
        $this->assertSame(0, KriReturnNotice::query()->where('kind', 'reminder')->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_reminder_outside_the_senders_departments_is_out_of_scope_and_the_batch_continues(): void
    {
        Mail::fake();
        $other = $this->makeUnit('Operations', 'OPS');
        $this->deptKri($other);
        $otherReturn = $this->makeReturn($other);

        // Assigned to Operations only; not a holder of kri.all_departments.
        $scoped = $this->makeStaff('Ops Reminder', ['kri.send_reminder']);
        \Illuminate\Support\Facades\DB::table('business_unit_user')->insert([
            'organization_id' => $this->organization->id, 'user_id' => $scoped->id, 'business_unit_id' => $other->id,
            'includes_descendants' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $results = $this->notices()->remind([$this->return->id, $otherReturn->id], $scoped);

        $this->assertSame(['out_of_scope', 'queued'], array_column($results, 'outcome'));
        $this->assertSame(0, KriReturnNotice::query()->where('business_unit_id', $this->unit->id)->where('kind', 'reminder')->count());
        $this->assertSame(1, KriReturnNotice::query()->where('business_unit_id', $other->id)->where('kind', 'reminder')->count());
    }

    #[Test]
    public function another_tenants_return_cannot_be_reminded_by_this_tenant(): void
    {
        Mail::fake();
        $theirUnit = $this->foreignDepartment();
        $theirReturn = $this->makeReturn($theirUnit, [], syncLines: false);

        $results = $this->notices()->remind([$theirReturn->id], $this->officer());

        $this->assertSame('out_of_scope', $results[0]['outcome']);
        $this->assertNull($results[0]['department'], "the other tenant's department name is not even revealed");
        $this->assertSame(0, KriReturnNotice::withoutGlobalScopes()->where('organization_id', $this->otherOrg->id)->count());
        Mail::assertNothingSent();
    }

    /* ------------------------------------------------------------------ */
    /*  Entry points that are not an authenticated web request */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_jobs_resolve_their_own_tenant_when_the_worker_has_none(): void
    {
        Mail::fake();
        $theirUnit = $this->foreignDepartment();
        $theirReturn = $this->makeReturn($theirUnit, [], syncLines: false);
        $theirUser = $this->foreignUser();
        $this->makeContact($theirUnit, $theirUser, KriContactRole::RiskChampion);

        $theirNoticeId = TenantContext::actingAs($this->otherOrg->id, fn () => (int) $this->notices()->queueRequest($theirReturn, KriWorkingCalendar::today()));

        // A queue worker: no tenant resolved at all.
        TenantContext::clear();
        (new DispatchKriReturnNotice($theirNoticeId))->handle($this->notices());

        $this->assertNull(TenantContext::organizationIdOrNull(), 'the job restores what it found; it must not leave a tenant (or clear one) behind');

        $rows = KriReturnNoticeRecipient::withoutGlobalScopes()->where('kri_return_notice_id', $theirNoticeId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($theirUser->email, $rows[0]->email);
        $this->assertSame($this->otherOrg->id, (int) $rows[0]->organization_id);
        Mail::assertSent(KriReturnRequested::class, 1);
        Mail::assertSent(KriReturnRequested::class, fn ($mail) => $mail->hasTo($theirUser->email));
        // Nobody from THIS tenant was dragged into their notice.
        $this->assertSame(0, KriReturnNoticeRecipient::withoutGlobalScopes()->where('kri_return_notice_id', $theirNoticeId)->where('email', $this->people['champion']->email)->count());
    }

    #[Test]
    public function the_jobs_do_not_untenant_a_caller_that_runs_them_inline(): void
    {
        Mail::fake();
        $id = $this->queueRequest();

        // The suite is the "something that runs it inline": a tenant is resolved and must survive.
        $this->assertSame($this->organization->id, TenantContext::organizationIdOrNull());
        $this->dispatchNotice($id);

        $this->assertSame($this->organization->id, TenantContext::organizationIdOrNull());
    }

    #[Test]
    public function a_job_for_a_row_that_no_longer_exists_does_nothing(): void
    {
        Mail::fake();

        (new DispatchKriReturnNotice(999999))->handle($this->notices());
        (new SendKriReturnNoticeMail(999999))->handle($this->notices());

        Mail::assertNothingSent();
        $this->addToAssertionCount(1);
    }

    /* ------------------------------------------------------------------ */
    /*  The mail itself */
    /* ------------------------------------------------------------------ */

    private function recipientFor(string $key, string $kind = 'request'): KriReturnNoticeRecipient
    {
        $notice = $kind === 'request'
            ? KriReturnNotice::query()->findOrFail($this->queueRequest())
            : KriReturnNotice::query()->findOrFail($this->forceNotice(1));

        return KriReturnNoticeRecipient::query()->create([
            'organization_id' => $this->organization->id,
            'kri_return_notice_id' => $notice->id,
            'user_id' => $this->people[$key]->id,
            'email' => $this->people[$key]->email,
            'name' => $this->people[$key]->name,
            'contact_role' => $key === 'champion' ? 'risk_champion' : ($key === 'lead' ? 'team_lead' : ($key === 'head' ? 'unit_head' : 'group_head')),
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function the_request_links_a_champion_to_complete_my_kris_and_every_other_contact_to_the_read_only_return(): void
    {
        $championUrl = route('risk.kri.my-return', ['department' => $this->unit->id, 'period' => $this->return->period_id]);
        $viewUrl = route('risk.kri.returns.show', $this->return);

        $championHtml = (new KriReturnRequested($this->recipientFor('champion')))->render();
        $this->assertStringContainsString($championUrl, $championHtml);
        $this->assertStringContainsString('Complete my KRIs', $championHtml);
        $this->assertStringNotContainsString($viewUrl, $championHtml);
        $this->assertStringContainsString('Treasury', $championHtml);

        // A different notice per call is fine; reuse the request notice for the lead.
        $lead = KriReturnNoticeRecipient::query()->create([
            'organization_id' => $this->organization->id,
            'kri_return_notice_id' => KriReturnNotice::query()->where('kind', 'request')->firstOrFail()->id,
            'user_id' => $this->people['lead']->id, 'email' => $this->people['lead']->email, 'name' => 'Tola Lead',
            'contact_role' => 'team_lead', 'status' => 'pending',
        ]);
        $leadHtml = (new KriReturnRequested($lead))->render();
        $this->assertStringContainsString($viewUrl, $leadHtml);
        $this->assertStringContainsString('View the return', $leadHtml);
        $this->assertStringNotContainsString($championUrl, $leadHtml);
    }

    #[Test]
    public function the_reminder_renders_with_the_departments_kris_and_the_days_overdue(): void
    {
        $recipient = $this->recipientFor('lead', 'reminder');
        // Due Mon 9 Nov; it is Thu 12 Nov in Lagos.
        $this->freezeAt('2026-11-12 06:00:00');

        $mailable = new KriReturnReminder($recipient);
        $html = $mailable->render();

        $this->assertStringContainsString('Treasury', $html);
        $this->assertStringContainsString('Indicator', $html);
        $this->assertStringContainsString('3 days overdue', $html);
        $this->assertSame(3, KriReturnMailView::for($recipient)['days_overdue']);
        $this->assertStringContainsString('Replies to this email are not read', $html);
        $this->assertStringNotContainsString('reply to this', strtolower($html), 'templates must give no instruction to reply (A1.13)');
        $this->assertStringContainsString('3 days overdue', (string) $mailable->envelope()->subject);
    }
}
