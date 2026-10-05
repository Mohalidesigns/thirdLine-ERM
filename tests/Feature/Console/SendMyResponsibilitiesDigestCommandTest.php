<?php

namespace Tests\Feature\Console;

use App\Mail\MyResponsibilitiesDigest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `my:digest` end to end: real service, real queue contents, faked transport.
 *
 * It had no test, and the mailable it queues could not be loaded.
 */
class SendMyResponsibilitiesDigestCommandTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    private function makeUser(string $label, array $attributes = [], ?Organization $organization = null): User
    {
        return User::create(array_merge([
            'name' => $label,
            'email' => str($label)->slug().'-'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => ($organization ?? $this->organization)->id,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * An active risk owned by $owner whose review fell due yesterday lands in
     * their queue's OVERDUE bucket. (Not "never assessed with no review date":
     * that is bucket `later`, which the email counts but does not list.)
     */
    private function oweSomething(User $owner): void
    {
        $this->makeRisk([
            'risk_owner_id' => $owner->id,
            'title' => 'Owed by '.$owner->name,
            'next_review_date' => now()->subDay()->toDateString(),
        ]);
    }

    /**
     * Active risks owned by $owner with no review date and no assessment: the
     * service files them under `later`, which the digest does not carry.
     */
    private function owe(User $owner, int $count, bool $overdue): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->makeRisk([
                'risk_owner_id' => $owner->id,
                'title' => ($overdue ? 'Overdue' : 'Someday')." item {$i} of {$owner->name}",
                'next_review_date' => $overdue ? now()->subDays($i)->toDateString() : null,
            ]);
        }
    }

    /** Active risks owned by $owner whose review is $days days out (not due this week when 10). */
    private function oweLaterThanAWeek(User $owner, int $days): void
    {
        $this->makeRisk([
            'risk_owner_id' => $owner->id,
            'title' => "Distant item of {$owner->name}",
            // Unassessed, so it is in the queue; dated, so it is bucketed by date.
            'next_review_date' => now()->addDays($days)->toDateString(),
        ]);
    }

    #[Test]
    public function one_digest_is_queued_per_eligible_user_to_the_right_address(): void
    {
        Mail::fake();

        $alice = $this->makeUser('Alice Owner');
        $bob = $this->makeUser('Bob Owner');
        $idle = $this->makeUser('Idle User');
        $inactive = $this->makeUser('Inactive Owner', ['is_active' => false]);

        $this->oweSomething($alice);
        $this->oweSomething($bob);
        $this->oweSomething($inactive);

        $this->artisan('my:digest')
            ->expectsOutputToContain('Digest queued for 2 users.')
            ->assertExitCode(0);

        Mail::assertQueuedCount(2);

        foreach ([$alice, $bob] as $user) {
            Mail::assertQueued(
                MyResponsibilitiesDigest::class,
                fn (MyResponsibilitiesDigest $mail) => $mail->hasTo($user->email)
                    && $mail->recipient->is($user)
                    && $mail->digest['total_items'] === 1
                    && str_contains($mail->render(), 'Owed by '.$user->name),
            );
        }

        // An empty queue gets nothing, and a deactivated user gets nothing
        // even when they own something.
        Mail::assertNotQueued(MyResponsibilitiesDigest::class, fn ($mail) => $mail->hasTo($idle->email));
        Mail::assertNotQueued(MyResponsibilitiesDigest::class, fn ($mail) => $mail->hasTo($inactive->email));
    }

    #[Test]
    public function the_user_option_restricts_the_run_to_one_person(): void
    {
        Mail::fake();

        $alice = $this->makeUser('Alice Owner');
        $bob = $this->makeUser('Bob Owner');
        $this->oweSomething($alice);
        $this->oweSomething($bob);

        $this->artisan('my:digest', ['--user' => $bob->id])->assertExitCode(0);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(MyResponsibilitiesDigest::class, fn ($mail) => $mail->hasTo($bob->email));
    }

    #[Test]
    public function each_organisation_is_digested_within_its_own_tenant(): void
    {
        Mail::fake();

        $alice = $this->makeUser('Alice Owner');
        $this->oweSomething($alice);

        $otherOrg = Organization::create([
            'name' => 'Other Bank',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
        $carol = $this->makeUser('Carol Other', [], $otherOrg);
        TenantContext::set($otherOrg->id);
        $this->makeRisk([
            'organization_id' => $otherOrg->id,
            'risk_owner_id' => $carol->id,
            'title' => 'Owed by Carol',
            'next_review_date' => now()->subDay()->toDateString(),
            'risk_code' => 'RK-OTHER-1',
            'category_id' => $this->category->id,
        ]);
        TenantContext::set($this->organization->id);

        $this->artisan('my:digest')->assertExitCode(0);

        Mail::assertQueuedCount(2);
        Mail::assertQueued(MyResponsibilitiesDigest::class, fn ($mail) => $mail->hasTo($alice->email)
            && ! str_contains($mail->render(), 'Owed by Carol'));
        Mail::assertQueued(MyResponsibilitiesDigest::class, fn ($mail) => $mail->hasTo($carol->email)
            && $mail->digest['total_items'] === 1);
    }

    #[Test]
    public function a_configured_chat_webhook_gets_a_card_per_user_with_something_owed(): void
    {
        Mail::fake();
        Http::fake();

        $this->organization->update(['settings' => ['notifications' => ['chat_webhook_url' => 'https://chat.example.test/hook']]]);

        $alice = $this->makeUser('Alice Owner');
        $this->makeUser('Idle User');
        $this->oweSomething($alice);

        $this->artisan('my:digest')->assertExitCode(0);

        Mail::assertQueuedCount(1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://chat.example.test/hook'
            && str_contains($request['text'], 'Alice Owner'));
    }

    #[Test]
    public function no_one_owing_anything_means_no_mail_and_no_card(): void
    {
        Mail::fake();
        Http::fake();

        $this->makeUser('Idle User');

        $this->artisan('my:digest')
            ->expectsOutputToContain('Digest queued for 0 users.')
            ->assertExitCode(0);

        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    #[Test]
    public function a_user_whose_items_are_all_later_gets_no_mail_and_no_card(): void
    {
        Mail::fake();
        Http::fake();

        $this->organization->update(['settings' => ['notifications' => ['chat_webhook_url' => 'https://chat.example.test/hook']]]);

        $someday = $this->makeUser('Someday Owner');
        $this->owe($someday, 3, overdue: false);

        $this->artisan('my:digest')
            ->expectsOutputToContain('Digest queued for 0 users.')
            ->assertExitCode(0);

        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    #[Test]
    public function later_items_are_neither_counted_nor_listed(): void
    {
        Mail::fake();

        $alice = $this->makeUser('Alice Owner');
        $this->owe($alice, 2, overdue: true);
        $this->owe($alice, 3, overdue: false);

        $this->artisan('my:digest')->assertExitCode(0);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(MyResponsibilitiesDigest::class, function (MyResponsibilitiesDigest $mail) {
            $html = $mail->render();

            return $mail->digest['total_items'] === 2
                && $mail->digest['total_minutes'] === 10
                && $mail->hasSubject('Your risk queue: 2 items, 2 overdue')
                && str_contains($html, '2 items')
                && str_contains($html, 'Overdue item 1 of Alice Owner')
                && str_contains($html, 'Overdue item 2 of Alice Owner')
                && ! str_contains($html, 'Someday item')
                && ! str_contains($html, '5 items');
        });
    }

    #[Test]
    public function the_chat_card_counts_only_due_soon_items_too(): void
    {
        Mail::fake();
        Http::fake();

        $this->organization->update(['settings' => ['notifications' => ['chat_webhook_url' => 'https://chat.example.test/hook']]]);

        $alice = $this->makeUser('Alice Owner');
        $this->owe($alice, 2, overdue: true);
        $this->owe($alice, 3, overdue: false);

        $this->artisan('my:digest')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request['text'], '2 risk items')
            && str_contains($request['text'], '2 overdue')
            && ! str_contains($request['text'], 'Someday item'));
    }

    #[Test]
    public function the_subject_follows_the_due_soon_count_singular_and_plural(): void
    {
        Mail::fake();

        $one = $this->makeUser('One Overdue');
        $this->owe($one, 1, overdue: true);
        $this->owe($one, 4, overdue: false);

        $two = $this->makeUser('Two Overdue');
        $this->owe($two, 2, overdue: true);
        $this->owe($two, 1, overdue: false);

        $this->artisan('my:digest')->assertExitCode(0);

        Mail::assertQueued(MyResponsibilitiesDigest::class, fn ($mail) => $mail->hasTo($one->email)
            && $mail->hasSubject('Your risk queue: 1 item, 1 overdue'));
        Mail::assertQueued(MyResponsibilitiesDigest::class, fn ($mail) => $mail->hasTo($two->email)
            && $mail->hasSubject('Your risk queue: 2 items, 2 overdue'));
    }

    #[Test]
    public function undated_and_ten_days_out_items_are_both_later_and_send_nothing(): void
    {
        Mail::fake();
        Http::fake();

        $this->organization->update(['settings' => ['notifications' => ['chat_webhook_url' => 'https://chat.example.test/hook']]]);

        $someday = $this->makeUser('Someday Owner');
        $this->owe($someday, 1, overdue: false);          // null due_at
        $this->oweLaterThanAWeek($someday, 10);           // dated, a week or more out

        $this->artisan('my:digest')
            ->expectsOutputToContain('Digest queued for 0 users.')
            ->assertExitCode(0);

        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    #[Test]
    public function a_digest_goes_through_a_real_queue_round_trip_and_is_actually_sent(): void
    {
        // No Mail::fake(): phpunit.xml gives the array mailer and the sync
        // queue, so Mail::queue() runs SendQueuedMailable, serialises the
        // mailable (SerializesModels) and delivers it for real.
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('mail.default'));

        $alice = $this->makeUser('Alice Owner');
        $this->owe($alice, 2, overdue: true);
        $this->owe($alice, 3, overdue: false);
        $this->makeUser('Idle User');

        $this->artisan('my:digest')->assertExitCode(0);

        /** @var ArrayTransport $transport */
        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();
        $sent = $transport->messages();

        $this->assertCount(1, $sent);
        $message = $sent->first()->getOriginalMessage();
        $this->assertSame($alice->email, $message->getTo()[0]->getAddress());
        $this->assertSame('Your risk queue: 2 items, 2 overdue', $message->getSubject());
        $this->assertStringContainsString('Overdue item 1 of Alice Owner', $message->getHtmlBody());
        $this->assertStringNotContainsString('Someday item', $message->getHtmlBody());
    }
}
