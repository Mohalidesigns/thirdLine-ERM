<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\ImpactCategory;
use App\Enums\Bcms\ImpactHorizon;
use App\Enums\Bcms\PlanSectionSource;
use App\Enums\Bcms\PlanType;
use App\Enums\Bcms\StrategyType;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanAttestation;
use App\Models\Bcms\Process;
use App\Models\Bcms\Site;
use App\Models\Bcms\Strategy;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Bia\BiaAssessmentService;
use App\Services\Bcms\Plans\OfflineBundleBuilder;
use App\Services\Bcms\Plans\PlanAcknowledgementService;
use App\Services\Bcms\Plans\PlanActivationService;
use App\Services\Bcms\Plans\PlanAssembler;
use App\Services\Bcms\Plans\PlanDocumentRenderer;
use App\Services\Bcms\Plans\PlanDriftDetector;
use App\Services\Bcms\Plans\PlanService;
use App\Services\Bcms\Plans\SourceResolver;
use App\Services\Bcms\Strategy\GapAnalysisService;
use App\Services\Bcms\Strategy\StrategyService;
use App\Support\Bcms\PlanBinding;
use App\Support\Bcms\PlanTemplates;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 3 acceptance criteria — continuity strategy and the plan builder.
 *
 * Criterion 5 is worth reading before the test that answers it. The prompt asks
 * that the offline bundle "generates and validates against the bundle schema
 * … and renders in the offline plan viewer", and says in the same breath that
 * the PWA service worker is Phase 12 and end-to-end airplane mode is verified at
 * the W14 integration window. So what is testable now is that the bundle is
 * produced, that it carries every part the viewer reads, and that the validator
 * catches a bundle which does not. That is what
 * `the_offline_bundle_validates_against_its_own_schema()` asserts, and the
 * airplane-mode half is deliberately not claimed.
 *
 * Criteria 2 and 3 pull in opposite directions and the resolution is the most
 * important thing in this phase: a DRAFT renders live, and an APPROVED version
 * prints the render frozen at approval. Drift is still detected against the
 * approved version — what it produces is a review flag and a v2, never a silent
 * edit. `an_approved_version_is_immutable_and_still_printable()` and
 * `changing_the_bia_flags_the_bound_section()` are the two halves.
 */
