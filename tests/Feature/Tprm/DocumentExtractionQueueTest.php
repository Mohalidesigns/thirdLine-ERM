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
 * ADR 0015 §6a / contract §8.15 — extraction runs on the queue, and the
 * upload request returns immediately instead of holding the connection open
 * for a model call.
 */
class DocumentExtractionQueueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('tprm.ai.enabled', false);

        Storage::fake('local');

        $this->organization = Organization::create([
            'name' => 'Owerri Provincial Bank', 'short_name' => 'OPB',
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
            'name' => 'Reviewer', 'email' => 'reviewer@opb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-evidence-reviewer', 'web');
        foreach (['tprm.evidence.view', 'tprm.evidence.upload', 'tprm.evidence.confirm'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->user->assignRole($role);

        $vendor = ThirdParty::create([
            'legal_name' => 'Delta Hosting Nigeria Limited', 'slug' => Str::random(10), 'entity_type' => 'company',
        ]);

        $this->engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-0099',
            'name' => 'Core banking hosting',
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

    /**
     * Gate 2, blocking defect 1. `RunTprmDocumentExtraction::onQueue('tprm-extraction')`
     * dispatched to a queue no Horizon supervisor consumed — enqueued and
     * never picked up, in every environment, including production. Follows
     * the exact precedent `Phase0FoundationsTest::four_horizon_supervisors_
     * exist_on_four_separate_queues()` sets for the same class of defect.
     */
    #[Test]
    public function the_extraction_queue_is_consumed_by_a_supervisor_whose_timeout_and_tries_fit_the_job(): void
    {
        $job = new RunTprmDocumentExtraction(1);
        $queueName = 'tprm-extraction';

        $supervisors = config('horizon.defaults');

        $consumer = collect($supervisors)->first(
            fn (array $supervisor) => in_array($queueName, $supervisor['queue'], true)
        );

        $this->assertNotNull($consumer, "No Horizon supervisor consumes the [{$queueName}] queue.");

        $this->assertGreaterThan(
            $job->timeout,
            $consumer['timeout'],
            "The supervisor consuming [{$queueName}] has a timeout of {$consumer['timeout']}s, which does not "
            ."exceed RunTprmDocumentExtraction::\$timeout ({$job->timeout}s). Horizon would kill the worker "
            .'mid-extraction and record a timeout that was ours, not the model\'s.'
        );

        // The job owns tries = 1 on the job class itself (the gateway owns
        // transport retry, the dispatcher owns schema retry — a third layer
        // here would multiply both), so the supervisor default is not what
        // decides retry count for THIS job. It is still checked for
        // consistency: a supervisor default of more than 1 invites a future
        // reader to believe a queue-level retry is happening when it is not.
        $this->assertSame(1, $job->tries);
        $this->assertSame(
            1,
            $consumer['tries'],
            "The supervisor consuming [{$queueName}] retries by default, which disagrees with the job's own "
            .'$tries = 1 and would mislead a reader of the config into expecting a queue-level retry.'
        );
    }

    private function uploadSoc2(): Document
    {
        $type = DocumentType::query()->availableTo()->where('code', 'soc2_type2')->firstOrFail();

        return app(EvidenceService::class)->store(
            UploadedFile::fake()->createWithContent('soc2.pdf', 'report body'),
            Document::OWNER_ENGAGEMENT,
            $this->engagement->id,
            $type,
            ['title' => 'SOC 2 report'],
            $this->user->id,
        );
    }

    #[Test]
    public function requesting_extraction_dispatches_a_job_and_creates_a_job_run_instead_of_running_inline(): void
    {
        Queue::fake();
        $document = $this->uploadSoc2();

        $response = $this->actingAs($this->user)->post(route('tprm.documents.extract', $document));

        $response->assertRedirect();
        Queue::assertPushed(RunTprmDocumentExtraction::class, fn ($job) => $job->documentId === $document->id);

        $this->assertSame(1, JobRun::withoutGlobalScopes()
            ->where('job_class', RunTprmDocumentExtraction::class)
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->id)
            ->count());

        // No extraction row exists yet — nothing ran inline, and the queued
        // job has not been processed by this fake.
        $this->assertSame(0, \App\Models\Tprm\DocumentExtraction::query()->count());
    }

    /**
     * Gate 1 (QA restart cycle) — verifying Gate 2's blocking defect 2 fix:
     * `DocumentController::outstandingExtractionJobRunId()` used to read
     * `JobRun::withoutGlobalScopes()`, "safe only because" the resolved
     * `$document` was assumed to already be tenant-scoped — the exact
     * argument shape Gate 1 has rejected before (`WidgetQueryEngine`). This
     * forces the scenario that argument quietly assumed away: a `JobRun` row
     * that names this tenant's document as its subject but belongs to a
     * DIFFERENT organisation (a data anomaly, or a future bug elsewhere).
     * With the fix in place the ordinary tenant scope excludes it and a
     * fresh job is dispatched; reintroducing `withoutGlobalScopes()` here
     * would make this test fail by treating the other tenant's row as
     * "already reading" and refusing to queue a new one.
     */
    #[Test]
    public function another_tenants_job_run_on_the_same_subject_id_does_not_block_a_fresh_dispatch(): void
    {
        Queue::fake();
        $document = $this->uploadSoc2();

        $otherOrg = Organization::create([
            'name' => 'Sokoto Trust Bank', 'short_name' => 'STB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        JobRun::create([
            'organization_id' => $otherOrg->id,
            'job_class' => RunTprmDocumentExtraction::class,
            'label' => 'Reading someone else\'s document',
            'subject_type' => $document->getMorphClass(),
            'subject_id' => $document->id,
            'status' => JobRun::STATUS_QUEUED,
        ]);

        $response = $this->actingAs($this->user)->post(route('tprm.documents.extract', $document));

        $response->assertRedirect();
        $response->assertSessionHas('info', fn ($message) => ! str_contains((string) $message, 'Already reading'));

        Queue::assertPushed(RunTprmDocumentExtraction::class, fn ($job) => $job->documentId === $document->id);

        $this->assertSame(2, JobRun::withoutGlobalScopes()
            ->where('job_class', RunTprmDocumentExtraction::class)
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->id)
            ->count(), 'Expected the other tenant\'s row plus a fresh one for this tenant.');
    }

    #[Test]
    public function the_job_completes_and_records_the_manual_reason_when_ai_is_off(): void
    {
        $document = $this->uploadSoc2();

        $jobRun = RunTprmDocumentExtraction::track(
            label: 'Reading '.$document->title,
            subject: $document,
            organizationId: $document->organization_id,
            creator: $this->user,
        );

        (new RunTprmDocumentExtraction($document->id, $jobRun->id))
            ->handle(app(\App\Services\Tprm\Extraction\ExtractionDispatcher::class));

        $jobRun->refresh();
        $this->assertSame(JobRun::STATUS_COMPLETED, $jobRun->status);
        $this->assertStringContainsString('by hand', (string) $jobRun->message);
        $this->assertSame('unavailable', $document->fresh()->extraction_status);
    }

    #[Test]
    public function the_job_reports_the_document_no_longer_existing_rather_than_throwing(): void
    {
        $jobRun = JobRun::create([
            'organization_id' => $this->organization->id,
            'job_class' => RunTprmDocumentExtraction::class,
            'label' => 'Reading a deleted document',
            'status' => JobRun::STATUS_QUEUED,
        ]);

        (new RunTprmDocumentExtraction(999999, $jobRun->id))
            ->handle(app(\App\Services\Tprm\Extraction\ExtractionDispatcher::class));

        $jobRun->refresh();
        $this->assertSame(JobRun::STATUS_FAILED, $jobRun->status);
    }
}
