<?php

namespace Tests\Feature\Reports;

use App\Jobs\GenerateReportJob;
use App\Models\GeneratedReport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Report generation, end to end (migration Phase 5.4, criterion 5).
 *
 * Create → job → progress → download, driven the way the React pages drive it:
 * a POST to `reports/queue`, a redirect to the status page, the JSON endpoint
 * the status page polls, and the download of the bytes that were written.
 *
 * WHAT THE STATUS PAGE POLLS, AND WHY IT IS NOT `useJobProgress`. The phase
 * prompt asks for the status page's hand-rolled fetch loop to become
 * `useJobProgress`. That hook polls `/risk/jobs/{id}/progress`, which needs a
 * JobRun — and `GenerateReportJob` does not use the `TracksJobProgress` trait,
 * so no JobRun exists for a report and `generated_reports` has no
 * `job_run_id`. The instruction is declined and recorded in the module notes;
 * the page uses `useReportStatus`, which polls the `status.json` endpoint that
 * does exist and is asserted below.
 */
class ReportGenerationFlowTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['report.view', 'report.generate', 'report.export'] as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->actor->givePermissionTo(['report.view', 'report.generate', 'report.export']);
    }

    /** Queueing records the report and hands it to the worker. */
    #[Test]
    public function queueing_a_report_creates_it_and_dispatches_the_job(): void
    {
        Queue::fake();

        $this->actingAs($this->actor)
            ->post(route('risk.reports.queue'), [
                'report_type' => 'risk_register',
                'name' => 'Q3 register',
                'format' => 'pdf',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $report = GeneratedReport::firstOrFail();

        $this->assertSame('Q3 register', $report->name);
        $this->assertSame('queued', $report->status);
        $this->assertSame(0, $report->progress_pct);
        $this->assertSame($this->actor->id, $report->generated_by);
        $this->assertSame('pdf', $report->parameters['format']);

        Queue::assertPushed(GenerateReportJob::class);
    }

    /** A format the renderer cannot produce is refused, not silently downgraded. */
    #[Test]
    public function a_format_the_renderer_cannot_produce_is_refused(): void
    {
        Queue::fake();

        $this->actingAs($this->actor)
            ->post(route('risk.reports.queue'), ['report_type' => 'risk_register', 'format' => 'pptx'])
            ->assertSessionHasErrors('format');

        $this->assertSame(0, GeneratedReport::count());
        Queue::assertNothingPushed();
    }

    /** The status page renders, and the endpoint it polls answers. */
    #[Test]
    public function the_status_page_and_the_endpoint_it_polls_both_answer(): void
    {
        $report = $this->report(['status' => 'generating', 'progress_pct' => 40]);

        $props = $this->actingAs($this->actor)
            ->get(route('risk.reports.status', $report))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Status'))
            ->inertiaProps();

        $this->assertSame('generating', $props['report']['status']);
        $this->assertSame(40, $props['report']['progress_pct']);

        $this->actingAs($this->actor)
            ->getJson(route('risk.reports.status-json', $report))
            ->assertOk()
            ->assertJson([
                'status' => 'generating',
                'progress_pct' => 40,
                'download_url' => null,
            ]);
    }

    /** A finished report offers its file, and the download serves the bytes. */
    #[Test]
    public function a_finished_report_can_be_downloaded(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('reports/test-pack.pdf', '%PDF-1.4 fixture');

        $report = $this->report([
            'status' => 'completed',
            'progress_pct' => 100,
            'disk' => 'local',
            'file_path' => 'reports/test-pack.pdf',
            'file_name' => 'test-pack.pdf',
            'format' => 'pdf',
        ]);

        $this->actingAs($this->actor)
            ->getJson(route('risk.reports.status-json', $report))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('download_url', route('risk.reports.download', $report));

        $response = $this->actingAs($this->actor)->get(route('risk.reports.download', $report));

        $response->assertOk();
        $this->assertSame('%PDF-1.4 fixture', $response->streamedContent());
    }

    /**
     * A report belongs to the institution that generated it.
     *
     * The download serves a document containing this bank's register, loss
     * history and capital position; GeneratedReportPolicy is what says so, and
     * the OrganizationScope means binding does not resolve another tenant's
     * report at all.
     */
    #[Test]
    public function another_tenants_report_is_out_of_reach(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $outsider = User::create([
            'name' => 'Their Officer',
            'email' => 'outsider@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $otherOrg->id,
            'is_active' => true,
        ]);

        $foreign = TenantContext::bypass(fn () => GeneratedReport::create([
            'organization_id' => $otherOrg->id,
            'generated_by' => $outsider->id,
            'name' => 'Their pack',
            'report_type' => 'board_pack',
            'scope' => 'board_pack',
            'period' => now()->format('F Y'),
            'period_as_at' => now()->toDateString(),
            'status' => 'completed',
            'progress_pct' => 100,
            'version' => 1,
        ]));

        $this->actingAs($this->actor)->get(route('risk.reports.status', $foreign))->assertNotFound();
        $this->actingAs($this->actor)->get(route('risk.reports.download', $foreign))->assertNotFound();
        $this->actingAs($this->actor)->getJson(route('risk.reports.status-json', $foreign))->assertNotFound();
    }

    /** Somebody who may read reports may not queue one. */
    #[Test]
    public function generating_needs_its_own_permission(): void
    {
        Queue::fake();

        $reader = User::create([
            'name' => 'Reader',
            'email' => 'reader@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $reader->givePermissionTo('report.view');

        $this->actingAs($reader->fresh())
            ->post(route('risk.reports.queue'), ['report_type' => 'risk_register'])
            ->assertForbidden();

        $this->assertSame(0, GeneratedReport::count());
        Queue::assertNothingPushed();
    }

    /* ------------------------------------------------------------------ */

    private function report(array $attributes = []): GeneratedReport
    {
        return GeneratedReport::create(array_merge([
            'organization_id' => $this->organization->id,
            'generated_by' => $this->actor->id,
            'name' => 'Test report',
            'report_type' => 'risk_register',
            'scope' => 'risk_register',
            'period' => now()->format('F Y'),
            'period_as_at' => now()->toDateString(),
            'status' => 'queued',
            'progress_pct' => 0,
            'version' => 1,
        ], $attributes));
    }
}
