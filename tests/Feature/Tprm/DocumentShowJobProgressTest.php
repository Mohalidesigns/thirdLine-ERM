<?php

namespace Tests\Feature\Tprm;

use App\Jobs\RunTprmDocumentExtraction;
use App\Models\JobRun;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Evidence\EvidenceService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate 1 restart cycle — blocking defect 2: extraction moved to the queue and
 * `resources/js/Pages/Tprm/Documents/Show.jsx` consumes `extractionJobRunId`
 * via `useJobProgress`, but no JavaScript executes in this suite, so the
 * screen's own polling and rendering cannot be certified here. What CAN and
 * MUST be proven server-side:
 *
 *   1. The document show response actually carries `extractionJobRunId`.
 *   2. The job-progress endpoint the screen polls returns what it needs, is
 *      correctly authorised, and refuses another tenant's job run.
 *
 * Whether the React component actually renders a progress bar from this prop
 * was verified by the implementing agent in a browser and is NOT covered by
 * this or any other automated test in this suite.
 */
class DocumentShowJobProgressTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        Storage::fake('local');

        $this->organization = Organization::create([
            'name' => 'Yola Provident Bank', 'short_name' => 'YPB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->user = User::create([
            'name' => 'Reviewer', 'email' => 'reviewer@ypb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-evidence-reviewer-jp', 'web');
        foreach (['tprm.evidence.view', 'tprm.evidence.upload', 'tprm.evidence.confirm', 'job.view'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->user->assignRole($role);

        $vendor = ThirdParty::create([
            'legal_name' => 'Gombe Cloud Systems Limited', 'slug' => Str::random(10), 'entity_type' => 'company',
        ]);

        $this->engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-0299',
            'name' => 'Cloud hosting',
            'service_description' => 'Hosting.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->user->id,
        ]);

        $this->engagement->businessFunctions()->attach(
            BusinessFunction::where('function_code', 'BF-HR-01')->value('id'),
            ['organization_id' => $this->organization->id]
        );
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    private function uploadSoc2(): Document
    {
        $type = DocumentType::query()->availableTo()->where('code', 'soc2_type2')->firstOrFail();

        return app(EvidenceService::class)->store(
            UploadedFile::fake()->createWithContent('soc2.txt', 'report body'),
            Document::OWNER_ENGAGEMENT,
            $this->engagement->id,
            $type,
            ['title' => 'SOC 2 report'],
            $this->user->id,
        );
    }

    #[Test]
    public function the_show_response_carries_the_outstanding_job_run_id_after_dispatch(): void
    {
        Queue::fake();
        $document = $this->uploadSoc2();

        $extractResponse = $this->actingAs($this->user)->post(route('tprm.documents.extract', $document));
        $extractResponse->assertRedirect();

        $jobRun = JobRun::withoutGlobalScopes()
            ->where('job_class', RunTprmDocumentExtraction::class)
            ->where('subject_id', $document->id)
            ->firstOrFail();

        $showResponse = $this->actingAs($this->user)->get(route('tprm.documents.show', $document));
        $showResponse->assertOk();

        $props = $showResponse->original->getData()['page']['props'];

        $this->assertArrayHasKey('extractionJobRunId', $props);
        $this->assertSame($jobRun->id, $props['extractionJobRunId']);
    }

    #[Test]
    public function the_show_response_carries_no_job_run_id_when_nothing_is_outstanding(): void
    {
        $document = $this->uploadSoc2();

        $showResponse = $this->actingAs($this->user)->get(route('tprm.documents.show', $document));

        $props = $showResponse->original->getData()['page']['props'];
        $this->assertNull($props['extractionJobRunId']);
    }

    #[Test]
    public function the_job_progress_endpoint_returns_the_fields_the_poller_needs_with_the_right_authorisation(): void
    {
        Queue::fake();
        $document = $this->uploadSoc2();
        $this->actingAs($this->user)->post(route('tprm.documents.extract', $document));

        $jobRun = JobRun::withoutGlobalScopes()
            ->where('job_class', RunTprmDocumentExtraction::class)
            ->where('subject_id', $document->id)
            ->firstOrFail();

        $response = $this->actingAs($this->user)->get(route('risk.jobs.progress', $jobRun));

        $response->assertOk();
        $response->assertJsonStructure(['id', 'label', 'status', 'progress', 'finished', 'can_cancel']);
        $this->assertSame($jobRun->id, $response->json('id'));
    }

    #[Test]
    public function a_user_without_job_view_permission_cannot_poll_progress(): void
    {
        Queue::fake();
        $document = $this->uploadSoc2();
        $this->actingAs($this->user)->post(route('tprm.documents.extract', $document));

        $jobRun = JobRun::withoutGlobalScopes()
            ->where('job_class', RunTprmDocumentExtraction::class)
            ->where('subject_id', $document->id)
            ->firstOrFail();

        $noPermissionUser = User::create([
            'name' => 'No Permission', 'email' => 'noperm@ypb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $this->actingAs($noPermissionUser)->get(route('risk.jobs.progress', $jobRun))->assertForbidden();
    }

    #[Test]
    public function another_tenants_user_cannot_poll_this_tenants_extraction_job_run(): void
    {
        Queue::fake();
        $document = $this->uploadSoc2();
        $this->actingAs($this->user)->post(route('tprm.documents.extract', $document));

        $jobRun = JobRun::withoutGlobalScopes()
            ->where('job_class', RunTprmDocumentExtraction::class)
            ->where('subject_id', $document->id)
            ->firstOrFail();

        $otherOrg = Organization::create([
            'name' => 'Kano Frontier Bank', 'short_name' => 'KFB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($otherOrg->id);
        $otherUser = User::create([
            'name' => 'Other Tenant User', 'email' => 'other@kfb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $otherOrg->id, 'is_active' => true,
        ]);
        $otherRole = Role::findOrCreate('kfb-job-viewer', 'web');
        $otherRole->givePermissionTo(Permission::findOrCreate('job.view', 'web'));
        $otherUser->assignRole($otherRole);
        TenantContext::set($this->organization->id);

        $this->actingAs($otherUser)->get(route('risk.jobs.progress', $jobRun))->assertNotFound();
    }
}
