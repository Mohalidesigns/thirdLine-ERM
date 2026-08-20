<?php

namespace Tests\Feature;

use App\Models\ControlTest;
use App\Models\ControlTestEvidence;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\User;
use App\Services\FileUploadService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\TenantFixture;
use Tests\TestCase;

/**
 * WP-11 — control-test evidence and bulk imports must not be web-reachable.
 *
 * WHAT THESE PROTECT
 * ------------------
 * ControlTestController::uploadEvidence() and DataImportController::upload()
 * stored their files on the `public` disk. `storage/app/public` is symlinked to
 * `public/storage`, so the web server handed those bytes to anyone who asked
 * for `/storage/control-test-evidence/{id}/{name}` — no session, no permission,
 * no tenant check — while the application's own download action performed a
 * perfectly correct tenancy check on a copy nobody needed to use. For a product
 * sold to Nigerian banks to hold audit evidence, that is the whole ballgame.
 *
 * The regression these assert is subtle and easy to reintroduce: the download
 * action keeps working either way. A test that only checks "can an authorised
 * user download it" passes on the vulnerable code. So the load-bearing
 * assertion in every case below is the NEGATIVE one — that the public disk is
 * empty afterwards.
 */
class EvidenceStoragePrivacyTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private ControlTest $controlTest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures('Alpha Bank PLC');

        foreach (['control_test.view', 'control_test.execute', 'import.create'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['control_test.view', 'control_test.execute', 'import.create']);

        $this->controlTest = ControlTest::create([
            'organization_id' => $this->organization->id,
            'control_id' => $this->makeControl()->id,
            'test_code' => 'CT-EVID-0001',
            'title' => 'Quarterly access recertification walkthrough',
            'test_type' => 'operating_effectiveness',
            'tester_id' => $this->actor->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'in_progress',
            'created_by' => $this->actor->id,
        ]);

        // Both disks are faked so "did anything land in the web-served bucket"
        // is answerable by listing it, rather than by reasoning about config.
        Storage::fake('local');
        Storage::fake('public');

        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  (a) uploaded evidence is private, and reachable only via the route */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function uploaded_evidence_is_not_on_the_public_disk_and_is_served_by_the_authenticated_route(): void
    {
        $this->post(
            route('risk.control-tests.upload-evidence', $this->controlTest),
            ['file' => UploadedFile::fake()->create('quarterly-evidence.pdf', 12)],
        )->assertRedirect();

        $evidence = ControlTestEvidence::firstOrFail();

        // The point of the whole change: nothing was written where the web
        // server can reach it.
        $this->assertSame(
            [],
            Storage::disk('public')->allFiles(),
            'Control test evidence was written to the web-served public disk.'
        );

        Storage::disk(FileUploadService::DISK)->assertExists($evidence->file_path);
        $this->assertStringStartsWith(
            'control-test-evidence/'.$this->controlTest->id.'/',
            $evidence->file_path
        );

        // And it is still downloadable by someone entitled to it.
        $this->get(route('risk.control-tests.download-evidence', [
            'controlTest' => $this->controlTest,
            'evidence' => $evidence,
        ]))->assertOk()
            ->assertDownload();
    }

    #[Test]
    public function the_stored_file_name_is_not_the_client_supplied_one(): void
    {
        $this->post(
            route('risk.control-tests.upload-evidence', $this->controlTest),
            ['file' => UploadedFile::fake()->create('quarterly-evidence.pdf', 1)],
        )->assertRedirect();

        $evidence = ControlTestEvidence::firstOrFail();

        // A client-chosen name on disk lets a caller overwrite an existing
        // piece of evidence by re-uploading under the same name.
        $this->assertStringNotContainsString('quarterly-evidence', $evidence->file_path);
        $this->assertSame('quarterly-evidence.pdf', $evidence->file_name, 'The display name should still be the original.');
    }

    /* ------------------------------------------------------------------ */
    /*  DEFECT 4 — file_type comes off the bytes, not off the client */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_recorded_file_type_is_derived_from_the_detected_type(): void
    {
        $this->post(
            route('risk.control-tests.upload-evidence', $this->controlTest),
            ['file' => UploadedFile::fake()->create('screenshot.png', 4)],
        )->assertRedirect();

        $this->assertSame('png', ControlTestEvidence::firstOrFail()->file_type);
    }

    #[Test]
    public function a_client_mime_header_and_filename_cannot_decide_what_the_file_is_recorded_as(): void
    {
        // A REAL UploadedFile, not a fake: Illuminate\Http\Testing\File
        // reports its MIME from its own name, which would make this assertion
        // meaningless. Here the bytes are a PNG, the client-supplied filename
        // says .pdf and the client-supplied Content-Type says application/pdf.
        // getClientMimeType() and getClientOriginalExtension() — the two things
        // the endpoints used to record — would both report a PDF.
        $tmp = tempnam(sys_get_temp_dir(), 'wp11').'.bin';
        file_put_contents($tmp, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $file = new UploadedFile($tmp, 'audit-evidence.pdf', 'application/pdf', null, true);
        $service = app(FileUploadService::class);

        $this->assertSame('application/pdf', $file->getClientMimeType(), 'precondition: the client is lying');
        $this->assertSame(
            'png',
            $service->detectExtension($file, FileUploadService::PROFILE_CONTROL_TEST_EVIDENCE),
            'file_type must come from the bytes, not from the client.'
        );

        @unlink($tmp);
    }

    /* ------------------------------------------------------------------ */
    /*  (b) cross-tenant access */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_user_from_another_organisation_cannot_download_the_evidence(): void
    {
        $this->post(
            route('risk.control-tests.upload-evidence', $this->controlTest),
            ['file' => UploadedFile::fake()->create('quarterly-evidence.pdf', 2)],
        )->assertRedirect();

        $evidence = ControlTestEvidence::firstOrFail();
        $intruder = $this->intruderFromAnotherBank();

        // 404, not 403: ControlTest carries the tenant global scope, so
        // route-model binding cannot even see another organisation's row. The
        // controller's explicit 403 stays as a second line of defence.
        $this->actingAs($intruder)
            ->get(route('risk.control-tests.download-evidence', [
                'controlTest' => $this->controlTest->id,
                'evidence' => $evidence->id,
            ]))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  (c) file type rejection */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_disallowed_file_type_is_rejected_and_nothing_is_written(): void
    {
        $this->from(route('risk.control-tests.show', $this->controlTest))
            ->post(
                route('risk.control-tests.upload-evidence', $this->controlTest),
                ['file' => UploadedFile::fake()->create('webshell.php', 2)],
            )
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('control_test_evidence', 0);
        $this->assertSame([], Storage::disk(FileUploadService::DISK)->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function an_oversized_file_is_rejected(): void
    {
        $this->from(route('risk.control-tests.show', $this->controlTest))
            ->post(
                route('risk.control-tests.upload-evidence', $this->controlTest),
                ['file' => UploadedFile::fake()->create('huge.pdf', 20480)],
            )
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('control_test_evidence', 0);
    }

    /* ------------------------------------------------------------------ */
    /*  Bulk imports — same disk defect, no download route at all */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_uploaded_import_spreadsheet_is_not_on_the_public_disk(): void
    {
        $this->post(route('risk.imports.upload'), [
            'import_type' => 'risks',
            'file' => UploadedFile::fake()->createWithContent(
                'register.csv',
                "title,description\nFraud exposure,Fixture row\n"
            ),
        ]);

        $import = DataImport::firstOrFail();

        $this->assertSame(
            [],
            Storage::disk('public')->allFiles(),
            'A bulk import — the customer\'s whole risk register — was written to the web-served public disk.'
        );

        Storage::disk(FileUploadService::DISK)->assertExists($import->file_path);
        $this->assertStringStartsWith('imports/'.$this->organization->id.'/', $import->file_path);

        // Storing streams the upload rather than moving it, so the header read
        // that follows store() in the controller still has a readable temp
        // file. If that ever regresses the import is marked failed here.
        $this->assertNotSame('failed', $import->status, $import->errors[0] ?? '');
        $this->assertSame(1, $import->total_rows);
    }

    /* ------------------------------------------------------------------ */
    /*  DEFECT 3 — the traversal sink in FileUploadService::download() */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_upload_service_refuses_to_serve_a_path_outside_its_disk(): void
    {
        $service = app(FileUploadService::class);

        foreach (['../../../.env', '/etc/passwd', 'control-test-evidence/../../../.env', 'phar://evil.phar/x'] as $attempt) {
            try {
                $service->download($attempt);
                $this->fail("download() accepted a traversal path: {$attempt}");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  (d) the relocation migration */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_relocation_migration_moves_existing_files_and_normalises_stored_paths(): void
    {
        $migration = $this->relocationMigration();

        $plain = 'control-test-evidence/'.$this->controlTest->id.'/legacy-plain.pdf';
        $prefixed = 'control-test-evidence/'.$this->controlTest->id.'/legacy-prefixed.pdf';
        $importPath = 'imports/'.$this->organization->id.'/legacy-register.csv';

        Storage::disk('public')->put($plain, 'PLAIN-EVIDENCE-BYTES');
        Storage::disk('public')->put($prefixed, 'PREFIXED-EVIDENCE-BYTES');
        Storage::disk('public')->put($importPath, "title\nLegacy risk\n");

        $plainRow = $this->makeEvidenceRow($plain);
        // A legacy value carrying the redundant public/ prefix and a leading
        // slash: the file lives at the canonical path, the column does not.
        $prefixedRow = $this->makeEvidenceRow('/public/'.$prefixed);
        $importRow = $this->makeImportRow($importPath);

        $migration->up();

        foreach ([$plain, $prefixed, $importPath] as $path) {
            Storage::disk('public')->assertMissing($path);
            Storage::disk(FileUploadService::DISK)->assertExists($path);
        }

        $this->assertSame('PLAIN-EVIDENCE-BYTES', Storage::disk(FileUploadService::DISK)->get($plain));

        // The column is rewritten to the canonical, disk-relative value.
        $this->assertSame($plain, $plainRow->fresh()->file_path);
        $this->assertSame($prefixed, $prefixedRow->fresh()->file_path);
        $this->assertSame($importPath, $importRow->fresh()->file_path);
    }

    #[Test]
    public function the_relocation_migration_is_idempotent(): void
    {
        $migration = $this->relocationMigration();

        $path = 'control-test-evidence/'.$this->controlTest->id.'/legacy.pdf';
        Storage::disk('public')->put($path, 'EVIDENCE-BYTES');
        $row = $this->makeEvidenceRow($path);

        $migration->up();
        $migration->up();
        $migration->up();

        Storage::disk(FileUploadService::DISK)->assertExists($path);
        $this->assertSame('EVIDENCE-BYTES', Storage::disk(FileUploadService::DISK)->get($path));
        Storage::disk('public')->assertMissing($path);
        $this->assertSame($path, $row->fresh()->file_path);
    }

    #[Test]
    public function a_row_whose_file_is_missing_does_not_stop_the_relocation(): void
    {
        $migration = $this->relocationMigration();

        $present = 'control-test-evidence/'.$this->controlTest->id.'/present.pdf';
        Storage::disk('public')->put($present, 'STILL-HERE');

        // Ordered so the broken row is processed FIRST: if a gap aborted the
        // run, the good file below would never be relocated.
        $ghost = $this->makeEvidenceRow('control-test-evidence/'.$this->controlTest->id.'/deleted-years-ago.pdf');
        $good = $this->makeEvidenceRow($present);

        $migration->up();

        Storage::disk(FileUploadService::DISK)->assertExists($present);
        Storage::disk('public')->assertMissing($present);
        $this->assertSame($present, $good->fresh()->file_path);
        // The gap is reported, not repaired, and the row is left alone.
        $this->assertNotNull($ghost->fresh());
    }

    #[Test]
    public function the_relocation_migration_leaves_unrelated_public_files_alone(): void
    {
        $migration = $this->relocationMigration();

        // Organisation logos legitimately live on the public disk and are read
        // from there by DocumentRenderer. Nothing here may touch them.
        Storage::disk('public')->put('logos/alpha-bank.png', 'LOGO');

        $migration->up();

        Storage::disk('public')->assertExists('logos/alpha-bank.png');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function relocationMigration(): object
    {
        return require database_path(
            'migrations/2026_08_20_090000_relocate_public_attachments_to_private_disk.php'
        );
    }

    private function makeEvidenceRow(string $path): ControlTestEvidence
    {
        return ControlTestEvidence::create([
            'control_test_id' => $this->controlTest->id,
            'file_name' => basename($path),
            'file_path' => $path,
            'file_type' => 'pdf',
            'file_size' => 20,
            'uploaded_by' => $this->actor->id,
        ]);
    }

    private function makeImportRow(string $path): DataImport
    {
        return DataImport::create([
            'organization_id' => $this->organization->id,
            'import_type' => 'risks',
            'file_name' => basename($path),
            'file_path' => $path,
            'status' => 'pending',
            'imported_by' => $this->actor->id,
        ]);
    }

    /**
     * A user belonging to a different bank, built with the shared TenantFixture
     * so the row stays valid as the users table grows columns.
     */
    private function intruderFromAnotherBank(): User
    {
        $other = Organization::create([
            'name' => 'Beta Bank PLC',
            'short_name' => 'BETA',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $id = (new TenantFixture)->make('users', $other->id, [
            'name' => 'Intruder',
            'email' => 'intruder@beta-bank.test',
            'password' => bcrypt('secret-password'),
            'is_active' => true,
        ]);

        $intruder = User::withoutGlobalScopes()->findOrFail($id);
        $intruder->givePermissionTo('control_test.view');

        return $intruder;
    }
}
