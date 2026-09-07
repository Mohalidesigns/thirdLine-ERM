<?php

namespace Tests\Feature\Rcsa;

use App\Models\AssessmentCampaign;
use App\Models\CampaignAssignment;
use App\Models\CampaignResponse;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaRegisterControl;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\Risk;
use App\Services\Rcsa\RcsaLegacyInventory;
use App\Services\Rcsa\RcsaLegacyMigrator;
use App\Services\Rcsa\RcsaMigrationReconciler;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * §13 — replacing the existing module.
 *
 * THE FIRST TEST IS THE ONE THAT MATTERS MOST, because it pins the finding that
 * reshaped the whole phase: the legacy RCSA module has no tables of its own, so
 * "migrate the legacy schema" means DERIVING master data from the enterprise
 * risk register and reconstructing history from campaign responses. Everything
 * else here follows from that.
 */
class LegacyMigrationTest extends CycleTestCase
{
    private int $legacySequence = 0;

    /**
     * A row in the ENTERPRISE risk register — the legacy module's master data.
     *
     * Built here rather than through `makeRisk()`, which `UniverseTestCase`
     * overrides to build a v2 `RcsaRegisterRisk`. The whole point of this suite
     * is the boundary between those two, so it says which side it means.
     */
    private function legacyRisk(array $overrides = []): Risk
    {
        $n = ++$this->legacySequence;

        return Risk::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_code' => 'RK-LEG-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'title' => 'Legacy risk '.$n,
            'description' => 'Legacy risk statement '.$n.', long enough to read as a real one.',
            'category_id' => $this->category->id,
            'status' => 'active',
            'created_by' => $this->actor->id,
            'business_unit_id' => $this->retail->id,
            'process_id' => $this->onboarding->id,
            'inherent_likelihood' => 4,
            'inherent_impact' => 3,
            'residual_rating' => 'medium',
            'risk_source' => 'Manual process with no maker-checker.',
        ], $overrides));
    }

    private function legacyCampaign(array $overrides = []): AssessmentCampaign
    {
        return AssessmentCampaign::create(array_merge([
            'organization_id' => $this->organization->id,
            'campaign_code' => 'RCSA-LEG-'.uniqid(),
            'title' => 'RCSA 2025 H2',
            'campaign_type' => RcsaLegacyInventory::LEGACY_CAMPAIGN_TYPE,
            'status' => 'completed',
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-31',
            'created_by' => $this->actor->id,
        ], $overrides));
    }

    private function legacyResponse(AssessmentCampaign $campaign, ?Risk $risk, array $overrides = []): CampaignResponse
    {
        $assignment = CampaignAssignment::create([
            'campaign_id' => $campaign->id,
            'business_unit_id' => $this->retail->id,
            'respondent_id' => $this->actor->id,
            'status' => 'completed',
            'due_date' => '2025-12-31',
        ]);

        return CampaignResponse::create(array_merge([
            'assignment_id' => $assignment->id,
            'risk_id' => $risk?->id,
            'likelihood_score' => 2,
            'impact_score' => 2,
            'overall_score' => 4,
            'rating' => 'low',
            'control_effectiveness' => 'Mostly Achieved',
            'comments' => 'The reconciliation runs daily and is signed off by the branch head.',
            'questionnaire_data' => ['inherent_likelihood' => 5, 'inherent_impact' => 4],
        ], $overrides));
    }

    private function migrate(bool $commit = true): array
    {
        return app(RcsaLegacyMigrator::class)->migrate($this->organization->id, $commit);
    }

    /* ------------------------------------------------------------------ */
    /*  Step 1 — inventory */
    /* ------------------------------------------------------------------ */

    /**
     * The finding that reshaped the phase.
     */
    #[Test]
    public function the_inventory_reports_that_the_legacy_module_has_no_tables_of_its_own(): void
    {
        $this->legacyRisk();

        $report = app(RcsaLegacyInventory::class)->report($this->organization->id);

        $this->assertStringContainsString('no tables of its own', $report['finding']);

        // Every master-data source is SHARED, which is why §13's "make legacy
        // tables read-only" cannot be applied to them.
        foreach (['risks', 'controls', 'risk_control_mapping'] as $table) {
            $this->assertTrue($report['sources'][$table]['shared'], "{$table} should be marked shared.");
        }

        // The one table that is genuinely the legacy module's own.
        $this->assertFalse($report['sources']['campaign_responses']['shared']);

        // And the dependency list names the write path, which is what actually
        // has to close at cutover.
        $writePath = collect($report['dependencies'])->firstWhere('what', 'risk.rcsa.worksheet.store (RcsaWorksheetService)');
        $this->assertNotNull($writePath);
        $this->assertStringContainsString('must refuse after cutover', $writePath['at_cutover']);
    }

    /**
     * The inventory must count what the migration will read, not what a column
     * that is not reliably populated says.
     */
    #[Test]
    public function the_inventory_counts_control_mappings_through_the_risk(): void
    {
        $risk = $this->legacyRisk();
        $control = $this->makeControl();
        $this->attachControl($risk, $control);

        // Reproduce the estate's real condition: the pivot's own
        // organization_id is null on every row written without the pivot model.
        DB::table('risk_control_mapping')->update(['organization_id' => null]);

        $report = app(RcsaLegacyInventory::class)->report($this->organization->id);

        $this->assertSame(1, $report['sources']['risk_control_mapping']['rows']);
        $this->assertSame(1, $report['readiness']['control_mappings_missing_organization_id']);
    }

    #[Test]
    public function the_inventory_counts_what_cannot_be_migrated_before_anybody_tries(): void
    {
        $this->legacyRisk();
        $this->legacyRisk(['business_unit_id' => null]);

        $report = app(RcsaLegacyInventory::class)->report($this->organization->id);

        $this->assertSame(1, $report['readiness']['risks_without_business_unit']);
        $this->assertSame(2, $report['sources']['risks']['rows']);
        $this->assertSame(1, $report['sources']['risks']['migratable']);
    }

    /* ------------------------------------------------------------------ */
    /*  Step 3 — map and backfill */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_risk_register_becomes_the_universe_with_its_controls(): void
    {
        $risk = $this->legacyRisk(['title' => 'KYC gap', 'description' => 'Accounts opened without complete KYC.']);
        $control = $this->makeControl(['name' => 'Daily KYC exception report', 'control_type' => 'detective']);
        $this->attachControl($risk, $control, isKey: true);

        $result = $this->migrate();

        $this->assertSame(1, $result['master_data']['risks_created']);
        $this->assertSame(1, $result['master_data']['controls_created']);

        $row = RcsaRegisterRisk::sole();

        $this->assertSame($risk->id, (int) $row->legacy_risk_id);
        $this->assertSame('Accounts opened without complete KYC.', $row->potential_risk);
        $this->assertSame($this->retail->id, $row->business_unit_id);
        $this->assertSame($this->onboarding->id, $row->process_id);
        // The register's ratings become DEFAULTS, never an assessment.
        $this->assertSame(4, $row->default_likelihood);
        $this->assertSame(3, $row->default_impact);
        // Published on arrival: these are risks the bank has been assessing for
        // years, and landing them as drafts would mean re-approving hundreds of
        // rows as a formality.
        $this->assertSame(RcsaRegisterRisk::PUBLISHED, $row->status);
        $this->assertStringStartsWith($this->retail->code, (string) $row->risk_no);

        $migratedControl = RcsaRegisterControl::sole();

        $this->assertSame($control->id, (int) $migratedControl->legacy_control_id);
        $this->assertSame($control->id, (int) $migratedControl->control_library_id);
        $this->assertSame('detective', $migratedControl->control_type);
        $this->assertTrue($migratedControl->is_key);

        // NOTHING WAS REMOVED FROM THE ENTERPRISE REGISTER. It is shared, and
        // half the product reads it.
        $this->assertSame(1, Risk::count());
    }

    #[Test]
    public function a_legacy_campaign_becomes_one_closed_cycle_of_history(): void
    {
        $risk = $this->legacyRisk();
        $campaign = $this->legacyCampaign();
        $this->legacyResponse($campaign, $risk);

        $result = $this->migrate();

        $this->assertSame(1, $result['history']['cycles_created']);
        $this->assertSame(1, $result['history']['lines_created']);

        $cycle = RcsaCycle::sole();

        $this->assertSame($campaign->id, (int) $cycle->legacy_campaign_id);
        $this->assertStringStartsWith(RcsaLegacyMigrator::CYCLE_PREFIX, $cycle->name);
        // CLOSED ON ARRIVAL — history, not work. A closed cycle makes
        // acceptsEdits() false for everything under it.
        $this->assertSame(RcsaCycle::CLOSED, $cycle->status);
        $this->assertSame('2025-07-01', $cycle->period_start->toDateString());

        $assessment = RcsaAssessment::sole();

        $this->assertSame(RcsaAssessment::CLOSED, $assessment->status);
        $this->assertFalse($assessment->acceptsEdits());
        $this->assertSame($this->actor->id, $assessment->submitted_by);

        $line = RcsaAssessmentLine::sole();

        $this->assertNotNull($line->legacy_response_id);
        $this->assertNotNull($line->locked_at);
        $this->assertSame($risk->risk_code, $line->risk_no);
    }

    /**
     * The figures are recomputed by the v2 engine, not copied.
     */
    #[Test]
    public function the_inputs_migrate_and_the_engine_derives_the_rest(): void
    {
        $risk = $this->legacyRisk();
        $campaign = $this->legacyCampaign();

        // The legacy row asserts a residual of 4 / "low". The inputs it carries
        // are 5 × 4 inherent with a Mostly Achieved control.
        $this->legacyResponse($campaign, $risk, ['overall_score' => 4, 'rating' => 'low']);

        $this->migrate();

        $line = RcsaAssessmentLine::sole();

        $this->assertSame(5, $line->inherent_likelihood);
        $this->assertSame(4, $line->inherent_impact);
        $this->assertSame('Mostly Achieved', $line->control_effectiveness);

        // 5 × 4 = 20, and the residual follows from the methodology rather than
        // from the legacy module's own arithmetic — which is why the
        // reconciliation compares distributions and says they will differ.
        $this->assertSame(20, $line->inherent_score);
        $this->assertNotNull($line->residual_score);
        $this->assertNotSame(4.0, (float) $line->residual_score);
        $this->assertNotNull($line->risk_treatment);
    }

    /* ------------------------------------------------------------------ */
    /*  Nothing is silently dropped */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_risk_with_no_business_unit_becomes_an_exception_not_a_loss(): void
    {
        $this->legacyRisk();
        $orphan = $this->legacyRisk(['business_unit_id' => null, 'risk_code' => 'RK-ORPHAN']);

        $result = $this->migrate();

        $this->assertSame(1, $result['master_data']['risks_created']);
        $this->assertSame(1, $result['exception_count']);

        $exception = $result['exceptions'][0];

        $this->assertSame('risk', $exception['type']);
        $this->assertSame($orphan->id, $exception['id']);
        $this->assertSame('RK-ORPHAN', $exception['reference']);
        $this->assertStringContainsString('no business unit', $exception['reason']);
    }

    #[Test]
    public function a_response_whose_risk_is_gone_becomes_an_exception(): void
    {
        $campaign = $this->legacyCampaign();
        $this->legacyResponse($campaign, null);

        $result = $this->migrate();

        $this->assertSame(0, $result['history']['lines_created']);
        $this->assertSame(1, $result['exception_count']);
        $this->assertSame('campaign_response', $result['exceptions'][0]['type']);
    }

    /* ------------------------------------------------------------------ */
    /*  Re-runnable */
    /* ------------------------------------------------------------------ */

    /**
     * A one-off command that cannot be run twice is one nobody dares run once.
     */
    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        $risk = $this->legacyRisk();
        $control = $this->makeControl();
        $this->attachControl($risk, $control);
        $campaign = $this->legacyCampaign();
        $this->legacyResponse($campaign, $risk);

        $first = $this->migrate();

        $this->assertSame(1, $first['master_data']['risks_created']);
        $this->assertSame(1, $first['history']['cycles_created']);

        $second = $this->migrate();

        $this->assertSame(0, $second['master_data']['risks_created']);
        $this->assertSame(1, $second['master_data']['risks_skipped']);
        $this->assertSame(0, $second['master_data']['controls_created']);
        $this->assertSame(0, $second['history']['cycles_created']);

        $this->assertSame(1, RcsaRegisterRisk::count());
        $this->assertSame(1, RcsaRegisterControl::count());
        $this->assertSame(1, RcsaCycle::count());
        $this->assertSame(1, RcsaAssessmentLine::count());
    }

    /**
     * A dry run is a real run that is rolled back — so its numbers are the
     * numbers a commit would produce.
     */
    #[Test]
    public function a_dry_run_reports_real_numbers_and_writes_nothing(): void
    {
        $this->legacyRisk();
        $this->legacyRisk();

        $result = $this->migrate(commit: false);

        $this->assertSame(2, $result['master_data']['risks_created']);
        $this->assertFalse($result['committed']);

        $this->assertSame(0, RcsaRegisterRisk::count());
    }

    /**
     * A universe row typed in before the migration is not overwritten, and the
     * register row that duplicates it is reported rather than silently added.
     */
    #[Test]
    public function a_risk_already_in_the_universe_is_deduplicated_and_reported(): void
    {
        $statement = 'Cash is handled by agents with no evidence of supervision.';

        $this->legacyRisk(['description' => $statement]);

        // The same risk, typed into the Universe screen before the migration.
        RcsaRegisterRisk::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->retail->id,
            'process_id' => $this->onboarding->id,
            'risk_no' => 'RETAIL-R1',
            'potential_risk' => $statement,
            'risk_category' => 'Operational',
            'status' => RcsaRegisterRisk::PUBLISHED,
        ]);

        $result = $this->migrate();

        $this->assertSame(0, $result['master_data']['risks_created']);
        $this->assertSame(1, $result['master_data']['risks_skipped']);
        $this->assertSame(1, $result['exception_count']);
        $this->assertSame('info', $result['exceptions'][0]['severity']);

        // The hand-typed row survives untouched and is still born-in-v2.
        $this->assertSame(1, RcsaRegisterRisk::count());
        $this->assertNull(RcsaRegisterRisk::sole()->legacy_risk_id);
    }

    /* ------------------------------------------------------------------ */
    /*  Step 4 — reconciliation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_reconciliation_refuses_to_sign_a_unit_that_was_skipped_entirely(): void
    {
        $this->legacyRisk();
        $this->legacyRisk(['business_unit_id' => $this->treasury->id]);

        $before = app(RcsaMigrationReconciler::class)->report($this->organization->id);

        $this->assertFalse($before['verdict']['signable']);
        $this->assertNotEmpty($before['verdict']['blockers']);

        $this->migrate();

        $after = app(RcsaMigrationReconciler::class)->report($this->organization->id);

        $this->assertTrue($after['verdict']['signable']);
        $this->assertSame([], $after['verdict']['blockers']);

        $units = collect($after['per_business_unit']);

        $this->assertSame(0, $units->firstWhere('business_unit_id', $this->retail->id)['difference']);
        $this->assertSame(0, $units->firstWhere('business_unit_id', $this->treasury->id)['difference']);
        $this->assertFalse($units->contains('missing_entirely', true));
    }

    /**
     * A row nobody can ever migrate must not make the verdict permanently red —
     * a verdict that is always red is one every operator learns to override.
     */
    #[Test]
    public function an_unmappable_row_needs_triage_rather_than_blocking_sign_off(): void
    {
        $this->legacyRisk();
        $campaign = $this->legacyCampaign();
        $this->legacyResponse($campaign, null);

        $this->migrate();

        $report = app(RcsaMigrationReconciler::class)->report($this->organization->id);

        $this->assertTrue($report['verdict']['signable']);
        $this->assertSame([], $report['verdict']['blockers']);
        $this->assertNotEmpty($report['verdict']['requires_triage']);
        $this->assertStringContainsString('exceptions report', $report['verdict']['requires_triage'][0]);
    }

    #[Test]
    public function the_reconciliation_says_the_residual_distribution_is_expected_to_differ(): void
    {
        $risk = $this->legacyRisk(['residual_rating' => 'medium']);
        $campaign = $this->legacyCampaign();
        $this->legacyResponse($campaign, $risk);

        $this->migrate();

        $report = app(RcsaMigrationReconciler::class)->report($this->organization->id);

        $this->assertStringContainsString('expected to differ', $report['residual_distribution']['note']);
        $this->assertNotEmpty($report['residual_distribution']['rows']);
    }

    /* ------------------------------------------------------------------ */
    /*  Step 7 — rollback */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_rollback_removes_only_what_the_migration_created(): void
    {
        $risk = $this->legacyRisk();
        $control = $this->makeControl();
        $this->attachControl($risk, $control);
        $campaign = $this->legacyCampaign();
        $this->legacyResponse($campaign, $risk);

        // Something born in v2, which must survive.
        $native = RcsaRegisterRisk::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->treasury->id,
            'risk_no' => 'TREAS-R1',
            'potential_risk' => 'A risk somebody typed into the Universe screen.',
            'risk_category' => 'Operational',
            'status' => RcsaRegisterRisk::PUBLISHED,
        ]);

        $this->migrate();

        $this->assertSame(2, RcsaRegisterRisk::count());

        $this->artisan('rcsa:rollback-legacy-migration', [
            '--organization' => $this->organization->id,
            '--commit' => true,
        ])->assertSuccessful();

        $this->assertSame(1, RcsaRegisterRisk::count());
        $this->assertSame($native->id, RcsaRegisterRisk::sole()->id);
        $this->assertSame(0, RcsaRegisterControl::count());
        $this->assertSame(0, RcsaCycle::count());
        $this->assertSame(0, RcsaAssessmentLine::count());

        // The legacy side is untouched in both directions.
        $this->assertSame(1, Risk::count());
        $this->assertSame(1, CampaignResponse::count());
    }

    /**
     * The difference between a rollback and a truncation.
     */
    #[Test]
    public function the_rollback_keeps_a_migrated_risk_that_a_real_cycle_has_assessed(): void
    {
        $this->legacyRisk();

        $this->migrate();

        $migrated = RcsaRegisterRisk::whereNotNull('legacy_risk_id')->sole();

        // A real cycle opens over the migrated universe.
        $cycle = $this->makeCycle();
        app(\App\Services\Rcsa\RcsaCycleService::class)->open($cycle, $this->actor);

        $this->assertGreaterThan(0, RcsaAssessmentLine::where('register_risk_id', $migrated->id)->count());

        $this->artisan('rcsa:rollback-legacy-migration', [
            '--organization' => $this->organization->id,
            '--commit' => true,
        ])->assertSuccessful();

        // Kept: deleting it would take a live assessment's provenance with it.
        $this->assertNotNull($migrated->fresh());
    }

    #[Test]
    public function the_rollback_dry_run_deletes_nothing(): void
    {
        $this->legacyRisk();
        $this->migrate();

        $this->artisan('rcsa:rollback-legacy-migration', ['--organization' => $this->organization->id])
            ->assertSuccessful();

        $this->assertSame(1, RcsaRegisterRisk::count());
    }
}
