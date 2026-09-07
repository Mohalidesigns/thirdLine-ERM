<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Events\Tprm\ProhibitedOutsourcingAttempted;
use App\Exceptions\Tprm\ProhibitedOutsourcingException;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\ScoreRun;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\IntakeService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * TRD §17's AC-01 and AC-02, as automated tests — which is what the Phase 1
 * acceptance asks for.
 *
 * These two are the criteria a client is shown in the first five minutes of a
 * demo, and they are the two that a competitor's product fails. They are
 * exercised through `IntakeService`, the path every caller uses, rather than
 * through an HTTP route, so that the API and the bulk importer are covered by
 * the same assertions.
 */
class IntakeAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private ThirdParty $vendor;

    private IntakeService $intake;

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

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->vendor = ThirdParty::create([
            'legal_name' => 'Interlink Systems Limited',
            'slug' => 'interlink-systems',
            'entity_type' => 'company',
            'status' => 'approved_supplier',
        ]);

        $this->intake = app(IntakeService::class);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  AC-01 — prohibited outsourcing                                     */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function ac01_an_intake_naming_internal_audit_is_blocked_cited_and_audited(): void
    {
        Event::fake([ProhibitedOutsourcingAttempted::class]);

        $internalAudit = BusinessFunction::where('function_code', 'BF-AUD-01')->firstOrFail();
        $this->assertTrue((bool) $internalAudit->is_prohibited_outsourcing);

        $engagementsBefore = Engagement::count();

        try {
            $this->intake->submit(
                $this->engagementAttributes(),
                [$internalAudit->id],
                $this->answers(),
            );

            $this->fail('The intake was accepted for a function that may not be outsourced.');
        } catch (ProhibitedOutsourcingException $exception) {
            // The citation is half the criterion: a block with no reason is a
            // block a business unit works around.
            $details = $exception->details();

            $this->assertCount(1, $details);
            $this->assertSame('Internal audit', $details[0]['function']);
            $this->assertSame(
                'CBN Corporate Governance Guidelines 2023 §13.1, §3.6.2',
                $details[0]['citation']
            );
        }

        // "No engagement row is created" — the clause that makes this a write-
        // path guard rather than a validation rule.
        $this->assertSame($engagementsBefore, Engagement::count());

        // The attempt is recorded. A refusal that leaves no trace means the
        // risk function never learns the business unit tried.
        $audit = AuditLog::where('event', 'prohibited_outsourcing_attempted')->first();
        $this->assertNotNull($audit);
        $this->assertSame('Internal audit', $audit->after['functions'][0]['function']);

        Event::assertDispatched(ProhibitedOutsourcingAttempted::class);
    }

    #[Test]
    public function ac01_holds_when_a_prohibited_function_is_hidden_among_permitted_ones(): void
    {
        // The realistic attempt: a bundled "back office support" intake that
        // names four functions, one of which is compliance.
        $permitted = BusinessFunction::whereIn('function_code', ['BF-HR-01', 'BF-PROC-01'])->pluck('id')->all();
        $compliance = BusinessFunction::where('function_code', 'BF-COMP-01')->value('id');

        $this->expectException(ProhibitedOutsourcingException::class);

        try {
            $this->intake->submit(
                $this->engagementAttributes(),
                array_merge($permitted, [$compliance]),
                $this->answers(),
            );
        } finally {
            $this->assertSame(0, Engagement::count());
        }
    }

    #[Test]
    public function an_intake_for_a_permitted_function_proceeds_normally(): void
    {
        // The counterpart, so the guardrail is shown to block the prohibited
        // case rather than everything.
        $hr = BusinessFunction::where('function_code', 'BF-HR-01')->value('id');

        $result = $this->intake->submit($this->engagementAttributes(), [$hr], $this->answers());

        $this->assertSame(1, Engagement::count());
        $this->assertSame(EngagementStatus::IntakeSubmitted, $result->engagement->status);
        $this->assertMatchesRegularExpression('/^ENG-\d{4}-0001$/', $result->engagement->reference);
    }

    /* ------------------------------------------------------------------ */
    /*  AC-02 — knockout floors, end to end                                */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function ac02_core_banking_connectivity_tiers_critical_and_persists_the_citation(): void
    {
        $hr = BusinessFunction::where('function_code', 'BF-HR-01')->value('id');

        // Answers chosen to score Moderate on the weighted model while
        // answering "privileged / core banking connectivity" to A5.
        $result = $this->intake->submit(
            $this->engagementAttributes(),
            [$hr],
            $this->answers(['A5' => 'privileged']),
        );

        $outcome = $result->outcome;

        $this->assertSame(RiskTier::Critical, $outcome->effectiveTier);
        $this->assertTrue($outcome->tierRaisedByKnockout());

        $codes = array_column($outcome->knockouts->toArray(), 'code');
        $this->assertContains('KO-CORE-CONN', $codes);

        // The derivation is PERSISTED, so the panel renders the same figures
        // a reviewer sees later (AC-15's precondition).
        $engagement = $result->engagement->refresh();
        $this->assertSame(RiskTier::Critical, $engagement->effective_tier);

        $version = InherentAssessment::where('engagement_id', $engagement->id)->where('is_current', true)->firstOrFail();
        $fired = collect($version->knockouts_fired)->firstWhere('code', 'KO-CORE-CONN');

        $this->assertNotNull($fired);
        $this->assertSame('CBN Cyber Framework App. II §1.4, App. III §1.3', $fired['citation']);

        $run = ScoreRun::where('engagement_id', $engagement->id)->firstOrFail();
        $this->assertSame('knockout', $run->explanation['decided_by']);
        $this->assertSame(config('tprm.engine_version'), $run->explanation['engine_version']);
    }

    #[Test]
    public function ac02_the_tier_does_not_fall_when_the_weighted_score_falls(): void
    {
        $hr = BusinessFunction::where('function_code', 'BF-HR-01')->value('id');

        // The lowest-risk answers the questionnaire allows, still with core
        // banking connectivity. The weighted score is as low as it goes; the
        // tier must not move.
        $result = $this->intake->submit(
            $this->engagementAttributes(),
            [$hr],
            [
                'A1' => 'none', 'A2' => 'none', 'A3' => 'domestic', 'A5' => 'privileged',
                'A7' => 'standard', 'A8' => 'over_72h', 'A10' => [], 'A12' => 'many',
                'A13' => 'under_1m', 'A14' => 'under_10m',
            ],
        );

        $this->assertLessThan(50, $result->outcome->inherent->score);
        $this->assertSame(RiskTier::Critical, $result->outcome->effectiveTier);
    }

    /* ------------------------------------------------------------------ */
    /*  Versioning and the preview                                         */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function retiering_supersedes_the_previous_version_rather_than_overwriting_it(): void
    {
        // FR-TIER-08: every recomputation is a version with a diff. "Why was
        // this vendor Moderate in March" is answerable only while March's
        // answers survive.
        $hr = BusinessFunction::where('function_code', 'BF-HR-01')->value('id');
        $result = $this->intake->submit($this->engagementAttributes(), [$hr], $this->answers());

        $engagement = $result->engagement;
        $firstScore = $engagement->refresh()->inherent_score;

        app(\App\Services\Tprm\Scoring\TieringService::class)
            ->tier($engagement, $this->answers(['A1' => 'restricted', 'A2' => 'over_1m']));

        $versions = InherentAssessment::where('engagement_id', $engagement->id)->orderBy('version')->get();

        $this->assertCount(2, $versions);
        $this->assertFalse((bool) $versions[0]->is_current);
        $this->assertTrue((bool) $versions[1]->is_current);

        // The old version's answers are intact.
        $this->assertSame('internal', $versions[0]->answers['A1']);
        $this->assertSame('restricted', $versions[1]->answers['A1']);
        $this->assertNotSame($firstScore, $engagement->refresh()->inherent_score);

        // And each recomputation left its own immutable run.
        $this->assertSame(2, ScoreRun::where('engagement_id', $engagement->id)->count());
    }

    #[Test]
    public function the_live_preview_computes_a_tier_without_persisting_anything(): void
    {
        // FR-INT-02: the tier is shown to the requester before submission.
        $hr = BusinessFunction::where('function_code', 'BF-HR-01')->value('id');

        $outcome = $this->intake->previewTier(
            $this->engagementAttributes(),
            [$hr],
            $this->answers(['A5' => 'privileged'])
        );

        $this->assertSame(RiskTier::Critical, $outcome->effectiveTier);

        // Nothing was written. A preview that leaves rows behind fills the
        // register with abandoned drafts.
        $this->assertSame(0, Engagement::count());
        $this->assertSame(0, ScoreRun::count());
        $this->assertSame(0, InherentAssessment::count());
    }

    #[Test]
    public function the_preview_and_the_submission_agree(): void
    {
        // A preview that used a simplified path would show one tier and
        // persist another, which is worse than showing no preview at all.
        $hr = BusinessFunction::where('function_code', 'BF-HR-01')->value('id');
        $answers = $this->answers(['A1' => 'restricted', 'A2' => 'over_1m', 'A5' => 'network_api']);

        $preview = $this->intake->previewTier($this->engagementAttributes(), [$hr], $answers);
        $submitted = $this->intake->submit($this->engagementAttributes(), [$hr], $answers);

        $this->assertSame($preview->effectiveTier, $submitted->outcome->effectiveTier);
        $this->assertSame(
            round($preview->inherent->score, 4),
            round($submitted->outcome->inherent->score, 4)
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Approval routing                                                   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_critical_function_engagement_cannot_be_approved_without_an_executive_sponsor(): void
    {
        // FR-INT-08.
        $core = BusinessFunction::where('function_code', 'BF-CORE-01')->value('id');
        $result = $this->intake->submit($this->engagementAttributes(), [$core], $this->answers());

        $engagement = $result->engagement->refresh();
        $this->assertTrue((bool) $engagement->supports_critical_function);

        $refused = $this->intake->approve($engagement);
        $this->assertFalse($refused['approved']);
        $this->assertStringContainsString('executive sponsor', $refused['reason']);
        $this->assertSame(EngagementStatus::IntakeSubmitted, $engagement->refresh()->status);
    }

    #[Test]
    public function a_rejected_intake_is_retained_with_its_reason(): void
    {
        // FR-INT-07: retained, not deleted — it is evidence the institution
        // considered and declined an arrangement.
        $hr = BusinessFunction::where('function_code', 'BF-HR-01')->value('id');
        $engagement = $this->intake->submit($this->engagementAttributes(), [$hr], $this->answers())->engagement;

        $this->assertTrue($this->intake->reject($engagement, 'insufficient_controls', 'No ISO 27001 and no SOC 2.'));

        $this->assertSame(1, Engagement::count());
        $this->assertSame(EngagementStatus::Draft, $engagement->refresh()->status);

        $audit = AuditLog::where('event', 'intake_rejected')->firstOrFail();
        $this->assertSame('insufficient_controls', $audit->after['reason_code']);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function engagementAttributes(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'third_party_id' => $this->vendor->id,
            'name' => 'Back office support',
            'engagement_type' => 'outsourcing',
            'currency' => 'NGN',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function answers(array $overrides = []): array
    {
        return $overrides + [
            'A1' => 'internal', 'A2' => '1k_100k', 'A3' => 'domestic', 'A5' => 'read_only',
            'A7' => 'standard', 'A8' => 'over_72h', 'A10' => ['cbn_cyber'],
            'A12' => 'many', 'A13' => 'under_1m', 'A14' => 'under_10m',
        ];
    }
}
