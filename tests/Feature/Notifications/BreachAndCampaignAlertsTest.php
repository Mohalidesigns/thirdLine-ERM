<?php

namespace Tests\Feature\Notifications;

use App\Models\AssessmentCampaign;
use App\Models\BusinessUnit;
use App\Models\CampaignAssignment;
use App\Models\MeasureBreach;
use App\Models\User;
use App\Support\Measures\MeasureCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\TestCase;

/**
 * WP-13 — the two places where something important happened and nobody was
 * told.
 *
 * 1. A KRI breach entered BY HAND raised no event at all. The nightly
 *    kri:check-breaches sweep dispatched KriBreachDetected; the manual entry
 *    screen, which is how a red reading actually reaches this system on the
 *    day it matters, appended a sentence to a flash message and stopped. Both
 *    paths now converge on MeasureService::reconcileBreach(), the only code
 *    that can tell a NEW breach from one that is merely still open, so the
 *    event fires once per crossing from either direction.
 *
 * 2. Launching an RCSA campaign told none of its respondents. Two hundred
 *    people were given work with a deadline and the only signal was somebody
 *    remembering to email them.
 *
 * These tests go through the CONTROLLERS rather than calling the services,
 * because the gap was never in the services — every one of these paths wrote
 * its rows correctly and simply told nobody, and only the controller shows
 * that.
 */
class BreachAndCampaignAlertsTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Early in the fiscal year, so the readings below land in open periods
        // and a KRI created here precedes them.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 09:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-01-05 09:00:00'));

        $this->bootDomainFixtures();
        $this->bootMeasureEngine();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // super-admin passes the permission middleware on every route below.
        // Deliberately NOT risk-manager or chief-risk-officer: those are the
        // oversight roles SendNotification adds to a red breach, and giving
        // the actor one would make "who was told" ambiguous.
        $this->actor->assignRole('super-admin');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    private function makeUser(string $label): User
    {
        return User::create([
            'name' => $label,
            'email' => str($label)->slug().'-'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
    }

    private function businessUnit(): BusinessUnit
    {
        return BusinessUnit::firstOrCreate(
            ['organization_id' => $this->organization->id, 'code' => 'OPS'],
            ['name' => 'Operations']
        );
    }

    private function campaign(array $attributes = []): AssessmentCampaign
    {
        return AssessmentCampaign::create(array_merge([
            'organization_id' => $this->organization->id,
            'campaign_code' => 'CAM-'.uniqid(),
            'title' => 'Q1 RCSA',
            'campaign_type' => 'rcsa',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
            'status' => 'draft',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function assign(AssessmentCampaign $campaign, User $respondent, array $attributes = []): CampaignAssignment
    {
        return CampaignAssignment::create(array_merge([
            'campaign_id' => $campaign->id,
            'business_unit_id' => $this->businessUnit()->id,
            'respondent_id' => $respondent->id,
            'due_date' => '2026-03-25',
            'status' => 'pending',
        ], $attributes));
    }

    /** A higher-is-worse KRI: green below 5, amber 5-10, red at 10 and above. */
    private function breachableKri(User $owner)
    {
        return $this->makeKri([
            'owner_id' => $owner->id,
            'green_threshold_max' => 5,
            'amber_threshold_min' => 5,
            'amber_threshold_max' => 10,
            'red_threshold_min' => 10,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Assertions */
    /* ------------------------------------------------------------------ */

    /** @return \Illuminate\Support\Collection<int, object> */
    private function logged(string $type, ?int $userId = null)
    {
        return DB::table('notifications_log')
            ->where('type', $type)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('id')
            ->get();
    }

    /* ------------------------------------------------------------------ */
    /*  Gap 1 — the manual KRI path */
    /* ------------------------------------------------------------------ */

    /**
     * The headline regression: a risk officer types in a red reading and the
     * KRI's owner is told. Before this, nothing on this path raised an event.
     */
    #[Test]
    public function a_measurement_entered_by_hand_notifies_the_kri_owner(): void
    {
        $owner = $this->makeUser('KRI Owner');
        $kri = $this->breachableKri($owner);

        $this->actingAs($this->actor)
            ->post(route('risk.kri.record-measurement', $kri), [
                'measurement_date' => '2026-01-05',
                'value' => 12,
            ])
            ->assertRedirect();

        $rows = $this->logged('kri_breach', $owner->id);

        $this->assertCount(1, $rows, 'A red reading entered through the KRI screen must reach its owner.');

        $row = $rows->first();

        // The columns, not the metadata blob: NotificationController reads the
        // columns, and a notification with a NULL action_url is unclickable.
        $this->assertSame('breach_alert', $row->notification_category);
        $this->assertSame('critical', $row->priority);
        $this->assertSame("/risk/kri/{$kri->id}", $row->action_url);
        $this->assertStringContainsString($kri->kri_code, $row->subject);

        // And the breach itself is registered, not just announced.
        $this->assertSame(1, MeasureBreach::count());
    }

    /**
     * The reason the event lives at the point that knows a breach is NEWLY
     * open. A limit that stays breached is one breach, however many readings
     * are taken against it — re-announcing every reading is what taught people
     * to ignore the bell.
     */
    #[Test]
    public function a_second_reading_while_still_breached_announces_nothing(): void
    {
        $owner = $this->makeUser('KRI Owner');
        $kri = $this->breachableKri($owner);

        $this->actingAs($this->actor);

        $this->post(route('risk.kri.record-measurement', $kri), [
            'measurement_date' => '2026-01-05',
            'value' => 12,
        ]);

        // Still over the limit, same period, worse number.
        $this->post(route('risk.kri.record-measurement', $kri), [
            'measurement_date' => '2026-01-20',
            'value' => 14,
        ]);

        $this->assertCount(1, $this->logged('kri_breach', $owner->id));
        $this->assertSame(1, MeasureBreach::count(), 'One breach, two readings.');
    }

    /**
     * Recovery and relapse is a new breach, and has to be announced. The
     * register keeps one row per band per period and reopens it, so
     * wasRecentlyCreated alone would have missed this entirely.
     */
    #[Test]
    public function recovering_and_breaching_again_announces_a_new_breach(): void
    {
        $owner = $this->makeUser('KRI Owner');
        $kri = $this->breachableKri($owner);

        $this->actingAs($this->actor);

        $this->post(route('risk.kri.record-measurement', $kri), [
            'measurement_date' => '2026-01-05',
            'value' => 12,
        ]);

        // Back inside appetite: the open breach is resolved.
        $this->post(route('risk.kri.record-measurement', $kri), [
            'measurement_date' => '2026-01-12',
            'value' => 2,
        ]);

        $this->assertSame('resolved', MeasureBreach::firstOrFail()->status);
        $this->assertCount(1, $this->logged('kri_breach', $owner->id));

        // Over again.
        $this->post(route('risk.kri.record-measurement', $kri), [
            'measurement_date' => '2026-01-19',
            'value' => 11,
        ]);

        $this->assertSame('open', MeasureBreach::firstOrFail()->status);
        $this->assertCount(2, $this->logged('kri_breach', $owner->id), 'A relapse is a fresh breach.');
    }

    /**
     * Every measure in the platform reconciles breaches through the same
     * method — capital, inflation, an inherent risk score. KriBreachDetected
     * is typed on KeyRiskIndicator and has nowhere to put those, so they are
     * registered and not announced. The failure mode being guarded against is
     * an exception on a write that had nothing to do with a KRI.
     */
    #[Test]
    public function a_breach_on_a_measure_with_no_kri_behind_it_notifies_nobody(): void
    {
        $risk = $this->makeRisk(['risk_owner_id' => $this->makeUser('Risk Owner')->id]);
        $measure = $this->measures()->requireMeasure(MeasureCatalog::RISK_INHERENT_SCORE);

        $this->attachThreshold($measure, [
            ['code' => 'green', 'label' => 'Within appetite', 'min' => null, 'max' => 10],
            ['code' => 'red', 'label' => 'Breach', 'min' => 10, 'max' => null],
        ]);

        $value = $this->measures()->record($measure, $risk, $this->quarter('2026-01-05'), 20.0, [
            'entered_by' => $this->actor->id,
        ]);

        $this->assertSame('red', $value->rag_band);
        $this->assertSame(1, MeasureBreach::count(), 'The breach is still registered.');
        $this->assertCount(0, $this->logged('kri_breach'));
        $this->assertSame(0, DB::table('key_risk_indicators')->count(), 'No KRI was invented for it.');
    }

    /**
     * The nightly sweep, which is the path that DID work. It must keep working
     * and must not now announce twice — reconcileBreach() raises the event, so
     * the command's own dispatch was removed when this one was added.
     */
    #[Test]
    public function the_nightly_sweep_still_announces_each_new_breach_exactly_once(): void
    {
        $owner = $this->makeUser('KRI Owner');

        // No thresholds yet, so the reading lands unbanded and no breach opens:
        // limits configured after the fact is the ordinary shape of an import.
        $kri = $this->makeKri(['owner_id' => $owner->id]);

        app(\App\Services\KriMeasureBridge::class)->recordMeasurement($kri, '2026-01-05', 12.0, [
            'entered_by' => $this->actor->id,
        ]);

        $this->assertSame(0, MeasureBreach::count());
        $this->assertCount(0, $this->logged('kri_breach'));

        // The limits arrive. Now the sweep has something to find.
        $measure = app(\App\Services\KriMeasureBridge::class)->measureFor($kri);
        $this->attachThreshold($measure, [
            ['code' => 'green', 'label' => 'Within appetite', 'min' => null, 'max' => 10],
            ['code' => 'red', 'label' => 'Breach', 'min' => 10, 'max' => null],
        ], '2020-01-01', 'higher_worse', $this->measures()->objectIdFor($kri));

        $this->artisan('kri:check-breaches')->assertSuccessful();

        $this->assertSame(1, MeasureBreach::count());
        $this->assertCount(1, $this->logged('kri_breach', $owner->id), 'Exactly one — not one per dispatcher.');

        // Tomorrow night, same reading, same open breach.
        $this->artisan('kri:check-breaches')->assertSuccessful();

        $this->assertCount(1, $this->logged('kri_breach', $owner->id));
    }

    /* ------------------------------------------------------------------ */
    /*  Gap 2 — campaigns */
    /* ------------------------------------------------------------------ */

    /** The biggest single gap: N respondents, N notifications. */
    #[Test]
    public function launching_a_campaign_notifies_every_respondent(): void
    {
        $campaign = $this->campaign();

        $respondents = collect(['Alpha', 'Bravo', 'Charlie'])
            ->map(fn (string $label) => $this->makeUser($label.' Respondent'));

        $respondents->each(fn (User $user) => $this->assign($campaign, $user));

        $this->actingAs($this->actor)
            ->post(route('risk.campaigns.launch', $campaign))
            ->assertRedirect();

        $rows = $this->logged('campaign_launched');

        // Asserted on the rows, not on a queued job: sendMany() hands anything
        // over one recipient to FanOutNotificationsJob, and phpunit.xml runs
        // the queue synchronously, so the rows are the observable outcome
        // either way.
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(
            $respondents->pluck('id')->all(),
            $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all()
        );

        $row = $rows->first();
        $this->assertSame('assessment', $row->notification_category);
        $this->assertSame('high', $row->priority);
        $this->assertSame("/risk/campaigns/{$campaign->id}", $row->action_url);
    }

    /** Nobody is asked to do work they have already handed in. */
    #[Test]
    public function launching_skips_respondents_who_have_already_submitted(): void
    {
        $campaign = $this->campaign();

        $outstanding = $this->makeUser('Still Owing');
        $done = $this->makeUser('Already Filed');
        $approved = $this->makeUser('Already Approved');

        $this->assign($campaign, $outstanding);
        $this->assign($campaign, $done, ['status' => 'submitted', 'submitted_at' => now()]);
        $this->assign($campaign, $approved, ['status' => 'approved', 'submitted_at' => now()]);

        $this->actingAs($this->actor)->post(route('risk.campaigns.launch', $campaign));

        $this->assertSame(
            [$outstanding->id],
            $this->logged('campaign_launched')->pluck('user_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    #[Test]
    public function adding_an_assignment_notifies_the_respondent(): void
    {
        $campaign = $this->campaign();
        $respondent = $this->makeUser('New Respondent');

        $this->actingAs($this->actor)
            ->post(route('risk.campaigns.add-assignment', $campaign), [
                'business_unit_id' => $this->businessUnit()->id,
                'respondent_id' => $respondent->id,
                'due_date' => '2026-03-25',
            ])
            ->assertRedirect();

        $rows = $this->logged('campaign_assignment', $respondent->id);
        $this->assertCount(1, $rows);

        $assignment = CampaignAssignment::firstOrFail();

        $this->assertSame('assessment', $rows->first()->notification_category);
        $this->assertSame(
            "/risk/campaigns/assignments/{$assignment->id}/respond",
            $rows->first()->action_url
        );
    }

    #[Test]
    public function reviewing_an_assignment_tells_the_respondent_the_outcome(): void
    {
        $campaign = $this->campaign(['status' => 'active']);
        $respondent = $this->makeUser('Respondent');
        $assignment = $this->assign($campaign, $respondent, ['status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($this->actor)
            ->post(route('risk.campaigns.review-assignment', $assignment), [
                'action' => 'approve',
                'reviewer_notes' => 'Clear enough.',
            ])
            ->assertRedirect();

        $approvals = $this->logged('campaign_assignment_approved', $respondent->id);
        $this->assertCount(1, $approvals);
        $this->assertStringContainsString('Clear enough.', $approvals->first()->body);

        // A rejection is rework, so it is louder and points at the form.
        $second = $this->assign($campaign, $respondent, [
            'business_unit_id' => BusinessUnit::create([
                'organization_id' => $this->organization->id,
                'code' => 'FIN',
                'name' => 'Finance',
            ])->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->post(route('risk.campaigns.review-assignment', $second), [
            'action' => 'reject',
            'reviewer_notes' => 'Scores unsupported.',
        ]);

        $rejections = $this->logged('campaign_assignment_rejected', $respondent->id);
        $this->assertCount(1, $rejections);
        $this->assertSame('high', $rejections->first()->priority);
        $this->assertSame(
            "/risk/campaigns/assignments/{$second->id}/respond",
            $rejections->first()->action_url
        );
    }

    /**
     * The campaign screen is the second way to submit a worksheet, and it used
     * to raise nothing — so whether a submission reached a reviewer's queue
     * depended on which of two screens the respondent happened to use.
     */
    #[Test]
    public function submitting_from_the_campaign_screen_reaches_the_reviewer(): void
    {
        $reviewer = $this->makeUser('Reviewer');
        $respondent = $this->makeUser('Respondent');

        $campaign = $this->campaign(['status' => 'active', 'reviewer_id' => $reviewer->id]);
        $assignment = $this->assign($campaign, $respondent, ['reviewer_id' => $reviewer->id]);

        $risk = $this->makeRisk();

        $this->actingAs($this->actor)
            ->post(route('risk.campaigns.submit-response', $assignment), [
                'responses' => [
                    ['risk_id' => $risk->id, 'likelihood_score' => 3, 'impact_score' => 4],
                ],
            ])
            ->assertRedirect();

        $rows = $this->logged('rcsa_worksheet_submitted', $reviewer->id);

        $this->assertCount(1, $rows, 'A submission from the campaign screen must queue the same review as the RCSA screen.');
        $this->assertStringContainsString($campaign->campaign_code, $rows->first()->subject);
    }
}
