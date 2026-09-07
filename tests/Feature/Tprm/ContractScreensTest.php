<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\ClausePresence;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\ClauseLibraryEntry;
use App\Models\Tprm\Contract;
use App\Models\Tprm\ContractClause;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Obligation;
use App\Models\Tprm\PciResponsibility;
use App\Models\Tprm\Sla;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Contracts\ClauseAnalyzer;
use App\Services\Tprm\Contracts\ClauseResolver;
use App\Services\Tprm\Contracts\ContractService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 4 screens, at prop level per standard §10.
 *
 * The three that matter: the contract workspace has to hand the page the
 * activation verdict with its named clauses (a disabled button and a tooltip
 * would not be AC-06), the notice figures have to be the notice figures rather
 * than the expiry ones, and the PCI export has to carry the confirmation state
 * of every row.
 */
class ContractScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('tprm.ai.enabled', false);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->manager = $this->userWith([
            'tprm.view', 'tprm.contract.view', 'tprm.contract.manage', 'tprm.waiver.approve',
        ], 'manager@khb.test', 'tprm-contract-manager');

        $this->engagement = $this->makeEngagement();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The register */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_register_counts_notice_deadlines_rather_than_expiries(): void
    {
        // Expiring in 200 days with a 150-day notice period: the decision is
        // 50 days away. A register counting expiries would show nothing due.
        $contract = $this->executedContract();
        $contract->forceFill([
            'expiry_date' => now()->addDays(200)->toDateString(),
            'notice_period_days_entity' => 150,
        ])->save();

        $this->actingAs($this->manager)
            ->get(route('tprm.contracts.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Contracts/Index')
                ->where('summary.notice_due_90', 1)
                ->where('summary.in_force', 1)
                ->where('summary.unanalysed', 1)
                ->has('grid.rows.data', 1)
            );
    }

    #[Test]
    public function an_unanalysed_contract_is_not_counted_as_having_no_gaps(): void
    {
        // "Not analysed" and "no gaps" must not look alike: a contract nobody
        // has read has unknown gaps, not none.
        $this->executedContract();

        $this->actingAs($this->manager)
            ->get(route('tprm.contracts.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.unanalysed', 1)
                ->where('summary.blocking_gaps', 0)
            );
    }

    /* ------------------------------------------------------------------ */
    /*  The workspace */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_workspace_hands_the_page_the_activation_verdict_with_named_clauses(): void
    {
        $contract = $this->executedContract();
        $this->determineAll($contract, except: ['CBN-CYB-05']);

        $this->actingAs($this->manager)
            ->get(route('tprm.contracts.show', $contract))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('Tprm/Contracts/Show')
                    ->where('activation.allowed', false)
                    ->has('activation.blocking_clauses', 1);

                $props = $page->toArray()['props'];
                $this->assertSame('CBN-CYB-05', $props['activation']['blocking_clauses'][0]['code']);
                $this->assertSame('CBN Cyber 2024 §2.3(v)', $props['activation']['blocking_clauses'][0]['citation']);
                // The guidance goes with it, because a block with no route
                // forward is a block a user routes around.
                $this->assertNotEmpty($props['activation']['blocking_clauses'][0]['guidance']);
            });
    }

    #[Test]
    public function the_workspace_states_the_notice_deadline_and_whether_the_window_is_gone(): void
    {
        $contract = $this->executedContract();
        $contract->forceFill([
            'expiry_date' => now()->addDays(60)->toDateString(),
            'notice_period_days_entity' => 90,
            'renewal_type' => 'auto',
        ])->save();

        $this->actingAs($this->manager)
            ->get(route('tprm.contracts.show', $contract))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('contract.notice_deadline', now()->addDays(60)->subDays(90)->toDateString())
                ->where('contract.days_until_notice', -30)
                ->where('contract.notice_window_missed', true)
                ->where('contract.renews_automatically', true)
            );
    }

    #[Test]
    public function the_workspace_lists_the_clause_set_with_what_settled_each_one(): void
    {
        $msa = $this->executedContract();
        $this->determineAll($msa);

        $amendment = app(ContractService::class)->create($this->engagement, [
            'contract_type' => 'amendment',
            'title' => 'Amendment 1',
            'parent_contract_id' => $msa->id,
            'status' => Contract::STATUS_EXECUTED,
            'effective_date' => now()->subDay()->toDateString(),
        ], $this->manager->id);

        app(ClauseAnalyzer::class)->record(
            $amendment,
            $this->clause('CBN-CYB-03'),
            ClausePresence::Present,
            'Security measures meet the Bank\'s programme objectives.',
            'Clause 2',
            $this->manager->id,
        );

        $this->actingAs($this->manager)
            ->get(route('tprm.contracts.show', $msa))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($amendment) {
                $clauses = collect($page->toArray()['props']['clauses']['clauses']);

                $this->assertSame($amendment->reference, $clauses->firstWhere('code', 'CBN-CYB-03')['determined_by']);
                $this->assertTrue($clauses->firstWhere('code', 'CBN-CYB-05')['satisfied']);
            });
    }

    #[Test]
    public function the_workspace_says_when_automatic_analysis_is_unavailable(): void
    {
        $this->actingAs($this->manager)
            ->get(route('tprm.contracts.show', $this->executedContract()))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('capabilities.ai_clause_analysis', false)
            );
    }

    /* ------------------------------------------------------------------ */
    /*  The gap report */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_gap_report_renders_as_a_pdf(): void
    {
        $contract = $this->executedContract();
        $this->determineAll($contract, except: ['CBN-CYB-05']);

        $response = $this->actingAs($this->manager)
            ->get(route('tprm.contracts.gap-report', $contract));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /* ------------------------------------------------------------------ */
    /*  The two-step over HTTP */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function recording_a_determination_and_then_waiving_clears_the_gate(): void
    {
        $contract = $this->executedContract();
        $this->determineAll($contract, except: ['CBN-CYB-05']);

        $row = ContractClause::query()
            ->where('contract_id', $contract->id)
            ->where('clause_library_id', $this->clause('CBN-CYB-05')->id)
            ->firstOrFail();

        // A waiver with no rationale is unreviewable by the committee it is
        // reported to, so the request refuses it.
        $this->actingAs($this->manager)
            ->post(route('tprm.contracts.clauses.waive', [$contract, $row]), [
                'rationale' => 'too short',
                'expires_at' => now()->addMonths(3)->toDateString(),
            ])
            ->assertSessionHasErrors('rationale');

        $this->actingAs($this->manager)
            ->post(route('tprm.contracts.clauses.waive', [$contract, $row]), [
                'rationale' => 'Audit rights are in negotiation; the provider has agreed in principle and the '
                    .'amendment is with their counsel.',
                'expires_at' => now()->addMonths(3)->toDateString(),
                'approver_role' => 'Chief Risk Officer',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->manager)
            ->get(route('tprm.contracts.show', $contract))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('activation.allowed', true));
    }

    #[Test]
    public function generating_obligations_from_the_workspace_puts_them_on_the_register(): void
    {
        $contract = $this->executedContract();
        $this->determineAll($contract);

        $this->actingAs($this->manager)
            ->post(route('tprm.contracts.obligations.generate', $contract))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertGreaterThan(0, Obligation::query()->where('contract_id', $contract->id)->count());

        $this->actingAs($this->manager)
            ->get(route('tprm.obligations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Obligations/Index')
                ->where('summary.ours', fn ($value) => $value > 0)
                ->where('summary.theirs', fn ($value) => $value > 0)
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Service levels and the PCI matrix */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_sla_screen_reports_missing_periods_beside_the_trend(): void
    {
        Sla::create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $this->engagement->id,
            'metric_code' => 'AVAIL', 'metric_name' => 'Service availability', 'unit' => '%',
            'target_operator' => 'gte', 'target_value' => 99.9, 'measurement_window' => 'monthly',
            'created_by' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->get(route('tprm.slas.index', $this->engagement))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Slas/Index')
                ->has('slas', 1)
                ->where('slas.0.target', 'at least 99.9 %')
                // Nothing recorded at all, so every month in the window is a
                // gap — a provider that never reported is not a compliant one.
                ->has('slas.0.missing_periods', 12)
            );
    }

    #[Test]
    public function the_pci_matrix_reports_every_requirement_and_its_confirmation_state(): void
    {
        $this->actingAs($this->manager)
            ->get(route('tprm.pci-matrix.show', $this->engagement))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Pci/Matrix')
                ->where('matrix.total', 19)
                ->where('matrix.confirmed', 0)
                ->where('matrix.export_ready', false)
            );
    }

    #[Test]
    public function the_pci_export_marks_the_rows_nobody_confirmed(): void
    {
        // A matrix presented as agreed when a third of it is the vendor's
        // unreviewed opinion is worse than no matrix, because a QSA relies
        // on it.
        $this->actingAs($this->manager)->get(route('tprm.pci-matrix.show', $this->engagement));

        $row = PciResponsibility::query()->where('pci_requirement', '3')->firstOrFail();
        $this->actingAs($this->manager)->post(route('tprm.pci-matrix.confirm', [$this->engagement, $row]), [
            'responsibility' => 'tpsp',
            'notes' => 'The provider stores the PAN.',
        ])->assertRedirect();

        $response = $this->actingAs($this->manager)->get(route('tprm.pci-matrix.export', $this->engagement));
        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Not confirmed', $csv);
        $this->assertStringContainsString('have not been agreed with the provider', $csv);
        $this->assertStringContainsString('Service provider', $csv);
    }

    /* ------------------------------------------------------------------ */
    /*  The clause library */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_library_screen_shows_what_each_clause_puts_on_the_register(): void
    {
        // The answer to "why did approving this contract create four tasks".
        $this->actingAs($this->manager)
            ->get(route('tprm.clauses.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('Tprm/Settings/ClauseLibrary')
                    ->where('summary.total', 20)
                    ->where('summary.system_owned', 20);

                $clauses = collect($page->toArray()['props']['clauses']);
                $auditRights = $clauses->firstWhere('code', 'CBN-CYB-05');

                $this->assertTrue($auditRights['is_system_owned']);
                $this->assertSame(['model_text', 'guidance'], $auditRights['editable_fields']);
                $this->assertCount(2, $auditRights['obligations']);
                // The shipped library carries no wording, and the screen says
                // so rather than leaving a reader to think it is a bug.
                $this->assertFalse($auditRights['has_model_text']);
            });
    }

    #[Test]
    public function a_tenant_can_supply_model_text_but_not_change_a_citation(): void
    {
        $clause = $this->clause('CBN-CYB-05');

        $this->actingAs($this->manager)
            ->put(route('tprm.clauses.update', $clause), [
                'model_text' => 'The Provider shall permit the Bank and its regulators to audit...',
                'citation' => 'Something we made up',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $clause->refresh();
        $this->assertStringStartsWith('The Provider shall permit', $clause->model_text);
        $this->assertSame('CBN Cyber 2024 §2.3(v)', $clause->citation);
    }

    #[Test]
    public function a_system_clause_cannot_be_deleted_through_the_screen_either(): void
    {
        $this->actingAs($this->manager)
            ->delete(route('tprm.clauses.destroy', $this->clause('CBN-CYB-05')))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('tp_clause_library', ['code' => 'CBN-CYB-05']);
    }

    /* ------------------------------------------------------------------ */
    /*  Permissions */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function waiving_a_clause_needs_the_risk_functions_permission_and_not_merely_contract_management(): void
    {
        // Waiving admits a vendor a required term does not cover, and lands on
        // the register the risk committee reads. It is not the same act as
        // recording the contract.
        $viewer = $this->userWith(['tprm.contract.view', 'tprm.contract.manage'], 'clerk@khb.test', 'tprm-clerk');

        $contract = $this->executedContract();
        $this->determineAll($contract, except: ['CBN-CYB-05']);

        $row = ContractClause::query()
            ->where('contract_id', $contract->id)
            ->where('clause_library_id', $this->clause('CBN-CYB-05')->id)
            ->firstOrFail();

        $this->actingAs($viewer)
            ->post(route('tprm.contracts.clauses.waive', [$contract, $row]), [
                'rationale' => 'A rationale long enough to pass the minimum length requirement.',
                'expires_at' => now()->addMonth()->toDateString(),
            ])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */

    private function clause(string $code): ClauseLibraryEntry
    {
        return ClauseLibraryEntry::query()->availableTo()->where('code', $code)->firstOrFail();
    }

    private function executedContract(): Contract
    {
        return app(ContractService::class)->create($this->engagement, [
            'contract_type' => 'msa',
            'title' => 'Master services agreement',
            'status' => Contract::STATUS_EXECUTED,
            'effective_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addMonths(8)->toDateString(),
            'notice_period_days_entity' => 90,
            'renewal_type' => 'auto',
        ], $this->manager->id);
    }

    /** @param list<string> $except */
    private function determineAll(Contract $contract, array $except = []): void
    {
        $resolution = app(ClauseResolver::class)->resolve($this->engagement->fresh(), $contract);

        foreach ($resolution->applicable as $clause) {
            app(ClauseAnalyzer::class)->record(
                $contract,
                $clause,
                in_array($clause->code, $except, true) ? ClausePresence::Absent : ClausePresence::Present,
                'Agreed term',
                'Clause 1',
                $this->manager->id,
            );
        }
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email, string $roleName): User
    {
        $user = User::create([
            'name' => Str::of($roleName)->afterLast('-')->ucfirst()->toString(),
            'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate($roleName, 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }

    private function makeEngagement(): Engagement
    {
        $vendor = ThirdParty::create([
            'legal_name' => 'Cloudspan Nigeria Limited', 'slug' => Str::random(10), 'entity_type' => 'company',
        ]);

        return Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-0001',
            'name' => 'Core banking hosting',
            'service_description' => 'Hosting and operation of the core banking platform.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->manager->id,
        ])->refresh();
    }
}
