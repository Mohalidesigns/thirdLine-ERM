<?php

namespace Tests\Feature\Rcsa;

use App\Models\Risk;
use App\Support\Rcsa\RcsaCutover;
use PHPUnit\Framework\Attributes\Test;

/**
 * §13 steps 6 and 7 — cutting a tenant over, and reversing it.
 *
 * THE TEST THAT MATTERS MOST IS `the_enterprise_risk_register_is_untouched_by_
 * cutover`. §13 says "legacy tables become read-only", and applying that
 * literally to `risks` and `controls` would take the Risk Register, the KRI
 * module, the control library and the board pack down with it. What actually
 * closes is one write path.
 */
class CutoverTest extends CycleTestCase
{
    private function legacyRisk(): Risk
    {
        return Risk::create([
            'organization_id' => $this->organization->id,
            'risk_code' => 'RK-CUT-'.uniqid(),
            'title' => 'A legacy risk',
            'description' => 'A legacy risk statement long enough to read as a real one.',
            'category_id' => $this->category->id,
            'status' => 'active',
            'created_by' => $this->actor->id,
            'business_unit_id' => $this->retail->id,
        ]);
    }

    /**
     * A worksheet exactly as the legacy screen posts it — the field names are
     * `SubmitRcsaWorksheetRequest`'s, not a guess.
     *
     * @return array<string, mixed>
     */
    private function worksheetPayload(Risk $risk): array
    {
        return [
            'business_unit_id' => $this->retail->id,
            'assessment_date' => now()->toDateString(),
            'action' => 'submit',
            'risks' => [[
                'risk_id' => $risk->id,
                'description' => 'Accounts are opened without complete KYC documentation.',
                'inherent_likelihood' => 4,
                'inherent_impact' => 3,
                'residual_likelihood' => 2,
                'residual_impact' => 2,
                'control_effectiveness' => 'partially_effective',
            ]],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Before cutover */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_tenant_has_not_cut_over_by_default(): void
    {
        $cutover = app(RcsaCutover::class);

        $this->assertFalse($cutover->hasCutOver($this->organization));
        $this->assertNull($cutover->cutOverAt($this->organization));
    }

    #[Test]
    public function the_legacy_worksheet_accepts_submissions_before_cutover(): void
    {
        $this->grant(['rcsa.view', 'rcsa.submit']);
        $risk = $this->legacyRisk();

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $this->worksheetPayload($risk))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $this->assertSame(1, \App\Models\CampaignResponse::count());
    }

    /* ------------------------------------------------------------------ */
    /*  After cutover */
    /* ------------------------------------------------------------------ */

    /**
     * The one write path, closed. This is what "legacy becomes read-only"
     * actually means for a module with no tables of its own.
     */
    #[Test]
    public function the_legacy_worksheet_refuses_submissions_after_cutover(): void
    {
        $this->grant(['rcsa.view', 'rcsa.submit']);
        $risk = $this->legacyRisk();

        app(RcsaCutover::class)->cutOver($this->organization);

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $this->worksheetPayload($risk))
            ->assertSessionHas('error');

        // Nothing was filed, and the message says where the work goes now
        // rather than discarding it with a redirect.
        $this->assertSame(0, \App\Models\CampaignResponse::count());
        $this->assertStringContainsString('My Assessments', session('error'));
    }

    /**
     * §13 keeps the old module for at least one audit cycle, because the
     * regulator may ask what it showed.
     */
    #[Test]
    public function the_legacy_read_screens_stay_reachable_after_cutover(): void
    {
        $this->grant(['rcsa.view']);
        $this->legacyRisk();

        app(RcsaCutover::class)->cutOver($this->organization);

        foreach (['risk.rcsa.dashboard', 'risk.rcsa.worksheet', 'risk.rcsa.matrix', 'risk.rcsa.controls'] as $route) {
            $this->actingAs($this->actor)->get(route($route))->assertOk();
        }
    }

