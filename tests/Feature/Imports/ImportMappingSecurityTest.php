<?php

namespace Tests\Feature\Imports;

use App\Jobs\ProcessDataImportJob;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\User;
use App\Services\Import\DataImportProcessor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * What a column mapping is allowed to name (migration Phase 5.5).
 *
 * `processImport()` validated `column_mapping` as `required|array` and nothing
 * more — no rule on its KEYS. Those keys become the attribute names in
 * `Model::create()`:
 *
 *     foreach ($mapping as $field => $columnIndex) { $data[$field] = $value; }
 *     Risk::create(array_merge($data, [...]));
 *
 * The mapping screen offers nine fields for a risk import; `Risk::$fillable`
 * has forty-odd. A caller could name `parent_risk_id`, `entity_id`,
 * `hierarchy_path` or `created_by` — none of them things a spreadsheet import
 * was meant to set — and the row would be created with them.
 *
 * WHAT IT COULD NOT DO, asserted below because the obvious worry is the wrong
 * one: `organization_id` is fillable too, but DataImportProcessor overwrites it
 * with the import's own organisation AFTER the mapping is applied, so a mapping
 * naming it never wrote into another institution. The hole was in what a row
 * may CONTAIN, not in which bank it lands in.
 *
 * This is 4.6's rule again: WHAT A FORM OFFERS MUST BE WHAT THE VALIDATOR
 * ACCEPTS.
 */
class ImportMappingSecurityTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['import.view', 'import.create', 'import.process'] as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->actor->givePermissionTo(['import.view', 'import.create', 'import.process']);

        $this->otherOrg = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Their Officer',
            'email' => 'outsider@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->otherOrg->id,
            'is_active' => true,
        ]);
    }

    /** A mapping may only name the fields the mapping screen offers. */
    #[Test]
    public function a_mapping_cannot_name_a_field_the_screen_does_not_offer(): void
    {
        Queue::fake();

        $import = $this->import();

        // Fillable on Risk, and not among the nine a risk import offers.
        $this->actingAs($this->actor)
            ->post(route('risk.imports.process', $import), [
                'column_mapping' => ['parent_risk_id' => 0, 'title' => 1],
            ])
            ->assertSessionHasErrors('column_mapping.parent_risk_id');

        $this->assertSame('pending', $import->fresh()->status, 'Nothing is queued when the mapping is refused.');
    }

    /** And the fields it does offer are accepted. */
    #[Test]
    public function a_mapping_over_the_offered_fields_is_accepted(): void
    {
        Queue::fake();

        $import = $this->import();

        $this->actingAs($this->actor)
            ->post(route('risk.imports.process', $import), [
                'column_mapping' => ['title' => 0, 'description' => 1, 'status' => 2],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fresh = $import->fresh();

        $this->assertSame('queued', $fresh->status, 'The column could not hold this value until Phase 5.5.');
        Queue::assertPushed(ProcessDataImportJob::class);
        $this->assertSame(['title' => '0', 'description' => '1', 'status' => '2'], array_map('strval', $fresh->column_mapping));
    }

    /**
     * The processor itself drops an out-of-vocabulary field, whatever put it
     * there.
     *
     * Defence in depth: a mapping stored before this phase — when the rule was
     * `required|array` and nothing more — still cannot reach `create()` with a
     * column the import was never meant to set.
     */
    #[Test]
    public function the_processor_ignores_a_field_outside_the_import_type(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'imports/test.csv',
            "title,description,category,parent\nImported risk,From a spreadsheet,{$this->category->id},4242\n",
        );

        $import = $this->import([
            'file_path' => 'imports/test.csv',
            'column_mapping' => ['title' => 0, 'description' => 1, 'category_id' => 2, 'parent_risk_id' => 3],
            'status' => 'queued',
        ]);

        TenantContext::actingAs($this->organization->id, fn () => app(DataImportProcessor::class)->process($import));

        $risk = Risk::withoutGlobalScopes()->where('title', 'Imported risk')->first();

        $this->assertNotNull($risk, 'The row still imports — only the field the type does not offer is dropped.');
        $this->assertNull($risk->parent_risk_id, 'A field outside the import type never reaches create().');
        $this->assertSame($this->organization->id, (int) $risk->organization_id);
    }

    /**
     * And the worry that ISN'T real, pinned so nobody re-derives it.
     *
     * `organization_id` is fillable on every model an import writes, and
     * BelongsToOrganization leaves an explicitly-set id alone — but the
     * processor overwrites it with the import's own organisation after the
     * mapping is applied. A mapping naming it never wrote into another bank.
     */
    #[Test]
    public function a_mapping_could_never_have_written_into_another_institution(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'imports/test.csv',
            "title,description,category,org\nImported risk,From a spreadsheet,{$this->category->id},{$this->otherOrg->id}\n",
        );

        $import = $this->import([
            'file_path' => 'imports/test.csv',
            // Deliberately the pre-5.5 shape: a mapping nothing validated.
            'column_mapping' => ['title' => 0, 'description' => 1, 'category_id' => 2, 'organization_id' => 3],
            'status' => 'queued',
        ]);

        TenantContext::actingAs($this->organization->id, fn () => app(DataImportProcessor::class)->process($import));

        $risk = Risk::withoutGlobalScopes()->where('title', 'Imported risk')->firstOrFail();

        $this->assertSame(
            $this->organization->id,
            (int) $risk->organization_id,
            'The row belongs to the importing institution, not the one named in the spreadsheet.',
        );
    }

    /* ------------------------------------------------------------------ */

    private function import(array $attributes = []): DataImport
    {
        return DataImport::create(array_merge([
            'organization_id' => $this->organization->id,
            'import_type' => 'risks',
            'file_name' => 'register.csv',
            'file_path' => 'imports/register.csv',
            'status' => 'pending',
            'imported_by' => $this->actor->id,
        ], $attributes));
    }
}
