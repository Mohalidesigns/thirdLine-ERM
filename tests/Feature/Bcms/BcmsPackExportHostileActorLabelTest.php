<?php

namespace Tests\Feature\Bcms;

use App\Models\Bcms\AuditLog;
use App\Models\Bcms\Programme;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Reports\BoardPackService;
use App\Services\Bcms\Reports\CsatPrefillService;
use App\Services\Bcms\Reports\EvidencePackService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The three BCMS report services each write a `pack.exported` row to
 * `bcms_audit_logs` themselves, in a `try { ... } catch (\Throwable)` that
 * counts the failure and carries on, so the export is delivered either way.
 *
 * `actor_label` is `varchar(150)` and a user's name is not bounded by that.
 * Before `AuditText::clip()` an over-long (or multi-byte) name made the strict
 * insert fail, the catch swallowed it, and a board pack full of nonconformity
 * detail left no record of who took it. Assert on the stored row.
 */
class BcmsPackExportHostileActorLabelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $actor;

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

        $this->actor = User::create([
            'name' => str_repeat('é', 255), 'email' => 'long-name@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        Programme::factory()->create(['status' => 'approved', 'year' => now()->year]);
        Cache::forget(AuditLog::AUDIT_FAILURE_CACHE_KEY);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function exports(): array
    {
        return [
            'evidence pack' => ['evidence'],
            'board pack pdf' => ['board-pdf'],
            'board pack pptx' => ['board-pptx'],
            'csat prefill' => ['csat'],
        ];
    }

    #[Test]
    #[DataProvider('exports')]
    public function an_over_long_multibyte_actor_name_still_leaves_the_export_row(string $export): void
    {
        $this->runExport($export);

        $row = AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', 'bcms_report_pack')
            ->where('event', 'pack.exported')
            ->first();

        $this->assertNotNull($row, 'the export must be logged, not silently dropped');
        $this->assertSame($this->actor->getKey(), $row->actor_id);
        $this->assertTrue(mb_check_encoding($row->actor_label, 'UTF-8'));
        $this->assertSame(150, mb_strlen($row->actor_label, 'UTF-8'));
        $this->assertNull(Cache::get(AuditLog::AUDIT_FAILURE_CACHE_KEY), 'no audit failure may have been counted');
    }

    private function runExport(string $export): void
    {
        match ($export) {
            'evidence' => app(EvidencePackService::class)->generate(
                'iso22301', now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString(), $this->actor,
            ),
            'board-pdf' => app(BoardPackService::class)->generatePdf(now()->year, $this->actor),
            'board-pptx' => app(BoardPackService::class)->generatePptx(now()->year, $this->actor),
            'csat' => $this->csat(),
        };
    }

    private function csat(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csat-hostile-').'.xlsx';
        (new Xlsx(new Spreadsheet))->save($path);

        try {
            app(CsatPrefillService::class)->fill($path, $this->actor);
        } finally {
            @unlink($path);
        }
    }
}
