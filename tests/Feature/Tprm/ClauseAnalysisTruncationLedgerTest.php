<?php

namespace Tests\Feature\Tprm;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Contract;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\FileUploadService;
use App\Services\Tprm\Contracts\ClauseAnalyzer;
use App\Services\Tprm\Contracts\ContractService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * AC 24, phase-11a-ai-contract.md §8.24 / ADR 0015 §6e (deviation 10).
 *
 * `ClauseAnalyzer::analyse()` writes a `clause_analysis_partial` row to
 * `tp_audit_logs` whenever the contract document exceeded `clause_analysis`'s
 * `max_document_chars` cap (4,800), and leaves
 * `tp_contracts.clause_analysis_status = 'analysed_partial'`. A run UNDER the
 * cap writes no such row and leaves `analysed`. This is a gate-1 addition —
 * no test previously drove `ClauseAnalyzer::analyse()` at all; every existing
 * TPRM contract test only exercised the manual `record()`/`review()` paths.
 */
class ClauseAnalysisTruncationLedgerTest extends TestCase
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
        config()->set('tprm.ai.services.clause_analysis', true);
        config()->set('tprm.ai.implemented_services', ['clause_analysis']);
        config()->set('llm.retry.max_attempts', 1);
        config()->set('llm.breaker.failure_threshold', 3);
        config()->set('llm.breaker.open_seconds', 60);
        config()->set('llm.default_profile', 'local-ollama');
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://localhost:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);

        Storage::fake('local');

        $this->organization = Organization::create([
            'name' => 'Aba Metropolitan Bank', 'short_name' => 'AMB',
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
            'name' => 'Contract Reviewer', 'email' => 'reviewer@amb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $vendor = ThirdParty::create([
            'legal_name' => 'Aba Cloud Services Limited', 'slug' => Str::random(10), 'entity_type' => 'company',
        ]);

        $this->engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-0512',
            'name' => 'Core banking hosting',
            'service_description' => 'Hosting.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->reviewer->id,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    private function contractWithDocument(string $content): Contract
    {
        $contract = app(ContractService::class)->create($this->engagement, [
            'contract_type' => 'msa',
            'title' => 'Master services agreement',
            'status' => Contract::STATUS_EXECUTED,
            'effective_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addMonths(8)->toDateString(),
            'notice_period_days_entity' => 90,
            'renewal_type' => 'auto',
        ], $this->reviewer->id);

        $path = 'tprm/'.Str::random(20).'.txt';
        Storage::disk(FileUploadService::DISK)->put($path, $content);

        $document = Document::create([
            'organization_id' => $this->organization->id,
            'owner_type' => Document::OWNER_ENGAGEMENT,
            'owner_id' => $this->engagement->id,
            'title' => 'Executed MSA',
            'file_path' => $path,
            'mime' => 'text/plain',
            'uploaded_by' => $this->reviewer->id,
        ]);

        $contract->forceFill(['document_id' => $document->id])->save();

        return $contract->fresh();
    }

    private function fakeModel(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response([
                'response' => json_encode(['clauses' => []]),
                'prompt_eval_count' => 100,
                'eval_count' => 20,
            ], 200),
        ]);
    }

    #[Test]
    public function a_document_over_the_clause_analysis_cap_leaves_a_partial_status_and_exactly_one_ledger_row(): void
    {
        $this->fakeModel();

        // clause_analysis's max_document_chars is 4,800 — comfortably over it.
        $contract = $this->contractWithDocument(str_repeat("Clause text paragraph.\n\n", 400));

        $outcome = app(ClauseAnalyzer::class)->analyse($contract, $this->reviewer->id);

        $this->assertSame('analysed_partial', $outcome->state, 'sanity: analyse() must have run to completion, not skipped/manual.');

        $fresh = $contract->fresh();
        $this->assertSame(Contract::CLAUSE_ANALYSIS_ANALYSED_PARTIAL, $fresh->clause_analysis_status);

        $rows = $fresh->auditLogs()->where('event', 'clause_analysis_partial')->get();
        $this->assertCount(1, $rows, 'Exactly one clause_analysis_partial ledger row.');

        $after = $rows->first()->after;
        $this->assertSame('clause_analysis', $after['prompt_key']);
        $this->assertSame('clause_analysis.v1', $after['prompt_version']);
        $this->assertSame(4800, $after['cap']);
        $this->assertGreaterThan(4800, $after['original_length']);
        $this->assertArrayHasKey('detected', $after);
        $this->assertArrayHasKey('applicable', $after);
        $this->assertGreaterThan(0, $after['applicable']);

        // auditable_type must be the FQCN (not a morph alias) — the relation
        // that just found this row joins on exactly that column.
        $this->assertSame(Contract::class, $rows->first()->auditable_type);
    }

    #[Test]
    public function a_document_under_the_cap_writes_no_ledger_row_and_leaves_the_status_analysed(): void
    {
        $this->fakeModel();

        $contract = $this->contractWithDocument('A short master services agreement, well under the cap.');

        app(ClauseAnalyzer::class)->analyse($contract, $this->reviewer->id);

        $fresh = $contract->fresh();
        $this->assertSame(Contract::CLAUSE_ANALYSIS_ANALYSED, $fresh->clause_analysis_status);

        $rows = $fresh->auditLogs()->where('event', 'clause_analysis_partial')->get();
        $this->assertCount(0, $rows, 'A run under the cap must write no truncation ledger row.');
    }

    #[Test]
    public function the_numbers_survive_a_fresh_read_not_only_the_flash(): void
    {
        $this->fakeModel();

        $contract = $this->contractWithDocument(str_repeat("Clause text paragraph.\n\n", 400));

        app(ClauseAnalyzer::class)->analyse($contract, $this->reviewer->id);

        // Re-fetch the contract and its ledger row entirely fresh, as a page
        // reload would — never reading anything returned by analyse() itself.
        $reloaded = Contract::query()->findOrFail($contract->getKey());
        $row = $reloaded->auditLogs()->where('event', 'clause_analysis_partial')->firstOrFail();

        $this->assertSame(Contract::CLAUSE_ANALYSIS_ANALYSED_PARTIAL, $reloaded->clause_analysis_status);
        $this->assertSame(4800, $row->after['cap']);
    }
}
