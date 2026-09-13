<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\ClausePresence;
use App\Enums\Tprm\ObligationStatus;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Contract;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Obligation;
use App\Models\Tprm\PciResponsibility;
use App\Models\Tprm\Sla;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Contracts\ClauseAnalyzer;
use App\Services\Tprm\Contracts\ClauseResolver;
use App\Services\Tprm\Contracts\ContractService;
use App\Services\Tprm\Contracts\ObligationExtractor;
use App\Services\Tprm\Contracts\ObligationService;
use App\Services\Tprm\Contracts\PciMatrixBuilder;
use App\Services\Tprm\Contracts\SlaService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Obligations, service levels, the PCI matrix and the notice-period alerting.
 *
 * The through-line is the same in all four: the register has to distinguish
 * what somebody actually agreed to from what a tool assumed. A duty generated
 * from a clause nobody signed, a compliant month inferred from silence, a PCI
 * row the vendor filled in and nobody read, an expiry-based renewal reminder —
 * each is a plausible implementation that produces confident nonsense.
 */
class ContractObligationsTest extends TestCase
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
    /*  Obligation generation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function only_satisfied_clauses_generate_obligations(): void
    {
        // A duty generated from a clause the contract does not contain is a
        // duty the provider never agreed to, and the first time it showed as
        // breached the register would lose its credibility.
        $contract = $this->executedContract();
        $this->determineAll($contract, except: ['CBN-CYB-05']);

        $result = app(ObligationExtractor::class)->generate($contract, $this->user->id);

        $this->assertGreaterThan(0, $result['created']);
        $this->assertGreaterThan(0, $result['skipped_gaps']);

        $titles = Obligation::query()->pluck('title')->all();
        $this->assertNotContains('Obtain and review the provider\'s assurance report', $titles);
    }

    #[Test]
    public function obligations_are_generated_for_both_sides(): void
    {
        // The duties an institution is found to have breached are its own.
        $contract = $this->executedContract();
        $this->determineAll($contract);
        app(ObligationExtractor::class)->generate($contract, $this->user->id);

        $ours = Obligation::query()->where('obligor', Obligation::OBLIGOR_ENTITY)->count();
        $theirs = Obligation::query()->where('obligor', Obligation::OBLIGOR_PROVIDER)->count();

        $this->assertGreaterThan(0, $ours, 'No obligation on us was generated.');
        $this->assertGreaterThan(0, $theirs);

        // The audit-rights clause creates a duty on each side, and the one on
        // us is the one that matters: a right to audit that nobody exercises
        // is a term the institution paid for and never used.
        $ourDuty = Obligation::query()
            ->where('source_reference', 'CBN-CYB-05')
            ->where('obligor', Obligation::OBLIGOR_ENTITY)
            ->first();

        $this->assertNotNull($ourDuty, 'The audit-rights clause created no duty on us.');
        $this->assertSame('annual', $ourDuty->frequency);
        $this->assertTrue($ourDuty->evidence_required);
        // The clause's own citation travels with it, so a reader can answer
        // "who says we have to" without going back to the contract.
        $this->assertSame('CBN Cyber 2024 §2.3(v)', $ourDuty->citation);

        // And the access review is NOT generated, because this engagement
        // grants the provider no access to our systems — the applicability
        // rule doing its job.
        $this->assertSame(0, Obligation::query()->where('source_reference', 'CBN-ACC-01')->count());
    }

    #[Test]
    public function a_first_due_date_is_anchored_to_the_contract_and_is_never_in_the_past(): void
    {
        // Anchoring to today would make every register in the product renew on
        // the day it was migrated; leaving it in the past would arrive as an
        // instant breach nobody could have prevented.
        $contract = $this->executedContract(effective: now()->subYears(3)->startOfMonth());
        $this->determineAll($contract);
        app(ObligationExtractor::class)->generate($contract, $this->user->id);

        $annual = Obligation::query()->where('frequency', 'annual')->firstOrFail();

        $this->assertNotNull($annual->next_due_date);
        $this->assertTrue($annual->next_due_date->isFuture());
        // The anniversary of the agreement, not an arbitrary date.
        $this->assertSame(
            $contract->effective_date->format('m-d'),
            $annual->next_due_date->format('m-d'),
        );
    }

    #[Test]
    public function an_on_event_obligation_carries_no_due_date(): void
    {
        // A breach-notification duty has no clock until something happens.
        // Recording it as a one-off with no date would show as permanently
        // overdue or vanish from the register.
        $this->engagement->forceFill(['processes_personal_data' => true])->save();
        $contract = $this->executedContract();
        $this->determineAll($contract);
        app(ObligationExtractor::class)->generate($contract, $this->user->id);

        $breach = Obligation::query()
            ->where('source_reference', 'NDPA-BR-01')
            ->firstOrFail();

        $this->assertSame('on_event', $breach->frequency);
        $this->assertNull($breach->next_due_date);
        $this->assertFalse($breach->isOverdue());
    }

    #[Test]
    public function regenerating_does_not_reset_an_existing_obligation(): void
    {
        // A generator that overwrote them would wipe a year of evidence every
        // time somebody re-ran the clause analysis.
        $contract = $this->executedContract();
        $this->determineAll($contract);
        app(ObligationExtractor::class)->generate($contract, $this->user->id);

        $obligation = Obligation::query()->where('source_reference', 'CBN-CYB-05')->firstOrFail();
        $obligation->forceFill([
            'owner_id' => $this->user->id,
            'breach_count' => 2,
            'next_due_date' => now()->addMonths(2)->toDateString(),
        ])->save();

        $second = app(ObligationExtractor::class)->generate($contract, $this->user->id);

        $this->assertSame(0, $second['created']);
        $this->assertGreaterThan(0, $second['existing']);

        $obligation->refresh();
        $this->assertSame(2, $obligation->breach_count);
        $this->assertSame(now()->addMonths(2)->toDateString(), $obligation->next_due_date->toDateString());
    }

    /* ------------------------------------------------------------------ */
    /*  Satisfying and breaching */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function satisfying_a_recurring_duty_advances_it_rather_than_closing_it(): void
    {
        // A register that closed a quarterly review would show an empty list
        // and a bank with no access reviews.
        $obligation = $this->obligation(['frequency' => 'quarterly', 'evidence_required' => false]);
        $original = $obligation->next_due_date->copy();

        $result = app(ObligationService::class)->satisfy($obligation, null, $this->user->id);

        $this->assertTrue($result['satisfied']);
        $obligation->refresh();
        $this->assertSame(ObligationStatus::Pending, $obligation->status);
        $this->assertTrue($obligation->next_due_date->isAfter($original));
        $this->assertSame(3, (int) $original->diffInMonths($obligation->next_due_date));
    }

    #[Test]
    public function a_one_off_duty_is_closed_when_satisfied(): void
    {
        $obligation = $this->obligation(['frequency' => 'one_off', 'evidence_required' => false]);

        app(ObligationService::class)->satisfy($obligation, null, $this->user->id);

        $this->assertSame(ObligationStatus::Satisfied, $obligation->fresh()->status);
        $this->assertNull($obligation->fresh()->next_due_date);
    }

    #[Test]
    public function an_obligation_requiring_evidence_refuses_a_bare_tick(): void
    {
        // A tick with nothing behind it is what a supervisor asks to see
        // behind.
        $obligation = $this->obligation(['evidence_required' => true]);

        $result = app(ObligationService::class)->satisfy($obligation, null, $this->user->id);

        $this->assertFalse($result['satisfied']);
        $this->assertStringContainsString('requires evidence', $result['reason']);
        $this->assertSame(ObligationStatus::Pending, $obligation->fresh()->status);
    }

    #[Test]
    public function a_breach_increments_a_counter_that_is_never_reset(): void
    {
        // Three missed quarterly reviews in a year is a different fact from
        // one, and only if the earlier ones survive the later ones being done.
        $obligation = $this->obligation(['frequency' => 'quarterly', 'evidence_required' => false]);

        app(ObligationService::class)->breach($obligation, 'Missed.', $this->user->id);
        app(ObligationService::class)->breach($obligation->fresh(), 'Missed again.', $this->user->id);
        app(ObligationService::class)->satisfy($obligation->fresh(), null, $this->user->id);

        $this->assertSame(2, $obligation->fresh()->breach_count);
    }

    #[Test]
    public function the_sweep_marks_due_then_breaches_past_the_grace_period(): void
    {
        $due = $this->obligation([
            'frequency' => 'quarterly',
            'evidence_required' => false,
            'next_due_date' => now()->subDays(2)->toDateString(),
        ]);
        $overdue = $this->obligation([
            'frequency' => 'quarterly',
            'evidence_required' => false,
            'next_due_date' => now()->subDays(20)->toDateString(),
            'title' => 'Long overdue duty',
        ]);
        $overdue->forceFill(['status' => ObligationStatus::Due->value])->save();

        $this->artisan('tprm:check-obligations')->assertExitCode(0);

        // Two days past its date: due, not breached. A duty performed on the
        // Monday after a weekend deadline is not a breach nobody could avoid.
        $this->assertSame(ObligationStatus::Due, $due->fresh()->status);
        $this->assertSame(0, $due->fresh()->breach_count);

        $this->assertSame(1, $overdue->fresh()->breach_count);
        // And it advanced, so the same breach is not reported again tomorrow.
        $this->assertTrue($overdue->fresh()->next_due_date->isFuture());
    }

    #[Test]
    public function the_obligation_sweep_reminds_the_owner_at_the_exact_milestones(): void
    {
        $this->obligation([
            'frequency' => 'annual',
            'evidence_required' => false,
            'next_due_date' => now()->addDays(30)->toDateString(),
            'owner_id' => $this->user->id,
        ]);
        // 45 days out matches nothing — cumulative windows are how a channel
        // gets muted.
        $this->obligation([
            'frequency' => 'annual',
            'evidence_required' => false,
            'next_due_date' => now()->addDays(45)->toDateString(),
            'owner_id' => $this->user->id,
            'title' => 'Not yet due for a reminder',
        ]);

        $this->artisan('tprm:check-obligations')->assertExitCode(0);

        $this->assertDatabaseCount('notifications_log', 1);
        $this->assertDatabaseHas('notifications_log', ['type' => 'tprm.obligation.reminder']);
    }

    /* ------------------------------------------------------------------ */
    /*  FR-CTR-02 — notice, not expiry */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_notice_deadline_is_the_expiry_less_the_notice_period(): void
    {
        $contract = $this->executedContract();
        $contract->forceFill([
            'expiry_date' => now()->addDays(100)->toDateString(),
            'notice_period_days_entity' => 120,
            'renewal_type' => 'auto',
        ])->save();
        $contract->refresh();

        // Expiring in a hundred days, and the window to stop it closed twenty
        // days ago. This is the case an expiry-based reminder gets wrong.
        $this->assertSame(-20, $contract->daysUntilNotice());
        $this->assertTrue($contract->noticeWindowMissed());
    }

    #[Test]
    public function a_contract_with_no_notice_period_reports_null_rather_than_zero(): void
    {
        // A contract whose notice period nobody recorded cannot be alerted on,
        // and the register says so rather than treating it as zero days and
        // alerting on the expiry.
        $contract = $this->executedContract();
        $contract->forceFill(['notice_period_days_entity' => null])->save();

        $this->assertNull($contract->fresh()->noticeDeadline());
        $this->assertNull($contract->fresh()->daysUntilNotice());
        $this->assertFalse($contract->fresh()->noticeWindowMissed());
    }

    #[Test]
    public function the_renewal_sweep_fires_on_the_notice_milestone_not_the_expiry(): void
    {
        // Expiry in 120 days, 90-day notice: the notice deadline is 30 days
        // away, so the T-30 milestone fires today. An expiry-based sweep would
        // fire nothing.
        $contract = $this->executedContract();
        $contract->forceFill([
            'expiry_date' => now()->addDays(120)->toDateString(),
            'notice_period_days_entity' => 90,
        ])->save();

        $this->artisan('tprm:check-contract-renewals')->assertExitCode(0);

        $this->assertDatabaseHas('notifications_log', [
            'type' => 'tprm.contract.renewal_notice',
            'organization_id' => $this->organization->id,
        ]);
    }

    #[Test]
    public function a_contract_45_days_from_its_notice_deadline_fires_nothing(): void
    {
        $contract = $this->executedContract();
        $contract->forceFill([
            'expiry_date' => now()->addDays(135)->toDateString(),
            'notice_period_days_entity' => 90,
        ])->save();

        $this->artisan('tprm:check-contract-renewals')->assertExitCode(0);

        $this->assertDatabaseCount('notifications_log', 0);
    }

    #[Test]
    public function a_missed_notice_window_is_announced_the_day_after_it_closes(): void
    {
        $contract = $this->executedContract();
        $contract->forceFill([
            'expiry_date' => now()->addDays(89)->toDateString(),
            'notice_period_days_entity' => 90,
            'renewal_type' => 'auto',
            'renewal_term_months' => 12,
        ])->save();

        $this->artisan('tprm:check-contract-renewals')->assertExitCode(0);

        $this->assertDatabaseHas('notifications_log', [
            'type' => 'tprm.contract.notice_window_missed',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Service levels */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_operator_decides_the_breach_and_is_never_inferred(): void
    {
        // 99.9% availability is a FLOOR and 4 hours resolution is a CEILING.
        // Guessing which from the label is how a detector reports the exact
        // opposite of the truth, confidently, month after month.
        $availability = $this->sla(['metric_code' => 'AVAIL', 'target_operator' => 'gte', 'target_value' => 99.9]);
        $resolution = $this->sla(['metric_code' => 'RESOLVE', 'target_operator' => 'lte', 'target_value' => 4]);

        $this->assertTrue($availability->isBreach(99.5));
        $this->assertFalse($availability->isBreach(99.95));

        $this->assertTrue($resolution->isBreach(6));
        $this->assertFalse($resolution->isBreach(3));
    }

    #[Test]
    public function a_value_exactly_on_target_does_not_breach(): void
    {
        // A target stored as 99.9000 and a measurement of 99.9 differ in the
        // last bit; a naive comparison reports a breach on a month that met
        // the target exactly.
        $sla = $this->sla(['target_operator' => 'gte', 'target_value' => 99.9]);

        $measurement = app(SlaService::class)->record($sla, [
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'actual_value' => 99.9,
        ], $this->user->id);

        $this->assertFalse($measurement->is_breach);
    }

    #[Test]
    public function the_breach_verdict_is_stored_and_survives_a_target_change(): void
    {
        // Renegotiating a target next year must not retrospectively un-breach
        // last year.
        $sla = $this->sla(['target_operator' => 'gte', 'target_value' => 99.9]);

        $measurement = app(SlaService::class)->record($sla, [
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'actual_value' => 99.0,
        ], $this->user->id);

        $this->assertTrue($measurement->is_breach);

        $sla->forceFill(['target_value' => 98.0])->save();

        $this->assertTrue($measurement->fresh()->is_breach, 'A stored breach was rewritten by a target change.');
    }

    #[Test]
    public function a_missing_month_is_reported_rather_than_read_as_compliant(): void
    {
        // A provider whose reporting quietly stops looks identical to a
        // perfect record on any dashboard that only draws the points it has.
        $sla = $this->sla(['measurement_window' => 'monthly']);

        app(SlaService::class)->record($sla, [
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'actual_value' => 99.95,
        ], $this->user->id);

        $missing = app(SlaService::class)->missingPeriods($sla, 6);

        $this->assertCount(5, $missing);
        $this->assertNotContains(now()->subMonth()->format('Y-m'), $missing);
    }

    #[Test]
    public function an_import_reports_metric_codes_it_could_not_match(): void
    {
        // A report whose metric was renamed would otherwise import as a silent
        // partial success.
        $this->sla(['metric_code' => 'AVAIL']);

        $result = app(SlaService::class)->import($this->engagement->id, [
            ['metric_code' => 'AVAIL', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'actual_value' => 99.99],
            ['metric_code' => 'UPTIME', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'actual_value' => 99.99],
        ], $this->user->id);

        $this->assertSame(1, $result['recorded']);
        $this->assertSame(['UPTIME'], $result['unmatched']);
    }

    #[Test]
    public function the_credit_register_separates_claimed_from_received(): void
    {
        // The gap between them across a year is the part of a penalty regime
        // that quietly stops working.
        $sla = $this->sla(['target_operator' => 'gte', 'target_value' => 99.9]);

        app(SlaService::class)->record($sla, [
            'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'actual_value' => 98.0,
            'credit_claimed_minor' => 500000, 'credit_received_minor' => 200000, 'currency' => 'NGN',
        ], $this->user->id);
        app(SlaService::class)->record($sla, [
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'actual_value' => 97.0,
        ], $this->user->id);

        $register = app(SlaService::class)->creditRegister($this->engagement->id);

        $this->assertSame(2, $register['breaches']);
        // A breach nobody claimed for: the commonest and least visible failure.
        $this->assertSame(1, $register['unclaimed']);
        $this->assertSame(500000, $register['claimed_minor']);
        $this->assertSame(300000, $register['shortfall_minor']);
    }

    #[Test]
    public function a_gap_in_reporting_does_not_reset_a_breach_streak(): void
    {
        $sla = $this->sla(['target_operator' => 'gte', 'target_value' => 99.9]);

        foreach (['2026-06', '2026-07', '2026-08'] as $month) {
            app(SlaService::class)->record($sla, [
                'period_start' => $month.'-01',
                'period_end' => $month.'-28',
                'actual_value' => 95.0,
            ], $this->user->id);
        }

        $this->assertSame(3, app(SlaService::class)->consecutiveBreaches($sla));
    }

    /* ------------------------------------------------------------------ */
    /*  PCI matrix */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_pci_requirement_gets_a_row_including_the_inapplicable_ones(): void
    {
        // A matrix with twelve of nineteen rows is a matrix a QSA has to ask
        // about seven times.
        app(PciMatrixBuilder::class)->ensureRows($this->engagement);

        $matrix = app(PciMatrixBuilder::class)->matrix($this->engagement);

        $this->assertSame(count(PciMatrixBuilder::REQUIREMENTS), $matrix['total']);
        $this->assertContains('12.8.5', array_column($matrix['rows'], 'requirement'));
    }

    #[Test]
    public function an_unconfirmed_matrix_is_not_export_ready_and_says_so(): void
    {
        app(PciMatrixBuilder::class)->ensureRows($this->engagement);

        $matrix = app(PciMatrixBuilder::class)->matrix($this->engagement);
        $this->assertFalse($matrix['export_ready']);
        $this->assertSame($matrix['total'], $matrix['unconfirmed']);

        $row = PciResponsibility::query()->where('pci_requirement', '3')->firstOrFail();
        app(PciMatrixBuilder::class)->confirm($row, PciResponsibility::TPSP, 'The provider stores the PAN.', $this->user->id);

        $this->assertSame(1, app(PciMatrixBuilder::class)->matrix($this->engagement)['confirmed']);
    }

    #[Test]
    public function prepopulation_never_overwrites_a_confirmed_row(): void
    {
        // A vendor could otherwise reassign a duty to us by answering a
        // questionnaire differently next year.
        app(PciMatrixBuilder::class)->ensureRows($this->engagement);
        $row = PciResponsibility::query()->where('pci_requirement', '3')->firstOrFail();
        app(PciMatrixBuilder::class)->confirm($row, PciResponsibility::ENTITY, 'We hold the PAN.', $this->user->id);

        app(PciMatrixBuilder::class)->prepopulateFromAssessment($this->engagement);

        $row->refresh();
        $this->assertSame(PciResponsibility::ENTITY, $row->responsibility);
        $this->assertTrue($row->isConfirmed());
    }

    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $attributes */
    private function sla(array $attributes = []): Sla
    {
        return Sla::create($attributes + [
            'organization_id' => $this->organization->id,
            'engagement_id' => $this->engagement->id,
            'metric_code' => 'AVAIL',
            'metric_name' => 'Service availability',
            'unit' => '%',
            'target_operator' => 'gte',
            'target_value' => 99.9,
            'measurement_window' => 'monthly',
            'created_by' => $this->user->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function obligation(array $attributes = []): Obligation
    {
        $obligation = Obligation::create(array_merge([
            'organization_id' => $this->organization->id,
            'engagement_id' => $this->engagement->id,
            'source' => 'contract',
            'source_reference' => 'CBN-CYB-05',
            'title' => 'A duty '.Str::random(6),
            'obligor' => Obligation::OBLIGOR_ENTITY,
            'frequency' => 'annual',
            'evidence_required' => false,
            'next_due_date' => now()->addMonths(3)->toDateString(),
        ], $attributes));

        return $obligation->refresh();
    }

    private function executedContract(?\Illuminate\Support\Carbon $effective = null): Contract
    {
        return app(ContractService::class)->create($this->engagement, [
            'contract_type' => 'msa',
            'title' => 'Master services agreement',
            'status' => Contract::STATUS_EXECUTED,
            'effective_date' => ($effective ?? now()->subYear())->toDateString(),
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'notice_period_days_entity' => 90,
            'renewal_type' => 'auto',
        ], $this->user->id);
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
                $this->user->id,
            );
        }
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
            'relationship_owner_id' => $this->user->id,
        ])->refresh();
    }
}
