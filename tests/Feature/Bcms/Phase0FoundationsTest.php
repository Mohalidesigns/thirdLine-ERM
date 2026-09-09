<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\DependencyType;
use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\LadderLevel;
use App\Models\Bcms\Application;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\BlackoutPeriod;
use App\Models\Bcms\ClauseRef;
use App\Models\Bcms\DataSet;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\Equipment;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\ReadinessTemplate;
use App\Models\Bcms\Site;
use App\Models\Organization;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Bcms\BcmsSettings;
use App\Support\Bcms\ModuleSections;
use App\Support\MorphTypes;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Database\Seeders\Bcms\Reference\ClauseRefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate G0's acceptance criteria, as tests rather than as a checklist somebody
 * ticked once on one machine.
 *
 * The phase prompt states eight criteria. Six of them are asserted here in
 * full; criterion 1 (`migrate:fresh --seed` green) is what running this suite
 * proves, and criterion 7 (the queue separation under a 10,000-job backlog) is
 * asserted at the CONFIGURATION level only — that four supervisors exist on
 * four separate queues and that life safety is the one guaranteed a warm
 * worker. A real Redis backlog is a Phase 12 load test, and saying so is better
 * than a green tick that proves less than it looks like it proves
 * (`docs/performance/baseline.md` records the same limitation).
 */
