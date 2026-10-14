<?php

namespace Tests\Feature\Configuration;

use App\Models\ConfigBundle;
use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use App\Models\Organization;
use App\Models\ScoringProfile;
use App\Services\Configuration\ConfigurationExporter;
use App\Support\Scoring\ScoringProfileTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0026: a configuration bundle verifies, and applies exactly what was
 * exported, on MariaDB 10.4 AND MySQL 8.0.46.
 *
 * Both CI legs use the `mysql` driver, so the engine is told apart by
 * `SELECT VERSION()`, as AuditChainCrossEngineTest does. The control test
 * asserts that the object form really drifts on MySQL, so the leg cannot pass
 * by exercising nothing. Tests 1 and 4 fail on the code as it stood before the
 * ADR: test 4 on MySQL only, test 1 on both.
 */
class ConfigBundleCrossEngineTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private Organization $target;

    private Organization $target2;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures('Source Bank PLC');

        foreach (['target' => 'TGT1', 'target2' => 'TGT2'] as $property => $short) {
            $this->{$property} = Organization::create([
                'name' => "Target {$short}",
                'short_name' => $short,
                'institution_type' => 'commercial_bank',
                'sector' => 'banking',
                'is_active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    private function isMariaDb(): bool
    {
        return str_contains((string) DB::selectOne('select version() as v')->v, 'MariaDB');
    }

    /** ADR 0026 test A1's payload. */
    private function richPayload(): array
    {
        return [
            'format_version' => 2,
            'exported_from' => 'testing',
            'sections' => ['scoring_profiles' => [[
                'code' => 'x',
                'name' => 'Café / Ltd',
                'dimension_weights' => ['ratio' => 478 / 53, 'big' => 1.0e25, 'sum' => 0.1 + 0.2, 'whole' => 100.0, 'n' => 7],
            ]]],
        ];
    }

    private function makeBundle(array $payload, array $overrides = []): ConfigBundle
    {
        return ConfigBundle::create($overrides + [
            'organization_id' => $this->organization->id,
            'code' => 'probe',
            'name' => 'Probe',
            'version' => 1,
            'payload' => $payload,
            'checksum' => ConfigurationExporter::checksum($payload),
            'exported_at' => now(),
        ]);
    }

    private function rawInsert(string $payloadText, string $checksum, string $code = 'raw'): int
    {
        return DB::table('config_bundles')->insertGetId([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => 'Raw',
            'version' => 1,
            'payload' => $payloadText,
            'checksum' => $checksum,
            'is_snapshot' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tempFile(string $contents): string
    {
        $path = storage_path('app/test-bundle-'.uniqid().'.json');
        file_put_contents($path, $contents);

        return $this->files[] = $path;
    }

    /** A source configuration with a scoring profile and a numeric limit on a field. */
    private function buildSourceConfiguration(): void
    {
        TenantContext::set($this->organization->id);

        $type = ObjectType::create([
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

        ObjectAttribute::create([
            'object_type_id' => $type->id,
            'code' => 'annual_spend',
            'label' => 'Annual spend',
            'data_type' => 'money',
            'section' => 'Details',
            'sort_order' => 0,
            'validation' => ['min' => 0.5, 'max' => 250000.75],
        ]);

        ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'vendor-4x4',
            'name' => 'Vendor 4x4',
            'matrix_rows' => 4,
            'matrix_cols' => 4,
            'rating_bands' => ScoringProfileTemplates::ratingBandsFor(4, 4),
            'is_default' => false,
            'is_system' => false,
        ]));
    }

    #[Test]
    public function a_sealed_payload_survives_either_engine_exactly(): void
    {
        $payload = $this->richPayload();
        $bundle = $this->makeBundle($payload);

        $fresh = ConfigBundle::withoutGlobalScopes()->findOrFail($bundle->id);

        $this->assertSame(
            \App\Support\CanonicalJson::normalise($payload),
            $fresh->payload,
            'A float must stay a float and 100.0 must stay 100.0, on either engine.'
        );
        $this->assertSame($fresh->payload, $bundle->payload, 'an in-memory model and a fresh read must agree');
        $this->assertTrue($fresh->isIntact(), 'An honest bundle reads as altered.');

        $raw = json_decode((string) DB::table('config_bundles')->where('id', $bundle->id)->value('payload'), true);
        $this->assertIsString($raw, 'The column must hold one JSON string, not an object (ADR 0026 section 1).');
    }

    #[Test]
    public function control_the_object_form_really_drifts_on_mysql(): void
    {
        $text = '{"format_version":1,"sections":{"a":[{"big":1.0e25}]}}';
        $checksum = ConfigurationExporter::checksum(json_decode($text, true));

        $id = $this->rawInsert($text, $checksum);
        $bundle = ConfigBundle::withoutGlobalScopes()->findOrFail($id);

        if ($this->isMariaDb()) {
            $this->assertTrue($bundle->isIntact(), 'MariaDB hands the stored text back verbatim.');
        } else {
            $this->assertFalse(
                $bundle->isIntact(),
                'MySQL 8 should re-serialise 1.0e25; if it does not, this leg exercised nothing.'
            );
        }
    }

    #[Test]
    public function a_legacy_object_row_still_reads_and_verifies(): void
    {
        $payload = [
            'format_version' => 1,
            'exported_from' => 'testing',
            'sections' => ['object_types' => [['code' => 'A', 'name' => 'Café / Ltd', 'n' => 7]]],
        ];
        $id = $this->rawInsert(json_encode($payload), ConfigurationExporter::checksum($payload));

        $bundle = ConfigBundle::withoutGlobalScopes()->findOrFail($id);

        // assertEquals: MySQL hands an object back with its own key order.
        $this->assertEquals($payload, $bundle->payload);
        $this->assertTrue($bundle->isIntact());
    }

    #[Test]
    public function a_bundle_carried_in_a_file_applies_by_id_on_either_engine(): void
    {
        $this->buildSourceConfiguration();

        $exporter = app(ConfigurationExporter::class);
        $source = $exporter->export('baseline', 'Baseline', organizationId: $this->organization->id);

        $document = json_decode($exporter->document($source), true);

        // A double that has never been through the database: the route the
        // feature exists for (a bundle carried in from another estate).
        $this->assertSame(2, $document['payload']['format_version']);
        $document['payload']['sections']['scoring_profiles'][0]['dimension_weights']['financial'] = 478 / 53;
        $document['checksum'] = ConfigurationExporter::checksum($document['payload']);

        $path = $this->tempFile(json_encode($document, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->artisan('config:import', [
            'source' => $path,
            '--organization' => $this->target->id,
            '--apply' => true,
        ])->assertSuccessful();

        $stored = ConfigBundle::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)->where('code', 'baseline')->firstOrFail();

        // By id, from the stored row: this is the read that was refused on MySQL.
        $this->assertTrue($stored->isIntact(), 'The stored bundle reads as altered.');

        $this->artisan('config:import', [
            'source' => (string) $stored->id,
            '--organization' => $this->target2->id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(1, ObjectType::withoutGlobalScopes()->where('organization_id', $this->target2->id)->count());

        // The importer round trip: weights and limits arrive as numbers, not strings.
        foreach ([$this->target, $this->target2] as $organization) {
            $profile = ScoringProfile::withoutGlobalScopes()
                ->where('organization_id', $organization->id)->where('code', 'vendor-4x4')->firstOrFail();

            $weights = $profile->dimension_weights;
            $this->assertIsNumeric($weights['financial']);
            $this->assertIsNotString($weights['financial']);
            $this->assertEqualsWithDelta(478 / 53, $weights['financial'], 1e-9);

            foreach ($weights as $dimension => $weight) {
                $this->assertTrue(is_int($weight) || is_float($weight), "weight [{$dimension}] must be a number");
            }

            $type = ObjectType::withoutGlobalScopes()
                ->where('organization_id', $organization->id)->where('code', 'ThirdParty')->firstOrFail();
            $limits = ObjectAttribute::where('object_type_id', $type->id)->where('code', 'annual_spend')->firstOrFail()->validation;

            $this->assertSame(0.5, (float) $limits['min']);
            $this->assertTrue(is_int($limits['max']) || is_float($limits['max']), 'a limit must stay a number');
            $this->assertEqualsWithDelta(250000.75, $limits['max'], 1e-9);
        }
    }

    #[Test]
    public function the_download_keeps_the_zero_fraction(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');
        $this->actingAs($this->actor);

        $this->buildSourceConfiguration();

        // A real export, with two weights whose float-ness the file must keep.
        $payload = app(ConfigurationExporter::class)->payload($this->organization->id);
        $payload['sections']['scoring_profiles'][0]['dimension_weights']['whole'] = 100.0;
        $payload['sections']['scoring_profiles'][0]['dimension_weights']['big'] = 1.0e25;
        $bundle = $this->makeBundle($payload, ['code' => 'dl']);

        $response = $this->get(route('admin.configuration.download', $bundle))->assertOk();
        $body = $response->streamedContent();

        $this->assertStringContainsString('100.0', $body);

        $path = $this->tempFile($body);

        $this->artisan('config:import', [
            'source' => $path,
            '--organization' => $this->target->id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(1, ScoringProfile::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)->where('code', 'vendor-4x4')->count());
    }
}
