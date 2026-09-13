<?php

namespace Tests\Feature\Inertia;

use App\Models\JobRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 2 — the session-authenticated progress endpoint the SPA's
 * useJobProgress hook polls (the Livewire JobProgress component's replacement).
 */
class JobProgressTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('job.view');
        $this->actor->givePermissionTo('job.view');
    }

    #[Test]
    public function it_reports_a_running_job(): void
    {
        $run = $this->jobRun(['status' => 'running', 'progress' => 40, 'processed' => 4, 'total' => 10, 'message' => 'Importing rows']);

        $this->actingAs($this->actor)->getJson(route('risk.jobs.progress', $run))
            ->assertOk()
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('progress', 40)
            ->assertJsonPath('processed', 4)
            ->assertJsonPath('total', 10)
            ->assertJsonPath('message', 'Importing rows')
            ->assertJsonPath('finished', false)
            ->assertJsonPath('can_cancel', true)
            ->assertJsonStructure(['label', 'color', 'error', 'cancel_requested', 'estimated_seconds_remaining', 'duration_seconds']);
    }

    #[Test]
    public function a_finished_job_says_so_and_cannot_be_cancelled(): void
    {
        $run = $this->jobRun(['status' => 'completed', 'progress' => 100, 'finished_at' => now()]);

        $this->actingAs($this->actor)->getJson(route('risk.jobs.progress', $run))
            ->assertOk()
            ->assertJsonPath('finished', true)
            ->assertJsonPath('can_cancel', false);
    }

    #[Test]
    public function it_requires_job_view(): void
    {
        $run = $this->jobRun();
        $this->actor->revokePermissionTo('job.view');

        $this->actingAs($this->actor)->getJson(route('risk.jobs.progress', $run))->assertForbidden();
    }

    #[Test]
    public function another_tenants_job_is_not_found(): void
    {
        $run = $this->jobRun(['organization_id' => $this->otherOrganization()->id]);

        $this->actingAs($this->actor)->getJson(route('risk.jobs.progress', $run))->assertNotFound();
    }

    private function jobRun(array $attributes = []): JobRun
    {
        return JobRun::withoutGlobalScopes()->create(array_merge([
            'organization_id' => $this->organization->id,
            'job_class' => 'App\\Jobs\\ProcessDataImportJob',
            'label' => 'Import risks',
            'queue' => 'default',
            'status' => 'queued',
            'progress' => 0,
            'queued_at' => now(),
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function otherOrganization(): \App\Models\Organization
    {
        return \App\Models\Organization::create([
            'name' => 'Other Bank',
            'short_name' => 'OTHR'.random_int(100, 999),
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
    }
}
