<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Contract;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\NthPartyEdge;
use App\Models\Tprm\QuestionnaireTemplate;
use App\Models\Tprm\ThirdParty;
use App\Models\Tprm\TprmSetting;
use App\Models\User;
use App\Services\Tprm\Reporting\DoraRegisterBuilder;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * FR-RPT-02 — the Register of Information.
 *
 * THE ASSERTIONS THAT MATTER ARE ABOUT WHAT THE REGISTER REFUSES TO SAY. Any
 * builder can emit fourteen sheets; the failure mode of a supervisory register
 * is a confident blank — an LEI column that is empty because the product does
 * not hold LEIs, read by a supervisor as an institution that has none. So the
 * tests pin "Not held" and "Not set" as literal values, and pin that a table
 * missing a field declares itself partial.
 */
class DoraRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Lagos Continental Bank', 'short_name' => 'LCB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
            'rc_number' => 'RC123456',
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->user = User::create([
            'name' => 'Register Owner', 'email' => 'owner@lcb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-dora', 'web');
        foreach (['tprm.view', 'tprm.report.view', 'tprm.report.export'] as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->user->assignRole($role);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function the_register_ships_all_fourteen_templates(): void
    {
        $codes = collect(app(DoraRegisterBuilder::class)->tables())->pluck('code')->all();

        $this->assertSame([
            'RT.01.01', 'RT.01.02', 'RT.01.03',
            'RT.02.01', 'RT.02.02', 'RT.02.03',
            'RT.03.01', 'RT.03.02', 'RT.03.03',
            'RT.04.01',
            'RT.05.01', 'RT.05.02',
            'RT.06.01',
            'RT.07.01',
        ], $codes);
    }

    #[Test]
    public function an_unset_institution_identity_is_declared_partial_and_printed_as_not_set(): void
    {
        $table = $this->table('RT.01.01');

        $this->assertSame(DoraRegisterBuilder::COVERAGE_PARTIAL, $table['coverage']);
        $this->assertStringContainsString('LEI', (string) $table['note']);
        $this->assertContains('Not set', $table['rows'][0]);

        // The RC number IS held, and is not an LEI. A register that used it as
        // one would be making a false statement in a field with a definition.
        $this->assertContains('RC123456', $table['rows'][0]);
    }

    #[Test]
    public function setting_the_identity_makes_the_first_table_complete(): void
    {
        TprmSetting::forOrganization($this->bank->id)->forceFill([
            'lei' => '5493001KJTIIGC8Y1R12',
            'country' => 'NG',
            'competent_authority' => 'Central Bank of Nigeria',
            'reporting_currency' => 'NGN',
        ])->save();

        $table = $this->table('RT.01.01');

        $this->assertSame(DoraRegisterBuilder::COVERAGE_COMPLETE, $table['coverage']);
        $this->assertNull($table['note']);
        $this->assertContains('5493001KJTIIGC8Y1R12', $table['rows'][0]);
    }

    #[Test]
    public function a_provider_without_an_lei_is_identified_by_its_registration_number_and_the_type_says_so(): void
    {
        $this->ictEngagement('ENG-1', 'Core banking', vendorAttributes: [
            'registration_number' => 'RC998877',
        ]);

        $row = $this->table('RT.05.01')['rows'][0];

        $this->assertContains('RC998877', $row);
        $this->assertContains('National registration number', $row);
    }

    #[Test]
    public function a_provider_with_neither_code_reports_not_held_rather_than_a_blank(): void
    {
        $this->ictEngagement('ENG-2', 'Hosting');

        $row = $this->table('RT.05.01')['rows'][0];

        $this->assertContains('Not held', $row);
        $this->assertContains('None held', $row);
    }

    #[Test]
    public function the_supply_chain_table_carries_the_rank_and_marks_unconfirmed_links(): void
    {
        $engagement = $this->ictEngagement('ENG-3', 'Payments');

        $sub = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => 'Subprocessor Limited',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        NthPartyEdge::create([
            'organization_id' => $this->bank->id,
            'parent_third_party_id' => $engagement->third_party_id,
            'child_third_party_id' => $sub->id,
            'child_name_raw' => 'Subprocessor Limited',
            'engagement_id' => $engagement->id,
            'rank' => 2,
            'disclosure_source' => 'vendor_declared',
        ]);

        $table = $this->table('RT.05.02');

        $this->assertCount(1, $table['rows']);
        $this->assertContains(2, $table['rows'][0]);
        // Proposed links are included and MARKED. A chain of only confirmed
        // links understates what the institution has been told.
        $this->assertContains('Proposed', $table['rows'][0]);
        $this->assertSame(DoraRegisterBuilder::COVERAGE_PARTIAL, $table['coverage']);
    }

    #[Test]
    public function the_function_table_reports_a_never_assessed_criticality_as_such(): void
    {
        BusinessFunction::create([
            'organization_id' => $this->bank->id,
            'function_code' => 'BF-CLEAR',
            'name' => 'Clearing and settlement',
            'criticality' => 'critical',
        ]);

        $row = collect($this->table('RT.06.01')['rows'])->firstWhere(0, 'BF-CLEAR');

        $this->assertNotNull($row);
        $this->assertContains('Never assessed', $row);
        $this->assertContains('Not set', $row); // RTO and RPO
    }

    #[Test]
    public function only_engagements_supporting_a_critical_function_reach_the_assessment_table(): void
    {
        $this->ictEngagement('ENG-CRIT', 'Core banking', ['supports_critical_function' => true]);
        $this->ictEngagement('ENG-ORD', 'Wiki hosting');

        $references = collect($this->table('RT.07.01')['rows'])->map(fn (array $row) => $row[1])->all();

        $this->assertSame(['ENG-CRIT'], $references);
    }

    #[Test]
    public function a_submitted_but_unvalidated_assessment_is_not_reported_as_an_audit_date(): void
    {
        $engagement = $this->ictEngagement('ENG-AUD', 'Hosting', ['supports_critical_function' => true]);

        // The shipped packs are system-owned (`organization_id = null`) and the
        // tenancy global scope excludes null, so a tenant-owned template is
        // made here rather than reaching for one of theirs.
        $template = QuestionnaireTemplate::create([
            'organization_id' => $this->bank->id,
            'code' => 'FIXTURE', 'name' => 'Fixture pack', 'version' => 1,
        ]);

        Assessment::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'template_id' => $template->getKey(),
            'template_version' => $template->version,
            'cycle_label' => '2026',
            'assessment_type' => 'periodic',
        ])->forceFill(['status' => 'submitted', 'submitted_at' => now()->subMonth()])->save();

        $row = collect($this->table('RT.07.01')['rows'])->firstWhere(1, 'ENG-AUD');

        $this->assertContains('No validated assessment', $row);
    }

    #[Test]
    public function the_workbook_holds_a_cover_sheet_and_one_sheet_per_template(): void
    {
        $this->ictEngagement('ENG-WB', 'Hosting');

        $bytes = $this->actingAs($this->user)
            ->get(route('tprm.reports.dora-register.export', ['format' => 'xlsx']))
            ->assertOk()
            ->getContent();

        $path = tempnam(sys_get_temp_dir(), 'dora').'.xlsx';
        file_put_contents($path, $bytes);
        $spreadsheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx)->load($path);
        unlink($path);

        $names = array_map(fn ($sheet) => $sheet->getTitle(), $spreadsheet->getAllSheets());

        $this->assertCount(15, $names, 'A cover sheet and fourteen templates.');
        $this->assertSame('Cover', $names[0]);

        $cover = $spreadsheet->getSheet(0)->toArray();
        $flat = implode(' ', array_map(fn ($row) => implode(' ', array_map('strval', $row)), $cover));

        // TRD Appendix D requires the caveat on the cover sheet, not in a
        // developer comment.
        $this->assertStringContainsString('2024/2956', $flat);
        $this->assertStringContainsString('JC 2024 79', $flat);
    }

    #[Test]
    public function the_pdf_prints_the_coverage_summary_and_the_cardinality_caveat(): void
    {
        $this->ictEngagement('ENG-PDF', 'Hosting');

        $pdf = $this->actingAs($this->user)
            ->get(route('tprm.reports.dora-register.export', ['format' => 'pdf']))
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
        // A fourteen-table register is not readable as fourteen tables on
        // paper; the document's job is the coverage summary, so a PDF that
        // rendered nothing would still be several kilobytes of chrome.
        $this->assertGreaterThan(20_000, strlen($pdf));
    }

    #[Test]
    public function a_csv_export_must_name_the_template_it_wants(): void
    {
        $this->ictEngagement('ENG-CSV', 'Hosting');

        $this->actingAs($this->user)
            ->get(route('tprm.reports.dora-register.export', ['format' => 'csv']))
            ->assertStatus(422);

        $csv = $this->actingAs($this->user)
            ->get(route('tprm.reports.dora-register.export', ['format' => 'csv', 'table' => 'RT.05.01']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('RT.05.01', $csv);
        $this->assertStringContainsString('Identification code', $csv);
    }

    #[Test]
    public function the_screen_declares_coverage_and_gates_export_separately(): void
    {
        $this->ictEngagement('ENG-SCR', 'Hosting');

        $this->actingAs($this->user)
            ->get(route('tprm.reports.dora-register'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Reports/DoraRegister')
                ->has('tables', 14)
                ->where('tables.0.code', 'RT.01.01')
                ->has('caveat')
                ->where('can.export', true)
            );
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function table(string $code): array
    {
        $table = collect(app(DoraRegisterBuilder::class)->tables())->firstWhere('code', $code);

        $this->assertNotNull($table, "No table {$code} in the register.");

        return $table;
    }

    private function ictEngagement(string $reference, string $name, array $attributes = [], array $vendorAttributes = []): Engagement
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => $name.' provider',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ] + $vendorAttributes);

        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $vendor->id,
            'reference' => $reference,
            'name' => $name,
            'engagement_type' => 'ict_service',
        ]);

        $engagement->forceFill(['status' => EngagementStatus::Active->value] + $attributes)->save();

        Contract::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'contract_type' => 'msa',
            'reference' => 'CTR-'.$reference,
            'title' => $name.' agreement',
        ]);

        return $engagement->refresh();
    }
}
