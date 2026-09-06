<?php

namespace Tests\Feature\Configuration;

use App\Models\ConfigBundle;
use App\Models\ObjectAttribute;
use App\Models\ObjectRelationshipType;
use App\Models\ObjectType;
use App\Models\Organization;
use App\Models\ScoringProfile;
use App\Services\Configuration\ConfigurationExporter;
use App\Services\Configuration\ConfigurationImporter;
use App\Support\Scoring\ScoringProfileTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-05 TASK 4 acceptance — export, diff, import, rollback.
 *
 * The scenario the work package asks for is the last-but-one test:
 * config:export in one environment, config:import --dry-run in a clean one,
 * producing a correct diff that then applies cleanly. The rest are the failure
 * modes that make the difference between an import tool and a data-loss
 * incident: ids that mean different things in different environments,
 * checksums, conflicts, and a rollback that actually restores.
 */
class ConfigBundleTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private Organization $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures('Source Bank PLC');

        $this->target = Organization::create([
            'name' => 'Target Bank PLC',
            'short_name' => 'TGTB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Build a small but representative configuration in the source org:
     * a custom type, fields on it, a relationship type constrained to it, and
     * a scoring profile.
     */
    private function buildSourceConfiguration(): ObjectType
    {
        TenantContext::set($this->organization->id);

        $riskType = ObjectType::resolve('Risk');

        $thirdParty = ObjectType::create([
            'organization_id' => $this->organization->id,
            'code' => 'ThirdParty',
            'name' => 'Third Party',
            'plural_name' => 'Third Parties',
            'category' => 'governance',
            'icon' => 'handshake',
            'color' => '#7c3aed',
            'code_prefix' => 'TP',
            'is_system' => false,
        ]);

        foreach ([
            ['code' => 'legal_name', 'label' => 'Legal name', 'data_type' => 'string', 'is_required' => true],
            ['code' => 'rc_number', 'label' => 'RC number', 'data_type' => 'string', 'is_unique' => true],
            ['code' => 'criticality', 'label' => 'Criticality', 'data_type' => 'enum',
                'enum_options' => ['Low', 'Medium', 'High']],
            ['code' => 'annual_spend', 'label' => 'Annual spend', 'data_type' => 'money'],
            ['code' => 'holds_customer_data', 'label' => 'Holds customer data', 'data_type' => 'bool', 'is_pii' => true],
        ] as $index => $definition) {
            ObjectAttribute::create($definition + [
                'object_type_id' => $thirdParty->id,
                'section' => 'Details',
                'sort_order' => $index * 10,
            ]);
        }

        ObjectRelationshipType::create([
            'organization_id' => $this->organization->id,
            'code' => 'supplied_by',
            'name' => 'Supplied by',
            'inverse_code' => 'supplies',
            'from_type_ids' => [$riskType->id],
            'to_type_ids' => [$thirdParty->id],
            'cardinality' => 'many_to_many',
            'has_weight' => true,
            'is_system' => false,
        ]);

        ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'vendor-4x4',
            'name' => 'Vendor 4×4',
            'matrix_rows' => 4,
            'matrix_cols' => 4,
            'rating_bands' => ScoringProfileTemplates::ratingBandsFor(4, 4),
            'is_default' => false,
            'is_system' => false,
        ]));

        return $thirdParty;
    }

    /* ------------------------------------------------------------------ */
    /*  Export */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_export_captures_every_section_and_checksums_the_payload(): void
    {
        $this->buildSourceConfiguration();

        $bundle = app(ConfigurationExporter::class)->export('baseline', 'Baseline', organizationId: $this->organization->id);

        $this->assertSame(1, $bundle->version);
        $this->assertTrue($bundle->isIntact());
        $this->assertSame($bundle->checksum, ConfigurationExporter::checksum($bundle->payload));

        $sections = $bundle->payload['sections'];

        $this->assertCount(1, $sections['object_types'], 'only the tenant\'s own type, not the seeded registry');
        $this->assertSame('ThirdParty', $sections['object_types'][0]['code']);
        $this->assertCount(5, $sections['object_attributes']);
        $this->assertCount(1, $sections['object_relationship_types']);
        $this->assertCount(1, $sections['scoring_profiles']);

        // Sections the work package names that have no table yet are declared
        // empty rather than omitted, so a consumer can tell "none" from "this
        // bundle predates them".
        foreach (['dashboards', 'widgets', 'report_templates', 'content_pack_bindings'] as $reserved) {
            $this->assertArrayHasKey($reserved, $sections);
        }
    }

    #[Test]
    public function foreign_keys_are_exported_as_natural_keys_not_ids(): void
    {
        // This is the whole reason a bundle is portable. Ids differ between
        // environments; codes do not.
        $this->buildSourceConfiguration();

        $bundle = app(ConfigurationExporter::class)->export('baseline', 'Baseline', organizationId: $this->organization->id);

        $attribute = $bundle->payload['sections']['object_attributes'][0];

        $this->assertArrayHasKey('_refs', $attribute);
        $this->assertSame('ThirdParty', $attribute['_refs']['object_type_id']);
        $this->assertArrayNotHasKey('object_type_id', $attribute, 'a raw id would be meaningless elsewhere');

        // The type ids buried inside a relationship type's JSON arrays get the
        // same treatment — no per-column map can express those.
        $relationship = $bundle->payload['sections']['object_relationship_types'][0];

        $this->assertSame(['Risk'], $relationship['from_type_ids']);
        $this->assertSame(['ThirdParty'], $relationship['to_type_ids']);
    }

    #[Test]
    public function re_exporting_the_same_code_produces_a_new_version(): void
    {
        $exporter = app(ConfigurationExporter::class);

        $first = $exporter->export('baseline', 'Baseline', organizationId: $this->organization->id);
        $second = $exporter->export('baseline', 'Baseline', organizationId: $this->organization->id);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
    }

    #[Test]
    public function the_checksum_is_stable_across_key_order(): void
    {
        $payload = ['sections' => ['a' => [['x' => 1, 'y' => 2]]], 'format_version' => 1];
        $shuffled = ['format_version' => 1, 'sections' => ['a' => [['y' => 2, 'x' => 1]]]];

        $this->assertSame(
            ConfigurationExporter::checksum($payload),
            ConfigurationExporter::checksum($shuffled),
            'a re-serialised payload must not read as a modified one'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  The acceptance scenario */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function export_then_dry_run_on_a_clean_environment_produces_a_correct_diff_and_applies_cleanly(): void
    {
        $this->buildSourceConfiguration();

        $bundle = app(ConfigurationExporter::class)->export('baseline', 'Baseline', organizationId: $this->organization->id);
        $importer = app(ConfigurationImporter::class);

        /* ---- dry run against the clean target ---- */

        TenantContext::set($this->target->id);

        $plan = $importer->plan($bundle->payload, $this->target->id);
        $diff = $plan['diff'];

        $this->assertFalse($diff['is_empty']);
        $this->assertSame(1, count($diff['sections']['object_types']['added']));
        $this->assertSame(5, count($diff['sections']['object_attributes']['added']));
        $this->assertSame(1, count($diff['sections']['object_relationship_types']['added']));
        $this->assertSame(1, count($diff['sections']['scoring_profiles']['added']));
        $this->assertSame(0, $diff['totals']['changed']);
        $this->assertSame(0, $diff['totals']['conflicting']);

        // A dry run writes nothing but the log entry.
        $this->assertSame(0, ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)->count());

        /* ---- apply ---- */

        $result = $importer->apply($bundle, $this->target->id);

        $this->assertSame('ok', $result['application']->outcome);
        $this->assertNotNull($result['application']->snapshot_bundle_id, 'an apply must leave a rollback point');

        $imported = ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)
            ->where('code', 'ThirdParty')
            ->first();

        $this->assertNotNull($imported);
        $this->assertSame('Third Party', $imported->name);
        $this->assertSame('TP', $imported->code_prefix);
        $this->assertFalse($imported->is_system, 'an imported row is never a system row');

        // The five fields landed on the type the natural key named, not on
        // whichever type happened to hold that id here.
        $this->assertSame(5, ObjectAttribute::where('object_type_id', $imported->id)->count());
        $this->assertSame(
            ['Low', 'Medium', 'High'],
            ObjectAttribute::where('object_type_id', $imported->id)->where('code', 'criticality')->first()->enum_options
        );

        // And the ids inside the relationship type's JSON arrays were
        // re-resolved against this environment's ids.
        $relationship = ObjectRelationshipType::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)->where('code', 'supplied_by')->first();

        $this->assertNotNull($relationship);
        $this->assertSame([$imported->id], $relationship->to_type_ids);
        $this->assertSame([ObjectType::resolve('Risk')->id], $relationship->from_type_ids);

        /* ---- re-diffing is now empty ---- */

        $this->assertTrue(
            $importer->plan($bundle->payload, $this->target->id)['diff']['is_empty'],
            'applying a bundle then diffing it again must show no differences'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Failure modes */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_tampered_bundle_is_refused(): void
    {
        $this->buildSourceConfiguration();

        $bundle = app(ConfigurationExporter::class)->export('baseline', 'Baseline', organizationId: $this->organization->id);

        $payload = $bundle->payload;
        $payload['sections']['object_types'][0]['name'] = 'Edited By Hand';
        $bundle->forceFill(['payload' => $payload])->save();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(ConfigurationImporter::class)->apply($bundle->fresh(), $this->target->id);
    }

    #[Test]
    public function a_change_on_both_sides_since_the_last_apply_is_reported_as_a_conflict(): void
    {
        $this->buildSourceConfiguration();

        $exporter = app(ConfigurationExporter::class);
        $importer = app(ConfigurationImporter::class);

        $first = $exporter->export('baseline', 'Baseline', organizationId: $this->organization->id);

        TenantContext::set($this->target->id);
        $importer->apply($first, $this->target->id);

        // Both sides now edit the same field.
        TenantContext::set($this->organization->id);
        ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)->where('code', 'ThirdParty')
            ->update(['name' => 'Vendor']);

        TenantContext::set($this->target->id);
        ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)->where('code', 'ThirdParty')
            ->update(['name' => 'Supplier']);

        TenantContext::set($this->organization->id);
        $second = $exporter->export('baseline', 'Baseline', organizationId: $this->organization->id);

        TenantContext::set($this->target->id);
        $diff = $importer->plan($second->payload, $this->target->id)['diff'];

        $this->assertSame(1, $diff['totals']['conflicting'], 'a two-sided change must not be reported as a plain change');

        $conflict = $diff['sections']['object_types']['conflicting'][0];

        $this->assertSame('Third Party', $conflict['fields']['name']['base']);
        $this->assertSame('Supplier', $conflict['fields']['name']['ours']);
        $this->assertSame('Vendor', $conflict['fields']['name']['theirs']);

        // And applying is refused without an explicit decision.
        try {
            $importer->apply($second, $this->target->id);
            $this->fail('a conflicting apply should have been refused');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertStringContainsString('conflict', strtolower(implode(' ', $error->errors()['bundle'])));
        }

        // Local change is intact — nothing was written.
        $this->assertSame('Supplier', ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)->where('code', 'ThirdParty')->first()->name);

        // With force, the bundle wins, because somebody chose that.
        $importer->apply($second, $this->target->id, force: true);

        $this->assertSame('Vendor', ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)->where('code', 'ThirdParty')->first()->name);
    }

    #[Test]
    public function removals_are_reported_but_not_applied_without_prune(): void
    {
        $this->buildSourceConfiguration();

        $exporter = app(ConfigurationExporter::class);
        $importer = app(ConfigurationImporter::class);

        $bundle = $exporter->export('baseline', 'Baseline', organizationId: $this->organization->id);

        TenantContext::set($this->target->id);
        $importer->apply($bundle, $this->target->id);

        // Drop a field in the SOURCE and re-export. Scoped to the source's own
        // type: object_attributes carries no organization_id of its own, so an
        // unscoped delete by code would remove the target's copy too and the
        // test would prove nothing.
        TenantContext::set($this->organization->id);
        $sourceType = ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)->where('code', 'ThirdParty')->first();
        ObjectAttribute::where('object_type_id', $sourceType->id)->where('code', 'rc_number')->delete();

        $narrower = $exporter->export('baseline', 'Baseline', organizationId: $this->organization->id);

        TenantContext::set($this->target->id);
        $importer->apply($narrower, $this->target->id);

        $type = ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)->where('code', 'ThirdParty')->first();

        $this->assertSame(5, ObjectAttribute::where('object_type_id', $type->id)->count(),
            'a bundle that omits something is usually an older export, not a delete instruction');

        // With --prune, it goes.
        $importer->apply($narrower, $this->target->id, prune: true);

        $this->assertSame(4, ObjectAttribute::where('object_type_id', $type->id)->count());
    }

    #[Test]
    public function a_rollback_restores_the_configuration_that_preceded_an_apply(): void
    {
        $this->buildSourceConfiguration();

        $exporter = app(ConfigurationExporter::class);
        $importer = app(ConfigurationImporter::class);

        $bundle = $exporter->export('baseline', 'Baseline', organizationId: $this->organization->id);

        TenantContext::set($this->target->id);

        $this->assertSame(0, ObjectType::withoutGlobalScopes()->where('organization_id', $this->target->id)->count());

        $application = $importer->apply($bundle, $this->target->id)['application'];

        $this->assertSame(1, ObjectType::withoutGlobalScopes()->where('organization_id', $this->target->id)->count());
        $this->assertTrue($application->isRollbackable());

        $importer->rollback($application);

        $this->assertSame(
            0,
            ObjectType::withoutGlobalScopes()
                ->where('organization_id', $this->target->id)
                ->whereNull('deleted_at')
                ->count(),
            'rolling back an apply that added a type must leave it gone'
        );

        $this->assertFalse($application->fresh()->isRollbackable(), 'a rollback cannot be applied twice');
    }

    #[Test]
    public function every_application_is_logged_including_dry_runs(): void
    {
        $this->buildSourceConfiguration();

        $bundle = app(ConfigurationExporter::class)->export('baseline', 'Baseline', organizationId: $this->organization->id);
        $importer = app(ConfigurationImporter::class);

        TenantContext::set($this->target->id);

        $importer->plan($bundle->payload, $this->target->id);
        $applied = $importer->apply($bundle, $this->target->id)['application'];
        $importer->rollback($applied);

        $modes = \App\Models\ConfigBundleApplication::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)
            ->orderBy('id')
            ->pluck('mode')
            ->all();

        $this->assertSame(['dry_run', 'apply', 'rollback'], $modes);
    }

    #[Test]
    public function a_bundle_never_carries_another_organizations_configuration(): void
    {
        $this->buildSourceConfiguration();

        TenantContext::set($this->target->id);

        ObjectType::create([
            'organization_id' => $this->target->id,
            'code' => 'TheirOwnType',
            'name' => 'Their Own Type',
            'category' => 'governance',
            'is_system' => false,
        ]);

        $bundle = app(ConfigurationExporter::class)
            ->export('baseline', 'Baseline', organizationId: $this->organization->id);

        $codes = array_column($bundle->payload['sections']['object_types'], 'code');

        $this->assertContains('ThirdParty', $codes);
        $this->assertNotContains('TheirOwnType', $codes);
    }

    #[Test]
    public function the_seeded_system_registry_is_never_exported(): void
    {
        // Exporting it would make every bundle claim ownership of the shared
        // registry, and importing that elsewhere would fork it into a tenant
        // copy that then drifts from the platform's own.
        $bundle = app(ConfigurationExporter::class)
            ->export('baseline', 'Baseline', organizationId: $this->organization->id);

        $this->assertSame([], $bundle->payload['sections']['object_types']);
        $this->assertSame([], $bundle->payload['sections']['object_lifecycles']);
        $this->assertSame([], $bundle->payload['sections']['scoring_profiles']);
    }

    #[Test]
    public function the_artisan_commands_round_trip_through_a_file(): void
    {
        $this->buildSourceConfiguration();

        $path = storage_path('app/test-bundle-'.uniqid().'.json');

        $this->artisan('config:export', [
            '--organization' => $this->organization->id,
            '--code' => 'baseline',
            '--file' => $path,
        ])->assertSuccessful();

        $this->assertFileExists($path);

        $this->artisan('config:import', [
            'source' => $path,
            '--organization' => $this->target->id,
        ])->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame(0, ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)->count(), 'a dry run must write nothing');

        $this->artisan('config:import', [
            'source' => $path,
            '--organization' => $this->target->id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(1, ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)->count());

        unlink($path);
    }

    #[Test]
    public function config_rollback_undoes_an_applied_bundle_from_the_command_line(): void
    {
        $this->buildSourceConfiguration();

        $bundle = app(ConfigurationExporter::class)
            ->export('baseline', 'Baseline', organizationId: $this->organization->id);

        TenantContext::set($this->target->id);
        $application = app(ConfigurationImporter::class)->apply($bundle, $this->target->id)['application'];

        $this->artisan('config:rollback', ['application' => $application->id, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)
            ->whereNull('deleted_at')
            ->count());
    }

    #[Test]
    public function a_dry_run_of_an_unchanged_configuration_reports_no_changes(): void
    {
        $this->buildSourceConfiguration();

        $bundle = app(ConfigurationExporter::class)
            ->export('baseline', 'Baseline', organizationId: $this->organization->id);

        $plan = app(ConfigurationImporter::class)->plan($bundle->payload, $this->organization->id);

        $this->assertTrue($plan['diff']['is_empty'], 'exporting and immediately diffing must show nothing');
    }

    #[Test]
    public function bundles_are_scoped_to_their_organization(): void
    {
        app(ConfigurationExporter::class)->export('baseline', 'Baseline', organizationId: $this->organization->id);

        TenantContext::set($this->target->id);

        $this->assertSame(0, ConfigBundle::count(), 'a bundle is a complete statement of a tenant\'s configuration');
    }
}
