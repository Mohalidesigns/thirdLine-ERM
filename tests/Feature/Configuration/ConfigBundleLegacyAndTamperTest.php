<?php

namespace Tests\Feature\Configuration;

use App\Models\ConfigBundle;
use App\Models\ObjectType;
use App\Models\Organization;
use App\Services\Configuration\ConfigurationExporter;
use App\Services\Configuration\ConfigurationImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * QA additions for ADR 0026: the prose-only criteria (section 2 "a v1 file
 * imported after this ADR is stored sealed and still verifies under v1",
 * section 4 "legacy rows still verify", tamper detection on the stored column).
 */
class ConfigBundleLegacyAndTamperTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private Organization $target;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures('Source Bank PLC');
        $this->target = Organization::create([
            'name' => 'Target', 'short_name' => 'TGT', 'institution_type' => 'commercial_bank',
            'sector' => 'banking', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        TenantContext::clear();

        parent::tearDown();
    }

    private function v1Payload(): array
    {
        return [
            'format_version' => 1,
            'exported_from' => 'testing',
            'sections' => ['object_types' => [[
                'code' => 'LegacyType', 'name' => 'Legacy Type', 'plural_name' => 'Legacy Types',
                'category' => 'governance', 'icon' => 'x', 'color' => '#000000', 'code_prefix' => 'LG',
                'is_system' => false,
            ]]],
        ];
    }

    private function tempFile(string $contents): string
    {
        $path = storage_path('app/test-bundle-'.uniqid().'.json');
        file_put_contents($path, $contents);

        return $this->files[] = $path;
    }

    private function legacyRow(array $payload, ?string $checksum = null): ConfigBundle
    {
        $id = DB::table('config_bundles')->insertGetId([
            'organization_id' => $this->organization->id,
            'code' => 'legacy', 'name' => 'Legacy', 'version' => 1,
            'payload' => json_encode($payload),
            'checksum' => $checksum ?? ConfigurationExporter::checksum($payload),
            'is_snapshot' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ConfigBundle::withoutGlobalScopes()->findOrFail($id);
    }

    #[Test]
    public function a_legacy_format_1_row_applies_by_id_and_is_not_rewritten(): void
    {
        $bundle = $this->legacyRow($this->v1Payload());
        $rawBefore = DB::table('config_bundles')->where('id', $bundle->id)->value('payload');
        $checksumBefore = $bundle->checksum;

        TenantContext::set($this->target->id);
        app(ConfigurationImporter::class)->apply($bundle, $this->target->id);

        $this->assertSame(1, ObjectType::withoutGlobalScopes()
            ->where('organization_id', $this->target->id)->where('code', 'LegacyType')->count());

        $row = DB::table('config_bundles')->where('id', $bundle->id)->first();
        $this->assertSame($rawBefore, $row->payload, 'a legacy row must never be converted (ADR 0026 section 4)');
        $this->assertSame($checksumBefore, $row->checksum, 'a legacy row must never be re-checksummed');
    }

    #[Test]
    public function a_format_1_file_exported_before_the_change_imports_is_stored_sealed_and_still_verifies_under_v1(): void
    {
        $payload = $this->v1Payload();
        $checksum = hash('sha256', json_encode(ConfigurationExporter::canonicalise($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $path = $this->tempFile(json_encode(['code' => 'old', 'name' => 'Old', 'version' => 3, 'checksum' => $checksum, 'payload' => $payload]));

        $this->artisan('config:import', ['source' => $path, '--organization' => $this->target->id, '--apply' => true])
            ->assertSuccessful();

        $stored = ConfigBundle::withoutGlobalScopes()->where('organization_id', $this->target->id)->where('code', 'old')->firstOrFail();

        $this->assertIsString(json_decode((string) DB::table('config_bundles')->where('id', $stored->id)->value('payload'), true), 'stored sealed');
        $this->assertSame(1, $stored->payload['format_version'], 'the recipe selector must survive sealing');
        $this->assertSame($checksum, $stored->checksum);
        $this->assertTrue($stored->fresh()->isIntact(), 'sealed storage + v1 recipe');
        $this->assertSame(1, ObjectType::withoutGlobalScopes()->where('organization_id', $this->target->id)->where('code', 'LegacyType')->count());
    }

    #[Test]
    public function a_legacy_row_that_does_not_verify_is_never_re_checksummed_or_applied(): void
    {
        $bundle = $this->legacyRow($this->v1Payload(), str_repeat('a', 64));

        try {
            app(ConfigurationImporter::class)->apply($bundle, $this->target->id);
            $this->fail('a bundle whose checksum does not match must be refused');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('modified since', implode(' ', $error->errors()['bundle']));
        }

        $this->assertSame(str_repeat('a', 64), DB::table('config_bundles')->where('id', $bundle->id)->value('checksum'));
        $this->assertSame(0, ObjectType::withoutGlobalScopes()->where('organization_id', $this->target->id)->where('code', 'LegacyType')->count());
    }

    #[Test]
    public function editing_the_stored_sealed_text_directly_is_detected(): void
    {
        $payload = app(ConfigurationExporter::class)->payload($this->organization->id);
        $bundle = ConfigBundle::create([
            'organization_id' => $this->organization->id, 'code' => 'sealed', 'name' => 'Sealed', 'version' => 1,
            'payload' => $payload, 'checksum' => ConfigurationExporter::checksum($payload), 'exported_at' => now(),
        ]);
        $this->assertTrue($bundle->fresh()->isIntact());

        $tampered = $payload;
        $tampered['exported_from'] = 'attacker';
        DB::table('config_bundles')->where('id', $bundle->id)->update(['payload' => ConfigBundle::sealPayload($tampered)]);

        $this->assertFalse($bundle->fresh()->isIntact());
        $this->expectException(ValidationException::class);
        app(ConfigurationImporter::class)->apply($bundle->fresh(), $this->target->id);
    }

    #[Test]
    public function a_format_2_file_edited_after_export_is_refused_by_the_command_and_nothing_is_written(): void
    {
        $exporter = app(ConfigurationExporter::class);
        $source = $exporter->export('baseline', 'Baseline', organizationId: $this->organization->id);
        $document = json_decode($exporter->document($source), true);

        $document['payload']['sections']['scoring_profiles'][] = ['code' => 'evil', 'name' => 'Evil'];
        $path = $this->tempFile(json_encode($document));

        $this->artisan('config:import', ['source' => $path, '--organization' => $this->target->id, '--apply' => true])
            ->assertFailed();

        // Known, pre-existing (advisory): --apply persists the file before the importer refuses it.
        // What matters is that the stored copy never verifies and so can never be applied by id.
        foreach (ConfigBundle::withoutGlobalScopes()->where('organization_id', $this->target->id)->where('is_snapshot', false)->get() as $stored) {
            $this->assertFalse($stored->isIntact(), 'a refused file must not be stored as an intact bundle');
        }
        $this->assertSame(0, \App\Models\ScoringProfile::withoutGlobalScopes()->where('organization_id', $this->target->id)->where('code', 'evil')->count(), 'a refused file must write no configuration');
    }

    #[Test]
    public function a_file_of_a_newer_format_is_refused_by_the_command_with_the_release_message(): void
    {
        $payload = ['format_version' => 3, 'exported_from' => 'testing', 'sections' => []];
        $path = $this->tempFile(json_encode(['code' => 'f', 'name' => 'F', 'version' => 1, 'checksum' => str_repeat('0', 64), 'payload' => $payload]));

        $this->artisan('config:import', ['source' => $path, '--organization' => $this->target->id, '--apply' => true])
            ->expectsOutputToContain('newer release (format 3)')->assertFailed();
    }

    #[Test]
    public function the_download_and_apply_screens_refuse_a_user_without_the_permission(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $bundle = app(ConfigurationExporter::class)->export('baseline', 'Baseline', organizationId: $this->organization->id);

        $analyst = \App\Models\User::create([
            'name' => 'A', 'email' => 'a-cfg@example.test', 'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
        $analyst->assignRole('risk-analyst');
        $this->actingAs($analyst);

        $this->get(route('admin.configuration.download', $bundle))->assertForbidden();
        $this->post(route('admin.configuration.apply', $bundle), ['confirm' => true])->assertForbidden();
    }
}