class Phase3PlanBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $author;

    private User $approver;

    private BusinessUnit $unit;

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

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $this->author = $this->user('author@khb.test');
        $this->approver = $this->user('approver@khb.test');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 1 — a BCP is generated from BIA data, approved, versioned,
    /*  and downloads as a PDF and an offline bundle.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_bcp_is_assembled_from_bia_data_approved_and_printable(): void
    {
        $process = $this->processWithApprovedBia('BCP-PY', rto: 0.5, mtpd: 4);

        $plan = app(PlanService::class)->create(
            PlanType::Bcp,
            'Group Business Continuity Plan',
            ['business_unit_id' => $this->unit->id, 'owner_id' => $this->author->id, 'review_frequency_months' => 12],
            'bcp_group',
            $this->author->id,
        );

        $this->assertSame(16, $plan->sections()->count());

        $resolved = app(PlanAssembler::class)->assemble($plan, $this->author->id);

        $rto = $plan->sections()->where('section_key', 'recovery_objectives')->sole();

        $this->assertNotNull($rto->last_verified_at, 'A bound section carries the date it was last verified.');
        $this->assertNotNull($rto->source_fingerprint);
        $this->assertFalse((bool) $rto->needs_review);

        // The section renders the number the BIA holds — not a copy taken when
        // somebody typed it.
        $row = collect($resolved['recovery_objectives']['rows'])->firstWhere('code', 'BCP-PY');
        $this->assertSame(0.5, $row['rto_hours']);
        $this->assertTrue($row['has_approved_assessment']);

        app(PlanService::class)->submitForReview($plan, $this->author->id);
        $approved = app(PlanService::class)->approve($plan, $this->approver, now()->toDateString(), 12);

        $this->assertSame('approved', $approved->status);
        $this->assertSame(now()->addMonths(12)->toDateString(), $approved->next_review_date->toDateString());
        $this->assertNotEmpty($approved->content['sections'] ?? []);

        $pdf = app(PlanDocumentRenderer::class)->pdf($approved, $this->approver->name);
        $this->assertStringStartsWith('%PDF', $pdf);

        $bundle = app(OfflineBundleBuilder::class)->build($approved);
        $this->assertSame([], app(OfflineBundleBuilder::class)->validate($bundle));
    }

    #[Test]
    public function a_plan_with_no_sections_cannot_go_to_review(): void
    {
        $plan = app(PlanService::class)->create(PlanType::Bcp, 'Empty plan', [], null, $this->author->id);

        $this->expectException(InvalidArgumentException::class);
        app(PlanService::class)->submitForReview($plan, $this->author->id);
    }

    #[Test]
    public function a_plan_cannot_be_approved_by_its_own_author(): void
    {
        $plan = $this->templatedPlan();
        app(PlanService::class)->submitForReview($plan, $this->author->id);

        $this->expectException(InvalidArgumentException::class);
        app(PlanService::class)->approve($plan, $this->author);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 2 — a bound section renders the current RTO, and changing it
    /*  flags the plan and highlights the section.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function changing_the_bia_flags_the_bound_section(): void
    {
        $process = $this->processWithApprovedBia('BCP-CORE', rto: 2, mtpd: 8);

        $plan = $this->templatedPlan();
        app(PlanAssembler::class)->assemble($plan, $this->author->id);

        $rto = $plan->sections()->where('section_key', 'recovery_objectives')->sole();
        $before = $rto->source_fingerprint;

        $this->assertSame([], app(PlanDriftDetector::class)->check($plan->refresh()));

        // A second, later approved assessment with a different RTO — the real
        // shape of a BIA cycle, not an UPDATE against the old row.
        $this->approvedAssessment($process, rto: 6, mtpd: 24);

        $drifted = app(PlanDriftDetector::class)->check($plan->refresh());

        $this->assertContains('recovery_objectives', $drifted);
        $this->assertTrue((bool) $rto->refresh()->needs_review);
        $this->assertSame($before, $rto->source_fingerprint, 'Detection does not restamp; it flags.');

        // Other sections are untouched. "The plan has drifted" is not useful;
        // "this section has drifted" is.
        $this->assertFalse((bool) $plan->sections()->where('section_key', 'purpose_scope')->sole()->needs_review);

        // And the live render now shows the new figure.
        $binding = PlanBinding::make(PlanSectionSource::BiaRto);
        $row = collect(app(SourceResolver::class)->resolve($plan, $binding)['rows'])->firstWhere('code', 'BCP-CORE');
        $this->assertSame(6.0, $row['rto_hours']);
    }

    #[Test]
    public function a_source_that_changes_and_changes_back_clears_the_flag(): void
    {
        $process = $this->processWithApprovedBia('BCP-ATM', rto: 4, mtpd: 24);
        $plan = $this->templatedPlan();
        app(PlanAssembler::class)->assemble($plan, $this->author->id);

        $second = $this->approvedAssessment($process, rto: 9, mtpd: 24);
        $this->assertNotSame([], app(PlanDriftDetector::class)->check($plan->refresh()));

        // Reverted — the later assessment is withdrawn, so the latest approved
        // one is the original again. An `updated_at` watermark could not see
        // this; a fingerprint can.
        $second->delete();

        $this->assertSame([], app(PlanDriftDetector::class)->check($plan->refresh()));
        $this->assertFalse((bool) $plan->sections()->where('section_key', 'recovery_objectives')->sole()->needs_review);
    }

    #[Test]
    public function a_section_that_has_never_been_assembled_is_unverified_not_drifted(): void
    {
        $this->processWithApprovedBia('BCP-FX', rto: 4, mtpd: 24);
        $plan = $this->templatedPlan();

        $this->assertSame([], app(PlanDriftDetector::class)->check($plan));

        $section = $plan->sections()->where('section_key', 'recovery_objectives')->sole();
        $this->assertNull($section->last_verified_at);
        $this->assertNull($section->source_fingerprint);
        $this->assertFalse((bool) $section->needs_review);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 3 — an approved v1 is immutable; v2 supersedes it and v1
    /*  stays retrievable and printable.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_approved_version_is_immutable_and_still_printable(): void
    {
        $process = $this->processWithApprovedBia('BCP-CARD', rto: 1, mtpd: 6);

        $v1 = $this->templatedPlan();
        app(PlanAssembler::class)->assemble($v1, $this->author->id);
        app(PlanService::class)->submitForReview($v1, $this->author->id);
        $v1 = app(PlanService::class)->approve($v1, $this->approver, now()->toDateString(), 12);

        // assertEquals, not assertSame: the frozen document is stored as JSON,
        // and 1.0 comes back out of a json cast as an int. That is fine for a
        // figure that is printed and never re-computed, and it is exactly why
        // the frozen render is the thing that is printed rather than the thing
        // that is compared — comparison happens on the fingerprint, which is
        // taken before the round trip.
        $this->assertEquals(1.0, collect($this->frozenRows($v1))->firstWhere('code', 'BCP-CARD')['rto_hours']);

        // The world moves.
        $this->approvedAssessment($process, rto: 5, mtpd: 12);

        // v1 still prints what it was approved with. This is the whole of
        // clause 7.5 document control in one assertion.
        $this->assertEquals(1.0, collect($this->frozenRows($v1->refresh()))->firstWhere('code', 'BCP-CARD')['rto_hours']);

        // It cannot be edited or re-assembled.
        $this->expectExceptionMessageMatches('/cannot be edited/');
        app(PlanAssembler::class)->assemble($v1);
    }

    #[Test]
    public function superseding_produces_a_draft_copy_and_archives_the_original(): void
    {
        $this->processWithApprovedBia('BCP-CASH', rto: 4, mtpd: 24);

        $v1 = $this->templatedPlan();
        app(PlanAssembler::class)->assemble($v1, $this->author->id);
        app(PlanService::class)->submitForReview($v1, $this->author->id);
        $v1 = app(PlanService::class)->approve($v1, $this->approver);

        $v1->sections()->where('section_key', 'purpose_scope')->update(['body' => 'Something a human wrote.']);

        $v2 = app(PlanService::class)->supersede($v1, '2.0', $this->author->id);

        $this->assertSame('archived', $v1->refresh()->status);
        $this->assertSame('draft', $v2->status);
        $this->assertSame((int) $v1->getKey(), (int) $v2->supersedes_plan_id);
        $this->assertSame($v1->sections()->count(), $v2->sections()->count());
        $this->assertSame(
            'Something a human wrote.',
            $v2->sections()->where('section_key', 'purpose_scope')->sole()->body,
            'The next version starts as an amendable copy — a blank page loses the clause somebody added last year.',
        );

        // v1 is archived, not deleted, and still prints.
        $this->assertStringStartsWith('%PDF', app(PlanDocumentRenderer::class)->pdf($v1->refresh()));

        // The new draft has not been verified against live data yet, so it does
        // not inherit v1's stamp.
        $this->assertNull($v2->sections()->where('section_key', 'recovery_objectives')->sole()->last_verified_at);
    }

    #[Test]
    public function the_pdf_prints_a_section_whose_structured_column_is_null_on_the_first_row(): void
    {
        // The defect this is here for: the printed table derives its columns
        // from the rows, and a nullable STRUCTURED column — `resource_
        // requirements` on a strategy — is null on one row and an array on the
        // next. Deriving the header from row zero alone lets that column
        // through and then fatals on the row that has data in it, which is a
        // 500 on a download button rather than a wrong number. Found by
        // printing the demo estate; not found by printing a two-row fixture.
        $first = $this->processWithApprovedBia('BCP-AAA', rto: 4, mtpd: 24);
        $second = $this->processWithApprovedBia('BCP-BBB', rto: 4, mtpd: 24);

        $bare = app(StrategyService::class)->propose($first, StrategyType::Recover, [
            'title' => 'No resources recorded', 'rto_achievable_hours' => 2,
        ], $this->author->id);
        app(StrategyService::class)->select($bare);
        app(StrategyService::class)->approve($bare->refresh(), $this->approver);

        $rich = app(StrategyService::class)->propose($second, StrategyType::Relocate, [
            'title' => 'Resources recorded',
            'rto_achievable_hours' => 3,
            'resource_requirements' => ['people' => ['Recovery team, 12 seats'], 'facilities' => ['DR site']],
        ], $this->author->id);
        app(StrategyService::class)->select($rich);
        app(StrategyService::class)->approve($rich->refresh(), $this->approver);

        $plan = $this->templatedPlan();
        app(PlanAssembler::class)->assemble($plan, $this->author->id);

        $rows = collect(app(PlanAssembler::class)->render($plan))
            ->firstWhere('key', 'strategies')['live']['rows'];

        $this->assertNull($rows[0]['resource_requirements'], 'The fixture only bites when row zero is the null one.');
        $this->assertIsArray($rows[1]['resource_requirements']);

        $this->assertStringStartsWith('%PDF', app(PlanDocumentRenderer::class)->pdf($plan));
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 4 — the gap analysis.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function gap_analysis_lists_tier_one_processes_whose_strategy_is_too_slow(): void
    {
        $slow = $this->processWithApprovedBia('BCP-PY', rto: 0.5, mtpd: 4, tier: 1);
        $fine = $this->processWithApprovedBia('BCP-CHAN', rto: 4, mtpd: 24, tier: 1);
        $none = $this->processWithApprovedBia('BCP-CORE', rto: 2, mtpd: 8, tier: 1);
        $tierTwo = $this->processWithApprovedBia('BCP-RET', rto: 8, mtpd: 48, tier: 2);

        $this->selectedStrategy($slow, achievable: 1.5, cost: 18_000_000_00);
        $this->selectedStrategy($fine, achievable: 2, cost: 42_000_000_00);
        $this->selectedStrategy($tierTwo, achievable: 12, cost: 1_200_000_00);

        $analysis = app(GapAnalysisService::class)->analyse(null, 1);

        $this->assertSame(3, $analysis['summary']['processes'], 'Tier 1 only by default.');
        $this->assertSame(1, $analysis['summary']['with_gap']);
        $this->assertSame(1.0, $analysis['summary']['total_shortfall_hours']);
        $this->assertSame(1, $analysis['summary']['unprotected']);

        // Ranked by exposure: the shortfall first, then the unprotected
        // process, then the one that is fine.
        $this->assertSame(['BCP-PY', 'BCP-CORE', 'BCP-CHAN'], array_column($analysis['rows'], 'code'));
        $this->assertSame(1.0, $analysis['rows'][0]['shortfall_hours']);

        // A process with no strategy at all is the largest gap there is, and it
        // is IN the table with a reason rather than dropped by the join.
        $this->assertNull($analysis['rows'][1]['strategy_id']);
        $this->assertStringContainsString('No continuity strategy', (string) $analysis['rows'][1]['reason']);

        // Widening the tier brings in the tier-2 shortfall.
        $wider = app(GapAnalysisService::class)->analyse(null, 2);
        $this->assertSame(2, $wider['summary']['with_gap']);
        $this->assertSame(5.0, $wider['summary']['total_shortfall_hours']);
    }

    #[Test]
    public function a_shortfall_with_nothing_to_compare_is_null_and_not_zero(): void
    {
        $process = $this->process('BCP-LN', ['criticality_tier' => 1]);
        $this->selectedStrategy($process, achievable: 4, cost: 100_00);

        $analysis = app(GapAnalysisService::class)->analyse(null, 1);

        $this->assertNull($analysis['rows'][0]['shortfall_hours']);
        $this->assertNull($analysis['summary']['total_shortfall_hours']);
        $this->assertStringContainsString('No approved business impact assessment', (string) $analysis['rows'][0]['reason']);
    }

    #[Test]
    public function the_stored_gap_is_a_snapshot_and_the_live_gap_is_not(): void
    {
        $process = $this->processWithApprovedBia('BCP-DC', rto: 6, mtpd: 24, tier: 1);
        $strategy = $this->selectedStrategy($process, achievable: 6, cost: 1_000_00);

        $this->assertSame('0.00', (string) $strategy->refresh()->gap_vs_required_hours);

        // The BIA tightens. Last year's approved strategy paper still says what
        // it said; the live view says what is true today.
        $this->approvedAssessment($process, rto: 2, mtpd: 8);

        $analysis = app(GapAnalysisService::class)->analyse(null, 1);

        $this->assertSame(0.0, $analysis['rows'][0]['gap_at_assessment_hours']);
        $this->assertSame(4.0, $analysis['rows'][0]['shortfall_hours']);
        $this->assertTrue($analysis['rows'][0]['assessment_is_stale']);
    }

    #[Test]
    public function accepting_an_outage_requires_a_rationale(): void
    {
        $process = $this->process('BCP-TRAIN');

        $this->expectExceptionMessageMatches('/has to say why/');
        app(StrategyService::class)->propose($process, StrategyType::Accept, [], $this->author->id);
    }

    #[Test]
    public function only_one_strategy_per_process_is_selected(): void
    {
        $process = $this->processWithApprovedBia('BCP-NET', rto: 2, mtpd: 8);

        $first = $this->selectedStrategy($process, achievable: 1.5, cost: 100_00, approve: false);
        $second = app(StrategyService::class)->propose($process, StrategyType::Relocate, [
            'rto_achievable_hours' => 1, 'cost_estimate_minor' => 900_00, 'currency' => 'NGN',
        ], $this->author->id);

        app(StrategyService::class)->select($second);

        $this->assertFalse((bool) $first->refresh()->is_selected);
        $this->assertTrue((bool) $second->refresh()->is_selected);
    }

    #[Test]
    public function an_unselected_strategy_cannot_be_approved(): void
    {
        $process = $this->process('BCP-KY');
        $strategy = app(StrategyService::class)->propose($process, StrategyType::Remote, [], $this->author->id);

        $this->expectExceptionMessageMatches('/Select this strategy before approving/');
        app(StrategyService::class)->approve($strategy, $this->approver);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 5 — the offline bundle.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_offline_bundle_validates_against_its_own_schema(): void
    {
        $this->processWithApprovedBia('BCP-CHAN', rto: 1, mtpd: 8);
        $this->site('HQ');

        $plan = $this->approvedPlan();

        $bundle = app(OfflineBundleBuilder::class)->build($plan);

        $this->assertSame([], app(OfflineBundleBuilder::class)->validate($bundle));
        $this->assertSame(OfflineBundleBuilder::SCHEMA_VERSION, $bundle['schema_version']);
        $this->assertSame($plan->uuid, $bundle['plan']['uuid']);
        $this->assertNotEmpty($bundle['sections']);
        $this->assertArrayHasKey('call_tree', $bundle);
        $this->assertArrayHasKey('contacts', $bundle);
        $this->assertArrayHasKey('sites', $bundle);

        // Every section the viewer will iterate carries the keys it reads.
        foreach ($bundle['sections'] as $section) {
            $this->assertArrayHasKey('rows', $section);
            $this->assertArrayHasKey('notes', $section);
            $this->assertIsArray($section['rows']);
        }
    }

    #[Test]
    public function the_bundle_validator_catches_a_bundle_the_viewer_could_not_render(): void
    {
        $problems = app(OfflineBundleBuilder::class)->validate([
            'schema_version' => 99,
            'generated_at' => now()->toIso8601String(),
            'plan' => ['title' => 'No uuid'],
            'sections' => [['key' => 'a', 'title' => 'A']],
            'contains_personal_data' => false,
        ]);

        $this->assertNotEmpty($problems);
        $this->assertTrue(collect($problems)->contains(fn (string $p) => str_contains($p, 'schema version')));
        $this->assertTrue(collect($problems)->contains(fn (string $p) => str_contains($p, '"uuid"')));
        $this->assertTrue(collect($problems)->contains(fn (string $p) => str_contains($p, '"rows"')));
    }

    #[Test]
    public function a_draft_is_never_bundled_for_offline_use(): void
    {
        $plan = $this->templatedPlan();

        $this->expectExceptionMessageMatches('/Only an approved plan is bundled/');
        app(OfflineBundleBuilder::class)->build($plan);
    }

    #[Test]
    public function a_bundle_says_whether_it_carries_personal_data(): void
    {
        $plan = $this->approvedPlan();

        // The group template binds a call tree and a crisis-contact block, both
        // of which are mobile numbers leaving the platform.
        $this->assertTrue(app(OfflineBundleBuilder::class)->build($plan)['contains_personal_data']);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 6 — staleness and the KRI.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_plan_past_its_review_date_is_stale_and_moves_the_kri(): void
    {
        $current = $this->approvedPlan('Current plan');
        $overdue = $this->approvedPlan('Overdue plan');

        $overdue->forceFill(['next_review_date' => now()->subDay()->toDateString()])->save();

        $stale = app(PlanService::class)->staleQuery()->pluck('title')->all();

        $this->assertSame(['Overdue plan'], $stale);

        $currency = app(PlanService::class)->currency();

        $this->assertSame(2, $currency['approved']);
        $this->assertSame(1, $currency['stale']);
        $this->assertSame(50.0, $currency['percentage']);

        $this->assertNotNull($current->refresh()->next_review_date);
    }

    #[Test]
    public function the_bc_policy_is_not_a_plan_for_the_library_or_the_kri(): void
    {
        // The policy is a `bcms_plans` row on purpose (ADR 0008) and has its own
        // screen, its own clause and no sections. Letting it into "plans
        // current" moves an operational KRI with a governance document.
        $policy = app(\App\Services\Bcms\PolicyService::class)->draft('BC Policy', [], $this->author->id);
        app(\App\Services\Bcms\PolicyService::class)->approve($policy, $this->approver);
        $policy->forceFill(['next_review_date' => now()->subYear()->toDateString()])->save();

        $this->approvedPlan('A real plan');

        $currency = app(PlanService::class)->currency();

        $this->assertSame(1, $currency['approved'], 'The policy is not counted as a plan.');
        $this->assertSame(0, $currency['stale']);
        $this->assertSame(100.0, $currency['percentage']);
        $this->assertNotContains('BC Policy', app(PlanService::class)->staleQuery()->pluck('title')->all());
    }

    #[Test]
    public function plans_current_is_null_and_not_a_hundred_percent_when_there_are_no_plans(): void
    {
        $currency = app(PlanService::class)->currency();

        $this->assertSame(0, $currency['approved']);
        $this->assertNull($currency['percentage']);
    }

    #[Test]
    public function an_approved_plan_with_no_review_date_is_undated_rather_than_current(): void
    {
        // No declared cycle. Passing null to approve() means "keep whatever the
        // plan says", so the plan itself has to have no frequency — otherwise
        // the test would be asserting that approve() silently discards one.
        $plan = app(PlanService::class)->create(
            PlanType::Bcp,
            'A plan nobody set a cycle for',
            ['business_unit_id' => $this->unit->id, 'owner_id' => $this->author->id],
            'bcp_group',
            $this->author->id,
        );

        app(PlanService::class)->submitForReview($plan, $this->author->id);
        app(PlanService::class)->approve($plan, $this->approver, now()->toDateString(), null);

        $currency = app(PlanService::class)->currency();

        $this->assertSame(1, $currency['undated']);
        $this->assertSame(0, $currency['stale']);
        $this->assertNull($plan->refresh()->next_review_date);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 7 — reader acknowledgement as clause 7.4 evidence.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function reader_acknowledgement_is_recorded_and_exportable(): void
    {
        $plan = $this->approvedPlan();

        $record = app(PlanAcknowledgementService::class)->acknowledge($plan, $this->author, '10.0.0.4');

        $this->assertSame('read', $record->attestation_type);
        $this->assertSame($this->author->name, $record->attested_by_name);
        $this->assertNotNull($record->attested_at);
        $this->assertSame('iso22301.7.4', $record->iso_clause_ref);
        $this->assertSame(PlanAcknowledgementService::STATEMENT, $record->statement);

        $evidence = app(PlanAcknowledgementService::class)->evidence($plan);

        $this->assertCount(1, $evidence);
        $this->assertSame($this->author->name, $evidence[0]['name']);
        $this->assertSame('10.0.0.4', $evidence[0]['ip_address']);

        // Acknowledging twice in the same year is the same act, not two.
        app(PlanAcknowledgementService::class)->acknowledge($plan, $this->author);
        $this->assertCount(1, app(PlanAcknowledgementService::class)->evidence($plan));

        // A new year is a new act — the annual re-acknowledgement cycle.
        app(PlanAcknowledgementService::class)->acknowledge($plan, $this->author, null, (int) now()->year + 1);
        $this->assertCount(2, app(PlanAcknowledgementService::class)->evidence($plan));
    }

    #[Test]
    public function an_acknowledgement_never_counts_as_a_board_attestation(): void
    {
        $plan = $this->approvedPlan();

        app(PlanAcknowledgementService::class)->acknowledge($plan, $this->author);

        // The table is shared deliberately (ADR 0011). Anything counting
        // attestations must filter on the type, or a governance number silently
        // includes every branch manager who ticked "I have read this".
        $this->assertSame(0, PlanAttestation::query()
            ->where('plan_id', $plan->getKey())
            ->where('attestation_type', 'board')
            ->count());
        $this->assertSame(1, PlanAttestation::query()->where('plan_id', $plan->getKey())->count());
    }

    #[Test]
    public function a_draft_cannot_be_acknowledged(): void
    {
        $plan = $this->templatedPlan();

        $this->expectExceptionMessageMatches('/Only an approved plan version can be acknowledged/');
        app(PlanAcknowledgementService::class)->acknowledge($plan, $this->author);
    }

    #[Test]
    public function coverage_reports_null_rather_than_full_when_there_is_no_distribution_list(): void
    {
        $plan = $this->approvedPlan();

        app(PlanAcknowledgementService::class)->acknowledge($plan, $this->author);

        $coverage = app(PlanAcknowledgementService::class)->coverage($plan);

        $this->assertSame(1, $coverage['acknowledged_count']);
        $this->assertNull($coverage['percentage']);
        $this->assertStringContainsString('no distribution list', (string) $coverage['note']);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 8 — AI drafting lands in draft and cannot self-approve.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function ai_plan_drafting_is_off_by_default_and_refuses_rather_than_throwing(): void
    {
        $plan = $this->templatedPlan();

        $drafter = app(\App\Services\Bcms\Plans\PlanAiDrafter::class);

        $this->assertFalse($drafter->available($plan));
        $this->assertNotNull($drafter->unavailableReason($plan));

        $result = $drafter->draft($plan);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['written']);
        $this->assertFalse((bool) $plan->refresh()->ai_generated);
    }

    #[Test]
    public function every_plan_workflow_completes_with_ai_switched_off(): void
    {
        // Standing rule 4's real test: the module is buyable by a bank that
        // will not run a model at all.
        config()->set('services.llm.enabled', false);

        $this->processWithApprovedBia('BCP-CORE', rto: 2, mtpd: 8);

        $plan = $this->templatedPlan();
        app(PlanAssembler::class)->assemble($plan, $this->author->id);
        app(PlanService::class)->submitForReview($plan, $this->author->id);
        $approved = app(PlanService::class)->approve($plan, $this->approver, now()->toDateString(), 12);

        $this->assertSame('approved', $approved->status);
        $this->assertStringStartsWith('%PDF', app(PlanDocumentRenderer::class)->pdf($approved));
        $this->assertSame([], app(OfflineBundleBuilder::class)->validate(
            app(OfflineBundleBuilder::class)->build($approved)
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  The template pack and the binding grammar.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function twelve_templates_ship_and_every_binding_in_them_resolves(): void
    {
        $this->assertCount(12, PlanTemplates::all());
        $this->assertSame(12, count(array_unique(PlanTemplates::keys())));

        $plan = $this->templatedPlan();

        foreach (PlanTemplates::all() as $template) {
            $this->assertNotEmpty($template['sections'], $template['key'].' has no sections.');
            $this->assertNotNull(PlanType::tryFrom($template['plan_type']), $template['key'].' has an unknown plan type.');

            $keys = array_column($template['sections'], 'key');
            $this->assertSame($keys, array_unique($keys), $template['key'].' repeats a section key.');

            foreach ($template['sections'] as $section) {
                if ($section['binding'] === null) {
                    $this->assertNotEmpty($section['guidance'], $template['key'].'.'.$section['key'].' has no guidance.');

                    continue;
                }

                // Every binding a template ships must parse and resolve. A
                // template shipping a binding nobody can render is a plan that
                // silently loses a section.
                $binding = PlanBinding::fromArray($section['binding']);
                $payload = app(SourceResolver::class)->resolve($plan, $binding);

                $this->assertArrayHasKey('rows', $payload);
                $this->assertIsArray($payload['rows']);
            }
        }
    }

    #[Test]
    public function a_template_written_for_one_plan_type_is_refused_on_another(): void
    {
        $this->actingAs($this->authorWithPermissions(['bcms.plan.manage', 'bcms.view']))
            ->post(route('bcms.plans.store'), [
                'plan_type' => PlanType::Site->value,
                'title' => 'Wrong template',
                'template_key' => 'cmp_crisis',
            ])
            ->assertSessionHasErrors('template_key');
    }

    #[Test]
    public function a_binding_that_does_not_parse_is_refused_before_it_reaches_customer_data(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PlanBinding::fromArray(['source' => 'bia.everything']);
    }

    #[Test]
    public function a_process_scoped_binding_never_falls_back_to_the_whole_organisation(): void
    {
        $this->processWithApprovedBia('BCP-PY', rto: 0.5, mtpd: 4);

        $otherUnit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-EMPTY', 'name' => 'Empty', 'is_active' => true,
        ]);

        $plan = app(PlanService::class)->create(
            PlanType::Department,
            'A department with nothing in it',
            ['business_unit_id' => $otherUnit->id],
            'bcp_department',
            $this->author->id,
        );

        $payload = app(SourceResolver::class)->resolve($plan, PlanBinding::make(PlanSectionSource::BiaRto));

        $this->assertSame([], $payload['rows'], 'A departmental plan must never print the whole bank.');
        $this->assertStringContainsString('business unit', (string) $payload['empty_reason']);
    }

    #[Test]
    public function assembly_keeps_a_section_a_human_has_written(): void
    {
        $this->processWithApprovedBia('BCP-CORE', rto: 2, mtpd: 8);

        $plan = $this->templatedPlan();
        app(PlanAssembler::class)->assemble($plan, $this->author->id);

        $section = $plan->sections()->where('section_key', 'recovery_objectives')->sole();
        $section->update(['body' => 'The Operations team recovers this in the order below.', 'is_overridden' => true]);

        app(PlanAssembler::class)->assemble($plan->refresh(), $this->author->id);

        $this->assertSame(
            'The Operations team recovers this in the order below.',
            $section->refresh()->body,
            'A regenerate that discarded a week of somebody\'s writing would be used once.',
        );
        $this->assertNotNull($section->last_verified_at, 'Its fingerprint is still refreshed, so drift is still seen.');
    }

    #[Test]
    public function applying_a_template_twice_does_not_duplicate_or_overwrite_sections(): void
    {
        $plan = $this->templatedPlan();
        $plan->sections()->where('section_key', 'purpose_scope')->update(['body' => 'Mine.']);

        $before = $plan->sections()->count();
        app(PlanAssembler::class)->applyTemplate($plan, 'bcp_group', $this->author->id);

        $this->assertSame($before, $plan->refresh()->sections()->count());
        $this->assertSame('Mine.', $plan->sections()->where('section_key', 'purpose_scope')->sole()->body);
    }

    /* ------------------------------------------------------------------ */
    /*  Plan activation — the table Phase 10 writes to.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_exercise_activation_is_not_an_activation_in_anger(): void
    {
        $plan = $this->approvedPlan();
        $service = app(PlanActivationService::class);

        $exercise = $service->activate($plan, $this->author, 'Annual walkthrough', isExercise: true);
        $service->deactivate($exercise);

        $live = $service->activate($plan, $this->author, 'Data centre power failure');

        $history = $service->history($plan->refresh());

        $this->assertSame(1, $history['live_count']);
        $this->assertSame(1, $history['exercise_count']);
        $this->assertTrue($history['is_active']);

        $service->deactivate($live);
        $this->assertFalse($service->history($plan)['is_active']);
    }

    #[Test]
    public function a_draft_plan_cannot_be_activated(): void
    {
        $plan = $this->templatedPlan();

        $this->expectExceptionMessageMatches('/Only an approved plan can be activated/');
        app(PlanActivationService::class)->activate($plan, $this->author, 'Because');
    }

    #[Test]
    public function a_plan_cannot_be_activated_twice_without_being_stood_down(): void
    {
        $plan = $this->approvedPlan();
        app(PlanActivationService::class)->activate($plan, $this->author, 'First');

        $this->expectExceptionMessageMatches('/already active/');
        app(PlanActivationService::class)->activate($plan, $this->author, 'Second');
    }

    /* ------------------------------------------------------------------ */
    /*  Tenancy.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function another_tenants_plan_and_strategy_are_invisible(): void
    {
        $mine = $this->templatedPlan();
        $myProcess = $this->process('BCP-MINE');

        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);

        $this->assertSame(0, Plan::query()->count());
        $this->assertSame(0, Strategy::query()->count());
        $this->assertNull(Plan::query()->find($mine->getKey()));
        $this->assertNull(Process::query()->find($myProcess->getKey()));

        TenantContext::set($this->organization->id);

        $this->assertNotNull(Plan::query()->find($mine->getKey()));
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function user(string $email): User
    {
        return User::create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
    }

    /** @param list<string> $permissions */
    private function authorWithPermissions(array $permissions): User
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate('plan-author', 'web');

        foreach ($permissions as $permission) {
            $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }

        $this->author->assignRole($role);

        return $this->author->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function process(string $code, array $attributes = []): Process
    {
        return Process::query()->create(array_merge([
            'code' => $code, 'name' => "Process {$code}", 'status' => 'active',
            'business_unit_id' => $this->unit->id, 'owner_id' => $this->author->id,
        ], $attributes));
    }

    private function processWithApprovedBia(string $code, float $rto, float $mtpd, int $tier = 1): Process
    {
        $process = $this->process($code, ['criticality_tier' => $tier]);
        $this->approvedAssessment($process, $rto, $mtpd, $tier);

        return $process->refresh();
    }

    private function approvedAssessment(Process $process, float $rto, float $mtpd, int $tier = 1): BiaAssessment
    {
        $assessments = app(BiaAssessmentService::class);

        $assessment = $assessments->start($process, $this->author->id);

        $assessments->save($assessment, [
            'mtpd_hours' => $mtpd,
            'rto_hours' => $rto,
            'rpo_minutes' => 15,
            'mbco_description' => 'Minimum service for '.$process->code.'.',
        ]);

        foreach (ImpactHorizon::cases() as $horizon) {
            $assessments->scoreImpact(
                $assessment,
                ImpactCategory::Regulatory,
                $horizon,
                $horizon->hours() >= $mtpd ? 5 : 2,
                null,
                'Seeded for the test.',
            );
        }

        $assessments->submit($assessment->refresh());

        return $assessments->approve($assessment->refresh(), $this->approver->id, $tier);
    }

    private function selectedStrategy(
        Process $process,
        float $achievable,
        int $cost,
        bool $approve = true,
    ): Strategy {
        $strategy = app(StrategyService::class)->propose($process, StrategyType::Recover, [
            'title' => 'Recover '.$process->code,
            'rto_achievable_hours' => $achievable,
            'cost_estimate_minor' => $cost,
            'currency' => 'NGN',
        ], $this->author->id);

        app(StrategyService::class)->select($strategy, 'The funded option.');

        if ($approve) {
            app(StrategyService::class)->approve($strategy->refresh(), $this->approver);
        }

        return $strategy->refresh();
    }

    private function templatedPlan(string $title = 'Group Business Continuity Plan'): Plan
    {
        return app(PlanService::class)->create(
            PlanType::Bcp,
            $title,
            ['business_unit_id' => $this->unit->id, 'owner_id' => $this->author->id, 'review_frequency_months' => 12],
            'bcp_group',
            $this->author->id,
        );
    }

    private function approvedPlan(string $title = 'Group Business Continuity Plan'): Plan
    {
        $plan = $this->templatedPlan($title);
        app(PlanAssembler::class)->assemble($plan, $this->author->id);
        app(PlanService::class)->submitForReview($plan, $this->author->id);

        return app(PlanService::class)->approve($plan, $this->approver, now()->toDateString(), 12);
    }

    private function site(string $code): Site
    {
        return Site::query()->create([
            'code' => $code, 'name' => "Site {$code}", 'site_type' => 'office',
            'city' => 'Kano', 'state' => 'Kano', 'country' => 'NG', 'is_active' => true,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function frozenRows(Plan $plan): array
    {
        foreach (app(PlanAssembler::class)->document($plan) as $section) {
            if ($section['key'] === 'recovery_objectives') {
                return $section['live']['rows'] ?? [];
            }
        }

        return [];
    }
}
