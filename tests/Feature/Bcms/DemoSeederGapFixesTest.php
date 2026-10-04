<?php

namespace Tests\Feature\Bcms;

use App\Models\Bcms\Application;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\DrSystem;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseInject;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\TrainingRecord;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Dr\DrService;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
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
 * Gap 7 — four demo-seeder defects, each pinned by invoking the specific
 * (private) `BcmsDemoSeeder` method against a minimal, hand-built fixture —
 * the same reflection technique `Phase11DemoSeederTest` and
 * `DemoSeederCompletionTest` already use, rather than running the whole
 * (very expensive) `run()`.
 */
class DemoSeederGapFixesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    private function invoke(string $method, array $args = []): mixed
    {
        $seeder = new BcmsDemoSeeder;
        $reflection = new ReflectionMethod($seeder, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($seeder, ...$args);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
    }

    /**
     * The exact bug: on a demo tenant with exactly six active users,
     * `$users[5]` (the overdue warden) was `$users->last()` (the assessor),
     * so the self-assessment guard silently refused and no overdue row was
     * ever written — the seeder comment claimed one that did not exist.
     */
    #[Test]
    public function seed_training_records_writes_a_genuinely_overdue_warden_record_with_exactly_six_users(): void
    {
        $this->user('admin@risk.test');
        for ($i = 1; $i <= 5; $i++) {
            $this->user("user{$i}@khb.test");
        }
        $this->assertSame(6, User::query()->where('is_active', true)->count());

        $this->invoke('seedTrainingRecords');

        $overdue = TrainingRecord::query()
            ->whereHas('curriculum', fn ($q) => $q->where('code', 'BC-WARDEN'))
            ->where('next_due_date', '<', now()->toDateString())
            ->count();

        $this->assertSame(1, $overdue, 'Exactly one warden record must be overdue, even with only six active users.');
    }

    /** The BCMS programme must never end up `active` with nobody recorded as having approved it. */
    #[Test]
    public function seed_programme_sets_approved_by_and_approved_at_before_going_active(): void
    {
        $this->user('admin@risk.test');
        $this->user('approver@khb.test');

        $programme = $this->invoke('seedProgramme');

        $this->assertInstanceOf(Programme::class, $programme);
        $this->assertSame('active', $programme->status);
        $this->assertNotNull($programme->approved_by, 'An active programme must record who approved it.');
        $this->assertNotNull($programme->approved_at);
        $this->assertNotSame((int) $programme->owner_id, (int) $programme->approved_by, 'The approver must not be the programme\'s own owner.');
    }

    #[Test]
    public function seed_programme_is_idempotent_and_never_regresses_an_already_approved_programme(): void
    {
        $this->user('admin@risk.test');
        $this->user('approver@khb.test');

        $first = $this->invoke('seedProgramme');
        $approvedAt = $first->approved_at;

        $second = $this->invoke('seedProgramme');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame('active', $second->status);
        $this->assertTrue($approvedAt->equalTo($second->approved_at));
    }

    /** The crisis simulation's own occurrence must carry a few scripted injects, not zero. */
    #[Test]
    public function seed_crisis_simulation_injects_seeds_two_or_three_injects_on_that_occurrences_occurrence(): void
    {
        $author = $this->user('admin@risk.test');

        $type = ExerciseType::query()->first();
        $programme = app(ExerciseProgrammeService::class)->create((int) now()->addYear()->year, 'Programme');

        $definition = ExerciseDefinition::query()->create([
            'organization_id' => $this->organization->id,
            'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type->getKey(),
            'name' => 'Crisis management simulation',
            'status' => 'active',
        ]);

        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addMonths(6)->toDateString(),
            'status' => 'planned',
        ]);

        $this->invoke('seedCrisisSimulationInjects', [$author]);

        $count = ExerciseInject::query()->where('occurrence_id', $occurrence->getKey())->count();
        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertLessThanOrEqual(3, $count);

        // Idempotent: a second call does not duplicate.
        $this->invoke('seedCrisisSimulationInjects', [$author]);
        $this->assertSame($count, ExerciseInject::query()->where('occurrence_id', $occurrence->getKey())->count());
    }

    /**
     * QA gate defect — `seedCrisisSimulationInjects()` calls
     * `InjectService::create($occurrence, $data, $author)` with a THIRD
     * positional argument, but `InjectService::create()`'s signature (A4)
     * takes only `(ExerciseOccurrence $occurrence, array $data)` — it reads
     * the actor from `auth()->user()` on the `BcmsAuditable` model event, not
     * from an argument. PHP silently discards the extra positional
     * argument (no error, no warning), so `$author` is never used and every
     * seeded inject's `created` audit row is written with a NULL actor,
     * because nothing is authenticated in a console/seeder context. Flagged
     * by `phpstan analyse` as `arguments.count` — this test pins the
     * functional half. Fix: drop the third argument at the call site
     * (`database/seeders/Bcms/BcmsDemoSeeder.php`).
     */
    #[Test]
    public function seed_crisis_simulation_injects_records_the_author_as_the_audit_actor(): void
    {
        $author = $this->user('admin@risk.test');

        $type = ExerciseType::query()->first();
        $programme = app(ExerciseProgrammeService::class)->create((int) now()->addYear()->year, 'Programme');

        $definition = ExerciseDefinition::query()->create([
            'organization_id' => $this->organization->id,
            'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type->getKey(),
            'name' => 'Crisis management simulation',
            'status' => 'active',
        ]);

        ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addMonths(6)->toDateString(),
            'status' => 'planned',
        ]);

        $this->invoke('seedCrisisSimulationInjects', [$author]);

        $inject = ExerciseInject::query()->firstOrFail();

        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => ExerciseInject::class,
            'auditable_id' => $inject->getKey(),
            'event' => 'created',
            'actor_id' => $author->getKey(),
        ]);
    }

    /**
     * QA re-gate — the fix wraps the `create()` calls in
     * `Auth::setUser($author)`/`Auth::forgetUser()` so `BcmsAuditable` can
     * read an actor in a console context. That temporary login must not
     * leak into whatever the seeder (or a later console command sharing the
     * same process) runs next: nothing was authenticated before this method
     * ran in the ordinary `run()` flow, so nothing must be authenticated
     * after it either.
     */
    #[Test]
    public function seed_crisis_simulation_injects_leaves_auth_exactly_as_it_found_it_when_nobody_was_logged_in(): void
    {
        $author = $this->user('admin@risk.test');

        $type = ExerciseType::query()->first();
        $programme = app(ExerciseProgrammeService::class)->create((int) now()->addYear()->year, 'Programme');
        $definition = ExerciseDefinition::query()->create([
            'organization_id' => $this->organization->id,
            'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type->getKey(),
            'name' => 'Crisis management simulation',
            'status' => 'active',
        ]);
        ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addMonths(6)->toDateString(),
            'status' => 'planned',
        ]);

        $this->assertNull(\Illuminate\Support\Facades\Auth::user(), 'Precondition: nobody is authenticated before the seeder runs.');

        $this->invoke('seedCrisisSimulationInjects', [$author]);

        $this->assertNull(
            \Illuminate\Support\Facades\Auth::user(),
            'The seeder must not leave the demo author logged in for whatever runs next in this process.'
        );
    }

    /**
     * QA re-gate, the other half of the same guard: if something upstream
     * IS authenticated when this method runs — the ordinary Laravel case
     * for a console command invoked on behalf of a user, or a test harness
     * that logs in first — the seeder must hand that identity back
     * afterwards rather than clobbering it with the demo author.
     */
    #[Test]
    public function seed_crisis_simulation_injects_restores_a_pre_existing_authenticated_user(): void
    {
        $author = $this->user('admin@risk.test');
        $priorUser = $this->user('someone-else@khb.test');

        $type = ExerciseType::query()->first();
        $programme = app(ExerciseProgrammeService::class)->create((int) now()->addYear()->year, 'Programme');
        $definition = ExerciseDefinition::query()->create([
            'organization_id' => $this->organization->id,
            'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type->getKey(),
            'name' => 'Crisis management simulation',
            'status' => 'active',
        ]);
        ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addMonths(6)->toDateString(),
            'status' => 'planned',
        ]);

        \Illuminate\Support\Facades\Auth::setUser($priorUser);

        $this->invoke('seedCrisisSimulationInjects', [$author]);

        $this->assertNotNull(\Illuminate\Support\Facades\Auth::user());
        $this->assertSame(
            $priorUser->getKey(),
            \Illuminate\Support\Facades\Auth::user()->getKey(),
            'A pre-existing authenticated user must be restored, not left as the demo author.'
        );

        \Illuminate\Support\Facades\Auth::forgetUser();
    }

    /** The DR register must carry one system with one test — the APP-CORE / BCP-CORE tier mismatch. */
    #[Test]
    public function seed_dr_register_writes_the_app_core_tier_mismatch_example(): void
    {
        $admin = $this->user('admin@risk.test');

        $application = Application::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'APP-CORE', 'name' => 'Core Banking Platform',
        ]);

        $process = Process::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'BCP-CORE',
            'name' => 'Core banking transaction processing', 'status' => 'active', 'criticality_tier' => 1,
        ]);

        $assessment = BiaAssessment::factory()->create([
            'organization_id' => $this->organization->id, 'process_id' => $process->getKey(),
            'status' => 'approved', 'rto_hours' => 2,
        ]);

        app(\App\Services\Bcms\Bia\DependencyService::class)->attach($assessment, $application, [
            'dependency_type' => 'upstream', 'criticality' => 'critical', 'single_point_of_failure' => true,
        ]);

        $this->invoke('seedDrRegister');

        $system = DrSystem::query()->where('application_id', $application->getKey())->first();
        $this->assertNotNull($system, 'A DR system for APP-CORE must exist.');
        $this->assertSame(4.0, (float) $system->rto_target_hours);
        $this->assertSame(1, $system->tests()->count());

        $mismatch = app(DrService::class)->tierMismatch($system);
        $this->assertNotNull($mismatch, 'APP-CORE\'s DR target (4h) must be reported as looser than BCP-CORE\'s approved BIA RTO (2h).');
        $this->assertSame(2.0, $mismatch['required_hours']);
        $this->assertSame(4.0, $mismatch['target_hours']);
    }
}
