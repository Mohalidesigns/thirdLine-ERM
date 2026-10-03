<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\Evidence;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\FileUploadService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToRetrieveMetadata;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `bcms.evidence.download` (routes/web.php:2762-2763) — the route the parity
 * guard (`scripts/parity-check.php`) found no test naming.
 *
 * `EvidenceController::download()` is three lines: `Gate::authorize('bcms.
 * exercise.view')` then `$this->uploads->download($evidence->file_path,
 * $evidence->file_name)` (`App\Services\FileUploadService::download()`). This
 * file exercises the permission gate, the two-level route-scope binding
 * (ADR 0019 §4), tenancy, ADR 0017 record visibility, and the file-service
 * hardening (`assertInsideDisk()`) that download rides on.
 *
 * Evidence rows are built directly with `forceFill()`, the same one-`INSERT`
 * shape `EvidenceService::upload()` and `BcmsRecordVisibilityTest` already use
 * (`hash` is deliberately not fillable — Evidence's own docblock, advisory 8),
 * so these tests can plant a stored file and a matching row without going
 * through the upload endpoint's own validation, which is not what this route
 * is testing.
 */
class Phase9EvidenceDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $kano;

    private BusinessUnit $lagos;

    private ExerciseProgramme $programme;

    private ExerciseType $type;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);
        Storage::fake('local');

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->kano = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-KANO', 'name' => 'Kano', 'is_active' => true,
        ]);
        $this->lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS', 'name' => 'Lagos', 'is_active' => true,
        ]);

        $this->programme = ExerciseProgramme::query()->firstOrCreate(
            ['year' => 2031, 'name' => 'Evidence Download Programme'],
            ['organization_id' => $this->organization->id],
        );
        $this->type = ExerciseType::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** @param list<string> $permissions */
    private function userWith(
        array $permissions,
        string $email,
        ?BusinessUnit $unit = null,
        ?Organization $organization = null,
        bool $includesDescendants = true,
    ): User {
        $organization ??= $this->organization;

        $user = User::create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $organization->id,
            'business_unit_id' => $unit?->id, 'is_active' => true,
        ]);

        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        if ($unit !== null) {
            DB::table('business_unit_user')->insert([
                'organization_id' => $organization->id, 'user_id' => $user->id,
                'business_unit_id' => $unit->id, 'includes_descendants' => $includesDescendants,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function definition(array $attributes = []): ExerciseDefinition
    {
        return ExerciseDefinition::query()->create(array_merge([
            'exercise_programme_id' => $this->programme->getKey(),
            'exercise_type_id' => $this->type->getKey(),
            'name' => 'Download Drill '.Str::random(4),
            'business_unit_id' => $this->kano->id,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function occurrence(ExerciseDefinition $definition, array $attributes = []): ExerciseOccurrence
    {
        return ExerciseOccurrence::query()->create(array_merge([
            'organization_id' => $definition->organization_id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->toDateString(),
            'status' => OccurrenceStatus::Planned,
        ], $attributes));
    }

    /**
     * Plants both halves a download needs: bytes on the fake `local` disk and
     * a `bcms_evidence` row pointing at them. Mirrors
     * `EvidenceService::upload()`'s one-`INSERT`, `forceFill()`-for-`hash`
     * shape (Evidence's own docblock, advisory 8).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function evidenceFor(
        ExerciseOccurrence $occurrence,
        string $contents = 'headcount sheet, 340 present',
        string $storedName = 'sheet.txt',
        string $downloadName = 'headcount.txt',
        string $mime = 'text/plain',
        array $attributes = [],
    ): Evidence {
        $path = 'bcms/evidence/'.$occurrence->organization_id.'/'.$occurrence->getKey().'/'.$storedName;
        Storage::disk('local')->put($path, $contents);

        $evidence = new Evidence(array_merge([
            'organization_id' => $occurrence->organization_id,
            'occurrence_id' => $occurrence->getKey(),
            'owner_type' => Evidence::KIND_OCCURRENCE,
            'owner_id' => $occurrence->getKey(),
            'kind' => 'file',
            'caption' => 'Test evidence',
            'file_name' => $downloadName,
            'file_path' => $path,
            'mime' => $mime,
            'size' => strlen($contents),
        ], $attributes));
        $evidence->forceFill(['hash' => str_repeat('a', 64)]);
        $evidence->save();

        return $evidence;
    }

    /**
     * A row whose `file_path` is whatever the caller supplies, unwritten to
     * the disk — the shape every `FileUploadService::safeRelativePath()` /
     * `assertInsideDisk()` refusal is tested against. Never touches
     * `Storage::put()`, so a case that (incorrectly) served bytes would be
     * serving something this test never wrote.
     */
    private function evidenceWithRawPath(ExerciseOccurrence $occurrence, string $rawPath, string $fileName = 'exploit.txt'): Evidence
    {
        $evidence = new Evidence([
            'organization_id' => $occurrence->organization_id,
            'occurrence_id' => $occurrence->getKey(),
            'owner_type' => Evidence::KIND_OCCURRENCE,
            'owner_id' => $occurrence->getKey(),
            'kind' => 'file',
            'file_name' => $fileName,
            'file_path' => $rawPath,
            'mime' => 'text/plain',
            'size' => 10,
        ]);
        $evidence->forceFill(['hash' => str_repeat('e', 64)]);
        $evidence->save();

        return $evidence;
    }

    /**
     * The shared shape every service-level refusal must produce, per the
     * retest brief: status exactly 404; the body carries neither the stored
     * path, the file name, a stack trace nor the raw exception message; and
     * exactly one `warning` is logged, carrying `evidence_id`/`occurrence_id`
     * and a `reason` that may repeat the service's own fixed refusal text but
     * never the file name or path — with no `file_name`/`file_path` key in
     * the logged context at all.
     *
     * @param  list<string>  $forbiddenNeedles  Secret-bearing strings (the raw
     *                                          path, a traversal target, the
     *                                          file name) that must appear
     *                                          NOWHERE in the response body or
     *                                          the log's `reason`.
     */
    private function assertDownloadRefusedCleanly(
        ExerciseOccurrence $occurrence,
        Evidence $evidence,
        User $viewer,
        string $expectedReasonSubstring,
        array $forbiddenNeedles,
    ): void {
        Log::spy();

        $response = $this->actingAs($viewer)
            ->get(route('bcms.evidence.download', [$occurrence, $evidence]));

        $response->assertStatus(404);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('RuntimeException', $body, 'The 404 body leaked an exception class name.');
        $this->assertStringNotContainsString('Stack trace', $body, 'The 404 body leaked a stack trace.');
        $this->assertStringNotContainsString('FileUploadService.php', $body, 'The 404 body leaked a source file reference.');

        foreach ($forbiddenNeedles as $needle) {
            $this->assertStringNotContainsString($needle, $body, "The 404 body leaked: {$needle}");
        }

        Log::shouldHaveReceived('warning')->once()->with(
            'BCMS evidence could not be served.',
            \Mockery::on(function (array $context) use ($evidence, $occurrence, $expectedReasonSubstring, $forbiddenNeedles): bool {
                $this->assertSame($evidence->getKey(), $context['evidence_id'] ?? null, 'Logged context is missing the evidence id.');
                $this->assertSame($occurrence->getKey(), $context['occurrence_id'] ?? null, 'Logged context is missing the occurrence id.');
                $this->assertArrayNotHasKey('file_name', $context, 'The log must never carry the file name.');
                $this->assertArrayNotHasKey('file_path', $context, 'The log must never carry the stored path.');

                $reason = (string) ($context['reason'] ?? '');
                $this->assertStringContainsString($expectedReasonSubstring, $reason);

                foreach ($forbiddenNeedles as $needle) {
                    $this->assertStringNotContainsString($needle, $reason, "The logged reason leaked: {$needle}");
                }

                return true;
            }),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  1. A permitted user downloads their own tenant's evidence. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_user_with_view_permission_downloads_evidence_on_an_occurrence_they_can_see(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator@khb.test', $this->kano);
        $viewer = $this->userWith(['bcms.exercise.view', 'rcsa_scope.all_units'], 'viewer@khb.test', $this->kano);

        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);
        $evidence = $this->evidenceFor($occurrence, 'headcount sheet, 340 present', 'sheet.txt', 'headcount.txt', 'text/plain');

        $response = $this->actingAs($viewer)
            ->get(route('bcms.evidence.download', [$occurrence, $evidence]));

        $response->assertOk();
        $this->assertSame('headcount sheet, 340 present', $response->streamedContent());
        $this->assertStringContainsString('headcount.txt', (string) $response->headers->get('Content-Disposition'));
    }

    /* ------------------------------------------------------------------ */
    /*  2. Permission and authentication gates. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_user_without_the_view_permission_is_refused(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator2@khb.test', $this->kano);
        $noPermission = $this->userWith([], 'no-permission@khb.test', $this->kano);

        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);
        $evidence = $this->evidenceFor($occurrence);

        $this->actingAs($noPermission)
            ->get(route('bcms.evidence.download', [$occurrence, $evidence]))
            ->assertForbidden();
    }

    #[Test]
    public function an_unauthenticated_request_is_redirected_to_login(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator3@khb.test', $this->kano);
        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);
        $evidence = $this->evidenceFor($occurrence);

        $this->get(route('bcms.evidence.download', [$occurrence, $evidence]))
            ->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------ */
    /*  3. Cross-tenant: organization B cannot reach organization A's row. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_user_of_another_organization_cannot_download_this_organizations_evidence(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator4@khb.test', $this->kano);
        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);
        $evidence = $this->evidenceFor($occurrence);

        $otherOrg = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OTH',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        $outsider = $this->userWith(['bcms.exercise.view', 'rcsa_scope.all_units'], 'outsider@oth.test', null, $otherOrg);

        $response = $this->actingAs($outsider)
            ->get(route('bcms.evidence.download', [$occurrence, $evidence]));

        $response->assertNotFound();
        $this->assertStringNotContainsString(
            'headcount sheet, 340 present',
            (string) $response->getContent(),
            'A 404 response must never carry the evidence bytes of another organization.',
        );
    }

    /* ------------------------------------------------------------------ */
    /*  4. Scoped binding: evidence of occurrence X requested under Y. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function evidence_belonging_to_one_occurrence_404s_when_requested_under_a_different_occurrence(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator5@khb.test', $this->kano);
        $viewer = $this->userWith(['bcms.exercise.view', 'rcsa_scope.all_units'], 'viewer5@khb.test', $this->kano);

        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrenceX = $this->occurrence($definition, ['facilitator_id' => $facilitator->id, 'sequence_no' => 1]);
        $occurrenceY = $this->occurrence($definition, ['facilitator_id' => $facilitator->id, 'sequence_no' => 2]);
        $evidence = $this->evidenceFor($occurrenceX);

        $url = route('bcms.evidence.download', ['occurrence' => $occurrenceY->uuid, 'evidence' => $evidence->uuid]);

        $this->actingAs($viewer)->get($url)->assertNotFound();

        // Sanity: the same evidence row under its OWN occurrence still works,
        // so the 404 above is provably the scoped-binding mismatch and not
        // some other misconfiguration.
        $this->actingAs($viewer)
            ->get(route('bcms.evidence.download', [$occurrenceX, $evidence]))
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  5. ADR 0017 record visibility — business_unit_user, not */
    /*     users.business_unit_id. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function record_visibility_follows_the_business_unit_user_pivot_not_the_facilitator(): void
    {
        // No named-user arm in play: the occurrence's facilitator is a THIRD
        // user, neither the in-unit viewer nor the out-of-unit bystander, so
        // any result below is provably the org-hierarchy (Evidence's derived
        // `occurrence.definition` anchor) arm, not `orgVisibilityNamedUsers()`.
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator6@khb.test', $this->lagos);
        $definition = $this->definition(['business_unit_id' => $this->lagos->id, 'facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);
        $evidence = $this->evidenceFor($occurrence);

        // Assigned to Lagos via business_unit_user — reaches the occurrence.
        $inUnitViewer = $this->userWith(['bcms.exercise.view'], 'in-unit-viewer@khb.test', $this->lagos);
        // Assigned to Kano only — does not reach a Lagos-anchored occurrence,
        // and holds no rcsa_scope.all_units grant.
        $outOfUnitViewer = $this->userWith(['bcms.exercise.view'], 'out-of-unit-viewer@khb.test', $this->kano);

        $this->actingAs($outOfUnitViewer)
            ->get(route('bcms.evidence.download', [$occurrence, $evidence]))
            ->assertNotFound();

        $this->actingAs($inUnitViewer)
            ->get(route('bcms.evidence.download', [$occurrence, $evidence]))
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  6. Path safety: a missing file, and a traversal attempt. */
    /* ------------------------------------------------------------------ */

    /**
     * FIXED (was defect A.1). `EvidenceController::download()` now wraps the
     * service call in `try { … } catch (RuntimeException $e)`, logs a
     * `warning` with only `evidence_id`/`occurrence_id`/`reason`, and
     * `abort(404, …)`. This pins the full shape the retest brief asks for:
     * exact status, a body carrying none of the stored path/file name/stack
     * trace/exception message, and a scrubbed log entry.
     */
    #[Test]
    public function a_missing_stored_file_produces_a_404_not_a_server_error(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator7@khb.test', $this->kano);
        $viewer = $this->userWith(['bcms.exercise.view', 'rcsa_scope.all_units'], 'viewer7@khb.test', $this->kano);
        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);

        // A row whose file_path was never actually written to the fake disk.
        $path = 'bcms/evidence/'.$occurrence->organization_id.'/'.$occurrence->getKey().'/ghost-headcount-sheet.txt';
        $evidence = $this->evidenceWithRawPath($occurrence, $path, 'ghost-headcount-sheet.txt');

        $this->assertDownloadRefusedCleanly(
            $occurrence,
            $evidence,
            $viewer,
            'File not found.',
            [$path, 'ghost-headcount-sheet.txt'],
        );
    }

    /**
     * FIXED (was defect A.2). The traversal segment is refused by
     * `FileUploadService::safeRelativePath()` before any disk read — there
     * was never a byte-level leak — but the failure now also surfaces as a
     * clean 404 rather than an unhandled 500, and neither the traversal
     * target nor the exception message reaches the response body or an
     * unscrubbed log line.
     */
    #[Test]
    public function a_traversal_file_path_never_serves_a_file_outside_the_evidence_root(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator8@khb.test', $this->kano);
        $viewer = $this->userWith(['bcms.exercise.view', 'rcsa_scope.all_units'], 'viewer8@khb.test', $this->kano);
        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);

        $path = '../../../../../../etc/passwd';
        $evidence = $this->evidenceWithRawPath($occurrence, $path, 'escaped.txt');

        $this->assertDownloadRefusedCleanly(
            $occurrence,
            $evidence,
            $viewer,
            'parent-directory segment',
            [$path, 'etc/passwd', 'escaped.txt'],
        );
    }

    /**
     * Every OTHER refusal `FileUploadService::safeRelativePath()` can raise
     * from a stored path, each constructible directly in a fixture (no upload
     * validation stands between a stored row and this route): an absolute
     * path, the two stream-wrapper spellings named in the brief, a null byte,
     * and an empty string. Each must produce the identical clean shape the
     * two defects above now produce.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    public static function otherStoredPathRefusals(): iterable
    {
        yield 'absolute path' => [
            'abs', '/etc/passwd', 'Refusing an absolute storage path.', ['/etc/passwd'],
        ];
        yield 'php:// stream wrapper' => [
            'php', 'php://filter/read=convert.base64-encode/resource=index.php',
            'Refusing a storage path carrying a stream wrapper.',
            ['php://filter', 'index.php'],
        ];
        yield 'file:// stream wrapper' => [
            'file', 'file:///etc/passwd', 'Refusing a storage path carrying a stream wrapper.', ['file:///etc/passwd'],
        ];
        yield 'null byte' => [
            'null', "bcms/evidence/1/1/evil.txt\0.jpg", 'empty or null-byte', ['evil.txt'],
        ];
        yield 'empty string' => [
            'empty', '', 'empty or null-byte', [],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('otherStoredPathRefusals')]
    public function every_other_stored_path_refusal_produces_the_same_clean_404(
        string $slug,
        string $rawPath,
        string $expectedReasonSubstring,
        array $forbiddenNeedles,
    ): void {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], "facilitator-refusal-{$slug}@khb.test", $this->kano);
        $viewer = $this->userWith(['bcms.exercise.view', 'rcsa_scope.all_units'], "viewer-refusal-{$slug}@khb.test", $this->kano);
        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);

        $evidence = $this->evidenceWithRawPath($occurrence, $rawPath, "exploit-{$slug}.txt");

        $this->assertDownloadRefusedCleanly($occurrence, $evidence, $viewer, $expectedReasonSubstring, $forbiddenNeedles);
    }

    /**
     * Authorisation order (retest point 4): `Gate::authorize('bcms.exercise.
     * view')` runs BEFORE the try/catch around the file service, so a user
     * with no permission is refused with 403 even against a row whose stored
     * path is unreadable garbage — the file-existence check is never reached,
     * and the 403 is not converted into the file-refusal 404 shape.
     */
    #[Test]
    public function authorization_is_checked_before_the_file_is_ever_touched(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator-authz@khb.test', $this->kano);
        $noPermission = $this->userWith([], 'no-permission-authz@khb.test', $this->kano);
        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);

        // Deliberately broken — if the gate ran after the file check, this
        // would 404 instead of 403.
        $evidence = $this->evidenceWithRawPath($occurrence, '../../../../../../etc/passwd', 'escaped.txt');

        Log::spy();

        $this->actingAs($noPermission)
            ->get(route('bcms.evidence.download', [$occurrence, $evidence]))
            ->assertForbidden();

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Retest point 4, second half: a record the requesting user cannot SEE
     * (ADR 0017 — resolved at route binding, before the controller runs at
     * all) and a record the user CAN see whose file is merely gone both come
     * back as exactly the same status code, 404 — there is no way to
     * distinguish "this row is not yours" from "this row's file expired" by
     * status alone, which is the "no existence oracle" property ADR 0017 §3
     * already claims for visibility and this pins for the file layer too.
     */
    #[Test]
    public function an_invisible_record_and_a_visible_record_with_a_missing_file_both_404_identically(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator-oracle@khb.test', $this->lagos);
        $definition = $this->definition(['business_unit_id' => $this->lagos->id, 'facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);
        $visibleEvidence = $this->evidenceFor($occurrence);

        // Out of unit, no named-user arm, no rcsa_scope.all_units — cannot
        // see the record at all (ADR 0017).
        $outsideViewer = $this->userWith(['bcms.exercise.view'], 'oracle-outside-viewer@khb.test', $this->kano);
        $invisibleRecordResponse = $this->actingAs($outsideViewer)
            ->get(route('bcms.evidence.download', [$occurrence, $visibleEvidence]));

        // In unit — sees the record, but its file was never written.
        $insideViewer = $this->userWith(['bcms.exercise.view'], 'oracle-inside-viewer@khb.test', $this->lagos);
        $ghostEvidence = $this->evidenceWithRawPath(
            $occurrence,
            'bcms/evidence/'.$occurrence->organization_id.'/'.$occurrence->getKey().'/oracle-ghost.txt',
            'oracle-ghost.txt',
        );
        $visibleButMissingFileResponse = $this->actingAs($insideViewer)
            ->get(route('bcms.evidence.download', [$occurrence, $ghostEvidence]));

        $invisibleRecordResponse->assertStatus(404);
        $visibleButMissingFileResponse->assertStatus(404);
        $this->assertSame($invisibleRecordResponse->getStatusCode(), $visibleButMissingFileResponse->getStatusCode());
    }

    /**
     * Gate-1-retest-2: a genuine storage-layer failure — not one of
     * `FileUploadService`'s own deliberate refusals — must NOT be answered
     * with the same "no longer available" 404. `EvidenceController::
     * download()` now re-throws when `$e instanceof
     * \League\Flysystem\FilesystemException` before it ever reaches the
     * `Log::warning()`/`abort(404, …)` path, so it should surface as an
     * ordinary uncaught-exception 500 and get normal error-level attention,
     * not the same benign, `warning`-level shape as a retention-expired file.
     *
     * `FileUploadService` is swapped for a Mockery double so the exception
     * can be raised from `download()` itself without needing a real
     * unreadable file on the fake disk (there is no reliable, portable way to
     * make MariaDB/the local test disk simulate a permission-denied read).
     * `League\Flysystem\UnableToRetrieveMetadata::fileSize('x', 'boom')` is
     * the exact shape `Illuminate\Filesystem\FilesystemAdapter::size()`
     * propagates uncaught (no internal try/catch, unlike `mimeType()`/
     * `readStream()`) — the real defect named in the prior retest.
     *
     * CHOICE: NOT `withoutExceptionHandling()`/`expectException()`. Those
     * would make the exception propagate out of `->get()` itself, leaving no
     * `TestResponse` to assert a status code against and ending the test the
     * moment it throws — which cannot also confirm "no warning was logged"
     * afterwards in the same request. Leaving Laravel's normal exception
     * handling in place produces an ordinary 500 `TestResponse`, so both
     * halves of the proof (the status code AND the log spy's state after the
     * request completed) are checked in one request, which is the more
     * complete assertion here.
     */
    #[Test]
    public function a_flysystem_level_failure_is_never_turned_into_the_evidence_refused_404(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator-outage@khb.test', $this->kano);
        $viewer = $this->userWith(['bcms.exercise.view', 'rcsa_scope.all_units'], 'viewer-outage@khb.test', $this->kano);
        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);
        $evidence = $this->evidenceFor($occurrence);

        $this->mock(FileUploadService::class, function ($mock): void {
            $mock->shouldReceive('download')
                ->once()
                ->andThrow(UnableToRetrieveMetadata::fileSize('x', 'boom'));
        });

        Log::spy();

        $response = $this->actingAs($viewer)
            ->get(route('bcms.evidence.download', [$occurrence, $evidence]));

        $response->assertStatus(500);
        Log::shouldNotHaveReceived('warning');
    }

    /* ------------------------------------------------------------------ */
    /*  7. Response hygiene: Content-Type and Content-Disposition. */
    /* ------------------------------------------------------------------ */

    /**
     * `FileUploadService::download()` never passes a `Content-Type` header of
     * its own — it calls `$this->disk()->download($path, $downloadName)`,
     * which is Laravel's `FilesystemAdapter::response()`: `Content-Type` is
     * filled from `$this->mimeType($path)`, DETECTED from the bytes actually
     * on disk, and is NOT read from `bcms_evidence.mime` at all. Proven here
     * by storing the row with a deliberately WRONG `mime` column
     * (`application/pdf` on an `.html` file's bytes) — the header served back
     * is `text/html` regardless, confirming the column is never consulted.
     *
     * The disposition is the hygiene property that DOES hold: `download()`
     * always requests `attachment` (never `inline`), so an uploaded HTML/SVG
     * file cannot render on the application's origin no matter what
     * Content-Type is detected.
     */
    #[Test]
    public function an_html_evidence_file_is_served_as_an_attachment_never_inline(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator9@khb.test', $this->kano);
        $viewer = $this->userWith(['bcms.exercise.view', 'rcsa_scope.all_units'], 'viewer9@khb.test', $this->kano);
        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);

        $evidence = $this->evidenceFor(
            $occurrence,
            '<html><body><script>alert(document.cookie)</script></body></html>',
            'payload.html',
            'payload.html',
            // Deliberately wrong — proves Content-Type is not read from here.
            'application/pdf',
        );

        $response = $this->actingAs($viewer)
            ->get(route('bcms.evidence.download', [$occurrence, $evidence]));

        $response->assertOk();

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment', $disposition, 'An HTML evidence file must never be served inline.');

        // The stored `mime` column said `application/pdf`; the header served
        // is the disk's own content-detected type instead.
        $this->assertStringStartsWith(
            'text/html',
            (string) $response->headers->get('Content-Type'),
            'Content-Type is detected from the bytes on disk, not read from bcms_evidence.mime.',
        );
    }

    /* ------------------------------------------------------------------ */
    /*  8. Audit — does a download write a row? (report only; see below). */
    /* ------------------------------------------------------------------ */

    /**
     * Neither `EvidenceController::download()` nor `FileUploadService::
     * download()` calls `recordAudit()` or touches the `Evidence` model at
     * all (no save, no update) — `BcmsAuditable` only hooks `created`,
     * `updated` and `deleted`, so a read never writes a row through it
     * either. This test documents that a download today leaves the audit
     * trail unchanged. Neither `docs/DEVELOPMENT_STANDARD.md`'s Definition of
     * Done item 4 ("every STATE CHANGE writes an activity-log entry") nor
     * `docs/compliance/ndpa-register.md` §8.1/§8.2 requires an access-log row
     * for a read of exercise evidence — §8.2's control is "read permission",
     * not "log every read" — so this is recorded as a gap for the report
     * rather than asserted as a defect.
     */
    #[Test]
    public function downloading_evidence_writes_no_audit_row_today(): void
    {
        $facilitator = $this->userWith(['bcms.exercise.facilitate', 'rcsa_scope.all_units'], 'facilitator10@khb.test', $this->kano);
        $viewer = $this->userWith(['bcms.exercise.view', 'rcsa_scope.all_units'], 'viewer10@khb.test', $this->kano);
        $definition = $this->definition(['facilitator_id' => $facilitator->id]);
        $occurrence = $this->occurrence($definition, ['facilitator_id' => $facilitator->id]);
        $evidence = $this->evidenceFor($occurrence);

        $before = AuditLog::query()->where('auditable_type', Evidence::class)
            ->where('auditable_id', $evidence->getKey())->count();

        $this->actingAs($viewer)
            ->get(route('bcms.evidence.download', [$occurrence, $evidence]))
            ->assertOk();

        $after = AuditLog::query()->where('auditable_type', Evidence::class)
            ->where('auditable_id', $evidence->getKey())->count();

        $this->assertSame($before, $after, 'A download unexpectedly wrote an audit row — update this test to assert its shape.');
    }
}
