<?php

namespace Tests\Feature\Inertia;

use App\Models\TreatmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 0 — /my is the first page rendered through Inertia.
 */
class MyPageTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();
        Permission::findOrCreate('my.view');
        $this->actor->givePermissionTo('my.view');
    }

    #[Test]
    public function it_renders_the_queue_through_inertia(): void
    {
        $risk = $this->makeRisk();

        TreatmentPlan::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'strategy' => 'mitigate',
            'action_title' => 'Recalibrate loan approval limits',
            'owner_id' => $this->actor->id,
            'target_date' => now()->subDays(5)->toDateString(),
            'status' => 'in_progress',
            'progress_pct' => 40,
            'created_by' => $this->actor->id,
        ]);

        $this->actingAs($this->actor)->get('/my')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('My/Index')
                ->has('queue.sections')
                ->has('queue.buckets.overdue', 1)
                ->where('queue.buckets.overdue.0.title', fn ($title) => str_contains($title, 'Recalibrate loan approval limits'))
                ->where('queue.total_items', 1)
                ->has('queue.total_minutes')
            );
    }

    #[Test]
    public function an_empty_queue_is_an_empty_queue(): void
    {
        $this->actingAs($this->actor)->get('/my')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('My/Index')
                ->where('queue.total_items', 0)
                ->where('queue.buckets.overdue', [])
            );
    }

    #[Test]
    public function the_blade_view_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/my/index.blade.php'));
        $this->assertFileExists(resource_path('js/Pages/My/Index.jsx'));
    }

    #[Test]
    public function it_still_requires_the_permission(): void
    {
        $this->actor->revokePermissionTo('my.view');

        $this->actingAs($this->actor)->get('/my')->assertForbidden();
    }
}
