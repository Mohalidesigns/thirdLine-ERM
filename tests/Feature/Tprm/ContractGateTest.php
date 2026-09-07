<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\ClausePresence;
use App\Enums\Tprm\EngagementStatus;
use App\Exceptions\Tprm\BlockingClauseException;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\ClauseLibraryEntry;
use App\Models\Tprm\Contract;
use App\Models\Tprm\ContractClause;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\Tprm\Waiver;
use App\Models\User;
use App\Services\Tprm\Contracts\ActivationGuard;
use App\Services\Tprm\Contracts\ClauseAnalyzer;
use App\Services\Tprm\Contracts\ClauseResolver;
use App\Services\Tprm\Contracts\ContractService;
use App\Services\Tprm\IntakeService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * AC-06 — the blocking-clause gate, and the clause resolution it rests on.
 *
 * "Uploading a contract missing `CBN-CYB-05` yields a gap report naming the
 * clause, its citation and model text, and blocks activation until waived."
 *
 * The tests are written around the two ways a gate like this fails in
 * practice: admitting a vendor it should have refused (an unreviewed machine
 * detection, a stale denormalised count, no contract at all), and refusing one
 * it should have admitted (a clause satisfied by an amendment the resolver did
 * not read, a clause that does not apply to this engagement).
 */
