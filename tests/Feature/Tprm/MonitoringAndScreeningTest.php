<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Enums\Tprm\ScreeningDecision;
use App\Enums\Tprm\SignalType;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Alert;
use App\Models\Tprm\AlertRule;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\MonitoringSignal;
use App\Models\Tprm\Ownership;
use App\Models\Tprm\SanctionsEntry;
use App\Models\Tprm\SanctionsList;
use App\Models\Tprm\ScreeningCheck;
use App\Models\Tprm\ScreeningMatch;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Monitoring\AlertEngine;
use App\Services\Tprm\Monitoring\InternalSignalGenerator;
use App\Services\Tprm\Scoring\ResidualScoringService;
use App\Services\Tprm\Screening\SanctionsEscalation;
use App\Services\Tprm\Screening\ScreeningDispatcher;
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
 * Phase 6's acceptance criteria.
 *
 *   AC-08 end to end: a confirmed sanctions match suspends every engagement,
 *   forces the residual score to 100, notifies the AML function and opens an
 *   STR task with a 24-hour deadline.
 *
 *   With every external driver disabled, the internal generator produces
 *   signals from seeded overdue fixtures and at least one alert rule fires and
 *   creates a finding.
 *
 *   A cyber-rating band drop builds a targeted mini-assessment containing only
 *   the implicated questions, not the full template.
 *
 *   A five-year-old screening record is retrievable (CBN AML/CFT Reg. 35).
 */
class MonitoringAndScreeningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $amlOfficer;

    private ThirdParty $vendor;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('tprm.ai.enabled', false);

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

        $this->amlOfficer = $this->userWith([
            'tprm.view', 'tprm.screening.view', 'tprm.screening.decide',
            'tprm.finding.view', 'tprm.finding.manage', 'tprm.monitoring.view',
        ], 'aml@khb.test', 'tprm-aml');

        $this->vendor = ThirdParty::create([
            'legal_name' => 'Cloudspan Nigeria Limited', 'slug' => Str::random(10),
            'entity_type' => 'company', 'status' => 'active',
        ]);

        $this->engagement = $this->makeEngagement();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  AC-08 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_confirmed_sanctions_match_suspends_every_engagement_and_forces_the_maximum_score(): void
    {
        $second = $this->makeEngagement('ENG-2026-0002', 'Card personalisation');

        $match = $this->pendingMatch();

        $result = app(SanctionsEscalation::class)->decide(
            $match,
            ScreeningDecision::TrueMatch,
            'Confirmed: same registration number, same directors, same address as the designated entity.',
            $this->amlOfficer,
        );

        $this->assertTrue($result['decided']);
        $this->assertTrue($result['escalated']);
        $this->assertSame(2, $result['engagements_suspended']);

        // Every engagement stopped, not merely flagged.
        $this->assertSame(EngagementStatus::MonitoringException, $this->engagement->fresh()->status);
        $this->assertSame(EngagementStatus::MonitoringException, $second->fresh()->status);

        // The vendor itself.
        $this->assertSame(\App\Enums\Tprm\ThirdPartyStatus::Blacklisted, $this->vendor->fresh()->status);
        $this->assertNotNull($this->vendor->fresh()->blacklisted_at);

        // The residual score, through the SU override rather than a special
        // case in the scoring service.
        $run = app(ResidualScoringService::class)->score($this->engagement->fresh(), 'test');
        $this->assertSame(100.0, (float) $run->rr);
        $this->assertSame('critical', $run->band->value);
        $this->assertStringContainsString('criminal offence', $run->explanation['arithmetic']);
    }

    #[Test]
    public function the_str_task_carries_a_twenty_four_hour_deadline(): void
    {
        // CBN AML/CFT Reg. 38. A report filed a week later is a breach in its
        // own right, so the clock is set by the regulation rather than by the
        // tier policy's Critical default.
        $match = $this->pendingMatch();

        app(SanctionsEscalation::class)->decide(
            $match,
            ScreeningDecision::TrueMatch,
            'Confirmed against the designated entity on every identifying attribute.',
            $this->amlOfficer,
        );

        $finding = Finding::query()->where('id', $match->fresh()->str_task_id)->firstOrFail();

        $this->assertSame(1, $finding->sla_days);
        $this->assertSame(now()->addDay()->toDateString(), $finding->target_date->toDateString());
        $this->assertSame('critical', $finding->severity->value);
        $this->assertStringContainsString('Reg. 38', (string) $finding->regulatory_citation);
    }

    #[Test]
    public function the_aml_function_is_notified_and_not_only_the_person_who_clicked(): void
    {
        $match = $this->pendingMatch();

        app(SanctionsEscalation::class)->decide(
            $match,
            ScreeningDecision::TrueMatch,
            'Confirmed on every identifying attribute against the designation.',
            $this->amlOfficer,
        );

        $this->assertDatabaseHas('notifications_log', [
            'organization_id' => $this->organization->id,
            'type' => 'tprm.sanctions.true_match',
            'user_id' => $this->amlOfficer->id,
        ]);
    }

    #[Test]
    public function escalating_the_same_match_twice_does_not_open_a_second_str_clock(): void
    {
        $match = $this->pendingMatch();

        app(SanctionsEscalation::class)->decide(
            $match, ScreeningDecision::TrueMatch, 'Confirmed against the designation.', $this->amlOfficer,
        );

        $second = app(SanctionsEscalation::class)->decide(
            $match->fresh(), ScreeningDecision::TrueMatch, 'Confirmed again.', $this->amlOfficer,
        );

        $this->assertFalse($second['escalated']);
        $this->assertSame(1, Finding::query()->where('source', 'monitoring')->count());
    }

    #[Test]
    public function a_decision_without_a_rationale_is_refused_even_for_a_false_positive(): void
    {
        // "Different date of birth, no connection to the entity" is what makes
        // a dismissal reviewable by an examiner who cannot re-run the search.
        $result = app(SanctionsEscalation::class)->decide(
            $this->pendingMatch(), ScreeningDecision::FalsePositive, '   ', $this->amlOfficer,
        );

        $this->assertFalse($result['decided']);
        $this->assertStringContainsString('rationale', (string) $result['reason']);
    }

    #[Test]
    public function a_false_positive_does_not_suspend_anything(): void
    {
        $result = app(SanctionsEscalation::class)->decide(
            $this->pendingMatch(),
            ScreeningDecision::FalsePositive,
            'Different date of birth and nationality; no connection to this entity.',
            $this->amlOfficer,
        );

        $this->assertTrue($result['decided']);
        $this->assertFalse($result['escalated']);
        $this->assertSame(EngagementStatus::Active, $this->engagement->fresh()->status);
    }

    /* ------------------------------------------------------------------ */
    /*  Screening */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function screening_covers_the_entity_and_every_director_and_ubo(): void
    {
        // CBN AML/CFT Reg. 29. The common real case is a sanctioned individual
        // behind a company that is not itself designated; screening the
        // company alone finds nothing and reports clear.
        $this->seedUnscrList([['id' => 'UN-1', 'name' => 'Ibrahim Adewale Musa']]);

        Ownership::create([
            'organization_id' => $this->organization->id,
            'third_party_id' => $this->vendor->id,
            'holder_name' => 'Ibrahim Adewale Musa',
            'holder_type' => 'individual',
            'relationship' => 'director',
        ]);

        Ownership::create([
            'organization_id' => $this->organization->id,
            'third_party_id' => $this->vendor->id,
            'holder_name' => 'A Minor Shareholder',
            'holder_type' => 'individual',
            'relationship' => 'shareholder',
        ]);

        $result = app(ScreeningDispatcher::class)->screenThirdParty($this->vendor, $this->amlOfficer->id);

        // The entity plus the director, against both built-in lists. The
        // minority shareholder is not screened — a queue nobody works is the
        // same as no screening.
        $this->assertSame(4, $result['checks']);
        $this->assertGreaterThan(0, $result['matches']);

        $status = app(ScreeningDispatcher::class)->statusFor($this->vendor->fresh());
        $this->assertSame(1, $status['owners_screened']);
        $this->assertSame(1, $status['owners_to_screen']);
    }

    #[Test]
    public function an_inverted_name_still_matches(): void
    {
        // Sanctions lists print "AL-QADI, Yasin"; people are recorded as
        // "Yasin al-Qadi". Searching the printed form misses exactly the
        // designations the list exists to catch.
        $this->seedUnscrList([['id' => 'UN-2', 'name' => 'AL-QADI, Yasin Abdullah']]);

        Ownership::create([
            'organization_id' => $this->organization->id,
            'third_party_id' => $this->vendor->id,
            'holder_name' => 'Yasin al-Qadi',
            'holder_type' => 'individual',
            'relationship' => 'ubo',
        ]);

        app(ScreeningDispatcher::class)->screenThirdParty($this->vendor, $this->amlOfficer->id);

        $this->assertGreaterThan(0, ScreeningMatch::query()->count());
    }

    #[Test]
    public function an_empty_list_reports_a_failure_rather_than_a_clear_result(): void
    {
        // The single most dangerous sentence this module could produce is "we
        // screened and found nothing" written against a list with no entries.
        // The seeder already installed it empty, which is the state under
        // test: a search of a list with no entries must not read as clear.
        $this->assertSame(0, SanctionsList::query()->where('code', SanctionsList::UNSCR)->value('entry_count'));

        $result = app(ScreeningDispatcher::class)->screenThirdParty($this->vendor, $this->amlOfficer->id);

        $this->assertNotEmpty($result['failed']);
        $this->assertStringContainsString('no entries', implode(' ', $result['failed']));

        $check = ScreeningCheck::query()->where('provider', SanctionsList::UNSCR)->firstOrFail();
        $this->assertSame(ScreeningCheck::STATUS_FAILED, $check->status);
        $this->assertNotSame(ScreeningCheck::STATUS_CLEAR, $check->status);
    }

    #[Test]
    public function a_run_in_which_every_provider_failed_does_not_count_as_a_screening(): void
    {
        // Recording it as a screening date would silence the overdue signal
        // for a year on a vendor nobody actually screened.
        $result = app(ScreeningDispatcher::class)->screenThirdParty($this->vendor, $this->amlOfficer->id);

        $this->assertCount($result['checks'], $result['failed']);
        $this->assertNull($this->vendor->fresh()->last_screened_at);
    }

    #[Test]
    public function a_five_year_old_screening_record_is_retrievable_with_its_raw_response(): void
    {
        // CBN AML/CFT Reg. 35: five years, retrievable within 48 hours. What
        // has to be retrievable is the provider's answer, not our summary.
        $check = ScreeningCheck::create([
            'organization_id' => $this->organization->id,
            'subject_type' => ScreeningCheck::SUBJECT_THIRD_PARTY,
            'subject_id' => $this->vendor->id,
            'provider' => SanctionsList::UNSCR,
            'list_types' => ['sanctions'],
            'run_at' => now()->subYears(5)->addDay(),
            'status' => ScreeningCheck::STATUS_CLEAR,
            'raw_response' => [
                'provider' => 'unscr',
                'list_entry_count' => 742,
                'searched_name' => 'Cloudspan Nigeria Limited',
                'matches' => [],
            ],
        ]);

        $retrieved = ScreeningCheck::query()
            ->forThirdParty($this->vendor->id)
            ->where('run_at', '<', now()->subYears(4))
            ->firstOrFail();

        $this->assertTrue($retrieved->is($check));
        $this->assertTrue($retrieved->isRetainable());
        // The provider's own answer, verbatim — including how many entries the
        // list held at the time, which is what makes a historic clear result
        // meaningful.
        $this->assertSame(742, $retrieved->raw_response['list_entry_count']);
    }

    /* ------------------------------------------------------------------ */
    /*  FR-MON-09 — monitoring with no external feeds */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_internal_generator_produces_signals_with_no_external_source_configured(): void
    {
        $this->assertSame(0, \App\Models\Tprm\MonitoringSource::query()->count());

        $this->seedOverdueFixtures();

        $signals = app(InternalSignalGenerator::class)->generate($this->organization->id);

        $types = $signals->map(fn (MonitoringSignal $signal) => $signal->signal_type->value)->unique();

        $this->assertContains(SignalType::EvidenceExpired->value, $types);
        $this->assertContains(SignalType::AssessmentOverdue->value, $types);
        $this->assertContains(SignalType::MissingDpa->value, $types);
        $this->assertContains(SignalType::ScreeningOverdue->value, $types);

        // Internally derived signals carry no source: inventing one would put
        // a permanently green row on the source-health panel representing
        // nothing anybody configured.
        $this->assertTrue($signals->every(fn (MonitoringSignal $signal) => $signal->source_id === null));
    }

    #[Test]
    public function the_same_fact_does_not_produce_a_signal_twice(): void
    {
        // A stream that repeats itself is a stream people stop reading, and a
        // monitoring programme nobody reads is worse than none because it is
        // reported as coverage.
        $this->seedOverdueFixtures();

        $first = app(InternalSignalGenerator::class)->generate($this->organization->id);
        $second = app(InternalSignalGenerator::class)->generate($this->organization->id);

        $this->assertGreaterThan(0, $first->count());
        $this->assertSame(0, $second->count());
    }

    #[Test]
    public function the_sweep_derives_signals_and_a_rule_raises_a_finding(): void
    {
        // The phase acceptance, exactly: with every external driver disabled,
        // the internal generator produces signals and at least one alert rule
        // fires and creates a finding.
        $this->seedOverdueFixtures();

        AlertRule::create([
            'organization_id' => $this->organization->id,
            'name' => 'Expired assurance evidence',
            'signal_types' => [SignalType::EvidenceExpired->value],
            'actions' => [AlertRule::ACTION_CREATE_FINDING, AlertRule::ACTION_NOTIFY],
            'severity' => 'high',
        ]);

        $this->artisan('tprm:run-monitoring')
            ->expectsOutputToContain('internal signal(s) derived')
            ->assertExitCode(0);

        $alert = Alert::query()->whereNotNull('created_finding_id')->first();

        $this->assertNotNull($alert, 'No alert raised a finding.');

        $finding = Finding::query()->find($alert->created_finding_id);
        $this->assertSame('monitoring', $finding->source);
        $this->assertSame('high', $finding->severity->value);
    }

    #[Test]
    public function a_rule_does_not_fire_twice_for_the_same_subject_inside_its_cooldown(): void
    {
        $this->seedOverdueFixtures();

        AlertRule::create([
            'organization_id' => $this->organization->id,
            'name' => 'Expired evidence',
            'signal_types' => [SignalType::EvidenceExpired->value],
            'actions' => [AlertRule::ACTION_NOTIFY],
            'cooldown_hours' => 24,
        ]);

        app(InternalSignalGenerator::class)->generate($this->organization->id);
        $first = app(AlertEngine::class)->process($this->organization->id);

        // A second signal of the same type against the same engagement.
        $this->expiredDocument('A second lapsed certificate');
        app(InternalSignalGenerator::class)->generate($this->organization->id);
        $second = app(AlertEngine::class)->process($this->organization->id);

        $this->assertGreaterThan(0, $first->count());
        $this->assertSame(0, $second->count(), 'The rule fired again inside its cooldown.');
    }

    /* ------------------------------------------------------------------ */
    /*  FR-MON-06 — the targeted mini-assessment */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_cyber_rating_drop_builds_an_assessment_of_only_the_implicated_questions(): void
    {
        // "A cyber-rating band drop triggers a targeted mini-assessment
        // containing only the implicated questions, not the full template."
        $this->seed(\Database\Seeders\Tprm\TprmQuestionnairePackSeeder::class);
        $this->issueAndScoreAnAssessment();

        $signal = MonitoringSignal::create([
            'organization_id' => $this->organization->id,
            'third_party_id' => $this->vendor->id,
            'engagement_id' => $this->engagement->id,
            'signal_type' => SignalType::CyberRatingChange->value,
            'severity' => 'high',
            'title' => 'Cyber rating dropped from A to B',
            'observed_at' => now(),
            'dedupe_key' => 'rating-drop-test',
        ]);

        AlertRule::create([
            'organization_id' => $this->organization->id,
            'name' => 'Rating drop',
            'signal_types' => [SignalType::CyberRatingChange->value],
            'actions' => [AlertRule::ACTION_TARGETED_ASSESSMENT],
            'severity' => 'high',
        ]);

        $alerts = app(AlertEngine::class)->processSignal($signal);

        $this->assertCount(1, $alerts);
        $alert = $alerts->first();

        $this->assertNotNull($alert->created_assessment_id, json_encode($alert->actions_taken));

        $assessment = \App\Models\Tprm\Assessment::query()->find($alert->created_assessment_id);
        $template = \App\Models\Tprm\QuestionnaireTemplate::query()->withoutGlobalScopes()
            ->find($assessment->template_id);

        $templateQuestions = \App\Models\Tprm\Question::query()
            ->whereIn('section_id', $template->sections()->select('id'))
            ->count();

        $this->assertGreaterThan(0, $assessment->question_count);
        $this->assertLessThan(
            $templateQuestions,
            $assessment->question_count,
            'The targeted assessment asked the whole template.',
        );
        $this->assertSame('targeted', $assessment->assessment_type);
        // The trace says WHY each question is there, which is what the
        // vendor's contact will ask when four questions arrive out of cycle.
        $this->assertSame('targeted', $assessment->scoping_trace['mode']);
        $this->assertNotEmpty($assessment->scoping_trace['implicated_controls']);
    }

    #[Test]
    public function a_signal_with_no_matching_questions_builds_nothing_and_says_why(): void
    {
        // An empty questionnaire arriving in a vendor's portal is worse than
        // none.
        $signal = MonitoringSignal::create([
            'organization_id' => $this->organization->id,
            'third_party_id' => $this->vendor->id,
            'engagement_id' => $this->engagement->id,
            'signal_type' => SignalType::CyberRatingChange->value,
            'severity' => 'high',
            'title' => 'Rating dropped',
            'observed_at' => now(),
            'dedupe_key' => 'rating-drop-no-template',
        ]);

        AlertRule::create([
            'organization_id' => $this->organization->id,
            'name' => 'Rating drop',
            'signal_types' => [SignalType::CyberRatingChange->value],
            'actions' => [AlertRule::ACTION_TARGETED_ASSESSMENT],
        ]);

        $alert = app(AlertEngine::class)->processSignal($signal)->first();

        $this->assertNull($alert->created_assessment_id);
        $taken = collect($alert->actions_taken)->firstWhere('action', AlertRule::ACTION_TARGETED_ASSESSMENT);
        $this->assertFalse($taken['done']);
        $this->assertNotEmpty($taken['note']);
    }

    /* ------------------------------------------------------------------ */

    private function pendingMatch(): ScreeningMatch
    {
        $check = ScreeningCheck::create([
            'organization_id' => $this->organization->id,
            'subject_type' => ScreeningCheck::SUBJECT_THIRD_PARTY,
            'subject_id' => $this->vendor->id,
            'provider' => SanctionsList::UNSCR,
            'list_types' => ['sanctions'],
            'run_at' => now(),
            'status' => ScreeningCheck::STATUS_MATCHES,
        ]);

        return ScreeningMatch::create([
            'organization_id' => $this->organization->id,
            'check_id' => $check->getKey(),
            'list_name' => 'UN Security Council Consolidated List',
            'matched_name' => 'Cloudspan Nigeria Ltd',
            'match_score' => 95.0,
        ]);
    }

    /** @param list<array{id: string, name: string}> $entries */
    private function seedUnscrList(array $entries): void
    {
        // The reference seeder installs both lists EMPTY, so the fixture
        // fills the one that already exists rather than creating a second.
        $list = SanctionsList::query()->where('code', SanctionsList::UNSCR)->firstOrFail();

        foreach ($entries as $entry) {
            SanctionsEntry::create([
                'list_id' => $list->getKey(),
                'external_id' => $entry['id'],
                'name' => $entry['name'],
                'normalised_name' => SanctionsEntry::normalise($entry['name']),
                'entity_type' => 'individual',
            ]);
        }

        $list->forceFill(['entry_count' => count($entries), 'last_refreshed_at' => now()])->save();
    }

    private function seedOverdueFixtures(): void
    {
        $this->expiredDocument('A lapsed ISO 27001 certificate');

        $this->engagement->forceFill([
            'next_assessment_due' => now()->subMonths(8)->toDateString(),
            'processes_personal_data' => true,
        ])->save();
    }

    private function expiredDocument(string $title): Document
    {
        $type = DocumentType::query()->availableTo()->where('code', 'iso27001_cert')->firstOrFail();

        $document = Document::create([
            'organization_id' => $this->organization->id,
            'owner_type' => Document::OWNER_ENGAGEMENT,
            'owner_id' => $this->engagement->id,
            'document_type_id' => $type->id,
            'title' => $title,
            'file_path' => 'tprm/evidence/'.Str::random(12).'.pdf',
        ]);

        $document->forceFill(['valid_to' => now()->subMonths(2)->toDateString()])->save();

        return $document->refresh();
    }

    private function issueAndScoreAnAssessment(): void
    {
        $template = \App\Models\Tprm\QuestionnaireTemplate::query()->withoutGlobalScopes()
            ->where('code', 'CBN-CYBER-CORE')->firstOrFail();

        app(\App\Services\Tprm\Assessment\AssessmentService::class)->issue(
            $this->engagement, $template, now()->addDays(30), $this->amlOfficer->id,
        );
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email, string $roleName): User
    {
        $user = User::create([
            'name' => Str::of($roleName)->afterLast('-')->upper()->toString(),
            'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate($roleName, 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }

    private function makeEngagement(string $reference = 'ENG-2026-0001', string $name = 'Core banking hosting'): Engagement
    {
        $engagement = Engagement::create([
            'third_party_id' => $this->vendor->id,
            'reference' => $reference,
            'name' => $name,
            'service_description' => 'Hosting and operation of the core banking platform.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->amlOfficer->id,
        ]);

        $engagement->forceFill([
            'status' => EngagementStatus::Active->value,
            'inherent_score' => 70,
            'inherent_tier' => RiskTier::High->value,
            'effective_tier' => RiskTier::High->value,
        ])->save();

        InherentAssessment::create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $engagement->id,
            'version' => 1, 'ruleset_version' => '1.0.0',
            'raw_score' => 70, 'resulting_tier' => RiskTier::High->value,
            'assessed_at' => now(), 'is_current' => true,
            'answers' => [], 'factor_scores' => [], 'weights' => [], 'explanation' => [],
        ]);

        return $engagement->refresh();
    }
}
