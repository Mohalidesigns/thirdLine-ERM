<?php

namespace Tests\Feature\Mail;

use App\Mail\MyResponsibilitiesDigest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The morning digest mailable.
 *
 * It declared `public readonly array $queue` over Illuminate\Bus\Queueable's
 * `public $queue`, which is an incompatible redeclaration and a fatal at class
 * load. No test ever built it, so the first signal was my:digest dying at 07:30
 * the day RCSA v2 and TPRM made real users' queues non-empty.
 */
class MyResponsibilitiesDigestTest extends TestCase
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

    /** @return array{buckets: array, total_items: int, total_minutes: int} */
    private function digest(int $overdue = 2): array
    {
        $item = fn (string $title, ?string $dueAt, int $minutes, string $bucket) => [
            'type' => 'task',
            'key' => 'task-'.md5($title),
            'title' => $title,
            'subtitle' => null,
            'due_at' => $dueAt,
            'bucket' => $bucket,
            'minutes' => $minutes,
            'url' => 'https://erm.example.test/risk/'.md5($title),
            'badge' => null,
        ];

        $overdueItems = [];
        for ($i = 1; $i <= $overdue; $i++) {
            $overdueItems[] = $item("Overdue treatment: Patch core banking {$i}", now()->subDays(3)->toIso8601String(), 3, 'overdue');
        }

        return [
            'sections' => [],
            'buckets' => [
                'overdue' => $overdueItems,
                'today' => [$item('Review due: Naira exposure', now()->toIso8601String(), 5, 'today')],
                'this_week' => [$item('KRI reading due: Failed logins', now()->addDays(3)->toIso8601String(), 2, 'this_week')],
                'later' => [],
            ],
            'total_items' => $overdue + 2,
            'total_minutes' => ($overdue * 3) + 7,
        ];
    }

    #[Test]
    public function the_mailable_can_be_built_and_exposes_the_digest_not_the_queue_name(): void
    {
        $mail = new MyResponsibilitiesDigest($this->actor, $this->digest());

        $this->assertSame(4, $mail->digest['total_items']);
        $this->assertSame($this->actor->id, $mail->recipient->id);

        // Queueable's own property is the queue NAME and must stay untouched
        // by the payload.
        $this->assertNull($mail->queue);
    }

    #[Test]
    public function it_renders_every_bucket_with_links_and_the_total(): void
    {
        $html = (new MyResponsibilitiesDigest($this->actor, $this->digest()))->render();

        $this->assertStringContainsString('My Responsibilities', $html);
        $this->assertStringContainsString('4 items', $html);
        $this->assertStringContainsString('estimated ~13 min', $html);
        $this->assertStringContainsString('Overdue (2)', $html);
        $this->assertStringContainsString('Due today (1)', $html);
        $this->assertStringContainsString('This week (1)', $html);
        $this->assertStringContainsString('Overdue treatment: Patch core banking 1', $html);
        $this->assertStringContainsString('KRI reading due: Failed logins', $html);
        $this->assertStringContainsString('https://erm.example.test/risk/'.md5('Review due: Naira exposure'), $html);
        $this->assertStringContainsString(route('my.index'), $html);
    }

    #[Test]
    public function the_subject_counts_overdue_items_when_there_are_some(): void
    {
        $mail = new MyResponsibilitiesDigest($this->actor, $this->digest(overdue: 2));

        $mail->assertHasSubject('Your risk queue: 4 items, 2 overdue');
    }

    #[Test]
    public function the_subject_is_just_the_count_when_nothing_is_overdue(): void
    {
        $digest = $this->digest(overdue: 0);
        $digest['buckets']['overdue'] = [];

        $mail = new MyResponsibilitiesDigest($this->actor, $digest);

        $mail->assertHasSubject('Your risk queue: 2 items');
    }

    #[Test]
    public function a_single_item_is_not_pluralised(): void
    {
        $digest = $this->digest(overdue: 0);
        $digest['buckets'] = ['overdue' => [], 'today' => [$digest['buckets']['today'][0]], 'this_week' => [], 'later' => []];
        $digest['total_items'] = 1;
        $digest['total_minutes'] = 5;

        $mail = new MyResponsibilitiesDigest($this->actor, $digest);

        $mail->assertHasSubject('Your risk queue: 1 item');
        $this->assertStringContainsString('1 item ·', $mail->render());
    }

    #[Test]
    public function the_mailable_survives_queue_serialisation(): void
    {
        // The command queues it; the worker unserialises it. SerializesModels
        // swaps the User for an identifier and back.
        $mail = new MyResponsibilitiesDigest($this->actor, $this->digest());

        $restored = unserialize(serialize($mail));

        $this->assertInstanceOf(User::class, $restored->recipient);
        $this->assertSame($this->actor->id, $restored->recipient->id);
        $this->assertSame($mail->digest, $restored->digest);
    }

    #[Test]
    public function due_soon_keeps_three_buckets_and_recounts_from_them(): void
    {
        $queue = $this->digest(overdue: 2);
        $queue['buckets']['later'] = [
            ['type' => 'risk_review_due', 'key' => 'risk-1', 'title' => 'Someday', 'subtitle' => null, 'due_at' => null, 'bucket' => 'later', 'minutes' => 5, 'url' => 'https://erm.example.test/x', 'badge' => null],
        ];
        // The service's own figures include `later`.
        $queue['total_items'] = 5;
        $queue['total_minutes'] = 18;

        $digest = MyResponsibilitiesDigest::dueSoon($queue);

        $this->assertSame(['overdue', 'today', 'this_week'], array_keys($digest['buckets']));
        $this->assertSame(4, $digest['total_items']);
        $this->assertSame(13, $digest['total_minutes']);
    }

    #[Test]
    public function due_soon_of_only_later_items_is_empty(): void
    {
        $queue = $this->digest(overdue: 0);
        $queue['buckets'] = ['overdue' => [], 'today' => [], 'this_week' => [], 'later' => [['minutes' => 3, 'bucket' => 'later']]];

        $this->assertSame(0, MyResponsibilitiesDigest::dueSoon($queue)['total_items']);
    }
}
