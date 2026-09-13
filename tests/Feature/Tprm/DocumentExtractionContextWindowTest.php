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
 * AC 19, phase-11a-ai-contract.md §2.5 / ADR 0015 §6d.
 *
 * `_meta.context_window` is written on every persisted extraction, and
 * `fitted` is the three-valued truth table computed server-side in
 * `ExtractionDispatcher::contextWindowMeta()`: TRUE only on a strict `<`,
 * FALSE on `>=`, and NULL — never coerced to false, never to 0 — for both
 * "no num_ctx declared" and "no prompt_eval_count reported". Each of the
 * four cells is driven through a REAL extraction (upload, dispatch, job
 * handle()) rather than asserted by calling the private method directly, so
 * a regression anywhere in the five hops of contract §2.5 — config, driver,
 * gateway, LlmResult, dispatcher — is caught, not just a regression in the
 * dispatcher's own arithmetic.
 */
class DocumentExtractionContextWindowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $reviewer;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('services.llm.enabled', true);
        config()->set('services.llm.endpoint', 'http://localhost:11434');
        config()->set('services.llm.model', 'granite4:micro');
        config()->set('services.llm.budgets.extraction', ['max_tokens' => 2048, 'timeout' => 120]);
        config()->set('tprm.ai.enabled', true);
        config()->set('tprm.ai.services.evidence_extraction', true);
        config()->set('tprm.ai.implemented_services', ['evidence_extraction']);
        config()->set('llm.retry.max_attempts', 1);
        config()->set('llm.breaker.failure_threshold', 3);
        config()->set('llm.breaker.open_seconds', 60);
        config()->set('llm.context.num_ctx', 4096);
        config()->set('llm.default_profile', 'local-ollama');
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://localhost:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);

        Storage::fake('local');

        $this->organization = Organization::create([
            'name' => 'Owerri Community Bank', 'short_name' => 'OCB',
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
            'name' => 'Ifeoma Reviewer', 'email' => 'ifeoma@ocb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-evidence-reviewer-cw', 'web');
        foreach (['tprm.evidence.view', 'tprm.evidence.upload', 'tprm.evidence.confirm'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->reviewer->assignRole($role);

        $vendor = ThirdParty::create([
            'legal_name' => 'Owerri Colocation Limited', 'slug' => Str::random(10), 'entity_type' => 'company',
        ]);

        $this->engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-0311',
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

    private function uploadSoc2(string $filename): Document
    {
        $type = DocumentType::query()->availableTo()->where('code', 'soc2_type2')->firstOrFail();

        return app(EvidenceService::class)->store(
            UploadedFile::fake()->createWithContent($filename, 'This SOC 2 Type II report covers the period 2026-01-01 to 2026-06-30, audited by Big Firm LLP.'),
            Document::OWNER_ENGAGEMENT,
            $this->engagement->id,
            $type,
            ['title' => 'SOC 2 report '.$filename],
            $this->reviewer->id,
        );
    }

    private function extractionMetaFor(Document $document): array
    {
        $jobRun = JobRun::create([
            'organization_id' => $this->organization->id,
            'job_class' => RunTprmDocumentExtraction::class,
            'label' => 'Reading '.$document->title,
            'subject_type' => $document->getMorphClass(),
            'subject_id' => $document->id,
            'status' => JobRun::STATUS_QUEUED,
            'created_by' => $this->reviewer->id,
        ]);

        (new RunTprmDocumentExtraction($document->id, $jobRun->id))
            ->handle(app(ExtractionDispatcher::class));

        $extraction = $document->fresh()->extractions()->latest('id')->firstOrFail();

        return $extraction->extracted['_meta']['context_window'];
    }

    #[Test]
    public function fitted_is_true_when_prompt_tokens_are_strictly_below_the_declared_window(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response([
                'response' => json_encode([
                    'report_type' => 'Type II', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
                    'service_auditor' => 'Big Firm LLP', 'tsc_categories' => ['security'],
                    'exceptions' => [], 'cuecs' => [], 'subservice_orgs' => [],
                ]),
                'prompt_eval_count' => 2272, 'eval_count' => 300,
            ], 200),
        ]);

        $meta = $this->extractionMetaFor($this->uploadSoc2('soc2-fitted-true.txt'));

        $this->assertSame(4096, $meta['num_ctx']);
        $this->assertSame(2272, $meta['prompt_tokens']);
        $this->assertTrue($meta['fitted'], 'prompt_tokens strictly below num_ctx must prove the server did not truncate.');
    }

    #[Test]
    public function fitted_is_false_when_prompt_tokens_meet_or_exceed_the_declared_window(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response([
                'response' => json_encode([
                    'report_type' => 'Type II', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
                    'service_auditor' => 'Big Firm LLP', 'tsc_categories' => ['security'],
                    'exceptions' => [], 'cuecs' => [], 'subservice_orgs' => [],
                ]),
                // Exactly AT the window — proves nothing (indistinguishable
                // from a much larger prompt cut down to fit), so this must be
                // false, never true.
                'prompt_eval_count' => 4096, 'eval_count' => 300,
            ], 200),
        ]);

        $meta = $this->extractionMetaFor($this->uploadSoc2('soc2-fitted-false.txt'));

        $this->assertSame(4096, $meta['num_ctx']);
        $this->assertSame(4096, $meta['prompt_tokens']);
        $this->assertFalse($meta['fitted'], 'prompt_tokens >= num_ctx must never read as fitted — >= proves nothing, and must not be treated as true.');
    }

    #[Test]
    public function fitted_is_null_never_false_when_the_backend_reports_no_prompt_token_count(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response([
                'response' => json_encode([
                    'report_type' => 'Type II', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
                    'service_auditor' => 'Big Firm LLP', 'tsc_categories' => ['security'],
                    'exceptions' => [], 'cuecs' => [], 'subservice_orgs' => [],
                ]),
                // No prompt_eval_count key at all — "nobody was told", a
                // third answer distinct from both true and false.
            ], 200),
        ]);

        $meta = $this->extractionMetaFor($this->uploadSoc2('soc2-fitted-null-tokens.txt'));

        $this->assertSame(4096, $meta['num_ctx']);
        $this->assertNull($meta['prompt_tokens']);
        $this->assertNull($meta['fitted'], 'An unreported prompt-token count must yield NULL, never false and never 0.');
        $this->assertNotSame(false, $meta['fitted'], 'null must not be loosely confused with false by a future refactor.');
    }

    #[Test]
    public function fitted_is_null_never_false_when_no_context_window_was_declared(): void
    {
        config()->set('llm.context.num_ctx', null);

        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response([
                'response' => json_encode([
                    'report_type' => 'Type II', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
                    'service_auditor' => 'Big Firm LLP', 'tsc_categories' => ['security'],
                    'exceptions' => [], 'cuecs' => [], 'subservice_orgs' => [],
                ]),
                'prompt_eval_count' => 2272, 'eval_count' => 300,
            ], 200),
        ]);

        $meta = $this->extractionMetaFor($this->uploadSoc2('soc2-fitted-null-window.txt'));

        $this->assertNull($meta['num_ctx']);
        $this->assertSame(2272, $meta['prompt_tokens']);
        $this->assertNull($meta['fitted'], 'With nothing declared to compare against, nothing can be concluded — never false.');
    }
}
