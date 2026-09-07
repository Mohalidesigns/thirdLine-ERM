<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ImportBatch;
use App\Models\Tprm\Ruleset;
use App\Models\Tprm\ThirdParty;
use App\Models\Tprm\Waiver;
use App\Models\User;
use App\Services\Tprm\Scoring\Ruleset as RulesetValue;
use App\Services\Tprm\Scoring\RulesetSandbox;
use App\Services\Tprm\ThirdPartyImporter;
use App\Services\Tprm\TierOverrideService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The last three Phase 1 items: manual tier override (FR-TIER-04), reversible
 * bulk import (FR-TPR-09) and the ruleset sandbox (FR-TIER-09).
 */
class OverrideImportRulesetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->user = User::create([
            'name' => 'Chief Risk Officer', 'email' => 'cro@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-cro', 'web');
        foreach ([
            'tprm.view', 'tprm.create', 'tprm.edit', 'tprm.tier.override', 'tprm.ruleset.manage',
        ] as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->user->assignRole($role);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  FR-TIER-04 — manual tier override                                  */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_override_raises_a_tier_and_lands_on_the_register(): void
    {
        $engagement = $this->makeEngagement(RiskTier::Moderate);

        $result = app(TierOverrideService::class)->override(
            $engagement,
            RiskTier::Critical,
            'This vendor holds an undocumented feed into the payments switch that the questionnaire does not ask about.',
            now()->addMonths(6),
            $this->user->id,
            'Chief Risk Officer',
        );

        $this->assertTrue($result['applied']);

        $engagement->refresh();
        $this->assertSame(RiskTier::Critical, $engagement->tier_override);
        $this->assertSame(RiskTier::Critical, $engagement->effective_tier);

        // FR-TIER-04: overrides are reported separately to the risk committee.
        $waiver = Waiver::where('waivable_type', Waiver::TYPE_TIER_OVERRIDE)->firstOrFail();
        $this->assertTrue($waiver->isInForce());
        $this->assertSame('Chief Risk Officer', $waiver->approver_role);
    }

    #[Test]
    public function an_override_below_the_computed_tier_is_refused_with_a_reason(): void
    {
        // TRD §7.3's max() would silently discard it, and a form that accepts
        // a change which does nothing is the "screen that saves nothing"
        // defect this codebase keeps finding.
        $engagement = $this->makeEngagement(RiskTier::Critical);

        $result = app(TierOverrideService::class)->override(
            $engagement,
            RiskTier::Low,
            'The business believes this vendor is lower risk than the model says.',
            now()->addMonths(3),
            $this->user->id,
        );

        $this->assertFalse($result['applied']);
        $this->assertStringContainsString('raise a tier but never lower it', $result['reason']);
        $this->assertStringContainsString('change the ruleset', $result['reason']);

        $this->assertNull($engagement->refresh()->tier_override);
        $this->assertSame(0, Waiver::count());
    }

    #[Test]
    public function an_expired_override_stops_applying_and_is_restored_by_the_sweep(): void
    {
        $engagement = $this->makeEngagement(RiskTier::Moderate);

        app(TierOverrideService::class)->override(
            $engagement, RiskTier::Critical,
            'Temporary elevation while the data centre inspection is outstanding.',
            now()->addDay(), $this->user->id,
        );

        // Move the expiry into the past, as a day passing would.
        $engagement->forceFill(['tier_override_expires_at' => now()->subDay()->toDateString()])->save();

        // The scoring path already ignores it...
        $this->assertSame(RiskTier::Moderate, $engagement->refresh()->effectiveTier());

        // ...and the nightly sweep restores the stored column, so the register
        // stops showing the raised tier too.
        $restored = app(TierOverrideService::class)->restoreExpired();

        $this->assertSame(1, $restored);
        $engagement->refresh();
        $this->assertNull($engagement->tier_override);
        $this->assertSame(RiskTier::Moderate, $engagement->effective_tier);
        $this->assertSame(Waiver::STATUS_REVOKED, Waiver::first()->status);
    }

    #[Test]
    public function the_override_route_demands_a_rationale_and_an_expiry(): void
    {
        $engagement = $this->makeEngagement(RiskTier::Moderate);

        $this->actingAs($this->user)
            ->from(route('tprm.engagements.show', $engagement))
            ->post(route('tprm.engagements.tier-override', $engagement), [
                'tier' => 'critical',
                'rationale' => 'Risk accepted.',
            ])
            ->assertSessionHasErrors(['rationale', 'expires_at']);

        $this->assertNull($engagement->refresh()->tier_override);
    }

    #[Test]
    public function the_override_register_lists_what_is_in_force(): void
    {
        $engagement = $this->makeEngagement(RiskTier::Moderate);

        app(TierOverrideService::class)->override(
            $engagement, RiskTier::High,
            'Concentration on this provider group is above the committee threshold pending the exit plan.',
            now()->addDays(20), $this->user->id,
        );

        $this->actingAs($this->user)
            ->get(route('tprm.overrides.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Overrides/Index')
                ->where('summary.in_force', 1)
                ->where('summary.expiring_30', 1)
                ->has('waivers.data', 1)
                ->where('waivers.data.0.type_label', 'Tier override')
            );
    }

    /* ------------------------------------------------------------------ */
    /*  FR-TPR-09 — reversible bulk import                                 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_import_validates_maps_commits_and_rolls_back(): void
    {
        Storage::fake('local');

        $csv = "Vendor Name,RC Number,Country,Category\n"
            ."Alpha Systems Limited,RC-111111,NG,Cloud service providers\n"
            ."Beta Logistics Limited,RC-222222,NG,Cash-in-transit and logistics\n";

        $batch = $this->uploadCsv($csv);

        // The suggestion recognises a procurement export's own header names,
        // not only ours.
        $inspection = app(ThirdPartyImporter::class)->inspect($batch);
        $this->assertSame('Vendor Name', $inspection['suggestion']['legal_name']);
        $this->assertSame('RC Number', $inspection['suggestion']['registration_number']);

        app(ThirdPartyImporter::class)->dryRun($batch, $inspection['suggestion']);
        $batch->refresh();

        $this->assertSame(ImportBatch::STATUS_VALIDATED, $batch->status);
        $this->assertSame(2, $batch->rows_total);
        $this->assertSame(2, $batch->rows_valid);
        // The dry run wrote nothing to the register.
        $this->assertSame(0, ThirdParty::count());

        app(ThirdPartyImporter::class)->commit($batch, $this->user->id);
        $batch->refresh();

        $this->assertSame(2, ThirdParty::count());
        $this->assertCount(2, $batch->created_ids);
        $this->assertTrue($batch->canRollBack());

        // The category was resolved by name.
        $alpha = ThirdParty::where('legal_name', 'Alpha Systems Limited')->firstOrFail();
        $this->assertNotNull($alpha->category_id);

        $result = app(ThirdPartyImporter::class)->rollBack($batch);

        $this->assertSame(2, $result['deleted']);
        $this->assertSame(0, ThirdParty::count());
        $this->assertSame(ImportBatch::STATUS_ROLLED_BACK, $batch->refresh()->status);
    }

    #[Test]
    public function every_import_problem_names_the_row_in_the_users_own_file(): void
    {
        Storage::fake('local');

        $csv = "Vendor Name,LEI,Country\n"
            ."Good Vendor Limited,,NG\n"          // row 2 — fine
            .",,NG\n"                              // row 3 — no name
            ."Short LEI Limited,ABC123,NG\n"       // row 4 — bad LEI
            ."Good Vendor Limited,,NG\n";          // row 5 — repeats row 2

        $batch = $this->uploadCsv($csv);
        app(ThirdPartyImporter::class)->dryRun($batch, [
            'legal_name' => 'Vendor Name', 'lei' => 'LEI', 'country_of_incorporation' => 'Country',
        ]);

        $problems = collect($batch->refresh()->errors);

        // Row numbers are the spreadsheet's own — header is row 1.
        $this->assertSame(3, $problems->firstWhere('column', 'legal_name')['row']);
        $this->assertSame(4, $problems->firstWhere('column', 'lei')['row']);

        $duplicate = $problems->first(fn (array $p) => str_contains($p['message'], 'appears on row'));
        $this->assertSame(5, $duplicate['row']);
        $this->assertStringContainsString('row 2', $duplicate['message']);

        // One good row survives.
        $this->assertSame(4, $batch->rows_total);
        $this->assertSame(1, $batch->rows_valid);
    }

    #[Test]
    public function an_existing_lookalike_is_a_warning_rather_than_a_blocked_row(): void
    {
        // FR-TPR-02 refuses to block on a near-match, and a 400-row file that
        // fails because three vendors already exist is a file nobody retries.
        Storage::fake('local');

        ThirdParty::create([
            'legal_name' => 'Interswitch Limited', 'slug' => 'interswitch',
            'registration_number' => 'RC-201060', 'entity_type' => 'company',
        ]);

        $batch = $this->uploadCsv("Vendor Name,RC Number\nInterswitch Limited,RC-201060\n");
        app(ThirdPartyImporter::class)->dryRun($batch, [
            'legal_name' => 'Vendor Name', 'registration_number' => 'RC Number',
        ]);

        $batch->refresh();

        $this->assertSame(1, $batch->rows_valid, 'A duplicate must not block the row.');
        $this->assertSame('duplicate', $batch->errors[0]['severity']);
        $this->assertStringContainsString('merge them from the register', $batch->errors[0]['message']);
    }

    #[Test]
    public function a_rollback_keeps_a_vendor_that_has_since_been_engaged(): void
    {
        // The rollback undoes a bad import; it does not erase work done since.
        Storage::fake('local');

        $batch = $this->uploadCsv("Vendor Name\nKept Vendor Limited\nRemoved Vendor Limited\n");
        app(ThirdPartyImporter::class)->dryRun($batch, ['legal_name' => 'Vendor Name']);
        app(ThirdPartyImporter::class)->commit($batch, $this->user->id);

        $kept = ThirdParty::where('legal_name', 'Kept Vendor Limited')->firstOrFail();
        Engagement::create([
            'third_party_id' => $kept->id,
            'reference' => 'ENG-2026-5001',
            'name' => 'A service somebody set up afterwards',
            'engagement_type' => 'ict_service',
        ]);

        $result = app(ThirdPartyImporter::class)->rollBack($batch->refresh());

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(['Kept Vendor Limited'], $result['kept']);
        $this->assertTrue(ThirdParty::where('legal_name', 'Kept Vendor Limited')->exists());
        $this->assertFalse(ThirdParty::where('legal_name', 'Removed Vendor Limited')->exists());
    }

    /* ------------------------------------------------------------------ */
    /*  FR-TIER-09 — the ruleset editor and its sandbox                    */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_published_ruleset_cannot_be_edited(): void
    {
        // A score citing version 1.0 has to stay explainable by fetching
        // version 1.0, which is only true if 1.0 cannot change underneath it.
        $published = Ruleset::query()->published()->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be edited');

        $published->update(['factors' => []]);
    }

    #[Test]
    public function the_sandbox_shows_the_migration_and_writes_nothing(): void
    {
        $engagement = $this->tieredEngagement();

        $runsBefore = \App\Models\Tprm\ScoreRun::count();
        $versionsBefore = \App\Models\Tprm\InherentAssessment::count();
        $tierBefore = $engagement->refresh()->effective_tier;

        // A draft that puts every point of weight on data sensitivity, which
        // this engagement answers at the bottom of the scale.
        $factors = \App\Support\Tprm\DefaultRuleset::factors();
        foreach ($factors as $code => $factor) {
            $factors[$code]['weight'] = $code === 'DATA' ? 100 : 0;
        }

        $draft = new RulesetValue(
            version: 'sandbox',
            factors: $factors,
            knockouts: [],
            bandEdges: \App\Support\Tprm\DefaultRuleset::bandEdges(),
            volumeBands: \App\Support\Tprm\DefaultRuleset::dataVolumeBands(),
            rtoBands: \App\Support\Tprm\DefaultRuleset::rtoBands(),
        );

        $result = app(RulesetSandbox::class)->simulate($draft);

        $this->assertSame(1, $result['assessed']);
        $this->assertSame(1, $result['lowered']);
        $this->assertSame(0, $result['raised']);

        // Against the tier the engagement actually carries, not a guessed
        // constant — the fixture's score is an output of the model, and
        // hard-coding it here would make this test fail whenever the model
        // legitimately changes rather than when the sandbox breaks.
        $this->assertSame($tierBefore->value, $result['movements'][0]['before']);
        $this->assertNotSame($tierBefore->value, $result['movements'][0]['after']);

        // Nothing was written — not a run, not a version, not the stored tier.
        $this->assertSame($runsBefore, \App\Models\Tprm\ScoreRun::count());
        $this->assertSame($versionsBefore, \App\Models\Tprm\InherentAssessment::count());
        $this->assertSame($tierBefore, $engagement->refresh()->effective_tier);
    }

    #[Test]
    public function a_draft_cannot_be_published_while_the_weights_do_not_total_one_hundred(): void
    {
        $this->actingAs($this->user)->post(route('tprm.rulesets.draft'));

        $draft = Ruleset::query()->where('status', Ruleset::STATUS_DRAFT)->firstOrFail();

        $factors = $draft->factors;
        $factors['DATA']['weight'] = 40;   // total is now 115
        $draft->update(['factors' => $factors]);

        $this->actingAs($this->user)
            ->from(route('tprm.rulesets.show', $draft))
            ->post(route('tprm.rulesets.publish', $draft))
            ->assertSessionHas('error', fn (string $error) => str_contains($error, '115')
                && str_contains($error, 'must total 100'));

        $this->assertSame(Ruleset::STATUS_DRAFT, $draft->refresh()->status);
    }

    #[Test]
    public function publishing_retires_the_previous_ruleset_so_exactly_one_is_current(): void
    {
        $original = Ruleset::query()->published()->firstOrFail();

        $this->actingAs($this->user)->post(route('tprm.rulesets.draft'));
        $draft = Ruleset::query()->where('status', Ruleset::STATUS_DRAFT)->firstOrFail();

        $this->actingAs($this->user)->post(route('tprm.rulesets.publish', $draft))->assertRedirect();

        $this->assertSame(Ruleset::STATUS_RETIRED, $original->refresh()->status);
        $this->assertSame(Ruleset::STATUS_PUBLISHED, $draft->refresh()->status);
        $this->assertSame(1, Ruleset::query()->published()->count());

        // And the scoring path picks up the new version.
        $this->assertSame($draft->version, Ruleset::currentValue($this->organization->id)->version);
    }

    #[Test]
    public function the_ruleset_screens_need_their_own_permission(): void
    {
        $manager = User::create([
            'name' => 'Programme Manager', 'email' => 'pm@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
        $role = Role::findOrCreate('tprm-manager', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.view', 'web'));
        $role->givePermissionTo(Permission::findOrCreate('tprm.create', 'web'));
        $manager->assignRole($role);

        // Runs the programme, but redefining what Critical means is a
        // different authority.
        $this->actingAs($manager)->get(route('tprm.overrides.index'))->assertOk();
        $this->actingAs($manager)->get(route('tprm.rulesets.index'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */

    private function uploadCsv(string $contents): ImportBatch
    {
        $file = UploadedFile::fake()->createWithContent('vendors.csv', $contents);

        $this->actingAs($this->user)
            ->post(route('tprm.imports.store'), ['file' => $file])
            ->assertRedirect();

        return ImportBatch::latest('id')->firstOrFail();
    }

    private function makeEngagement(?RiskTier $tier): Engagement
    {
        $vendor = ThirdParty::create([
            'legal_name' => 'Vendor '.Str::random(6), 'slug' => Str::random(10),
            'entity_type' => 'company',
        ]);

        $engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-'.random_int(1000, 9999),
            'name' => 'Managed service',
            'engagement_type' => 'ict_service',
        ]);

        if ($tier !== null) {
            $engagement->forceFill(['inherent_tier' => $tier->value, 'effective_tier' => $tier->value])->save();
        }

        return $engagement;
    }

    private function tieredEngagement(): Engagement
    {
        $vendor = ThirdParty::create([
            'legal_name' => 'Core Banking Vendor', 'slug' => 'core-banking-vendor', 'entity_type' => 'company',
        ]);

        $engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-4242',
            'name' => 'Core banking support',
            'engagement_type' => 'ict_service',
        ]);

        // Answers that score high on everything EXCEPT data sensitivity, so a
        // draft weighting data at 100 moves the tier down.
        app(\App\Services\Tprm\Scoring\TieringService::class)->tier($engagement, [
            'A1' => 'public', 'A2' => 'under_1k', 'A3' => 'no_basis', 'A5' => 'network_api',
            'A7' => 'critical', 'A8' => 'under_4h',
            'A10' => ['cbn_cyber', 'aml_cft', 'ndpa', 'pci_dss'],
            'A12' => 'sole', 'A13' => 'over_6m', 'A14' => 'over_1b',
        ], $this->user->id);

        return $engagement->refresh();
    }
}
