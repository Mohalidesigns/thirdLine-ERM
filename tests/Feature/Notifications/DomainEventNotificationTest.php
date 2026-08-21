<?php

namespace Tests\Feature\Notifications;

use App\Events\ControlUpdated;
use App\Events\KriBreachDetected;
use App\Events\RcsaWorksheetSubmitted;
use App\Events\TreatmentCompleted;
use App\Listeners\SendNotification;
use App\Models\AssessmentCampaign;
use App\Models\BusinessUnit;
use App\Models\CampaignAssignment;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use App\Models\Organization;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-13 — domain-event notifications reach the right person, from a worker.
 *
 * Every test here calls the listener DIRECTLY with no acting user. That is the
 * point: the listener is ShouldQueue, so in production it runs in a worker
 * process with no session. The previous implementation read the recipient from
 * auth()->id(), found null, logged a warning and returned — leaving a
 * successful job and an empty queue, so nothing showed up in Horizon or
 * failed_jobs either.
 *
 * The suite never caught it because phpunit.xml sets QUEUE_CONNECTION=sync,
 * which runs the listener inline inside the request where actingAs() has put a
 * user on the guard. Not calling actingAs() is therefore not incidental to
 * these tests — it IS the test. Do not add one.
 */
class DomainEventNotificationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private int $kriSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();
    }

    /** Run the listener the way a queue worker would: no session, no tenant. */
    private function handleOnAWorker(object $event): void
    {
        auth()->logout();
        TenantContext::clear();

        (new SendNotification)->handle($event);
    }

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

    /** @return list<int> */
    private function recipientsOf(string $type): array
    {
        return DB::table('notifications_log')
            ->where('type', $type)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */

    /**
     * The regression that matters most. With no authenticated user at all, a
     * notification must still be written — because the recipient comes from
     * the event, not the request.
     */
    #[Test]
    public function a_notification_is_written_when_no_user_is_authenticated(): void
    {
        $owner = $this->makeUser('Control Owner');
        $control = $this->makeControl(['owner_id' => $owner->id]);

        $this->handleOnAWorker(new ControlUpdated($control, ['effectiveness_pct']));

        $this->assertSame([$owner->id], $this->recipientsOf('control_updated'));
    }

    /** The notification goes to the owner, not to whoever happened to act. */
    #[Test]
    public function the_recipient_is_the_owner_not_the_actor(): void
    {
        $owner = $this->makeUser('Control Owner');
        $actor = $this->makeUser('Someone Else');
        $control = $this->makeControl(['owner_id' => $owner->id]);

        // An acting user is present AND is not the owner. The old code would
        // have written the row to $actor.
        $this->actingAs($actor);

        (new SendNotification)->handle(new ControlUpdated($control, []));

        $recipients = $this->recipientsOf('control_updated');

        $this->assertContains($owner->id, $recipients);
        $this->assertNotContains($actor->id, $recipients, 'The actor was notified about their own action.');
    }

    /**
     * The listener writes through NotificationService, so category, priority
     * and action_url land in their COLUMNS. The old hand-rolled insert buried
     * them in the metadata JSON and left the columns null — and
     * NotificationController reads the column, so every one of these
     * notifications was unclickable.
     */
    #[Test]
    public function the_notification_is_clickable_and_categorised(): void
    {
        $owner = $this->makeUser('Control Owner');
        $control = $this->makeControl(['owner_id' => $owner->id]);

        $this->handleOnAWorker(new ControlUpdated($control, []));

        $row = DB::table('notifications_log')->where('type', 'control_updated')->first();

        $this->assertNotNull($row->action_url, 'action_url column is null, so the bell entry does not link anywhere.');
        $this->assertSame('/risk/controls/'.$control->id, $row->action_url);
        $this->assertSame('control', $row->notification_category);
        $this->assertSame('medium', $row->priority);
    }

    /**
     * A red KRI breach is a board-level number, so oversight roles are told as
     * well as the owner. This is the path that fires from `kri:check-breaches`
     * — a scheduled command with no user — which is why it was doubly broken.
     */
    #[Test]
    public function a_red_kri_breach_reaches_the_owner_and_oversight(): void
    {
        $kriOwner = $this->makeUser('KRI Owner');
        $cro = $this->makeUser('Chief Risk Officer');
        $bystander = $this->makeUser('Unrelated Analyst');

        Role::findOrCreate('chief-risk-officer');
        $cro->assignRole('chief-risk-officer');

        [$kri, $measurement] = $this->makeKriWithMeasurement($kriOwner->id);

        $this->handleOnAWorker(new KriBreachDetected($kri, $measurement, 'red'));

        $recipients = $this->recipientsOf('kri_breach');

        $this->assertContains($kriOwner->id, $recipients);
        $this->assertContains($cro->id, $recipients, 'A red breach did not reach oversight.');
        $this->assertNotContains($bystander->id, $recipients);
    }

    /**
     * Amber stays with the owner. Sending every amber reading to every risk
     * manager is how a notification bell becomes something people turn off.
     */
    #[Test]
    public function an_amber_kri_breach_stops_at_the_owner(): void
    {
        $kriOwner = $this->makeUser('KRI Owner');
        $cro = $this->makeUser('Chief Risk Officer');

        Role::findOrCreate('chief-risk-officer');
        $cro->assignRole('chief-risk-officer');

        [$kri, $measurement] = $this->makeKriWithMeasurement($kriOwner->id);

        $this->handleOnAWorker(new KriBreachDetected($kri, $measurement, 'amber'));

        $this->assertSame([$kriOwner->id], $this->recipientsOf('kri_breach'));
    }

    /** The risk owner is the point: their number is about to move. */
    #[Test]
    public function treatment_completion_tells_the_risk_owner(): void
    {
        $riskOwner = $this->makeUser('Risk Owner');
        $treatmentOwner = $this->makeUser('Treatment Owner');

        $risk = $this->makeRisk(['risk_owner_id' => $riskOwner->id]);
        $treatment = TreatmentPlan::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'action_title' => 'Deploy dual authorisation',
            'strategy' => 'mitigate',
            'owner_id' => $treatmentOwner->id,
            'status' => 'completed',
            'created_by' => $this->actor->id,
        ]);

        $this->handleOnAWorker(new TreatmentCompleted($treatment, $risk));

        $recipients = $this->recipientsOf('treatment_completed');

        $this->assertContains($riskOwner->id, $recipients);
        $this->assertContains($treatmentOwner->id, $recipients);
    }

    /**
     * The submitter pressed Submit; they know. This notification exists to put
     * the worksheet in front of the reviewer, which is precisely the step the
     * old code skipped — it notified auth()->id(), i.e. the submitter, and the
     * reviewer got nothing.
     */
    #[Test]
    public function an_rcsa_submission_goes_to_the_reviewer_not_the_submitter(): void
    {
        $respondent = $this->makeUser('Respondent');
        $reviewer = $this->makeUser('Reviewer');

        $unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-RCSA',
            'name' => 'Operations',
            'is_active' => true,
        ]);

        $campaign = AssessmentCampaign::create([
            'organization_id' => $this->organization->id,
            'campaign_code' => 'RCSA-2026-Q3',
            'title' => 'Q3 RCSA',
            'campaign_type' => 'rcsa',
            'status' => 'active',
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'created_by' => $respondent->id,
        ]);

        $assignment = CampaignAssignment::create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'business_unit_id' => $unit->id,
            'respondent_id' => $respondent->id,
            'reviewer_id' => $reviewer->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'submitted',
        ]);

        $this->actingAs($respondent);

        (new SendNotification)->handle(new RcsaWorksheetSubmitted($assignment, $campaign, 4));

        $this->assertSame([$reviewer->id], $this->recipientsOf('rcsa_worksheet_submitted'));
    }

    /**
     * A KRI with no owner and no linked risk has nobody to tell. That must be
     * a quiet no-op, not an exception that fails the job and retries three
     * times against the same missing data.
     */
    #[Test]
    public function an_event_with_no_resolvable_recipient_is_a_quiet_no_op(): void
    {
        [$kri, $measurement] = $this->makeKriWithMeasurement(null);

        $this->handleOnAWorker(new KriBreachDetected($kri, $measurement, 'amber'));

        $this->assertSame(0, DB::table('notifications_log')->count());
    }

    /**
     * The worker has no tenant, so the listener sets one before looking up
     * role holders. Without that, OrganizationScope is inert and a red breach
     * at one bank would notify another bank's CRO.
     */
    #[Test]
    public function oversight_lookup_does_not_cross_tenants(): void
    {
        $ourCro = $this->makeUser('Our CRO');
        Role::findOrCreate('chief-risk-officer');
        $ourCro->assignRole('chief-risk-officer');

        [$kri, $measurement] = $this->makeKriWithMeasurement($this->makeUser('KRI Owner')->id);

        $otherOrg = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $theirCro = User::create([
            'name' => 'Their CRO',
            'email' => 'their-cro@other.test',
            'password' => Hash::make('password'),
            'organization_id' => $otherOrg->id,
            'is_active' => true,
        ]);
        $theirCro->assignRole('chief-risk-officer');

        $this->handleOnAWorker(new KriBreachDetected($kri, $measurement, 'red'));

        $recipients = $this->recipientsOf('kri_breach');

        $this->assertContains($ourCro->id, $recipients);
        $this->assertNotContains($theirCro->id, $recipients, "Another bank's CRO was notified of this bank's breach.");
    }

    /** @return array{0: KeyRiskIndicator, 1: KriMeasurement} */
    private function makeKriWithMeasurement(?int $ownerId): array
    {
        $kri = KeyRiskIndicator::create([
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-'.(++$this->kriSequence),
            'name' => 'Failed payment ratio',
            'owner_id' => $ownerId,
            'status' => 'active',
            'measurement_frequency' => 'monthly',
            'created_by' => $this->actor->id,
        ]);

        $measurement = KriMeasurement::create([
            'kri_id' => $kri->id,
            'measurement_date' => now()->toDateString(),
            'value' => 12.5,
            'status' => 'recorded',
            'entered_by' => $this->actor->id,
        ]);

        return [$kri, $measurement];
    }
}
