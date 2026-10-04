<?php

namespace Tests\Feature\Bcms;

use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Process;
use App\Models\Bcms\TrainingRecord;
use App\Models\KeyRiskIndicator;
use App\Models\Organization;
use App\Models\Tprm\BcpTest;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use Database\Seeders\Bcms\BcmsDemoSeeder;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 11 demo data — the DoD line "seed/demo data added to the Kano
 * Heritage Bank demo tenant": training records across the six curricula,
 * vendor continuity evidence through TPRM's own recorder (never a BCMS
 * table), and the resilience KRIs adopted with real readings.
 *
 * SCOPED TO EXACTLY WHAT `BcmsDemoSeeder`'S THREE PHASE 11 METHODS NEED — the
 * TPRM demo portfolio's own vendor/engagement shape (`TprmDemoSeeder`'s
 * "Interswitch Limited" / "Cloudspan Digital Limited" and its
 * `ENG-DEMO-000{1,2,4}` references), a Tier-1 process with an approved BIA
 * per vendor, and a roster of active users — rather than the whole (very
 * expensive) estate `BcmsDemoSeeder::run()` builds around it. The three
 * methods are invoked directly, by reflection, because they are private and
 * `run()` is a single ~1,000-line method with no smaller public seam; this is
 * the same trade `DemoSeederCompletionTest` makes for the call-tree and EMNS
 * seeders.
 */
