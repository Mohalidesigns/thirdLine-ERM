<?php

namespace Tests\Feature;

use App\Models\KeyRiskIndicator;
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
 * WP-13 — the demo loop, end to end.
 *
 * The pitch this product is sold on is one sentence: a KRI breaches, somebody
 * is told, they assess, they treat, and the number moves. Before this wave
 * every joint in that sentence was broken independently — the breach notified
 * nobody, the notification would have been dropped by the queue anyway, the
 * treatment moved no score because its listener guarded on a column that has
 * never existed, and approving the reassessment could zero the risk's inherent
 * score outright.
 *
 * Each of those has its own focused test. This one exists because they were
 * broken in a way that only shows up when you walk the whole path: every
 * individual piece looked plausible in isolation, and the suite passed at
 * 1045 tests with the loop wide open.
 *
 * It is deliberately written the way the demo is given, not the way the code
 * is structured.
 */
class DemoLoopTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    #[Test]
    public function a_breach_reaches_a_person_and_a_completed_treatment_moves_the_number(): void
    {
        $this->bootDomainFixtures();

        $riskOwner = $this->user('Head of Payments');
        $cro = $this->user('Chief Risk Officer');
        Role::findOrCreate('chief-risk-officer');
        $cro->assignRole('chief-risk-officer');

        /* 1. A risk on the register, scored. ---------------------------- */
        $risk = $this->makeRisk([
            'risk_owner_id' => $riskOwner->id,
            'title' => 'Failed interbank settlement',
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_score' => 20,
            'inherent_rating' => 'Critical',
            'residual_likelihood' => 4,
            'residual_impact' => 4,
            'residual_score' => 16,
            'residual_rating' => 'High',
        ]);

        /* 2. Its KRI breaches. ------------------------------------------ */
        $kri = KeyRiskIndicator::create([
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-SETTLE',
            'name' => 'Failed settlement ratio',
            'owner_id' => $riskOwner->id,
            'status' => 'active',
            'measurement_frequency' => 'daily',
            'created_by' => $this->actor->id,
        ]);

        $measurement = \App\Models\KriMeasurement::create([
            'kri_id' => $kri->id,
            'measurement_date' => now()->toDateString(),
            'value' => 8.4,
            'status' => 'recorded',
            'entered_by' => $this->actor->id,
        ]);

        // Run the listener exactly as a queue worker does: no session, no
        // tenant. This is the step that used to log a warning and return,
        // leaving a green queue and nobody told.
        auth()->logout();
        TenantContext::clear();
        (new \App\Listeners\SendNotification)->handle(
            new \App\Events\KriBreachDetected($kri, $measurement, 'red')
        );

        /* 3. Somebody is actually told. --------------------------------- */
        $alert = DB::table('notifications_log')->where('type', 'kri_breach')->get();

        $this->assertGreaterThan(0, $alert->count(), 'The breach notified nobody.');

        $told = $alert->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($riskOwner->id, $told, 'The risk owner was not told about the breach.');
        $this->assertContains($cro->id, $told, 'A red breach did not reach the CRO.');

        // And the alert is clickable — it links to the KRI, in the column the
        // bell actually reads.
        $this->assertSame('/risk/kri/'.$kri->id, $alert->first()->action_url);

        /* 4. A treatment is raised and completed. ----------------------- */
        TenantContext::set($this->organization->id);

        $treatment = TreatmentPlan::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'action_title' => 'Dual-rail settlement failover',
            'strategy' => 'mitigate',
            'owner_id' => $riskOwner->id,
            'status' => 'in_progress',
            'expected_residual_likelihood' => 2,
            'expected_residual_impact' => 3,
            'created_by' => $this->actor->id,
        ]);

        $scoreBefore = $risk->fresh()->residual_score;
        $this->assertSame(16, (int) $scoreBefore);

        $treatment->update(['status' => 'completed', 'completion_date' => now()]);
        \App\Events\TreatmentCompleted::dispatch($treatment->fresh(), $risk->fresh());

        /* 5. The number does NOT move yet — a reassessment is raised. ---- */
        $this->assertSame(
            16,
            (int) $risk->fresh()->residual_score,
            'Completing a treatment moved the risk score with nobody approving it.'
        );

        $assessment = \App\Models\RiskAssessment::query()
            ->where('risk_id', $risk->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($assessment, 'Completing the treatment raised no reassessment.');
        $this->assertSame(2, (int) $assessment->residual_likelihood);
        $this->assertSame(3, (int) $assessment->residual_impact);

        /* 6. Approval moves it. ----------------------------------------- */
        // The real approval route — the same call the reviewer's Approve
        // button makes through ModuleApprovals.
        app(\App\Services\Workflow\ModuleApprovals::class)
            ->decideDirectly($assessment, 'approve', $cro, 'Reviewed.');

        $after = $risk->fresh();

        $this->assertSame(6, (int) $after->residual_score, 'Approval did not move the residual score.');
        $this->assertSame(2, (int) $after->residual_likelihood);
        $this->assertSame(3, (int) $after->residual_impact);

        // And the inherent side is untouched. An approval that silently rated
        // this Critical risk at zero was a live defect until this wave.
        $this->assertSame(20, (int) $after->inherent_score, 'Approval damaged the inherent score.');
        $this->assertSame('Critical', $after->inherent_rating);

        /* 7. The owner is told their number moved. ---------------------- */
        $this->assertGreaterThan(
            0,
            DB::table('notifications_log')
                ->where('type', 'treatment_completed')
                ->where('user_id', $riskOwner->id)
                ->count(),
            'The risk owner was not told the treatment landed.'
        );
    }

    private function user(string $label): User
    {
        return User::create([
            'name' => $label,
            'email' => str($label)->slug().'-'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
    }
}
