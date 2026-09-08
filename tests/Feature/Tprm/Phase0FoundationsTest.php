<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\FindingStatus;
use App\Enums\Tprm\RiskBand;
use App\Enums\Tprm\RiskTier;
use App\Models\AuditTrailIsAppendOnly;
use App\Models\Organization;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Category;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ScoreRun;
use App\Models\Tprm\ThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 0 acceptance criteria, as tests rather than as a tinker session.
 *
 * The phase prompt asks that `php artisan tinker` be able to create a third
 * party, an engagement and a business function with all relationships
 * resolving. A tinker session proves that once, on one machine, for whoever
 * ran it. These assertions prove it on every build.
 */
class Phase0FoundationsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank',
            'short_name' => 'KHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The object graph resolves */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_third_party_an_engagement_and_a_business_function_relate(): void
    {
        $category = Category::create([
            'code' => 'ICT-CORE',
            'name' => 'Core banking and ICT',
            'is_ict' => true,
        ]);

        $vendor = ThirdParty::create([
            'legal_name' => 'Interlink Systems Limited',
            'slug' => 'interlink-systems',
            'registration_number' => 'RC-441290',
            'entity_type' => 'company',
            'country_of_incorporation' => 'NG',
            'category_id' => $category->id,
        ]);

        $function = BusinessFunction::create([
            'function_code' => 'BF-CORE-01',
            'name' => 'Core banking transaction processing',
            'criticality' => 'critical',
            'rto_hours' => 2,
        ]);

        $engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-0001',
            'name' => 'Core banking application support',
            'engagement_type' => 'ict_service',
            'service_type_id' => $category->id,
            'processes_personal_data' => true,
            'cross_border' => false,
            'currency' => 'NGN',
            'annual_spend_minor' => 480_000_000_00,
        ]);

        $engagement->businessFunctions()->attach($function->id, [
            'organization_id' => $this->organization->id,
            'dependency_level' => 'primary',
            'reliance_level' => 'full',
        ]);

        // Every relationship the phase's acceptance criterion names, resolved
        // from a freshly loaded model rather than the in-memory one.
        $loaded = Engagement::with(['thirdParty.category', 'businessFunctions', 'serviceType'])
            ->findOrFail($engagement->id);

        $this->assertSame('Interlink Systems Limited', $loaded->thirdParty->legal_name);
        $this->assertSame('Core banking and ICT', $loaded->thirdParty->category->name);
        $this->assertSame('Core banking and ICT', $loaded->serviceType->name);
        $this->assertCount(1, $loaded->businessFunctions);
        $this->assertSame('BF-CORE-01', $loaded->businessFunctions->first()->function_code);
        $this->assertSame('full', $loaded->businessFunctions->first()->pivot->reliance_level);

        // And back the other way.
        $this->assertTrue($vendor->fresh()->engagements->contains($engagement->id));
        $this->assertTrue($function->fresh()->engagements->contains($engagement->id));

        // The uuid pattern the product uses in place of UUID primary keys.
        $this->assertNotNull($loaded->uuid);
        $this->assertSame($loaded->uuid, $loaded->getRouteKey());
    }

    #[Test]
    public function enum_columns_cast_both_ways(): void
    {
        $engagement = $this->makeEngagement();

        $this->assertInstanceOf(EngagementStatus::class, $engagement->status);
        $this->assertSame(EngagementStatus::Draft, $engagement->status);

        $engagement->forceFill([
            'inherent_tier' => RiskTier::Critical,
            'residual_band' => RiskBand::Moderate,
        ])->save();

        $reloaded = $engagement->fresh();

        $this->assertSame(RiskTier::Critical, $reloaded->inherent_tier);
        $this->assertSame(RiskBand::Moderate, $reloaded->residual_band);
    }

    /* ------------------------------------------------------------------ */
    /*  Tenancy */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_tprm_table_is_tenant_scoped_or_deliberately_global(): void
    {
        // The four kinds of table without an organization_id, each with a
        // reason. Anything else added later without one fails here, which is
        // the point: an un-scoped TPRM table is a cross-tenant read waiting to
        // be written.
        $global = [
            // Shipped reference libraries. `tp_frameworks`,
            // `tp_questionnaire_templates`, `tp_document_types` and
            // `tp_clause_library` DO carry a nullable organization_id — null
            // means "system library, readable by all, editable by none" — and
            // are therefore covered below, not here.
            'tp_framework_controls' => 'Belongs to its framework; scoped through it.',
            'tp_questionnaire_sections' => 'Belongs to its template; scoped through it.',
            'tp_questions' => 'Belongs to its section; scoped through it.',
            'tp_question_control_maps' => 'Belongs to its question; scoped through it.',

            // Phase 8. The vendor owns these, not one of our tenants. Access
            // is granted per client through tp_trust_profile_shares, which IS
            // scoped — and every internal read goes through
            // TrustProfileService::documentFor(), the single door that checks
            // a live share before returning anything.
            //
            // `tp_vendor_identities` is the row that means "this company, in
            // the world". It has to be global for the same reason the profile
            // does: Lagos Union Bank's supplier record for Cloudspan and Abuja
            // Trust Bank's are two tenant-scoped rows, and the whole point of
            // the reusable profile is that one company completes it once.
            'tp_trust_profiles' => 'Vendor-owned; read only through a share row.',
            'tp_vendor_identities' => 'The company itself, spanning tenants; read only through a share row.',

            // Phase 6. A sanctions list is the same list for every institution
            // in the country. Scoping it per tenant would hold the UN
            // consolidated list once per customer and let one tenant's stale
            // refresh give a different answer from another's — which is the
            // one place in this module where two tenants MUST see the same
            // thing.
            'tp_sanctions_lists' => 'The same published list for every tenant; scoping it would let two '
                .'tenants screen against different data.',
            'tp_sanctions_entries' => 'Belongs to its list; global for the same reason.',
        ];

        $missing = [];

        foreach ($this->tprmTables() as $table) {
            if (array_key_exists($table, $global)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'organization_id')) {
                $missing[] = $table;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These TPRM tables carry no organization_id and are not on the documented global list: '
            .implode(', ', $missing)
        );
    }

    #[Test]
    public function another_tenants_rows_are_invisible_rather_than_readable(): void
    {
        $other = Organization::create([
            'name' => 'Abbey Mortgage Bank',
            'short_name' => 'ABBEY',
            'institution_type' => 'mortgage_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $mine = $this->makeThirdParty('mine');

        TenantContext::set($other->id);
        $theirs = $this->makeThirdParty('theirs');

        TenantContext::set($this->organization->id);

        $this->assertTrue(ThirdParty::query()->where('id', $mine->id)->exists());

        // Empty, not another tenant's row — and `find` returns null rather
        // than throwing, so a route binding cannot leak by exception message.
        $this->assertFalse(ThirdParty::query()->where('id', $theirs->id)->exists());
        $this->assertNull(ThirdParty::find($theirs->id));
        $this->assertSame(1, ThirdParty::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /*  The audit trail */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function creating_and_updating_an_audited_model_writes_a_diff(): void
    {
        $vendor = $this->makeThirdParty('audited');

        $created = AuditLog::query()
            ->where('auditable_type', ThirdParty::class)
            ->where('auditable_id', $vendor->id)
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertSame('audited', $created->after['slug']);

        $vendor->update(['trading_name' => 'Audited Trading Name']);

        $updated = AuditLog::query()
            ->where('auditable_id', $vendor->id)
            ->where('event', 'updated')
            ->firstOrFail();

        // Only what changed, with both sides of the change.
        $this->assertSame(['trading_name'], array_keys($updated->after));
        $this->assertNull($updated->before['trading_name']);
        $this->assertSame('Audited Trading Name', $updated->after['trading_name']);
    }

    #[Test]
    public function re_saving_an_unchanged_value_writes_no_audit_row(): void
    {
        // The trait records `getChanges()`, not `getAttributes()`, so a screen
        // that re-posts forty untouched fields writes nothing rather than
        // forty columns of noise a reader has to diff by eye.
        //
        // Note the setup: the value is written FIRST, then written again. An
        // attribute that was never set at all going to null is a real change
        // (absent, then null) and is recorded as one.
        $vendor = $this->makeThirdParty('quiet');
        $vendor->update(['trading_name' => 'Quiet Ltd']);

        $before = AuditLog::query()->where('auditable_id', $vendor->id)->count();

        $vendor->trading_name = 'Quiet Ltd';
        $vendor->save();

        $this->assertSame($before, AuditLog::query()->where('auditable_id', $vendor->id)->count());
    }

    #[Test]
    public function the_audit_log_refuses_updates_and_deletes(): void
    {
        $vendor = $this->makeThirdParty('immutable');
        $row = AuditLog::query()->where('auditable_id', $vendor->id)->firstOrFail();

        try {
            $row->update(['event' => 'something_else']);
            $this->fail('The audit log accepted an update.');
        } catch (AuditTrailIsAppendOnly $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->expectException(AuditTrailIsAppendOnly::class);
        $row->delete();
    }

    #[Test]
    public function the_audit_chain_detects_a_row_edited_around_the_application(): void
    {
        $vendor = $this->makeThirdParty('chained');
        $vendor->update(['trading_name' => 'First']);
        $vendor->update(['trading_name' => 'Second']);

        $rows = AuditLog::query()->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(3, $rows->count());
        foreach ($rows as $row) {
            $this->assertTrue($row->isIntact(), "Audit row {$row->id} does not match its sealed digest.");
        }

        // Now do what the append-only guard cannot stop — change the row
        // through the query builder, bypassing the model entirely — and prove
        // the chain notices. This is the difference between "our code does not
        // edit it" and "an edit is detectable".
        $target = $rows->last();
        \DB::table('tp_audit_logs')->where('id', $target->id)->update(['event' => 'tampered']);

        $this->assertFalse(AuditLog::findOrFail($target->id)->isIntact());
    }

    /* ------------------------------------------------------------------ */
    /*  Score runs are immutable */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_score_run_cannot_be_edited_or_removed(): void
    {
        $engagement = $this->makeEngagement();

        $run = ScoreRun::create([
            'engagement_id' => $engagement->id,
            'run_type' => 'residual',
            'ruleset_version' => '2026.1',
            'inputs' => ['ir' => 88],
            'ir' => 88,
            'ac' => 0.82,
            'ec' => 0.58,
            'm' => 0.285,
            'fu' => 7,
            'su' => 6,
            'rr' => 75.9,
            'band' => RiskBand::Critical,
            'explanation' => ['factors' => []],
        ]);

        // The engine version is stamped from config rather than passed in, so
        // that no caller can record a score against a version that did not
        // produce it.
        $this->assertSame(config('tprm.engine_version'), $run->engine_version);

        try {
            $run->update(['rr' => 10]);
            $this->fail('A score run accepted an update.');
        } catch (AuditTrailIsAppendOnly $exception) {
            $this->assertStringContainsString('new run', $exception->getMessage());
        }

        $this->expectException(AuditTrailIsAppendOnly::class);
        $run->delete();
    }

    /* ------------------------------------------------------------------ */
    /*  The scoring vocabulary matches the specification */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_evidence_confidence_coefficients_are_the_specified_ones(): void
    {
        // TRD §7.4. These four numbers are the module's commercial argument —
        // two vendors with identical answers scoring 23.8 points apart — so
        // they are pinned rather than left to a config edit nobody reviews.
        $this->assertSame(0.35, AssuranceLevel::SelfAttested->confidence());
        $this->assertSame(0.60, AssuranceLevel::Documented->confidence());
        $this->assertSame(0.85, AssuranceLevel::IndependentlyAssured->confidence());
        $this->assertSame(1.00, AssuranceLevel::Validated->confidence());
    }

    #[Test]
    public function a_bridge_letter_caps_assurance_at_documented(): void
    {
        // AC-05. A control evidenced only by a bridge letter for the gap
        // period scores 0.60, not 0.85: a bridge letter is the vendor's
        // assertion that nothing changed, not an auditor's opinion that
        // nothing did.
        $capped = AssuranceLevel::IndependentlyAssured->cappedAt(AssuranceLevel::Documented);

        $this->assertSame(AssuranceLevel::Documented, $capped);
        $this->assertSame(0.60, $capped->confidence());

        // Capping never RAISES a level.
        $this->assertSame(
            AssuranceLevel::SelfAttested,
            AssuranceLevel::SelfAttested->cappedAt(AssuranceLevel::Validated)
        );
    }

    #[Test]
    public function residual_bands_split_at_the_specified_edges(): void
    {
        // TRD §7.5: Low 0-24, Moderate 25-49, High 50-74, Critical 75-100.
        // Both sides of all three edges, because an off-by-one here moves
        // every vendor sitting on a boundary into the wrong board report.
        $this->assertSame(RiskBand::Low, RiskBand::fromScore(24));
        $this->assertSame(RiskBand::Moderate, RiskBand::fromScore(25));
        $this->assertSame(RiskBand::Moderate, RiskBand::fromScore(49));
        $this->assertSame(RiskBand::High, RiskBand::fromScore(50));
        $this->assertSame(RiskBand::High, RiskBand::fromScore(74));
        $this->assertSame(RiskBand::Critical, RiskBand::fromScore(75));

        // The worked examples in TRD §7.5, rounded as the band function does.
        $this->assertSame(RiskBand::Critical, RiskBand::fromScore(75.9));
        $this->assertSame(RiskBand::Moderate, RiskBand::fromScore(44.3));

        // 24.6 rounds to 25 and belongs to Moderate — a raw score does not
        // fall into a gap between bands.
        $this->assertSame(RiskBand::Moderate, RiskBand::fromScore(24.6));
    }

    #[Test]
    public function a_knockout_floor_raises_a_tier_and_never_lowers_it(): void
    {
        // TRD §7.3: final tier = max(computed, knockout floor, override floor).
        $this->assertSame(RiskTier::Critical, RiskTier::Moderate->max(RiskTier::Critical));
        $this->assertSame(RiskTier::Critical, RiskTier::Critical->max(RiskTier::Low));
        $this->assertSame(RiskTier::High, RiskTier::High->max(null));
    }

    #[Test]
    public function an_expired_tier_override_stops_applying(): void
    {
        $engagement = $this->makeEngagement();

        $engagement->forceFill([
            'inherent_tier' => RiskTier::Moderate,
            'tier_override' => RiskTier::Critical,
            'tier_override_expires_at' => now()->addDay(),
        ])->save();

        $this->assertSame(RiskTier::Critical, $engagement->fresh()->effectiveTier());

        $engagement->forceFill(['tier_override_expires_at' => now()->subDay()])->save();

        // An exception with an end date that has passed is not an exception.
        $this->assertSame(RiskTier::Moderate, $engagement->fresh()->effectiveTier());
    }

    #[Test]
    public function a_risk_accepted_finding_still_contributes_to_the_uplift(): void
    {
        // TRD §7.5 weights a risk-accepted finding at 0.5 until its acceptance
        // expires. Accepting a risk is not fixing it, and the residual score
        // has to keep saying so.
        $this->assertTrue(FindingStatus::ClosedRiskAccepted->contributesToUplift());
        $this->assertFalse(FindingStatus::ClosedRemediated->contributesToUplift());
        $this->assertFalse(FindingStatus::ClosedFalsePositive->contributesToUplift());
        $this->assertTrue(FindingStatus::InRemediation->contributesToUplift());
    }

    #[Test]
    public function engagement_transitions_are_guarded(): void
    {
        $this->assertTrue(EngagementStatus::Draft->canTransitionTo(EngagementStatus::IntakeSubmitted));
        $this->assertFalse(EngagementStatus::Draft->canTransitionTo(EngagementStatus::Active));
        $this->assertFalse(EngagementStatus::Archived->canTransitionTo(EngagementStatus::Active));

        // Every status reachable in one hop is itself a valid status — a
        // typo'd target in the transition table would otherwise sit there
        // until someone tried that path in production.
        foreach (EngagementStatus::cases() as $status) {
            foreach ($status->allowedTransitions() as $target) {
                $this->assertInstanceOf(EngagementStatus::class, $target);
            }
        }
    }

    #[Test]
    public function every_status_enum_can_reach_a_terminal_state(): void
    {
        // A status with no way out is a record that can never be closed. Walk
        // the transition graph from each status and assert a terminal state is
        // reachable.
        foreach (EngagementStatus::cases() as $start) {
            $this->assertTrue(
                $this->reachesTerminal($start),
                "EngagementStatus::{$start->name} cannot reach a terminal state."
            );
        }
    }

    /* ------------------------------------------------------------------ */

    private function reachesTerminal(EngagementStatus $start): bool
    {
        $seen = [];
        $queue = [$start];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($seen[$current->value])) {
                continue;
            }

            $seen[$current->value] = true;

            if ($current->isTerminal()) {
                return true;
            }

            foreach ($current->allowedTransitions() as $next) {
                $queue[] = $next;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function tprmTables(): array
    {
        return array_values(array_filter(
            array_map(fn (array $t) => $t['name'], Schema::getTables()),
            fn (string $name) => str_starts_with($name, 'tp_')
        ));
    }

    private function makeThirdParty(string $slug): ThirdParty
    {
        return ThirdParty::create([
            'legal_name' => 'Vendor '.$slug,
            'slug' => $slug,
            'entity_type' => 'company',
        ]);
    }

    private function makeEngagement(): Engagement
    {
        return Engagement::create([
            'third_party_id' => $this->makeThirdParty('eng-'.uniqid())->id,
            'reference' => 'ENG-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'name' => 'Test engagement',
            'engagement_type' => 'ict_service',
        ]);
    }
}