class Phase11DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);
        config()->set('features.tprm', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        // Ten active users: seedTrainingRecords() needs at least six, and
        // recordOutcome()'s self-assessment guard needs an assessor distinct
        // from every subject.
        for ($i = 0; $i < 10; $i++) {
            $this->user('Demo user '.$i, "demo{$i}@khb.test");
        }

        // The preferred actor every Phase 11 seeding method looks for first —
        // super-admin, exactly as `DatabaseSeeder` creates it, since
        // recording a BCP test additionally requires tprm.edit.
        $admin = $this->user('System Administrator', 'admin@risk.test');
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super-admin', 'web'));

        // TPRM's own demo shape: two vendors, three engagements, exactly the
        // legal names and references `seedSupplierResilienceEvidence()`
        // looks up (`TprmDemoSeeder`'s cluster one and cluster two).
        $interswitch = ThirdParty::create([
            'organization_id' => $this->organization->id,
            'legal_name' => 'Interswitch Limited', 'slug' => Str::random(12),
            'entity_type' => 'company', 'status' => 'active',
        ]);
        $cloudspan = ThirdParty::create([
            'organization_id' => $this->organization->id,
            'legal_name' => 'Cloudspan Digital Limited', 'slug' => Str::random(12),
            'entity_type' => 'company', 'status' => 'active',
        ]);

        Engagement::create([
            'organization_id' => $this->organization->id, 'third_party_id' => $interswitch->getKey(),
            'reference' => 'ENG-DEMO-0001', 'name' => 'Card processing and issuing',
            'engagement_type' => 'ict_service',
        ]);
        Engagement::create([
            'organization_id' => $this->organization->id, 'third_party_id' => $interswitch->getKey(),
            'reference' => 'ENG-DEMO-0002', 'name' => 'ATM and POS switching',
            'engagement_type' => 'ict_service',
        ]);
        Engagement::create([
            'organization_id' => $this->organization->id, 'third_party_id' => $cloudspan->getKey(),
            'reference' => 'ENG-DEMO-0004', 'name' => 'Internet and mobile banking platform',
            'engagement_type' => 'ict_service',
        ]);

        // Tier-1 processes, each with an approved BIA — what makes a
        // dependency on the vendor above BCMS-critical.
        foreach (['BCP-CARD', 'BCP-CHAN'] as $code) {
            $process = Process::factory()->create(['code' => $code, 'criticality_tier' => 1]);
            BiaAssessment::factory()->create(['process_id' => $process->getKey(), 'status' => 'approved']);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function the_demo_seeder_runs_phase_11_data_twice_without_duplicating_anything(): void
    {
        $expectedEmnsNextDue = now()->subMonths(5)->addMonths(6)->toDateString();

        $this->runPhase11Seeding();
        $this->runPhase11Seeding();

        // Training records exist, across more than one curriculum, and were
        // not doubled by the second pass.
        $trainingCount = TrainingRecord::query()->count();
        $this->assertGreaterThan(0, $trainingCount);

        $emnsRecords = TrainingRecord::query()
            ->whereHas('curriculum', fn ($q) => $q->where('code', 'BC-EMNS'))
            ->get();
        $this->assertCount(1, $emnsRecords, 'The EMNS record was not duplicated by the second seeding pass.');
        $this->assertSame(
            $expectedEmnsNextDue,
            $emnsRecords->first()->next_due_date->toDateString(),
            'The EMNS record demonstrates the six-month cadence, not the twelve-month default.'
        );

        $overdue = TrainingRecord::query()
            ->whereHas('curriculum', fn ($q) => $q->where('code', 'BC-WARDEN'))
            ->where('next_due_date', '<', now()->toDateString())
            ->count();
        $this->assertSame(1, $overdue, 'Exactly one warden record is overdue for re-certification.');

        // Supplier resilience: exactly one BcpTest per engagement, not two —
        // the per-engagement guard held across the second pass.
        foreach (['ENG-DEMO-0001', 'ENG-DEMO-0002', 'ENG-DEMO-0004'] as $reference) {
            $engagementId = Engagement::query()->where('reference', $reference)->value('id');
            $this->assertSame(
                1,
                BcpTest::query()->where('engagement_id', $engagementId)->count(),
                "{$reference} has exactly one BCP test row after two seeding passes."
            );
        }

        // Interswitch carries two qualifying (supports_critical_function)
        // engagements — the evidence-ambiguous precondition — set once, not
        // toggled or duplicated by the second pass.
        $interswitchId = ThirdParty::query()->where('legal_name', 'Interswitch Limited')->value('id');
        $this->assertSame(
            2,
            Engagement::query()->where('third_party_id', $interswitchId)
                ->where('supports_critical_function', true)->count(),
        );

        // KRIs: adopted exactly once per code, seventeen total, never a
        // second row for the same kri_code.
        $this->assertSame(17, KeyRiskIndicator::query()->where('kri_code', 'like', 'BCMS-%')->count());

        $kri = KeyRiskIndicator::query()->where('kri_code', 'BCMS-VENDOR-ATTEST')->firstOrFail();
        $this->assertNotNull($kri->current_value, 'BCMS-VENDOR-ATTEST was measured from the seeded continuity evidence, not left null.');
    }

    /**
     * qa-engineer gate-1 re-gate, fixed: `seedTrainingRecords()`'s guard used
     * to be `TrainingRecord::query()->exists()` — ANY row in the tenant, so a
     * tenant that already carried one unrelated training record (exactly
     * what phase-11-notes.md §1.9 reported having happened on the shared dev
     * database) never got the role-based curricula records this method
     * exists to seed, on this run or any future re-seed. The guard is now
     * scoped per (curriculum, user) pair
     * (`recordTrainingOutcomeOnce()`), the same shape
     * `seedSupplierResilienceEvidence()` already uses per engagement, so the
     * block seeds beside an unrelated pre-existing row and self-heals on a
     * second run rather than staying permanently blocked.
     */
    #[Test]
    public function a_pre_existing_unrelated_training_record_no_longer_blocks_the_phase_11_training_seed(): void
    {
        $curriculum = \App\Models\Bcms\TrainingCurriculum::query()->where('code', 'BC-AWARE-ALL')->firstOrFail();
        $someone = User::query()->where('email', 'demo0@khb.test')->firstOrFail();

        // One pre-existing record, unrelated to this seeder's own role-based
        // demo content, exactly as the dev database is reported to have had.
        $unrelated = TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $someone->getKey(),
            'completed_at' => now()->subYears(3),
            'competency_assessed' => false,
        ]);

        $this->runPhase11Seeding();
        $this->runPhase11Seeding();

        $wardenRecords = TrainingRecord::query()
            ->whereHas('curriculum', fn ($q) => $q->where('code', 'BC-WARDEN'))
            ->count();

        // BC-WARDEN deliberately carries two demo records — the assessed
        // holder and the separate overdue-for-re-certification holder — so
        // two, not zero, is what "the block seeded" looks like here.
        $this->assertSame(
            2,
            $wardenRecords,
            'FIXED: the per-pair guard seeds the role-based curricula records beside the unrelated '
            .'pre-existing row, instead of the whole block staying permanently blocked by it.'
        );

        // The unrelated row is untouched, not overwritten or duplicated —
        // the fix adds what is missing, it does not reconcile what is there.
        $this->assertSame(1, TrainingRecord::query()->where('id', $unrelated->getKey())->count());
        $this->assertDatabaseHas('bcms_training_records', [
            'id' => $unrelated->getKey(), 'competency_assessed' => false,
        ]);

        // Idempotent across the second pass too: exactly one row per
        // (curriculum, user) pair this method seeds.
        foreach (['BC-CRISIS', 'BC-CHAMPION', 'BC-ITDR', 'BC-EMNS'] as $code) {
            $this->assertSame(
                1,
                TrainingRecord::query()->whereHas('curriculum', fn ($q) => $q->where('code', $code))->count(),
                "{$code} has exactly one assessed demo record after two seeding passes."
            );
        }
    }

    /** Invoke the three Phase 11 private seeding methods directly. */
    private function runPhase11Seeding(): void
    {
        $seeder = new BcmsDemoSeeder;

        foreach (['seedTrainingRecords', 'seedSupplierResilienceEvidence', 'seedResilienceKris'] as $method) {
            $reflection = new ReflectionMethod($seeder, $method);
            $reflection->setAccessible(true);
            $reflection->invoke($seeder);
        }
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'name' => $name, 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
    }
}