class ContractGateTest extends TestCase
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

        $this->user = User::create([
            'name' => 'Risk Officer', 'email' => 'risk@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $this->engagement = $this->makeEngagement();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  AC-06                                                              */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_contract_missing_the_audit_rights_clause_blocks_activation_and_names_it(): void
    {
        $contract = $this->executedContract();
        $this->satisfyAllExcept($contract, ['CBN-CYB-05']);

        $verdict = app(ActivationGuard::class)->check($this->engagement);

        $this->assertFalse($verdict->allowed);
        $this->assertCount(1, $verdict->blockingClauses);
        $this->assertSame('CBN-CYB-05', $verdict->blockingClauses[0]['code']);

        // The message is the feature, not the refusal. It has to name the
        // clause and cite the authority, or a user learns to find whoever can
        // override it instead of asking the vendor for the term.
        $this->assertStringContainsString('CBN-CYB-05', $verdict->reason);
        $this->assertStringContainsString('right to audit', $verdict->reason);
        $this->assertStringContainsString('CBN Cyber 2024 §2.3(v)', $verdict->reason);
    }

    #[Test]
    public function the_gate_refuses_the_transition_itself_and_not_only_a_check(): void
    {
        // On the transition path, not in a Form Request: the importer and the
        // API reach the transition without passing a validator.
        $contract = $this->executedContract();
        $this->satisfyAllExcept($contract, ['CBN-CYB-05']);
        $this->engagement->forceFill(['status' => EngagementStatus::Onboarding->value])->save();

        try {
            app(IntakeService::class)->transition($this->engagement, EngagementStatus::Active, $this->user->id);
            $this->fail('An engagement with a blocking clause gap was activated.');
        } catch (BlockingClauseException $exception) {
            $this->assertSame('CBN-CYB-05', $exception->details()[0]['code']);
        }

        $this->assertSame(EngagementStatus::Onboarding, $this->engagement->fresh()->status);
    }

    #[Test]
    public function an_approved_waiver_admits_the_engagement_and_the_gap_still_shows(): void
    {
        $contract = $this->executedContract();
        $this->satisfyAllExcept($contract, ['CBN-CYB-05']);

        $row = $this->clauseRow($contract, 'CBN-CYB-05');
        $row->forceFill(['waiver_id' => $this->approvedWaiver($row)->id])->save();

        $verdict = app(ActivationGuard::class)->check($this->engagement);
        $this->assertTrue($verdict->allowed, (string) $verdict->reason);

        // Waived is not satisfied. The clause is still a gap on every report —
        // it has been accepted by someone with the authority to accept it, for
        // a stated period, and that is a different fact from the contract
        // containing the term.
        $resolution = app(ClauseResolver::class)->resolve($this->engagement, $contract);
        $this->assertTrue($resolution->gaps()->contains(fn (array $g) => $g['clause']->code === 'CBN-CYB-05'));
        $this->assertTrue($resolution->isClear());
    }

    #[Test]
    public function a_lapsed_waiver_stops_admitting_the_engagement(): void
    {
        $contract = $this->executedContract();
        $this->satisfyAllExcept($contract, ['CBN-CYB-05']);

        $row = $this->clauseRow($contract, 'CBN-CYB-05');
        $waiver = $this->approvedWaiver($row);
        $row->forceFill(['waiver_id' => $waiver->id])->save();

        $this->assertTrue(app(ActivationGuard::class)->check($this->engagement)->allowed);

        $waiver->forceFill(['expires_at' => now()->subDay()->toDateString()])->save();

        $this->assertFalse(app(ActivationGuard::class)->check($this->engagement->fresh())->allowed);
    }

    #[Test]
    public function an_engagement_with_no_contract_is_refused_rather_than_waved_through(): void
    {
        // "Nothing to check" must not read as "nothing wrong", or the whole
        // gate is bypassed by not uploading the contract.
        $verdict = app(ActivationGuard::class)->check($this->engagement);

        $this->assertFalse($verdict->allowed);
        $this->assertStringContainsString('no executed contract', $verdict->reason);
    }

    #[Test]
    public function a_draft_contract_does_not_satisfy_the_gate(): void
    {
        $contract = $this->executedContract();
        $this->satisfyAll($contract);
        $this->assertTrue(app(ActivationGuard::class)->check($this->engagement)->allowed);

        $contract->forceFill(['status' => Contract::STATUS_DRAFT])->save();

        $this->assertFalse(app(ActivationGuard::class)->check($this->engagement->fresh())->allowed);
    }

    #[Test]
    public function an_unreviewed_machine_detection_does_not_open_the_gate(): void
    {
        // The failure this guard exists for: a model that reads "the Provider
        // shall not be obliged to permit audits" as an audit-rights clause has
        // produced exactly the output that would otherwise clear the gate.
        $contract = $this->executedContract();
        $this->satisfyAll($contract);

        $this->clauseRow($contract, 'CBN-CYB-05')->forceFill([
            'presence' => ClausePresence::Present->value,
            'detected_by' => ContractClause::DETECTED_BY_AI,
            'reviewer_status' => ContractClause::REVIEW_PENDING,
        ])->save();

        $this->assertFalse(app(ActivationGuard::class)->check($this->engagement)->allowed);

        app(ClauseAnalyzer::class)->review(
            $this->clauseRow($contract, 'CBN-CYB-05'),
            accept: true,
            userId: $this->user->id,
        );

        $this->assertTrue(app(ActivationGuard::class)->check($this->engagement->fresh())->allowed);
    }

    #[Test]
    public function a_partial_clause_blocks_and_is_described_as_partial(): void
    {
        // A notification duty with no timeframe is not the clause. Naming it
        // "partial" rather than "missing" is a shorter conversation with the
        // vendor and a shorter redline.
        $contract = $this->executedContract();
        $this->satisfyAll($contract);

        $this->clauseRow($contract, 'CBN-CYB-05')->forceFill([
            'presence' => ClausePresence::Partial->value,
            'reviewer_status' => ContractClause::REVIEW_ACCEPTED,
        ])->save();

        $verdict = app(ActivationGuard::class)->check($this->engagement);

        $this->assertFalse($verdict->allowed);
        $this->assertStringContainsString('stops short of the obligation', $verdict->reason);
    }

    /* ------------------------------------------------------------------ */
    /*  Applicability                                                      */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_clause_that_does_not_apply_is_not_a_gap(): void
    {
        // A report full of irrelevant gaps is a report nobody reads, and the
        // moment people ignore it the gate becomes an obstacle to route
        // around rather than a control.
        $resolution = app(ClauseResolver::class)->resolve($this->engagement);

        $codes = $resolution->applicable->pluck('code')->all();

        // The engagement processes no personal data and is not in PCI scope.
        $this->assertNotContains('NDPA-DPA-01', $codes);
        $this->assertNotContains('PCI-12.8.5', $codes);
        // Applies to everything.
        $this->assertContains('CBN-CYB-05', $codes);

        $this->assertTrue($resolution->inapplicable->contains(fn ($c) => $c->code === 'NDPA-DPA-01'));
    }

    #[Test]
    public function turning_on_personal_data_brings_the_ndpa_clauses_into_scope(): void
    {
        $this->engagement->forceFill(['processes_personal_data' => true])->save();

        $codes = app(ClauseResolver::class)->resolve($this->engagement->fresh())
            ->applicable->pluck('code')->all();

        $this->assertContains('NDPA-DPA-01', $codes);
    }

    /* ------------------------------------------------------------------ */
    /*  Amendment precedence                                               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_amendment_granting_a_clause_beats_the_master_agreements_silence(): void
    {
        // Reading the MSA alone would raise a finding against a term the
        // parties agreed in writing.
        $msa = $this->executedContract();
        $this->satisfyAllExcept($msa, ['CBN-CYB-05']);

        $this->assertFalse(app(ActivationGuard::class)->check($this->engagement)->allowed);

        $amendment = app(ContractService::class)->create($this->engagement, [
            'contract_type' => 'amendment',
            'title' => 'Amendment 1 — audit rights',
            'parent_contract_id' => $msa->id,
            'status' => Contract::STATUS_EXECUTED,
            'effective_date' => now()->subMonth()->toDateString(),
        ], $this->user->id);

        app(ClauseAnalyzer::class)->record(
            $amendment,
            $this->clause('CBN-CYB-05'),
            ClausePresence::Present,
            'The Bank and its regulators shall have the right to audit the Provider.',
            'Clause 4.2',
            $this->user->id,
        );

        $this->assertTrue(app(ActivationGuard::class)->check($this->engagement->fresh())->allowed);
    }

    #[Test]
    public function the_gap_report_names_which_document_settled_each_clause(): void
    {
        $msa = $this->executedContract();
        $this->satisfyAll($msa);

        $amendment = app(ContractService::class)->create($this->engagement, [
            'contract_type' => 'amendment',
            'title' => 'Amendment 1',
            'parent_contract_id' => $msa->id,
            'status' => Contract::STATUS_EXECUTED,
            'effective_date' => now()->subDay()->toDateString(),
        ], $this->user->id);

        app(ClauseAnalyzer::class)->record(
            $amendment,
            $this->clause('CBN-CYB-03'),
            ClausePresence::Present,
            'Security measures shall meet the Bank\'s programme objectives.',
            'Clause 2',
            $this->user->id,
        );

        $payload = app(ClauseResolver::class)->resolve($this->engagement->fresh(), $msa)->toArray();
        $row = collect($payload['clauses'])->firstWhere('code', 'CBN-CYB-03');

        $this->assertSame($amendment->reference, $row['determined_by']);
    }

    /* ------------------------------------------------------------------ */
    /*  Hierarchy invariants                                               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_amendment_cannot_be_filed_without_a_parent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sit under the agreement/');

        app(ContractService::class)->create($this->engagement, [
            'contract_type' => 'amendment',
            'title' => 'Orphan amendment',
        ], $this->user->id);
    }

    #[Test]
    public function an_amendment_cannot_be_filed_under_another_engagements_contract(): void
    {
        // Not a data-quality problem: the activation gate would be reading the
        // wrong contract.
        $other = $this->makeEngagement('ENG-2026-0002', 'Card personalisation');
        $theirs = app(ContractService::class)->create($other, [
            'contract_type' => 'msa', 'title' => 'Their MSA', 'status' => Contract::STATUS_EXECUTED,
        ], $this->user->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/different engagement/');

        app(ContractService::class)->create($this->engagement, [
            'contract_type' => 'amendment',
            'title' => 'Cross-filed amendment',
            'parent_contract_id' => $theirs->id,
        ], $this->user->id);
    }

    /* ------------------------------------------------------------------ */
    /*  The denormalised count                                             */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_register_count_tracks_the_gaps_but_the_gate_reads_the_rows(): void
    {
        $contract = $this->executedContract();
        $this->satisfyAllExcept($contract, ['CBN-CYB-05']);

        $this->assertSame(1, app(ContractService::class)->refreshBlockingGapCount($contract));
        $this->assertSame(1, $contract->fresh()->blocking_gaps_count);

        // A stale count must not admit a vendor. Set it to zero by hand — the
        // state a promoted clause or a lapsed waiver would leave behind — and
        // the gate must still refuse.
        $contract->forceFill(['blocking_gaps_count' => 0])->save();

        $this->assertFalse(app(ActivationGuard::class)->check($this->engagement->fresh())->allowed);
    }

    /* ------------------------------------------------------------------ */
    /*  System-owned clauses                                               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_system_owned_clause_cannot_be_deleted(): void
    {
        // A tenant able to delete CBN-CYB-05 could make its own audit-rights
        // gap disappear, which is exactly the gap the regulator cares about.
        $clause = $this->clause('CBN-CYB-05');
        $this->assertTrue($clause->is_system_owned);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be deleted/');

        $clause->delete();
    }

    #[Test]
    public function a_tenant_may_edit_model_text_on_a_system_clause_but_not_its_citation(): void
    {
        $system = $this->clause('CBN-CYB-05');
        $this->assertSame(['model_text', 'guidance'], $system->tenantEditableFields());

        $own = ClauseLibraryEntry::create([
            'organization_id' => $this->organization->id,
            'code' => 'OWN-01', 'title' => 'Our own clause', 'citation' => 'Internal policy 4.1',
        ]);

        $this->assertContains('citation', $own->tenantEditableFields());
        $this->assertFalse($own->is_system_owned, 'is_system_owned must not be mass-assignable.');
    }

    /* ------------------------------------------------------------------ */

    private function clause(string $code): ClauseLibraryEntry
    {
        return ClauseLibraryEntry::query()->availableTo()->where('code', $code)->firstOrFail();
    }

    private function clauseRow(Contract $contract, string $code): ContractClause
    {
        return ContractClause::query()
            ->where('contract_id', $contract->getKey())
            ->where('clause_library_id', $this->clause($code)->getKey())
            ->firstOrFail();
    }

    private function executedContract(): Contract
    {
        return app(ContractService::class)->create($this->engagement, [
            'contract_type' => 'msa',
            'title' => 'Master services agreement',
            'status' => Contract::STATUS_EXECUTED,
            'effective_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'notice_period_days_entity' => 90,
            'renewal_type' => 'auto',
        ], $this->user->id);
    }

    private function satisfyAll(Contract $contract): void
    {
        $this->satisfyAllExcept($contract, []);
    }

    /** @param list<string> $except */
    private function satisfyAllExcept(Contract $contract, array $except): void
    {
        $resolution = app(ClauseResolver::class)->resolve($this->engagement, $contract);

        foreach ($resolution->applicable as $clause) {
            app(ClauseAnalyzer::class)->record(
                $contract,
                $clause,
                in_array($clause->code, $except, true) ? ClausePresence::Absent : ClausePresence::Present,
                in_array($clause->code, $except, true) ? null : 'Agreed term for '.$clause->code,
                'Clause 1',
                $this->user->id,
            );
        }
    }

    private function approvedWaiver(ContractClause $row): Waiver
    {
        return Waiver::create([
            'organization_id' => $this->organization->id,
            'waivable_type' => Waiver::TYPE_BLOCKING_CLAUSE,
            'waivable_id' => $row->getKey(),
            'engagement_id' => $this->engagement->id,
            'rationale' => 'Audit rights are being negotiated; the provider has agreed in principle.',
            'requested_by' => $this->user->id,
            'requested_at' => now(),
            'approver_id' => $this->user->id,
            'approver_role' => 'Chief Risk Officer',
            'approved_at' => now(),
            'expires_at' => now()->addMonths(3)->toDateString(),
            'status' => Waiver::STATUS_APPROVED,
        ]);
    }

    private function makeEngagement(string $reference = 'ENG-2026-0001', string $name = 'Core banking hosting'): Engagement
    {
        $vendor = ThirdParty::create([
            'legal_name' => 'Vendor '.Str::random(6), 'slug' => Str::random(10), 'entity_type' => 'company',
        ]);

        return Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => $reference,
            'name' => $name,
            'service_description' => 'Hosting and operation of the core banking platform.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->user->id,
        ])->refresh();
    }
}
