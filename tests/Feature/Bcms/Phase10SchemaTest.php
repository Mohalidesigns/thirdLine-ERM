<?php

namespace Tests\Feature\Bcms;

use App\Models\Bcms\Aar;
use App\Models\Organization;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0020 §1's own consequence: "the nullability change is the riskiest
 * line in the migration... assert afterwards, in a test, that the FK and
 * both unique indexes still exist and that two AARs on one occurrence are
 * still refused by the database."
 *
 * ALSO GUARDS THE MARIADB IDENTIFIER-LENGTH DEFECT FOUND LIVE ON THIS BRANCH:
 * an auto-generated index name on `bcms_incident_notifications` was 71
 * characters, over MariaDB 10.4's 64-character limit, and `migrate:fresh`
 * failed outright — every `RefreshDatabase` test on the branch, not just this
 * one, went red. Every index and foreign-key constraint this phase adds is
 * asserted under the limit here so the same defect cannot silently recur.
 */
class Phase10SchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function every_index_and_foreign_key_name_this_phase_touches_stays_under_the_mariadb_identifier_limit(): void
    {
        $offenders = [];

        foreach (['bcms_aars', 'bcms_incident_notifications', 'bcms_incidents', 'bcms_incident_log', 'bcms_incident_tasks', 'bcms_plan_activations'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (strlen($index['name']) > 64) {
                    $offenders[] = "{$table}.{$index['name']} (".strlen($index['name']).' chars)';
                }
            }

            foreach (Schema::getForeignKeys($table) as $fk) {
                if (strlen($fk['name']) > 64) {
                    $offenders[] = "{$table}.{$fk['name']} (".strlen($fk['name']).' chars)';
                }
            }
        }

        $this->assertSame([], $offenders, "MariaDB 10.4's identifier limit is 64 characters; these are over it:\n".implode("\n", $offenders));
    }

    #[Test]
    public function occurrence_id_is_nullable_and_keeps_its_foreign_key_and_unique_index(): void
    {
        $this->assertTrue(Schema::hasColumn('bcms_aars', 'occurrence_id'));

        $foreignKeys = collect(Schema::getForeignKeys('bcms_aars'));
        $this->assertTrue(
            $foreignKeys->contains(fn (array $fk) => $fk['columns'] === ['occurrence_id']),
            'bcms_aars.occurrence_id lost its foreign key.'
        );

        $indexes = collect(Schema::getIndexes('bcms_aars'));
        $this->assertTrue(
            $indexes->contains(fn (array $i) => $i['columns'] === ['occurrence_id'] && $i['unique']),
            'bcms_aars.occurrence_id lost its unique index — "one AAR per occurrence" is no longer enforced.'
        );
    }

    #[Test]
    public function incident_id_is_nullable_unique_and_foreign_keyed_to_incidents(): void
    {
        $this->assertTrue(Schema::hasColumn('bcms_aars', 'incident_id'));

        $foreignKeys = collect(Schema::getForeignKeys('bcms_aars'));
        $this->assertTrue(
            $foreignKeys->contains(fn (array $fk) => $fk['columns'] === ['incident_id'] && $fk['foreign_table'] === 'bcms_incidents'),
        );

        $indexes = collect(Schema::getIndexes('bcms_aars'));
        $this->assertTrue(
            $indexes->contains(fn (array $i) => $i['columns'] === ['incident_id'] && $i['unique']),
        );
    }

    /** ADR 0020 Amendment 4 — the one column the P10 freeze line grew by, second time round. */
    #[Test]
    public function kept_active_entry_id_is_nullable_and_foreign_keyed_to_the_incident_log_not_unique(): void
    {
        $this->assertTrue(Schema::hasColumn('bcms_plan_activations', 'kept_active_entry_id'));

        $foreignKeys = collect(Schema::getForeignKeys('bcms_plan_activations'));
        $this->assertTrue(
            $foreignKeys->contains(fn (array $fk) => $fk['columns'] === ['kept_active_entry_id'] && $fk['foreign_table'] === 'bcms_incident_log'),
        );

        // Deliberately NOT unique — nothing stops two activations from
        // legitimately pointing at the same kind of entry, and uniqueness
        // was never the rule Amendment 4 asked for (one decision entry is
        // written per kept-active activation, but that is a service
        // invariant, not a database constraint).
        $indexes = collect(Schema::getIndexes('bcms_plan_activations'));
        $this->assertFalse(
            $indexes->contains(fn (array $i) => $i['columns'] === ['kept_active_entry_id'] && $i['unique']),
        );
    }

    #[Test]
    public function two_aars_on_one_occurrence_are_still_refused_by_the_database(): void
    {
        $occurrence = \App\Models\Bcms\ExerciseOccurrence::query()->create([
            'organization_id' => TenantContext::organizationId(),
            'definition_id' => $this->fakeDefinitionId(),
            'sequence_no' => 1,
            'status' => 'planned',
        ]);
        $occurrenceId = $occurrence->getKey();

        Aar::query()->create([
            'organization_id' => TenantContext::organizationId(), 'occurrence_id' => $occurrenceId,
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Aar::query()->create([
            'organization_id' => TenantContext::organizationId(), 'occurrence_id' => $occurrenceId,
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);
    }

    #[Test]
    public function two_pirs_on_one_incident_are_also_refused_by_the_database(): void
    {
        $incidentId = (int) \Illuminate\Support\Facades\DB::table('bcms_incidents')->insertGetId([
            'organization_id' => TenantContext::organizationId(),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => 'INC-TEST-1', 'title' => 'Test', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Aar::query()->create([
            'organization_id' => TenantContext::organizationId(), 'incident_id' => $incidentId,
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Aar::query()->create([
            'organization_id' => TenantContext::organizationId(), 'incident_id' => $incidentId,
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);
    }

    private function fakeDefinitionId(): int
    {
        $type = \App\Models\Bcms\ExerciseType::query()->firstOrFail();
        $programme = \App\Models\Bcms\ExerciseProgramme::query()->create(['year' => 2027, 'name' => 'Programme 2027']);

        $definition = \App\Models\Bcms\ExerciseDefinition::query()->create([
            'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type->getKey(),
            'name' => 'Fire drill',
        ]);

        return $definition->getKey();
    }
}