    /**
     * The test that stops §13 being applied literally.
     */
    #[Test]
    public function the_enterprise_risk_register_is_untouched_by_cutover(): void
    {
        $risk = $this->legacyRisk();

        app(RcsaCutover::class)->cutOver($this->organization);

        // Still readable, still writable — `risks` and `controls` are the
        // enterprise register, not legacy RCSA tables, and locking them would
        // take half the product down.
        $risk->forceFill(['title' => 'Renamed after cutover'])->save();

        $this->assertSame('Renamed after cutover', $risk->fresh()->title);

        $second = $this->legacyRisk();
        $this->assertNotNull($second->fresh());
    }

    /* ------------------------------------------------------------------ */
    /*  The command */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_command_refuses_a_tenant_whose_reconciliation_has_blockers(): void
    {
        // A legacy risk that has not been migrated: its unit is in the register
        // and absent from the universe, which is a blocker.
        $this->legacyRisk();

        $this->artisan('rcsa:cutover', [
            '--organization' => $this->organization->id,
            '--commit' => true,
        ])->assertFailed();

        $this->assertFalse(app(RcsaCutover::class)->hasCutOver($this->organization->fresh()));
    }

    #[Test]
    public function force_overrides_the_blockers_and_says_so(): void
    {
        $this->legacyRisk();

        $this->artisan('rcsa:cutover', [
            '--organization' => $this->organization->id,
            '--commit' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('FORCED past a reconciliation with blockers')
            ->assertSuccessful();

        $this->assertTrue(app(RcsaCutover::class)->hasCutOver($this->organization->fresh()));
    }

    #[Test]
    public function a_clean_tenant_cuts_over_and_the_dry_run_records_nothing(): void
    {
        $this->legacyRisk();
        app(\App\Services\Rcsa\RcsaLegacyMigrator::class)->migrate($this->organization->id, commit: true);

        $this->artisan('rcsa:cutover', ['--organization' => $this->organization->id])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertFalse(app(RcsaCutover::class)->hasCutOver($this->organization->fresh()));

        $this->artisan('rcsa:cutover', ['--organization' => $this->organization->id, '--commit' => true])
            ->assertSuccessful();

        $this->assertTrue(app(RcsaCutover::class)->hasCutOver($this->organization->fresh()));
    }

    #[Test]
    public function reversing_reopens_the_legacy_write_path(): void
    {
        $this->grant(['rcsa.view', 'rcsa.submit']);
        $risk = $this->legacyRisk();

        app(RcsaCutover::class)->cutOver($this->organization);

        $this->artisan('rcsa:cutover', [
            '--organization' => $this->organization->id,
            '--reverse' => true,
            '--commit' => true,
        ])->assertSuccessful();

        $this->assertFalse(app(RcsaCutover::class)->hasCutOver($this->organization->fresh()));

        $this->actingAs($this->actor)
            ->post(route('risk.rcsa.worksheet.store'), $this->worksheetPayload($risk))
            ->assertSessionMissing('error');
    }

    /**
     * Cutover is per tenant; the feature flag is per environment. They are
     * different decisions and a shared install cuts tenants over one at a time.
     */
    #[Test]
    public function cutover_is_recorded_per_tenant_and_leaves_the_neighbouring_settings_alone(): void
    {
        $this->organization->forceFill([
            'settings' => ['rcsa' => ['bu_approval_required' => true], 'board_pack' => ['sections' => ['a']]],
        ])->save();

        app(RcsaCutover::class)->cutOver($this->organization);

        $settings = $this->organization->fresh()->settings;

        $this->assertNotNull($settings['rcsa']['cutover_at']);
        // Merged, not replaced — turning cutover on must not silently clear the
        // BU-approval step sitting beside it.
        $this->assertTrue($settings['rcsa']['bu_approval_required']);
        $this->assertSame(['a'], $settings['board_pack']['sections']);

        // And the other tenant is unaffected.
        $this->assertFalse(app(RcsaCutover::class)->hasCutOver($this->otherOrg));
    }
}
