<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\DependencyType;
use App\Enums\Bcms\DrTestType;
use App\Enums\Bcms\NotificationKind;
use App\Enums\Bcms\NotificationRegulator;
use App\Models\ApiToken;
use App\Models\Bcms\Application;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\DrSystem;
use App\Models\Bcms\DrTest;
use App\Models\Bcms\Incident;
use App\Models\Bcms\IncidentNotification;
use App\Models\Bcms\IncidentTask;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanActivation;
use App\Models\Bcms\Process;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Presenters\Bcms\DrPresenter;
use App\Services\Bcms\Dr\DrService;
use App\Services\Bcms\Incidents\IncidentService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 10 — the IT DR register: tier mismatch (clause map §3.2),
 * cadence inheritance (§3.3), a real invocation's structural exclusion from
 * `bcms_dr_tests` (§3.4, ADR 0020 §4), backup attestation without a new table
 * (§3.5), and idempotent provider ingestion (§3.6).
 */
class Phase10DrTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $engineer;

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

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-IT', 'name' => 'IT', 'is_active' => true,
        ]);

        $this->engineer = User::create([
            'name' => 'DR Engineer', 'email' => 'dr@khb.test', 'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(), 'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Tier mismatch (criterion 4, clause map §3.2) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_system_in_a_24_hour_tier_supporting_a_30_minute_process_is_flagged_by_name_and_numbers(): void
    {
        $application = Application::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'APP-CORE', 'name' => 'Core Banking',
        ]);

        $process = Process::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'PROC-PAY', 'name' => 'Payments Processing',
        ]);

        $assessment = BiaAssessment::query()->create([
            'organization_id' => $this->organization->id, 'process_id' => $process->getKey(),
            'status' => 'approved', 'rto_hours' => 0.5,
        ]);

        $this->attachDependency($assessment, $application);

        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Core Banking DR',
            'application_id' => $application->getKey(), 'recovery_tier' => 3, 'rto_target_hours' => 24,
        ]);

        $mismatch = app(DrService::class)->tierMismatch($system);

        $this->assertNotNull($mismatch);
        $this->assertSame('Payments Processing', $mismatch['process']);
        $this->assertSame(0.5, $mismatch['required_hours']);
        $this->assertSame(24.0, $mismatch['target_hours']);
    }

    #[Test]
    public function a_draft_bia_never_retiers_a_system(): void
    {
        $application = Application::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'APP-X', 'name' => 'App X',
        ]);
        $process = Process::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'PROC-X', 'name' => 'Process X',
        ]);
        $assessment = BiaAssessment::query()->create([
            'organization_id' => $this->organization->id, 'process_id' => $process->getKey(),
            'status' => 'draft', 'rto_hours' => 0.25,
        ]);
        $this->attachDependency($assessment, $application);

        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'App X DR',
            'application_id' => $application->getKey(), 'recovery_tier' => 3, 'rto_target_hours' => 24,
        ]);

        $this->assertNull(app(DrService::class)->tierMismatch($system));
    }

    /**
     * `DrService::tierMismatch()`/`inheritsOpenBankingScope()` query
     * `dependable_type = 'applications'` (a literal string). But
     * `App\Enums\Bcms\DependencyType::Applications->value` — the value
     * actually written by `Dependency::dependable()->associate($target)`
     * under `Relation::enforceMorphMap()` (`App\Support\MorphTypes::map()`
     * registers the BCMS `Application` model under the key
     * `'bcms_application'`, never `'applications'`) — is `'bcms_application'`.
     * Every real dependency row in this product is created through
     * `DependencyService::attach()`, which associates the morph relation and
     * therefore always writes `'bcms_application'`. This test creates the
     * dependency the way production actually does (via the enum value, the
     * same value `->associate()` would write) rather than the two tests
     * above's hardcoded `'applications'` literal, which matches the service's
     * bug rather than any row this product ever persists.
     */
    #[Test]
    public function tier_mismatch_is_found_against_a_dependency_row_shaped_the_way_production_actually_writes_one(): void
    {
        $application = Application::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'APP-CORE2', 'name' => 'Core Banking 2',
        ]);
        $process = Process::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'PROC-PAY2', 'name' => 'Payments Processing 2',
        ]);
        $assessment = BiaAssessment::query()->create([
            'organization_id' => $this->organization->id, 'process_id' => $process->getKey(),
            'status' => 'approved', 'rto_hours' => 0.5,
        ]);

        Dependency::query()->create([
            'organization_id' => $this->organization->id, 'assessment_id' => $assessment->getKey(),
            'dependable_type' => DependencyType::Applications->value, 'dependable_id' => $application->getKey(),
        ]);

        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Core Banking DR 2',
            'application_id' => $application->getKey(), 'recovery_tier' => 3, 'rto_target_hours' => 24,
        ]);

        $mismatch = app(DrService::class)->tierMismatch($system);

        $this->assertNotNull(
            $mismatch,
            'tierMismatch() found nothing against a dependency row keyed the way production actually writes it '
            ."(dependable_type = '".DependencyType::Applications->value."'). DrService.php queries the literal "
            ."string 'applications', which no real Dependency row ever carries — criterion 4 is unmet against "
            .'real data even though the two tests above (which hardcode the same wrong literal in their own '
            .'fixtures) are green.'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Cadence inheritance (criterion 5, clause map §3.3) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_open_banking_system_gets_the_quarterly_cadence_and_a_four_month_gap_is_overdue(): void
    {
        $application = Application::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'APP-OB', 'name' => 'Open Banking Gateway',
        ]);
        $process = Process::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'PROC-OB', 'name' => 'Open Banking API',
            'regulatory_flags' => ['open_banking'],
        ]);
        $assessment = BiaAssessment::query()->create([
            'organization_id' => $this->organization->id, 'process_id' => $process->getKey(),
            'status' => 'approved', 'rto_hours' => 4,
        ]);
        $this->attachDependency($assessment, $application);

        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Open Banking DR',
            'application_id' => $application->getKey(),
        ]);

        $dr = app(DrService::class);
        $this->assertTrue($dr->inheritsOpenBankingScope($system));

        $lastTest = now()->subMonths(4);
        $nextDue = $dr->deriveNextTestDue($system, $lastTest);

        $this->assertTrue($nextDue->lt(now()), 'A system tested 4 months ago on a quarterly cadence should already be overdue.');

        $system->update(['next_test_due' => $nextDue]);
        $this->assertContains($system->getKey(), $dr->overdue()->pluck('id')->all());
    }

    /** Same defect as the tier-mismatch case above, applied to criterion 5's cadence inheritance. */
    #[Test]
    public function open_banking_cadence_is_inherited_against_a_dependency_row_shaped_the_way_production_actually_writes_one(): void
    {
        $application = Application::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'APP-OB2', 'name' => 'Open Banking Gateway 2',
        ]);
        $process = Process::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'PROC-OB2', 'name' => 'Open Banking API 2',
            'regulatory_flags' => ['open_banking'],
        ]);
        $assessment = BiaAssessment::query()->create([
            'organization_id' => $this->organization->id, 'process_id' => $process->getKey(),
            'status' => 'approved', 'rto_hours' => 4,
        ]);
        Dependency::query()->create([
            'organization_id' => $this->organization->id, 'assessment_id' => $assessment->getKey(),
            'dependable_type' => DependencyType::Applications->value, 'dependable_id' => $application->getKey(),
        ]);

        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Open Banking DR 2',
            'application_id' => $application->getKey(),
        ]);

        $dr = app(DrService::class);

        $this->assertTrue(
            $dr->inheritsOpenBankingScope($system),
            'inheritsOpenBankingScope() returned false against a dependency row keyed the way production actually '
            ."writes it (dependable_type = '".DependencyType::Applications->value."'). Criterion 5's quarterly "
            .'cadence is never inherited against real data, so an open-banking system silently gets the 365-day '
            .'default and never appears overdue at 4 months.'
        );

        $nextDue = $dr->deriveNextTestDue($system, now()->subMonths(4));
        $this->assertTrue($nextDue->lt(now()), 'A system tested 4 months ago on a quarterly cadence should already be overdue.');
    }

    #[Test]
    public function a_system_with_no_open_banking_exposure_gets_the_annual_default(): void
    {
        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Internal Tool DR',
        ]);

        $dr = app(DrService::class);
        $this->assertFalse($dr->inheritsOpenBankingScope($system));

        $nextDue = $dr->deriveNextTestDue($system, now()->subMonths(4));
        $this->assertTrue($nextDue->gt(now()), 'A system on the annual default is not yet overdue at 4 months.');
    }

    /* ------------------------------------------------------------------ */
    /*  Recording a test — computed met_objectives, never typed */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_failover_test_breaching_its_target_is_flagged_and_denormalised_onto_the_system(): void
    {
        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Payments DR',
            'rto_target_hours' => 0.5, 'rpo_target_minutes' => 5,
        ]);

        $test = app(DrService::class)->recordTest($system, [
            'test_type' => DrTestType::Failover->value, 'test_date' => now()->toDateString(),
            'rto_actual_minutes' => 62, 'rpo_actual_minutes' => 3,
        ], $this->engineer);

        $this->assertFalse($test->met_objectives);
        $this->assertFalse($system->fresh()->last_test_met_objectives);
        $this->assertSame(62, $system->fresh()->last_test_rto_actual_minutes);
    }

    #[Test]
    public function a_real_invocation_has_no_path_into_the_dr_test_service(): void
    {
        // Structural, not a business rule to test around: DrService has no
        // method that accepts an incident at all (ADR 0020 §4).
        $this->assertFalse(method_exists(DrService::class, 'recordInvocation'));
        $this->assertFalse((new \ReflectionClass(DrService::class))->hasMethod('fromIncident'));
    }

    /* ------------------------------------------------------------------ */
    /*  Ingestion — idempotent, met_objectives withheld from the provider */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function ingesting_the_same_external_test_id_twice_writes_only_one_row(): void
    {
        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Ingested System',
        ]);

        $payload = ['rto_actual_minutes' => 45, 'met_objectives' => true]; // the provider's claim, ignored

        $first = app(DrService::class)->ingest($system, 'zerto', 'ext-123', $payload);
        $second = app(DrService::class)->ingest($system, 'zerto', 'ext-123', $payload);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, DrTest::query()->where('dr_system_id', $system->getKey())->count());
        $this->assertNull($first->met_objectives, 'met_objectives must never be accepted from the provider payload.');
    }

    #[Test]
    public function confirming_an_ingested_tests_objective_cannot_be_done_twice(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);
        $test = app(DrService::class)->ingest($system, 'veeam', 'ext-456', ['rto_actual_minutes' => 10]);

        app(DrService::class)->confirmObjectives($test, true, $this->engineer);

        $this->expectException(InvalidArgumentException::class);
        app(DrService::class)->confirmObjectives($test->fresh(), false, $this->engineer);
    }

    /* ------------------------------------------------------------------ */
    /*  Backup attestation — an audited event, no new table (clause map §3.5) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_backup_attestation_writes_the_timestamp_and_an_audit_row_with_the_statement(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);

        app(DrService::class)->backupAttestation($system, $this->engineer, 'Backups completed and restore-tested on the DR site.');

        $this->assertNotNull($system->fresh()->last_backup_verified_at);

        $audit = AuditLog::query()->where('event', 'backup.attested')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('restore-tested', json_encode($audit->after));
    }

    #[Test]
    public function an_empty_attestation_statement_is_refused(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);

        $this->expectException(InvalidArgumentException::class);
        app(DrService::class)->backupAttestation($system, $this->engineer, '   ');
    }

    /* ------------------------------------------------------------------ */
    /*  DR invocation record — read-only, never bcms_dr_tests (§3.4, ADR 0020 §4) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function opening_the_dr_invocation_record_writes_no_row_to_bcms_dr_tests(): void
    {
        [$incident] = $this->incidentWithDrInvocation();
        $this->grantPermissions($this->engineer, ['bcms.incident.view', 'bcms.dr.view']);

        $before = DrTest::query()->count();

        $this->actingAs($this->engineer)->get(route('bcms.incidents.dr-invocation.show', $incident))
            ->assertOk();

        $this->assertSame(
            $before,
            DrTest::query()->count(),
            'A read-only evidence assembly must never write to bcms_dr_tests.'
        );
    }

    #[Test]
    public function a_user_in_another_business_unit_cannot_open_this_incidents_dr_invocation_record_by_uuid(): void
    {
        [$incident] = $this->incidentWithDrInvocation();

        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS', 'name' => 'Lagos', 'is_active' => true,
        ]);
        $lagosUser = User::query()->create([
            'name' => 'Lagos Viewer', 'email' => 'lagos-dr@khb.test', 'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(), 'organization_id' => $this->organization->id,
            'business_unit_id' => $lagos->id, 'is_active' => true,
        ]);
        $this->grantPermissions($lagosUser, ['bcms.incident.view', 'bcms.dr.view']);

        $this->actingAs($lagosUser)->get(route('bcms.incidents.dr-invocation.show', $incident))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 review #1 defect 1 — DR ingestion authenticates with a
        per-tenant ApiToken, not a shared HMAC secret (ADR 0020 Amendment 1). */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_dr_ingestion_route_refuses_a_request_with_no_token(): void
    {
        $this->postJson('/api/v1/bcms/dr-tests/ingest/zerto', [
            'dr_system_uuid' => (string) Str::uuid(), 'external_test_id' => 'x',
        ])->assertStatus(401);
    }

    #[Test]
    public function a_token_without_the_dr_test_record_scope_is_refused(): void
    {
        $plain = $this->issueDrToken($this->organization->id, ['bcms.dr.view']);

        $this->withToken($plain)->postJson('/api/v1/bcms/dr-tests/ingest/zerto', [
            'dr_system_uuid' => (string) Str::uuid(), 'external_test_id' => 'x',
        ])->assertStatus(403);
    }

    #[Test]
    public function an_unknown_dr_provider_is_refused_before_any_query(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);
        $plain = $this->issueDrToken($this->organization->id, ['bcms.dr.test.record']);

        $this->withToken($plain)->postJson('/api/v1/bcms/dr-tests/ingest/not-a-real-vendor', [
            'dr_system_uuid' => $system->uuid, 'external_test_id' => 'x',
        ])->assertStatus(422);
    }

    #[Test]
    public function the_tenant_for_dr_ingestion_comes_from_the_token_not_the_payload(): void
    {
        $mySystem = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'Mine']);

        $otherOrg = TenantContext::bypass(fn () => Organization::create([
            'name' => 'Lagos Trust Bank', 'short_name' => 'LTB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]), 'test fixture');

        // A token that belongs to the OTHER organization presents MY
        // system's uuid in the payload. api.auth binds the tenant from the
        // token before this controller runs, so the lookup happens inside
        // the other org's scope and never finds my system.
        $plain = $this->issueDrToken($otherOrg->id, ['bcms.dr.test.record']);

        $this->withToken($plain)->postJson('/api/v1/bcms/dr-tests/ingest/zerto', [
            'dr_system_uuid' => $mySystem->uuid, 'external_test_id' => 'x',
        ])->assertStatus(404)->assertJsonPath('error', 'Unknown DR system.');

        $this->assertSame(0, DrTest::query()->where('dr_system_id', $mySystem->getKey())->count());
    }

    #[Test]
    public function a_correctly_scoped_token_can_ingest_a_test_for_its_own_tenant(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);
        $plain = $this->issueDrToken($this->organization->id, ['bcms.dr.test.record']);

        $this->withToken($plain)->postJson('/api/v1/bcms/dr-tests/ingest/zerto', [
            'dr_system_uuid' => $system->uuid, 'external_test_id' => 'ext-999', 'rto_actual_minutes' => 30,
        ])->assertOk()->assertJsonPath('matched', true);

        $this->assertSame(1, DrTest::query()->where('dr_system_id', $system->getKey())->count());
    }

    #[Test]
    public function a_provider_outside_the_known_emns_list_can_never_authenticate_an_alert_reply(): void
    {
        // 'zerto' used to be a valid key in the SAME webhook_secrets map the
        // EMNS routes read — the defect this allowlist closes. It is refused
        // here even with no BCMS_WEBHOOK_SECRET_ZERTO configured at all,
        // which is the point: the allowlist is checked independently of
        // whether the map happens to contain a matching key.
        $this->postJson('/bcms/alert-reply/zerto', ['body' => 'safe'])->assertStatus(403);
    }

    /**
     * Review #2 advisory A2. The test above proves nothing about the
     * allowlist specifically — an unconfigured secret ALSO 403s on `reply()`
     * (it fails closed either way, per `AlertWebhookController`'s own
     * docblock), so a passing test there is equally consistent with the
     * allowlist working and with it not existing at all. This one configures
     * `webhook_secrets.zerto` and presents a genuinely valid signature over
     * it — the same helper `Phase7EmnsTest::signedReplyPost()` uses — and
     * still expects 403, which only the `KNOWN_PROVIDERS` check can produce
     * once the signature itself is correct.
     */
    #[Test]
    public function a_validly_signed_zerto_callback_is_still_refused_by_the_emns_allowlist(): void
    {
        config()->set('bcms-gateways.webhook_secrets.zerto', 'a-zerto-secret-that-should-never-work-here');

        $payload = ['body' => 'safe'];
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.json_encode($payload), 'a-zerto-secret-that-should-never-work-here');

        $this->postJson('/bcms/alert-reply/zerto', $payload, [
            'X-BCMS-Signature' => $signature,
            'X-BCMS-Timestamp' => $timestamp,
        ])->assertStatus(403);
    }

    /** @param list<string> $scopes */
    private function issueDrToken(int $organizationId, array $scopes): string
    {
        $token = ApiToken::create([
            'organization_id' => $organizationId,
            'tokenable_type' => null,
            'tokenable_id' => null,
            'name' => 'DR ingestion token',
            'token_type' => ApiToken::TYPE_CLIENT,
            'client_id' => 'cid_'.Str::random(24),
            'token' => hash('sha256', $plain = Str::random(48)),
            'abilities' => $scopes,
        ]);

        return $token->id.'|'.$plain;
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 review #1 defect 2 — ingest/record validate their input, and
        next_test_due only ever moves forward. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function ingesting_an_unrecognised_test_type_is_refused(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);

        $this->expectException(InvalidArgumentException::class);
        app(DrService::class)->ingest($system, 'zerto', 'ext-bad-type', ['test_type' => 'not-a-real-type']);
    }

    #[Test]
    public function ingesting_a_future_test_date_is_refused(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);

        $this->expectException(InvalidArgumentException::class);
        app(DrService::class)->ingest($system, 'zerto', 'ext-future', [
            'test_date' => now()->addDay()->toDateString(),
        ]);
    }

    #[Test]
    public function ingesting_a_negative_rto_is_refused(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);

        $this->expectException(InvalidArgumentException::class);
        app(DrService::class)->ingest($system, 'zerto', 'ext-negative', ['rto_actual_minutes' => -5]);
    }

    #[Test]
    public function ingesting_a_note_over_five_thousand_characters_is_refused(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);

        $this->expectException(InvalidArgumentException::class);
        app(DrService::class)->ingest($system, 'zerto', 'ext-longnote', ['notes' => str_repeat('a', 5001)]);
    }

    #[Test]
    public function an_older_test_recorded_late_never_moves_next_test_due_backwards(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);
        $dr = app(DrService::class);

        $dr->ingest($system, 'zerto', 'ext-recent', ['test_date' => now()->subDay()->toDateString()]);
        $afterRecent = $system->fresh();

        $dr->ingest($system, 'zerto', 'ext-old-backfill', ['test_date' => now()->subDays(30)->toDateString()]);
        $afterOldBackfill = $system->fresh();

        $this->assertTrue($afterOldBackfill->last_test_date->equalTo($afterRecent->last_test_date));
        $this->assertTrue($afterOldBackfill->next_test_due->equalTo($afterRecent->next_test_due));
        // Both rows are still written — an older test recorded late is still
        // evidence, it just does not get to move the register's clock.
        $this->assertSame(2, DrTest::query()->where('dr_system_id', $system->getKey())->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 review #2 defect 10 — confirmObjectives() applies in both
        directions, and only when the test is still the system's latest. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function confirming_met_true_on_the_latest_ingested_test_sets_the_systems_last_test_met_objectives(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);
        $test = app(DrService::class)->ingest($system, 'zerto', 'ext-confirm-true', []);

        $this->assertNull($system->fresh()->last_test_met_objectives, 'An ingested test awaits confirmation before the system shows any verdict.');

        app(DrService::class)->confirmObjectives($test, true, $this->engineer);

        $this->assertTrue(
            $system->fresh()->last_test_met_objectives,
            'Confirming met=true on the systems own latest test must set last_test_met_objectives — '
            .'before this fix only the false branch existed, so a confirmed-met ingested test showed unconfirmed for ever.'
        );
    }

    #[Test]
    public function confirming_an_older_tests_objective_does_not_overwrite_the_systems_latest_result(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);
        $dr = app(DrService::class);

        $older = $dr->ingest($system, 'zerto', 'ext-older', ['test_date' => now()->subDays(10)->toDateString()]);
        $newer = $dr->ingest($system, 'zerto', 'ext-newer', ['test_date' => now()->subDay()->toDateString()]);

        // The newer test is confirmed met first, exactly as it would be if a
        // reviewer worked the register in delivery order.
        $dr->confirmObjectives($newer, true, $this->engineer);
        $this->assertTrue($system->fresh()->last_test_met_objectives);

        // The OLDER test is then confirmed NOT met — its own row still
        // records that, but it must not overwrite the newer test's result on
        // the system, which is what the register actually shows as current.
        $dr->confirmObjectives($older, false, $this->engineer);

        $this->assertFalse($older->fresh()->met_objectives);
        $this->assertTrue(
            $system->fresh()->last_test_met_objectives,
            "Confirming an OLDER test's objective must not overwrite the system's latest test result — "
            .'before this fix, confirming either value on any test unconditionally wrote the system column.'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 review #2 defect 11 — an ingested test and the next_test_due
        change it causes name the token that posted it. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_ingested_tests_audit_row_names_the_token(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);
        $plain = $this->issueDrToken($this->organization->id, ['bcms.dr.test.record']);

        $this->withToken($plain)->postJson('/api/v1/bcms/dr-tests/ingest/zerto', [
            'dr_system_uuid' => $system->uuid, 'external_test_id' => 'ext-actor-1',
        ])->assertOk();

        $token = \App\Models\ApiToken::query()->where('organization_id', $this->organization->id)->sole();
        $test = DrTest::query()->where('dr_system_id', $system->getKey())->sole();

        $testAudit = AuditLog::query()
            ->where('auditable_type', DrTest::class)->where('auditable_id', $test->getKey())
            ->where('event', 'created')->first();

        $systemAudit = AuditLog::query()
            ->where('auditable_type', DrSystem::class)->where('auditable_id', $system->getKey())
            ->where('event', 'updated')->latest('id')->first();

        $this->assertNotNull($testAudit, 'The ingested test itself has no audit row.');
        $this->assertNotNull($systemAudit, 'The next_test_due change the ingest caused has no audit row.');

        $this->assertStringContainsString('#'.$token->getKey(), (string) $testAudit->actor_label);
        $this->assertStringContainsString($token->name, (string) $testAudit->actor_label);
        $this->assertStringContainsString('#'.$token->getKey(), (string) $systemAudit->actor_label);
        $this->assertStringContainsString($token->name, (string) $systemAudit->actor_label);

        // And the token is on the row itself, not only in the audit trail —
        // so the register can be searched for "everything this token wrote"
        // without joining through the audit log at all.
        $this->assertSame($token->getKey(), $test->fresh()->evidence['ingested_by_token_id'] ?? null);
        $this->assertSame($token->name, $test->fresh()->evidence['ingested_by_token_name'] ?? null);
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 review #2 advisory A3 — the dedupe lookup is a portable JSON
        query, and a redelivery race is serialised by a row lock rather than
        two PHP "not found" checks both passing. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function ingest_locks_the_system_row_before_its_dedupe_check(): void
    {
        // WHY THIS IS A QUERY-LOG PROOF, NOT A TWO-CONNECTION RACE.
        // `RefreshDatabase` wraps this whole test in one uncommitted
        // transaction on the default connection — every fixture row created
        // above, including $system, only exists inside it. A second,
        // independent PDO connection can never see or lock that row until
        // the transaction commits, which does not happen until the test
        // ends, so a genuine cross-connection lock-contention test is not
        // reachable from here (this was tried and reliably self-deadlocked:
        // the second connection's own `FOR UPDATE` timed out waiting on the
        // still-open outer transaction, not on anything ingest() did).
        // `ReferenceCodeConcurrencyTest` is deliberately the only test in the
        // suite that works around this, by forking real OS processes that
        // each boot their own connection outside any such wrapper — "it is
        // slow, and it is worth it precisely once". A second such test is
        // not worth it for this invariant; proving the SQL ingest() issues
        // actually asks the database to serialise is.
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);

        DB::enableQueryLog();
        app(DrService::class)->ingest($system, 'zerto', 'ext-lock-proof', []);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockQuery = collect($queries)->first(
            fn (array $q) => str_contains($q['query'], 'bcms_dr_systems') && str_contains(strtolower($q['query']), 'for update')
        );
        $this->assertNotNull(
            $lockQuery,
            'ingest() must issue a SELECT ... FOR UPDATE against bcms_dr_systems before its dedupe check, or two '
            .'redeliveries of the same webhook can both pass "not found" and both insert.'
        );

        $dedupeQuery = collect($queries)->first(fn (array $q) => str_contains($q['query'], 'bcms_dr_tests') && str_contains($q['query'], 'select'));
        $this->assertNotNull($dedupeQuery, 'ingest() issued no lookup at all against bcms_dr_tests.');

        // `json_contains(...)`, PARAMETERISED (the bound value is a `?`, not
        // interpolated into the SQL string) — Laravel's own generated form
        // of `whereJsonContains()`, which ADR 0020 Amendment 3 rules
        // acceptable on MariaDB 10.4 precisely because it is this, not a raw
        // hand-written `JSON_CONTAINS('...literal...')` string built outside
        // the query builder. The defect this replaces was neither: it was
        // `->get()->first(fn ...)` — no WHERE filter on `evidence` at all,
        // fetching every test for the system and scanning them in PHP.
        $this->assertStringContainsStringIgnoringCase('json_contains(`evidence`, ?', (string) $dedupeQuery['query']);
        $this->assertMatchesRegularExpression(
            '/where `dr_system_id` = \? and json_contains/i',
            (string) $dedupeQuery['query'],
            'The lookup must filter by provider/external_test_id in SQL, not fetch every row for the system and '
            .'scan them in PHP.'
        );

        // And the lookup itself still works, end to end.
        $again = app(DrService::class)->ingest($system, 'zerto', 'ext-lock-proof', []);
        $this->assertSame(1, DrTest::query()->where('dr_system_id', $system->getKey())->count());
        $this->assertNotNull($again);
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 review #2 advisory A4 — a loosely-typed payload 422s, never
        500s, and never silently stores the wrong value. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_string_false_rollback_required_is_stored_as_a_real_false_not_a_truthy_string(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);

        // PHP's own (bool) cast treats the non-empty string "false" as TRUE —
        // the exact trap this normalisation exists to close. Proven against
        // the ingest path, which has no Form Request to coerce it first.
        $test = app(DrService::class)->ingest($system, 'zerto', 'ext-bool-string', ['rollback_required' => 'false']);

        $this->assertFalse($test->fresh()->rollback_required);
    }

    #[Test]
    public function an_array_test_type_is_refused_with_422_not_500_over_the_ingestion_route(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);
        $plain = $this->issueDrToken($this->organization->id, ['bcms.dr.test.record']);

        $this->withToken($plain)->postJson('/api/v1/bcms/dr-tests/ingest/zerto', [
            'dr_system_uuid' => $system->uuid, 'external_test_id' => 'ext-array-type',
            'test_type' => ['failover'],
        ])->assertStatus(422);

        $this->assertSame(0, DrTest::query()->where('dr_system_id', $system->getKey())->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 review #2 defect 12 — dependentProcesses() traversal (the
        server-built default for the raise-a-finding control). */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function dependent_processes_returns_every_approved_process_and_excludes_draft_ones(): void
    {
        $application = Application::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'APP-DEP', 'name' => 'Dependent App',
        ]);

        $approvedProcess = Process::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'PROC-DEP-A', 'name' => 'Approved Process',
        ]);
        $draftProcess = Process::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'PROC-DEP-B', 'name' => 'Draft Process',
        ]);

        $approvedAssessment = BiaAssessment::query()->create([
            'organization_id' => $this->organization->id, 'process_id' => $approvedProcess->getKey(),
            'status' => 'approved', 'rto_hours' => 2,
        ]);
        $draftAssessment = BiaAssessment::query()->create([
            'organization_id' => $this->organization->id, 'process_id' => $draftProcess->getKey(),
            'status' => 'draft', 'rto_hours' => 1,
        ]);
        $this->attachDependency($approvedAssessment, $application);
        $this->attachDependency($draftAssessment, $application);

        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Dependent App DR',
            'application_id' => $application->getKey(),
        ]);

        $processes = app(DrService::class)->dependentProcesses($system);

        $this->assertCount(1, $processes);
        $this->assertSame($approvedProcess->getKey(), $processes->first()->getKey());
    }

    /**
     * End to end: DrPresenter::testShow() hands the screen exactly the
     * values `RaiseFindingForm` sends back — one process, one submission —
     * and the resulting Finding carries `affected_process_id` and
     * `iso22301.8.2.2`, queryable by process the way the BIA screen's own
     * finding-against-a-process reading would (`Finding::where(
     * 'affected_process_id', ...)` — the same predicate ADR 0019 names for
     * Phase 9's identical "the finding is the flag" pattern).
     */
    #[Test]
    public function a_breached_test_raises_a_finding_carrying_affected_process_id_and_the_bia_clause_ref(): void
    {
        $application = Application::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'APP-BREACH', 'name' => 'Breach App',
        ]);
        $process = Process::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'PROC-BREACH', 'name' => 'Breach Process',
        ]);
        $assessment = BiaAssessment::query()->create([
            'organization_id' => $this->organization->id, 'process_id' => $process->getKey(),
            'status' => 'approved', 'rto_hours' => 1,
        ]);
        $this->attachDependency($assessment, $application);

        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Breach App DR',
            'application_id' => $application->getKey(), 'rto_target_hours' => 0.5, 'rpo_target_minutes' => 5,
        ]);

        $test = app(DrService::class)->recordTest($system, [
            'test_type' => DrTestType::Failover->value, 'test_date' => now()->toDateString(),
            'rto_actual_minutes' => 90, 'rpo_actual_minutes' => 20,
        ], $this->engineer);
        $this->assertFalse($test->fresh()->met_objectives, 'Fixture is not actually a breach.');

        $this->grantPermissions($this->engineer, ['bcms.dr.view', 'bcms.finding.manage']);

        $screen = app(\App\Presenters\Bcms\DrPresenter::class)->testShow($test->fresh());
        $this->assertCount(1, $screen['affected_processes']);
        $this->assertSame($process->getKey(), $screen['affected_processes'][0]['id']);
        $this->assertSame('iso22301.8.2.2', $screen['finding_iso_clause_ref']);

        // What RaiseFindingForm actually sends for a single affected process.
        $this->actingAs($this->engineer)->post($screen['raise_finding_url'], [
            'source' => 'dr_test',
            'classification' => 'nonconformity',
            'severity' => 'medium',
            'dr_test_id' => $test->getKey(),
            'iso_clause_ref' => $screen['finding_iso_clause_ref'],
            'affected_process_id' => $screen['affected_processes'][0]['id'],
            'description' => 'Failover exceeded target RTO (Dependent process: Breach Process)',
        ])->assertSessionHasNoErrors();

        $finding = \App\Models\Bcms\Finding::query()
            ->where('affected_process_id', $process->getKey())
            ->where('iso_clause_ref', 'iso22301.8.2.2')
            ->where('dr_test_id', $test->getKey())
            ->first();

        $this->assertNotNull(
            $finding,
            'No finding was raised carrying both affected_process_id and iso22301.8.2.2 against this breached test.'
        );
        $this->assertSame('dr_test', $finding->source->value);
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 review #1 defect 3 — DrTest, IncidentTask and
        IncidentNotification are audited. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function recording_a_dr_test_writes_an_audit_row(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);

        $test = app(DrService::class)->recordTest($system, [
            'test_type' => DrTestType::Failover->value, 'test_date' => now()->toDateString(),
        ], $this->engineer);

        $audit = AuditLog::query()
            ->where('auditable_type', DrTest::class)
            ->where('auditable_id', $test->getKey())
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($audit);
    }

    #[Test]
    public function creating_an_incident_task_writes_an_audit_row(): void
    {
        [$incident] = $this->incidentWithDrInvocation();

        $task = IncidentTask::query()->create([
            'organization_id' => $this->organization->id, 'incident_id' => $incident->getKey(),
            'title' => 'Contact the payment switch vendor', 'status' => 'open',
        ]);

        $audit = AuditLog::query()
            ->where('auditable_type', IncidentTask::class)
            ->where('auditable_id', $task->getKey())
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($audit);
    }

    #[Test]
    public function creating_an_incident_notification_writes_an_audit_row_and_excludes_content_snapshot(): void
    {
        [$incident] = $this->incidentWithDrInvocation();

        $notification = IncidentNotification::query()->create([
            'organization_id' => $this->organization->id, 'incident_id' => $incident->getKey(),
            'regulator' => NotificationRegulator::Ndpc->value,
            'basis_clause_ref' => NotificationRegulator::Ndpc->basisClauseRef()->value,
            'kind' => NotificationKind::Initial->value, 'sequence' => 1,
            'awareness_at' => now(),
            'content_snapshot' => ['data_subjects_affected' => 'a genuinely secret detail'],
        ]);

        $audit = AuditLog::query()
            ->where('auditable_type', IncidentNotification::class)
            ->where('auditable_id', $notification->getKey())
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('a genuinely secret detail', json_encode($audit->after));
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 review #1 defect 11 — the DR register's query count does not
        grow with the number of systems on it. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_dr_register_runs_the_same_number_of_queries_at_two_and_at_eight_systems(): void
    {
        $application = Application::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'APP-REG', 'name' => 'Register App',
        ]);
        $process = Process::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'PROC-REG', 'name' => 'Register Process',
        ]);
        $assessment = BiaAssessment::query()->create([
            'organization_id' => $this->organization->id, 'process_id' => $process->getKey(),
            'status' => 'approved', 'rto_hours' => 0.5,
        ]);
        $this->attachDependency($assessment, $application);

        $this->actingAs($this->engineer);

        $makeSystems = function (int $count) use ($application): void {
            for ($i = 0; $i < $count; $i++) {
                DrSystem::query()->create([
                    'organization_id' => $this->organization->id, 'name' => 'Register System '.Str::random(6),
                    'application_id' => $i % 2 === 0 ? $application->getKey() : null,
                    'recovery_tier' => 3, 'rto_target_hours' => 24,
                ]);
            }
        };

        $makeSystems(2);

        // A cold call warms Spatie's permission cache (`$user?->can(...)`
        // below queries `permissions`/`roles`/`model_has_roles` exactly
        // once, ever, per test process) — measuring the very first call
        // would count that warm-up as though it scaled with the number of
        // systems, when it does not scale with anything.
        app(DrPresenter::class)->register();

        DB::enableQueryLog();
        app(DrPresenter::class)->register();
        $queriesAtTwo = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        $makeSystems(6); // 8 systems total now — created with logging OFF,
        // or the fixture's own inserts (and their audit rows) would be
        // counted as though register() issued them.

        DB::enableQueryLog();
        app(DrPresenter::class)->register();
        $queriesAtEight = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $queriesAtTwo, $queriesAtEight,
            "DrPresenter::register() issued {$queriesAtTwo} queries for 2 systems and {$queriesAtEight} for 8 — "
            .'the query count must not grow with the number of systems on the register.',
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 review #1 defect 12 — StoreBcmsDrTestRequest refuses a future
        date and a cross-tenant occurrence id. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function storing_a_dr_test_with_a_future_date_is_refused(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);
        $this->grantPermissions($this->engineer, ['bcms.dr.test.record']);

        $this->actingAs($this->engineer)
            ->post(route('bcms.dr-systems.tests.store', $system), [
                'test_type' => DrTestType::Failover->value, 'test_date' => now()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('test_date');
    }

    #[Test]
    public function storing_a_dr_test_with_another_tenants_occurrence_id_is_refused(): void
    {
        $system = DrSystem::query()->create(['organization_id' => $this->organization->id, 'name' => 'S']);
        $this->grantPermissions($this->engineer, ['bcms.dr.test.record']);

        $otherOrg = TenantContext::bypass(fn () => Organization::create([
            'name' => 'Abuja Federal Bank', 'short_name' => 'AFB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]), 'test fixture');

        $foreignOccurrenceId = $this->foreignOccurrenceId($otherOrg);

        $this->actingAs($this->engineer)
            ->post(route('bcms.dr-systems.tests.store', $system), [
                'test_type' => DrTestType::Failover->value, 'test_date' => now()->toDateString(),
                'occurrence_id' => $foreignOccurrenceId,
            ])
            ->assertSessionHasErrors('occurrence_id');

        $this->assertSame(0, DrTest::query()->where('dr_system_id', $system->getKey())->count());
    }

    private function foreignOccurrenceId(Organization $org): int
    {
        $typeId = DB::table('bcms_exercise_types')->insertGetId([
            'organization_id' => $org->id, 'code' => 'FD-'.Str::random(4), 'name' => 'Fire Drill',
            'ladder_level' => 'tabletop', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $programmeId = DB::table('bcms_exercise_programmes')->insertGetId([
            'uuid' => (string) Str::uuid(), 'organization_id' => $org->id, 'year' => now()->year,
            'name' => 'Programme '.Str::random(4), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $definitionId = DB::table('bcms_exercise_definitions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'organization_id' => $org->id,
            'exercise_programme_id' => $programmeId, 'exercise_type_id' => $typeId,
            'name' => 'Definition '.Str::random(4), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('bcms_exercise_occurrences')->insertGetId([
            'uuid' => (string) Str::uuid(), 'organization_id' => $org->id,
            'definition_id' => $definitionId, 'sequence_no' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: Incident, 1: DrSystem}
     */
    private function incidentWithDrInvocation(): array
    {
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Core Banking Failover Runbook',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);

        $system = DrSystem::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Core Banking DR',
            'failover_runbook_plan_id' => $plan->getKey(),
        ]);

        $incident = app(IncidentService::class)->declare([
            'title' => 'A real invocation', 'severity' => 'sev2', 'detected_at' => now()->toIso8601String(),
            'business_unit_id' => $this->unit->id,
        ], $this->engineer);

        // `business_unit_user`, not the `users.business_unit_id` column, is
        // what `RcsaScope::unitIdsFor()` reads (ADR 0017 — visibility is
        // resolved through the pivot). A user created after the seed's
        // once-off backfill needs the same assignment written explicitly, or
        // an incident scoped to a real unit is invisible to its own engineer.
        DB::table('business_unit_user')->insertOrIgnore([
            'organization_id' => $this->organization->id, 'user_id' => $this->engineer->getKey(),
            'business_unit_id' => $this->unit->id, 'includes_descendants' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        PlanActivation::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'incident_id' => $incident->getKey(), 'is_exercise' => false,
            'activated_by' => $this->engineer->getKey(), 'activated_at' => now(),
            'activation_reason' => 'Sev2 declared.',
        ]);

        return [$incident, $system];
    }

    private function grantPermissions(User $user, array $permissions): void
    {
        $role = Role::findOrCreate('phase10dr-'.md5($user->email), 'web');

        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $user->assignRole($role);
    }

    /**
     * Builds a dependency the way production actually does — through the
     * morph relation's own `associate()`, under `Relation::enforceMorphMap()`
     * — rather than a hand-typed `dependable_type` literal that can silently
     * diverge from the enforced morph map (see the two tests above named for
     * this exact defect).
     */
    private function attachDependency(BiaAssessment $assessment, \Illuminate\Database\Eloquent\Model $target): Dependency
    {
        $dependency = new Dependency([
            'organization_id' => $this->organization->id,
            'assessment_id' => $assessment->getKey(),
        ]);

        $dependency->dependable()->associate($target);
        $dependency->save();

        return $dependency;
    }
}
