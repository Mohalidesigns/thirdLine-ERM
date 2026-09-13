<?php

namespace Tests\Feature\Tprm;

use App\Jobs\RunTprmDocumentExtraction;
use App\Models\LlmUsageEvent;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Evidence\EvidenceService;
use App\Services\Tprm\Extraction\ExtractionDispatcher;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate 1 restart cycle — blocking defect 3: `llm_usage_events.user_id` had no
 * writer, so a real, human-initiated extraction recorded a null actor and the
 * usage grid printed "Scheduled" for work a person actually did. This drives
 * a REAL extraction — upload, dispatch, job `handle()` — end to end and
 * asserts the row and the grid the way an administrator would actually see
 * them, rather than asserting only that `LlmClient::run()` accepts a
 * `$userId` argument somewhere in the middle of the chain.
 */
class DocumentExtractionUserAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $reviewer;

    private User $aiAdmin;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('services.llm.enabled', true);
        config()->set('tprm.ai.enabled', true);
        config()->set('tprm.ai.services.evidence_extraction', true);
        config()->set('tprm.ai.implemented_services', ['evidence_extraction']);
        config()->set('llm.default_profile', 'local-ollama');
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://localhost:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);

        Storage::fake('local');

        $this->organization = Organization::create([
            'name' => 'Aba Trust Bank', 'short_name' => 'ATB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->reviewer = User::create([
            'name' => 'Chidinma Reviewer', 'email' => 'chidinma@atb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-evidence-reviewer-ua', 'web');
        foreach (['tprm.evidence.view', 'tprm.evidence.upload', 'tprm.evidence.confirm'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->reviewer->assignRole($role);

        $this->aiAdmin = User::create([
            'name' => 'AI Admin', 'email' => 'aiadmin@atb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
        $adminRole = Role::findOrCreate('tprm-ai-admin-ua', 'web');
        $adminRole->givePermissionTo(Permission::findOrCreate('tprm.admin', 'web'));
        $this->aiAdmin->assignRole($adminRole);

        $vendor = ThirdParty::create([
            'legal_name' => 'Nsukka Data Centres Limited', 'slug' => Str::random(10), 'entity_type' => 'company',
        ]);

        $this->engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-0199',
            'name' => 'Colocation hosting',
            'service_description' => 'Hosting.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->reviewer->id,
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
            UploadedFile::fake()->createWithContent('soc2.txt', 'This SOC 2 Type II report covers the period 2026-01-01 to 2026-06-30, audited by Big Firm LLP.'),
            Document::OWNER_ENGAGEMENT,
            $this->engagement->id,
            $type,
            ['title' => 'SOC 2 report'],
            $this->reviewer->id,
        );
    }

    #[Test]
    public function a_real_human_initiated_extraction_records_the_actor_on_the_usage_row(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response([
                'response' => json_encode([
                    'report_type' => 'Type II', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
                    'service_auditor' => 'Big Firm LLP', 'tsc_categories' => ['security'],
                    'exceptions' => [], 'cuecs' => [], 'subservice_orgs' => [],
                ]),
                'prompt_eval_count' => 40, 'eval_count' => 20,
            ], 200),
        ]);

        $document = $this->uploadSoc2();

        // The real controller path: creates a JobRun with created_by set from
        // the ACTING user, exactly the way `RunTprmDocumentExtraction::track()`
        // is invoked from `DocumentController::extract()`.
        $jobRun = RunTprmDocumentExtraction::track(
            label: 'Reading '.$document->title,
            subject: $document,
            organizationId: $document->organization_id,
            creator: $this->reviewer,
        );

        $this->assertSame($this->reviewer->id, $jobRun->created_by);

        // Run the job body directly (queue not faked) — the actual work a
        // worker would perform, including the real gateway call.
        (new RunTprmDocumentExtraction($document->id, $jobRun->id))
            ->handle(app(ExtractionDispatcher::class));

        $row = LlmUsageEvent::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->id)
            ->firstOrFail();

        $this->assertNotNull($row->user_id, 'A human-initiated extraction must not record a null actor.');
        $this->assertSame($this->reviewer->id, $row->user_id);

        // The grid an administrator actually reads must show the person, not
        // the "Scheduled"/fallback label the defect produced.
        $gridResponse = $this->actingAs($this->aiAdmin)->get(route('tprm.settings.ai.usage'));
        $gridResponse->assertOk();

        $rows = $gridResponse->original->getData()['page']['props']['grid']['rows']['data'];
        $userColumnValues = collect($rows)->map(fn ($row) => $row['cells']['user_id']['text'] ?? null);

        $this->assertTrue(
            $userColumnValues->contains(fn ($value) => is_string($value) && str_contains($value, $this->reviewer->name)),
            'Expected the recent-calls grid to name the reviewer, not "Not recorded"/"Scheduled". Got: '.json_encode($userColumnValues->all())
        );
    }

    #[Test]
    public function a_worker_run_with_no_creator_on_the_job_run_records_no_actor_and_the_grid_says_not_recorded(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response([
                'response' => json_encode(['report_type' => 'Type II']),
                'prompt_eval_count' => 10, 'eval_count' => 5,
            ], 200),
        ]);

        $document = $this->uploadSoc2();

        // A JobRun with no creator at all — the "genuinely unattended" path
        // the phase-11a contract distinguishes from "nobody recorded who".
        $jobRun = \App\Models\JobRun::create([
            'organization_id' => $this->organization->id,
            'job_class' => RunTprmDocumentExtraction::class,
            'label' => 'Reading '.$document->title,
            'subject_type' => $document->getMorphClass(),
            'subject_id' => $document->id,
            'status' => \App\Models\JobRun::STATUS_QUEUED,
        ]);

        $this->assertNull($jobRun->created_by);

        (new RunTprmDocumentExtraction($document->id, $jobRun->id))
            ->handle(app(ExtractionDispatcher::class));

        $row = LlmUsageEvent::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->id)
            ->firstOrFail();

        $this->assertNull($row->user_id);

        $gridResponse = $this->actingAs($this->aiAdmin)->get(route('tprm.settings.ai.usage'));
        $rows = $gridResponse->original->getData()['page']['props']['grid']['rows']['data'];
        $userColumnValues = collect($rows)->map(fn ($row) => $row['cells']['user_id']['text'] ?? null);

        $this->assertTrue(
            $userColumnValues->contains('Not recorded'),
            'A genuinely unattributed row must say "Not recorded", never a guessed actor.'
        );
    }
}
