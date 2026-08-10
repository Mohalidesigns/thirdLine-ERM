<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskAuditTrail;
use App\Models\RiskCategory;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Support\MorphTypes;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The audit trail must be readable across every historic spelling of
 * entity_type WITHOUT the sealed rows ever being rewritten.
 *
 * risk_audit_trail is append-only at the database level and hash-chained, with
 * entity_type inside each row's digest. Normalising the column in place — the
 * obvious fix for the mixed spellings — would mean dropping the immutability
 * triggers, editing sealed rows and re-computing every chain. That inverts the
 * guarantee the chain exists to give: after a re-seal, the migration's own edit
 * is indistinguishable from a tamper.
 *
 * So the reconciliation happens on read. These tests pin both halves: the trail
 * is not rewritten, and the history is still found.
 */
class AuditTrailMorphSpellingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Risk $risk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Audit Bank PLC',
            'short_name' => 'AUDB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->org->id);

        $this->user = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Audit Officer',
            'email' => 'audit@auditbank.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $category = RiskCategory::create([
            'organization_id' => $this->org->id,
            'code' => 'OPS',
            'name' => 'Operational Risk',
        ]);

        $this->risk = Risk::create([
            'organization_id' => $this->org->id,
            'risk_code' => 'RK-AUD-0001',
            'title' => 'Audited risk',
            'description' => 'Has history in three spellings.',
            'category_id' => $category->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
    }

    #[Test]
    public function spellings_for_covers_the_alias_the_basename_and_the_fqcn(): void
    {
        $spellings = MorphTypes::spellingsFor('risk');

        $this->assertContains('risk', $spellings, 'the canonical alias');
        $this->assertContains('Risk', $spellings, 'what AuditTrailService wrote before the morph map');
        $this->assertContains(Risk::class, $spellings, 'what WorkflowController wrote');
    }

    #[Test]
    public function spellings_for_resolves_from_any_input_spelling(): void
    {
        $fromAlias = MorphTypes::spellingsFor('treatment_plan');
        $fromBasename = MorphTypes::spellingsFor('TreatmentPlan');
        $fromClass = MorphTypes::spellingsFor(TreatmentPlan::class);

        $this->assertSame($fromAlias, $fromBasename);
        $this->assertSame($fromAlias, $fromClass);
    }

    #[Test]
    public function an_unknown_type_is_not_widened_into_something_unaccountable(): void
    {
        $this->assertSame(
            ['something_that_is_not_a_model'],
            MorphTypes::spellingsFor('something_that_is_not_a_model')
        );
    }

    #[Test]
    public function the_audit_trail_returns_rows_written_in_every_historic_spelling(): void
    {
        // Three rows for the same risk, as the three eras of this codebase
        // would each have written them.
        foreach (['risk', 'Risk', Risk::class] as $index => $spelling) {
            RiskAuditTrail::create([
                'organization_id' => $this->org->id,
                'entity_type' => $spelling,
                'entity_id' => $this->risk->id,
                'action_type' => 'updated',
                'field_changed' => 'title',
                'old_value' => 'before '.$index,
                'new_value' => 'after '.$index,
                'changed_by' => $this->user->id,
                'changed_at' => now(),
            ]);
        }

        $trail = $this->risk->auditTrail()->get();

        $this->assertCount(
            3,
            $trail,
            'A history screen must show entries regardless of which era wrote them.'
        );
    }

    #[Test]
    public function the_audit_trail_does_not_pick_up_another_entity_types_rows(): void
    {
        RiskAuditTrail::create([
            'organization_id' => $this->org->id,
            'entity_type' => 'TreatmentPlan',
            // Same id as the risk: only entity_type distinguishes them, which
            // is exactly the case a too-wide whereIn would get wrong.
            'entity_id' => $this->risk->id,
            'action_type' => 'deleted',
            'changed_by' => $this->user->id,
            'changed_at' => now(),
        ]);

        $this->assertCount(0, $this->risk->auditTrail()->get());
    }

    #[Test]
    public function the_normalising_migration_leaves_the_audit_trail_alone(): void
    {
        // The migration has already run for this test database. If it ever
        // starts touching risk_audit_trail, this row's spelling would change.
        RiskAuditTrail::create([
            'organization_id' => $this->org->id,
            'entity_type' => 'Risk',
            'entity_id' => $this->risk->id,
            'action_type' => 'created',
            'changed_by' => $this->user->id,
            'changed_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_08_10_110003_normalise_entity_type_to_morph_aliases.php'
        );

        $source = file_get_contents(database_path(
            'migrations/2026_08_10_110003_normalise_entity_type_to_morph_aliases.php'
        ));

        // The table may appear in the explanatory comment, but must not be a
        // target the migration writes to.
        $this->assertStringNotContainsString(
            "'risk_audit_trail' => 'entity_type'",
            $source,
            'risk_audit_trail must not be a normalisation target: it is append-only and hash-chained.'
        );

        $migration->up();

        $this->assertSame(
            'Risk',
            DB::table('risk_audit_trail')->where('entity_id', $this->risk->id)->value('entity_type'),
            'Running the migration must not rewrite a sealed audit row.'
        );
    }

    #[Test]
    public function the_hash_chain_still_verifies_after_the_migration_runs(): void
    {
        foreach (['risk', 'Risk'] as $spelling) {
            RiskAuditTrail::create([
                'organization_id' => $this->org->id,
                'entity_type' => $spelling,
                'entity_id' => $this->risk->id,
                'action_type' => 'updated',
                'changed_by' => $this->user->id,
                'changed_at' => now(),
            ]);
        }

        $migration = require database_path(
            'migrations/2026_08_10_110003_normalise_entity_type_to_morph_aliases.php'
        );
        $migration->up();

        $this->artisan('audit:verify')->assertExitCode(0);
    }

    #[Test]
    public function new_audit_rows_are_written_with_the_canonical_alias(): void
    {
        // The write sites used to hardcode 'TreatmentPlan' and 'risk'; they now
        // go through getMorphClass(), so the legacy set stops growing.
        $this->assertSame('risk', $this->risk->getMorphClass());

        RiskAuditTrail::create([
            'organization_id' => $this->org->id,
            'entity_type' => $this->risk->getMorphClass(),
            'entity_id' => $this->risk->id,
            'action_type' => 'created',
            'changed_by' => $this->user->id,
            'changed_at' => now(),
        ]);

        $this->assertSame(
            'risk',
            DB::table('risk_audit_trail')->where('entity_id', $this->risk->id)->value('entity_type')
        );
    }
}
