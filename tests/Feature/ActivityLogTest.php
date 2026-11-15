<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Middleware\LogRequestActivity;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('admin.activity_log');
        Permission::findOrCreate('dashboard.view');
    }

    #[Test]
    public function activity_logger_records_entries_with_actor_and_subject(): void
    {
        $log = ActivityLogger::log(
            event: 'custom_event',
            subject: $this->actor,
            description: 'Custom action executed',
            properties: ['foo' => 'bar'],
            userId: $this->actor->id,
            userName: $this->actor->name,
            userEmail: $this->actor->email,
            organizationId: $this->organization->id,
        );

        $this->assertNotNull($log);
        $this->assertDatabaseHas('activity_logs', [
            'id' => $log->id,
            'event' => 'custom_event',
            'user_id' => $this->actor->id,
            'user_name' => $this->actor->name,
            'user_email' => $this->actor->email,
            'auditable_type' => User::class,
            'auditable_id' => $this->actor->id,
            'description' => 'Custom action executed',
        ]);

        $this->assertSame(['foo' => 'bar'], $log->fresh()->properties);
    }

    #[Test]
    public function activity_logger_label_for_resolves_known_naming_attributes(): void
    {
        $this->assertSame($this->actor->name, ActivityLogger::labelFor($this->actor));
    }

    #[Test]
    public function log_request_activity_middleware_ignores_get_requests(): void
    {
        $middleware = new LogRequestActivity;
        $request = Request::create('/test-get', 'GET');
        $response = new Response('ok', 200);

        $this->actingAs($this->actor);
        $middleware->terminate($request, $response);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[Test]
    public function log_request_activity_middleware_records_state_changes(): void
    {
        $middleware = new LogRequestActivity;
        $request = Request::create('/test-action', 'POST');
        $response = new Response('ok', 200);

        $this->actingAs($this->actor);
        $middleware->terminate($request, $response);

        $this->assertDatabaseHas('activity_logs', [
            'event' => 'post:test-action',
            'user_id' => $this->actor->id,
            'method' => 'POST',
        ]);
    }

    #[Test]
    public function log_request_activity_middleware_records_access_denied_on_forbidden(): void
    {
        $middleware = new LogRequestActivity;
        $request = Request::create('/forbidden-action', 'POST');
        $response = new Response('denied', 403);

        $this->actingAs($this->actor);
        $middleware->terminate($request, $response);

        $this->assertDatabaseHas('activity_logs', [
            'event' => 'access_denied',
            'user_id' => $this->actor->id,
        ]);
    }

    #[Test]
    public function activity_log_screen_requires_permission(): void
    {
        // Without admin.activity_log permission
        $this->actingAs($this->actor)
            ->get(route('admin.activity-log'))
            ->assertForbidden();

        // With admin.activity_log permission
        $this->actor->givePermissionTo('admin.activity_log');

        $this->actingAs($this->actor)
            ->get(route('admin.activity-log'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ActivityLog/Index')
                ->has('logs.data')
                ->has('filters')
                ->has('options')
            );
    }

    #[Test]
    public function activity_log_screen_filters_by_event_and_user(): void
    {
        $this->actor->givePermissionTo('admin.activity_log');

        ActivityLogger::log(
            event: 'event_alpha',
            description: 'Action Alpha',
            userId: $this->actor->id,
            userName: $this->actor->name,
            organizationId: $this->organization->id,
        );

        ActivityLogger::log(
            event: 'event_beta',
            description: 'Action Beta',
            userId: $this->actor->id,
            userName: $this->actor->name,
            organizationId: $this->organization->id,
        );

        $response = $this->actingAs($this->actor)
            ->get(route('admin.activity-log', ['event' => 'event_alpha']))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/ActivityLog/Index')
            ->where('logs.data.0.event', 'event_alpha')
            ->has('logs.data', 1)
        );
    }
}
