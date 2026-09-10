<?php

namespace Tests\Feature;

use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * A stored notification must not carry a host.
 *
 * `route()` and `url()` build absolute URLs from the current request, or from
 * `APP_URL` when there is no request. A notification raised by a queue worker,
 * a scheduled command or a seeder was therefore stamped with whatever `APP_URL`
 * said, and one raised under `artisan serve` with that port — then served to a
 * browser on a different origin, where the bell led to a 404 from whatever else
 * was listening there. Nothing in the suite noticed, because the row was
 * written and read back perfectly; only the host was wrong.
 */
class NotificationActionUrlTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        $this->actor->assignRole('chief-risk-officer');
    }

    public static function hostBearingUrls(): array
    {
        return [
            'APP_URL fallback' => ['http://localhost/risk/my-tasks', '/risk/my-tasks'],
            'artisan serve' => ['http://127.0.0.1:8000/risk/my-tasks', '/risk/my-tasks'],
            'dev port' => ['http://localhost:8765/risk/assessments/71', '/risk/assessments/71'],
            'https production' => ['https://grc.example.test/risk/kri/4', '/risk/kri/4'],
            'query preserved' => ['http://localhost/risk/my-tasks?status=open', '/risk/my-tasks?status=open'],
            'fragment preserved' => ['http://localhost/risk/register/9#controls', '/risk/register/9#controls'],
            'protocol relative' => ['//evil.test/risk/my-tasks', '/risk/my-tasks'],
            'already relative' => ['/risk/treatments/3', '/risk/treatments/3'],
        ];
    }

    #[Test]
    #[DataProvider('hostBearingUrls')]
    public function an_action_url_is_reduced_to_a_path(string $given, string $expected): void
    {
        $this->assertSame($expected, NotificationService::normaliseActionUrl($given));
    }

    #[Test]
    public function a_notification_is_stored_with_a_relative_action_url(): void
    {
        NotificationService::send(
            $this->organization->id,
            $this->actor->id,
            'workflow_task',
            'Task awaiting you',
            'A risk assessment needs review.',
            [],
            route('risk.my-tasks.index'),
        );

        $stored = DB::table('notifications_log')->orderByDesc('id')->value('action_url');

        $this->assertSame('/risk/my-tasks', $stored);
    }

    #[Test]
    public function a_deep_link_derived_from_metadata_is_relative_too(): void
    {
        $risk = $this->makeRisk();

        NotificationService::send(
            $this->organization->id,
            $this->actor->id,
            'risk_updated',
            'Risk updated',
            'Scores changed.',
            ['entity_type' => 'Risk', 'entity_id' => $risk->id],
        );

        $stored = DB::table('notifications_log')->orderByDesc('id')->value('action_url');

        $this->assertSame('/risk/register/'.$risk->id, $stored);
    }

    #[Test]
    public function following_a_notification_cannot_leave_the_application(): void
    {
        // Rows written before the path-only rule still carry a host, and the
        // controller hands this value straight to redirect().
        $id = DB::table('notifications_log')->insertGetId([
            'organization_id' => $this->organization->id,
            'user_id' => $this->actor->id,
            'channel' => 'database',
            'type' => 'workflow_task',
            'subject' => 'Legacy row',
            'body' => 'Written before the fix.',
            'status' => 'sent',
            'action_url' => 'https://evil.test/steal',
            'priority' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->actor)
            ->get(route('notifications.read', $id))
            ->assertRedirect('/steal');
    }

    #[Test]
    public function an_empty_action_url_stays_null_rather_than_becoming_a_bare_slash(): void
    {
        $this->assertNull(NotificationService::normaliseActionUrl(null));
        $this->assertNull(NotificationService::normaliseActionUrl(''));
        $this->assertNull(NotificationService::normaliseActionUrl('   '));
    }
}
