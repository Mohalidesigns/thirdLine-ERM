<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\ContactSource;
use App\Events\Bcms\ExerciseOccurrenceScheduled;
use App\Listeners\Bcms\MaterialiseReminderLadder;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\BusinessUnit;
use App\Models\Organization;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `BcmsAuditable::writeBcmsAuditRow()` and
 * `MaterialiseReminderLadder::handle()` catch every throwable so a business
 * write never rolls back because its audit trail could not be recorded — but
 * `QueryException::getMessage()` inlines every BOUND VALUE into the formatted
 * SQL text. A contact-bearing model's own name, mobile number or email would
 * therefore have reached the one log sink with no declared retention or
 * residency, on the exact path that exists to make a write accountable. Both
 * catch blocks now log only `sqlstate`/`driver_error_code` (or a bare
 * exception code for a non-`QueryException`), never `getMessage()` and never
 * `errorInfo[2]` (the driver's own repeated message text).
 *
 * These tests force a genuine `QueryException` — a truncation error under
 * MariaDB's strict mode, not a mock — and inspect what actually reached
 * `Log::error`.
 */
class Phase7AuditFailureLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const RECOGNISABLE_MOBILE = '+2348199999999';

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

    #[Test]
    public function a_failed_audit_write_never_logs_the_contacts_own_mobile_number(): void
    {
        $unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $contact = Contact::query()->create([
            'organization_id' => $this->organization->id,
            'source' => ContactSource::Manual->value,
            'full_name' => 'Recognisable Person',
            'employee_id' => 'E-1',
            'business_unit_id' => $unit->id,
            'email' => 'recognisable@khb.test',
            'mobile_primary' => self::RECOGNISABLE_MOBILE,
            'preferred_language' => 'en',
            'consent_status' => 'granted',
            'verification_status' => 'verified',
            'last_verified_at' => now(),
            'is_active' => true,
        ]);

        $captured = null;
        Log::listen(function ($event) use (&$captured) {
            if ($event->message === 'BCMS audit row could not be written') {
                $captured = $event->context;
            }
        });

        // `event` is varchar(60) (widened by
        // 2026_09_16_120001_widen_bcms_audit_log_event_column). This is 90
        // characters, which MariaDB's strict mode refuses with a genuine
        // QueryException rather than a silent truncation.
        $overlongEvent = str_repeat('x', 90);

        $contact->recordAudit($overlongEvent, [
            'note' => 'Reachable on '.self::RECOGNISABLE_MOBILE,
        ]);

        $this->assertNotNull($captured, 'The audit failure must be logged.');

        $encoded = json_encode($captured);

        $this->assertStringNotContainsString(self::RECOGNISABLE_MOBILE, $encoded);
        $this->assertStringNotContainsString('Recognisable Person', $encoded);
        $this->assertStringNotContainsString('recognisable@khb.test', $encoded);

        $this->assertArrayHasKey('sqlstate', $captured);
        $this->assertArrayHasKey('driver_error_code', $captured);
        $this->assertNotNull($captured['sqlstate']);
    }

    #[Test]
    public function the_reminder_ladder_failure_log_never_carries_a_bound_sql_value(): void
    {
        $programme = ExerciseProgramme::query()->create([
            'organization_id' => $this->organization->id,
            'year' => (int) now()->year,
            'name' => 'Programme',
            'status' => 'draft',
        ]);

        $type = \App\Models\Bcms\ExerciseType::query()->first();

        $definition = ExerciseDefinition::query()->create([
            'organization_id' => $this->organization->id,
            'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type?->getKey(),
            'name' => 'Evacuation drill',
            'frequency_per_year' => 1,
        ]);

        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => \App\Enums\Bcms\OccurrenceStatus::Planned->value,
        ]);

        // Force the underlying write to fail with a genuine QueryException
        // WITHOUT altering the schema (a DDL statement mid-test causes an
        // implicit commit on MariaDB, which `RefreshDatabase`'s transaction
        // wrapper cannot undo, permanently corrupting the shared gate
        // database for every later test). Hard-deleting the parent row is
        // schema-safe: `ReadinessTask.occurrence_id` is foreign-key
        // constrained to `bcms_exercise_occurrences`, so inserting a task
        // against an occurrence that no longer exists is refused by the
        // database itself.
        $occurrence->forceDelete();

        $captured = null;
        Log::listen(function ($event) use (&$captured) {
            if ($event->message === 'BCMS could not arm the reminder ladder for an occurrence') {
                $captured = $event->context;
            }
        });

        app(MaterialiseReminderLadder::class)->handle(
            new ExerciseOccurrenceScheduled($occurrence, $definition)
        );

        $this->assertNotNull($captured, 'The materialisation failure must be logged.');

        $this->assertArrayHasKey('sqlstate', $captured);
        $this->assertArrayHasKey('driver_error_code', $captured);
        $this->assertArrayNotHasKey('message', $captured);
        $this->assertArrayNotHasKey('sql', $captured);
    }
}