class Phase0FoundationsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank',
            'short_name' => 'KHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 2 — every table exists, and the manifest agrees */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_table_in_the_frozen_manifest_exists_with_its_full_column_set(): void
    {
        /** @var array<string, list<string>> $manifest */
        $manifest = require database_path('schema/bcms-manifest.php');

        $this->assertNotEmpty($manifest, 'The BCMS schema manifest is empty.');

        foreach ($manifest as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Missing BCMS table: {$table}");

            $live = Schema::getColumnListing($table);
            $missing = array_values(array_diff($columns, $live));

            $this->assertSame([], $missing, "Missing columns on {$table}: ".implode(', ', $missing));
        }
    }

    #[Test]
    public function the_verify_schema_command_exits_zero_against_a_fresh_database(): void
    {
        // The command is the operator-facing half of the freeze. If it does not
        // pass on a fresh install it will never be trusted on a real one.
        $this->artisan('bcms:verify-schema')->assertExitCode(0);
    }

    #[Test]
    public function every_blueprint_section_nine_table_is_present(): void
    {
        // The blueprint's own list, restated so that a table quietly dropped
        // from a migration during a merge fails here rather than in Phase 4.
        $required = [
            'bcms_programmes', 'bcms_processes', 'bcms_bia_campaigns', 'bcms_bia_assessments',
            'bcms_bia_impacts', 'bcms_dependencies',
            'bcms_strategies', 'bcms_plans', 'bcms_plan_sections', 'bcms_plan_activations',
            'bcms_exercise_types', 'bcms_exercise_programmes', 'bcms_exercise_definitions',
            'bcms_exercise_occurrences', 'bcms_exercise_participants', 'bcms_readiness_tasks',
            'bcms_reminder_schedules', 'bcms_exercise_injects', 'bcms_exercise_timeline',
            'bcms_exercise_scores', 'bcms_aars', 'bcms_findings', 'bcms_corrective_actions',
            'bcms_call_trees', 'bcms_call_tree_nodes', 'bcms_call_tree_tests',
            'bcms_call_tree_test_nodes', 'bcms_contacts', 'bcms_alert_templates', 'bcms_alerts',
            'bcms_alert_recipients', 'bcms_notification_deliveries',
            'bcms_incidents', 'bcms_incident_log', 'bcms_incident_tasks', 'bcms_dr_systems',
            'bcms_dr_tests', 'bcms_training_curricula', 'bcms_training_records',
            'bcms_readiness_templates', 'bcms_readiness_template_tasks', 'bcms_blackout_periods',
            'bcms_objectives', 'bcms_settings',
        ];

        foreach ($required as $table) {
            $this->assertTrue(Schema::hasTable($table), "Blueprint §9 table missing: {$table}");
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 6 — the morph map resolves all seven dependency types */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_dependency_type_round_trips_through_the_morph_map(): void
    {
        $assessment = BiaAssessment::factory()->create();

        // A list of pairs rather than a keyed array: a PHP array key cannot be
        // an enum, and the alternative — keying by `->value` — would let a typo
        // in the key silently test the wrong type.
        $targets = [
            [DependencyType::Applications, Application::factory()->create()],
            [DependencyType::Sites, Site::factory()->create()],
            [DependencyType::Equipment, Equipment::factory()->create()],
            [DependencyType::DataSets, DataSet::factory()->create()],
            [DependencyType::Processes, Process::factory()->create()],
            [DependencyType::Users, User::factory()->create(['organization_id' => $this->organization->id])],
            [DependencyType::Vendors, ThirdParty::create([
                'legal_name' => 'Interlink Systems Limited',
                'slug' => 'interlink-systems-'.uniqid(),
                'entity_type' => 'company',
                'country_of_incorporation' => 'NG',
            ])],
        ];

        $this->assertCount(7, $targets, 'Blueprint §9.1 names seven dependency types.');
        $this->assertCount(7, DependencyType::cases());

        foreach ($targets as [$type, $target]) {
            $dependency = new Dependency([
                'assessment_id' => $assessment->id,
                'dependency_type' => 'supporting',
            ]);
            $dependency->dependable()->associate($target);
            $dependency->save();

            $fresh = Dependency::query()->findOrFail($dependency->id);

            // The SHORT ALIAS is what is stored, never a class name. A row
            // holding an FQCN survives until somebody renames a namespace.
            $this->assertSame(
                $type->value,
                $fresh->dependable_type,
                "Dependency stored a class name rather than the '{$type->value}' alias."
            );
            $this->assertNotNull($fresh->dependable, "The '{$type->value}' morph did not resolve.");
            $this->assertTrue($fresh->dependable->is($target));
            $this->assertSame($type, $fresh->type());
            $this->assertNotSame('', $fresh->dependableLabel());
        }
    }

    #[Test]
    public function the_dependency_enum_and_the_application_morph_map_cannot_drift(): void
    {
        $global = MorphTypes::map();

        foreach (DependencyType::morphMap() as $alias => $class) {
            $this->assertArrayHasKey(
                $alias,
                $global,
                "DependencyType declares '{$alias}' but App\\Support\\MorphTypes does not. "
                .'Relation::enforceMorphMap() takes one map for the whole application.'
            );
            $this->assertSame($global[$alias], $class, "The '{$alias}' alias points at two different classes.");
        }
    }

    #[Test]
    public function a_dependency_whose_target_has_gone_says_so_rather_than_rendering_blank(): void
    {
        $assessment = BiaAssessment::factory()->create();

        $dependency = Dependency::query()->create([
            'assessment_id' => $assessment->id,
            'dependable_type' => DependencyType::Vendors->value,
            // A vendor id that no longer resolves — the state a soft-deleted
            // TPRM third party leaves behind.
            'dependable_id' => 999999,
        ]);

        $this->assertNull($dependency->dependable);
        $this->assertStringContainsString('no longer in the register', $dependency->dependableLabel());
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 5 — reference data seeds, and is visible to a tenant */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function system_owned_reference_rows_are_visible_to_a_tenant(): void
    {
        $this->seed(BcmsReferenceSeeder::class);

        // TenantContext is set for this whole test class. These rows carry
        // `organization_id = null`, so without `$tenantIncludesGlobal` the
        // global scope would hide every one of them — the failure that looks
        // exactly like "the seeder did not run".
        $this->assertGreaterThan(0, ExerciseType::query()->count(), 'Exercise types are invisible to a tenant.');
        $this->assertGreaterThan(0, ReadinessTemplate::query()->count(), 'Readiness templates are invisible to a tenant.');
        $this->assertGreaterThan(0, BlackoutPeriod::query()->count(), 'Blackout periods are invisible to a tenant.');

        $this->assertTrue(
            ExerciseType::query()->where('code', 'DRFAILOVER')->exists(),
            'The DR failover exercise type did not seed.'
        );
    }

    #[Test]
    public function the_regulated_cadences_are_the_ones_the_regulator_actually_states(): void
    {
        $this->seed(BcmsReferenceSeeder::class);

        // CBN Open Banking: failover quarterly, DR test six-monthly. These two
        // are the only shipped frequencies that carry a regulatory driver, and
        // a change to either is a claim about Nigerian law.
        $failover = ExerciseType::query()->where('code', 'DRFAILOVER')->firstOrFail();
        $this->assertSame(4, (int) $failover->default_frequency_per_year);
        $this->assertSame(IsoClauseRef::Cbn_ob_failover->value, $failover->cadence_clause_ref);

        $drTest = ExerciseType::query()->where('code', 'DRTEST')->firstOrFail();
        $this->assertSame(2, (int) $drTest->default_frequency_per_year);
        $this->assertSame(IsoClauseRef::Cbn_ob_dr_test->value, $drTest->cadence_clause_ref);

        // Everything else is a starting point, not a rule. A cadence_clause_ref
        // on a type the regulator says nothing about would put our opinion into
        // a client's compliance file.
        $unregulated = ExerciseType::query()
            ->whereNotIn('code', ['DRFAILOVER', 'DRFAILBACK', 'DRTEST', 'CALLTREE', 'BACKUP', 'CYBER', 'CRISISSIM', 'SUPPLIER'])
            ->get();

        foreach ($unregulated as $type) {
            $this->assertNull(
                $type->cadence_clause_ref,
                "{$type->code} claims a regulatory cadence driver it should not have."
            );
        }
    }

    #[Test]
    public function every_clause_ref_case_is_seeded_and_every_seeded_row_is_a_case(): void
    {
        $this->seed(BcmsReferenceSeeder::class);

        $seeded = ClauseRef::query()->pluck('code')->all();
        $cases = IsoClauseRef::values();

        $this->assertSame([], array_values(array_diff($cases, $seeded)), 'Enum cases with no seeded clause row.');
        $this->assertSame([], array_values(array_diff($seeded, $cases)), 'Seeded clause rows with no enum case.');
    }

    #[Test]
    public function the_mandatory_record_flag_agrees_with_the_enum(): void
    {
        $this->seed(BcmsReferenceSeeder::class);

        foreach (IsoClauseRef::cases() as $case) {
            $row = ClauseRef::query()->where('code', $case->value)->firstOrFail();

            $this->assertSame(
                $case->isMandatoryRecord(),
                (bool) $row->is_mandatory_record,
                "{$case->value}: the seeded mandatory-record flag disagrees with the enum."
            );
        }

        // ISO 22301's own list of retained documented information. A change to
        // this set is a change to what an auditor is entitled to demand, so it
        // is asserted by count as well as by agreement above.
        $this->assertCount(11, IsoClauseRef::mandatoryRecords());
    }

    #[Test]
    public function every_clause_ref_appears_in_at_least_one_export_pack(): void
    {
        $this->seed(BcmsReferenceSeeder::class);

        foreach (ClauseRefs::all() as $row) {
            $this->assertNotEmpty(
                $row['packs'],
                "{$row['code']} is in no export pack, so nobody can produce evidence for it."
            );
        }
    }

    #[Test]
    public function the_nigerian_blackout_calendar_covers_month_end_and_salary_week(): void
    {
        $this->seed(BcmsReferenceSeeder::class);

        // The two entries a generator must respect or it produces a calendar
        // nobody will run. Month-end has no fixed date, which is why it is a
        // recurrence rather than a pair of dates.
        $monthEnd = BlackoutPeriod::query()->where('category', 'month_end')->firstOrFail();
        $this->assertTrue((bool) $monthEnd->is_hard_block);
        $this->assertSame('last_working_days_of_month', $monthEnd->recurrence['rule'] ?? null);

        $this->assertTrue(BlackoutPeriod::query()->where('category', 'payroll')->exists());

        // The lunar and election windows are ADVISORY, because their dates are
        // declared rather than computable. Shipping them as hard blocks on a
        // guessed date would block a real exercise on the wrong day.
        $advisory = BlackoutPeriod::query()->where('is_hard_block', false)->get();
        $this->assertGreaterThan(0, $advisory->count());
    }

    #[Test]
    public function the_exercise_ladder_is_ordered_rather_than_alphabetical(): void
    {
        // The whole warning about "a full-scale exercise for a process that has
        // never had a successful tabletop" rests on this comparison. A string
        // comparison answers it alphabetically, which is wrong in both
        // directions.
        $this->assertTrue(LadderLevel::FullScale->isAtOrAbove(LadderLevel::Tabletop));
        $this->assertFalse(LadderLevel::Tabletop->isAtOrAbove(LadderLevel::FullScale));
        $this->assertSame(LadderLevel::Walkthrough, LadderLevel::Drill->previous());
        $this->assertNull(LadderLevel::Orientation->previous());
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 4 — settings persist and are read back through a service */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function tenant_settings_persist_and_are_read_back_through_the_service(): void
    {
        $settings = app(BcmsSettings::class);

        // Before anything is saved the service answers from the config
        // defaults rather than throwing or returning nulls a caller has to
        // guard.
        $this->assertSame('Africa/Lagos', $settings->timezone());
        $this->assertSame(10, (int) $settings->for()->default_lead_time_days);

        $settings->update(['default_lead_time_days' => 14, 'reminder_send_time' => '06:45:00']);
        $settings->forget();

        $this->assertSame(14, (int) $settings->for()->default_lead_time_days);
        $this->assertDatabaseHas('bcms_settings', [
            'organization_id' => $this->organization->id,
            'default_lead_time_days' => 14,
        ]);
    }

    #[Test]
    public function quiet_hours_that_wrap_midnight_are_evaluated_correctly(): void
    {
        $settings = app(BcmsSettings::class);
        $settings->update(['quiet_hours_start' => '22:00:00', 'quiet_hours_end' => '06:00:00']);
        $settings->forget();

        // The naive `between` gets this exactly backwards, and the consequence
        // is a reminder ladder that either never sends or never pauses.
        $this->assertTrue($settings->isQuietHour(new \DateTimeImmutable('2026-03-01 23:30:00', new \DateTimeZone('Africa/Lagos'))));
        $this->assertTrue($settings->isQuietHour(new \DateTimeImmutable('2026-03-01 02:00:00', new \DateTimeZone('Africa/Lagos'))));
        $this->assertFalse($settings->isQuietHour(new \DateTimeImmutable('2026-03-01 12:00:00', new \DateTimeZone('Africa/Lagos'))));
    }

    #[Test]
    public function quiet_hours_never_defer_critical_or_life_safety_traffic(): void
    {
        $settings = app(BcmsSettings::class);
        $settings->update(['quiet_hours_start' => '22:00:00', 'quiet_hours_end' => '06:00:00']);
        $settings->forget();

        $middleOfTheNight = new \DateTimeImmutable('2026-03-01 02:00:00', new \DateTimeZone('Africa/Lagos'));

        // Standing rule 6. This is the assertion that stops a roll-call being
        // held until morning.
        $this->assertFalse($settings->mayDefer(AlertSeverity::LifeSafety, $middleOfTheNight));
        $this->assertFalse($settings->mayDefer(AlertSeverity::Critical, $middleOfTheNight));
        $this->assertFalse($settings->mayDefer(AlertSeverity::Urgent, $middleOfTheNight));
        $this->assertTrue($settings->mayDefer(AlertSeverity::Informational, $middleOfTheNight));
    }

    #[Test]
    public function the_life_safety_channel_default_works_without_a_data_connection(): void
    {
        // Blueprint §7.2: the network is the first thing to fail. A life-safety
        // default of email and Teams is a default that stops working in the
        // situation it exists for.
        $channels = app(BcmsSettings::class)->lifeSafetyChannels();

        $offline = array_map(fn (ChannelKey $c) => $c->value, ChannelKey::offlineCapable());
        $configured = array_map(fn (ChannelKey $c) => $c->value, $channels);

        $this->assertNotEmpty(array_intersect($configured, $offline));
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 8 — cross-tenant reads fail closed */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_cross_tenant_read_returns_nothing_on_every_core_model(): void
    {
        $other = Organization::create([
            'name' => 'Another Bank',
            'short_name' => 'AB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        // Rows belonging to the other tenant.
        TenantContext::set($other->id);
        $theirSite = Site::factory()->create();
        $theirProcess = Process::factory()->create();
        $theirProgramme = Programme::factory()->create();
        TenantContext::clear();

        TenantContext::set($this->organization->id);

        $this->assertNull(Site::query()->find($theirSite->id), 'A site leaked across a tenant boundary.');
        $this->assertNull(Process::query()->find($theirProcess->id), 'A process leaked across a tenant boundary.');
        $this->assertNull(Programme::query()->find($theirProgramme->id), 'A programme leaked across a tenant boundary.');

        $this->assertSame(0, Site::query()->count());
        $this->assertSame(0, Process::query()->count());
    }

    #[Test]
    public function a_new_row_is_stamped_with_the_current_tenant(): void
    {
        $site = Site::factory()->create();

        $this->assertSame($this->organization->id, $site->organization_id);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 7 — the queue topology, at the level a unit test can reach */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function four_horizon_supervisors_exist_on_four_separate_queues(): void
    {
        $supervisors = config('horizon.defaults');

        foreach (['bcms-lifesafety', 'bcms-alerts', 'bcms-reminders', 'bcms-sync'] as $name) {
            $this->assertArrayHasKey($name, $supervisors, "No Horizon supervisor for {$name}.");
            $this->assertSame([$name], $supervisors[$name]['queue'], "{$name} shares a pool with another queue.");
        }

        // Life safety is the only pool guaranteed a warm worker. Everything
        // else may scale to zero; a roll-call may not wait for a boot.
        $this->assertArrayHasKey('minProcesses', $supervisors['bcms-lifesafety']);
        $this->assertGreaterThanOrEqual(1, $supervisors['bcms-lifesafety']['minProcesses']);

        // The queue names the jobs will use come from config, not from string
        // literals, so a deployment on a shared Redis can prefix them.
        $this->assertSame('bcms-lifesafety', config('bcms.queues.life_safety'));
        $this->assertSame(AlertSeverity::LifeSafety->queue(), config('bcms.queues.life_safety'));
    }

    /* ------------------------------------------------------------------ */
    /*  The module shell */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_module_section_has_a_registered_route_and_a_declared_permission(): void
    {
        $permissions = (new \App\Authorization\RiskPermissionCatalog)->modules()['Business continuity (BCMS)'];

        foreach (ModuleSections::all() as $section) {
            $this->assertArrayHasKey(
                $section['permission'],
                $permissions,
                "Section '{$section['key']}' names a permission that is not in the catalogue."
            );

            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has('bcms.'.$section['key'].'.index'),
                "Section '{$section['key']}' has a navigation entry and no route."
            );
        }

        // Twelve sub-modules from Blueprint §4.1, plus one. Phase 1 added
        // `findings`: the CAPA register is the cross-track contract of
        // Orchestration §5 — four phases create findings and none of them owns
        // the register — and the blueprint files it inside Programme
        // Governance, where an overdue action is two clicks from anywhere and
        // therefore stops being looked at.
        $this->assertCount(13, ModuleSections::all());
        $this->assertContains('findings', ModuleSections::keys());
    }
}
