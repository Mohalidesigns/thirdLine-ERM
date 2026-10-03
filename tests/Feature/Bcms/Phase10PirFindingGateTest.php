<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\IncidentSeverity;
use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Aar;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\DrTest;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Incident;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Incidents\IncidentService;
use App\Services\Bcms\Incidents\NotificationService;
use App\Services\Bcms\Incidents\PirService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * QA gate 1 — the "raise a finding from a PIR" fix
 * (FindingController::store's `source === FindingSource::Incident` branch,
 * IncidentPresenter::review()'s `urls.raise_finding` gate). Verifies the
 * security/tenancy, finalisation-lock and idempotency properties the fix's
 * own docblocks claim, against `bcms.findings.store` directly — the surface
 * a hidden button does not actually protect.
 */
class Phase10PirFindingGateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $officer;

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

        $this->officer = $this->user('officer@khb.test');

        foreach (['bcms.incident.view', 'bcms.incident.declare', 'bcms.incident.manage', 'bcms.incident.notify', 'bcms.plan.activate', 'bcms.report.export'] as $p) {
            $this->givePermission($this->officer, $p);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /**
     * Confirms `VisibleToUser(Aar::class)` (a per-tenant/org-hierarchy global
     * scope) already stops a cross-tenant `aar_id` — not a defect, a
     * regression guard.
     */
    #[Test]
    public function a_cross_tenant_aar_id_is_refused_by_validation_and_creates_no_finding(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);

        $otherOrg = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($otherOrg->id);
        $otherUnit = BusinessUnit::create([
            'organization_id' => $otherOrg->id, 'code' => 'BU-X', 'name' => 'X', 'is_active' => true,
        ]);
        $otherUser = User::query()->create([
            'email' => 'other@ob.test', 'name' => 'Other',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $otherOrg->id, 'business_unit_id' => $otherUnit->id, 'is_active' => true,
        ]);
        $this->givePermission($otherUser, 'bcms.finding.manage');
        TenantContext::set($this->organization->id);

        $this->actingAs($otherUser)->post(route('bcms.findings.store'), [
            'source' => 'incident',
            'classification' => 'observation',
            'description' => 'Cross tenant attempt.',
            'aar_id' => $aar->getKey(),
        ])->assertSessionHasErrors('aar_id');

        $this->assertSame(0, Finding::query()->count());
    }

    /**
     * DEFECT 1. `FindingController::store()` (app/Http/Controllers/Bcms/
     * FindingController.php:170-173) only branches on
     * `$source === FindingSource::Incident && $aarRecord !== null` — it never
     * checks `$aarRecord->isPostIncident()`. An exercise AAR (occurrence_id
     * set, incident_id null) is a valid, visible `Aar` row, so it passes
     * `VisibleToUser(Aar::class)`. `$sourceRecord = $aarRecord->incident`
     * then evaluates to null, `sourceLink()` in FindingService omits
     * `incident_id` from the insert entirely, and the row is created anyway
     * with `source = incident`, `incident_id = null`,
     * `aar_id = <the exercise AAR>`. This does not 500, but it is exactly
     * the malformed row the class docblock (FindingController.php:157-169)
     * says the two-FK design exists to prevent: a `source = incident`
     * finding that cannot be "found under its incident" because it has none.
     */
    #[Test]
    public function an_exercise_aar_with_no_incident_posted_as_source_incident_must_not_create_a_finding_with_no_incident_id(): void
    {
        $occurrence = $this->fakeOccurrenceId();

        $aar = Aar::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence,
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);
        $this->assertFalse($aar->isPostIncident());

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $response = $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'incident',
            'classification' => 'observation',
            'description' => 'Exercise AAR posted as incident source.',
            'aar_id' => $aar->getKey(),
        ]);

        // Re-gate: `FindingController::store()` now refuses this with a
        // named validation error on `aar_id` rather than silently creating a
        // malformed row — asserted on the key AND the message, not merely
        // that something in `errors` is non-empty.
        $response->assertSessionHasErrors([
            'aar_id' => 'That review is not a post-incident review, so a finding cannot be raised against it '
                .'with source "incident".',
        ]);

        $this->assertNull(
            Finding::query()->where('aar_id', $aar->getKey())->first(),
            'No finding should be created when an exercise AAR is posted as source=incident.'
        );
    }

    /**
     * DEFECT 2. `IncidentPresenter::review()` (IncidentPresenter.php:532,
     * ":653") omits `urls.raise_finding` once `$aar->status === 'final'` —
     * but that is the ONLY enforcement point. `FindingController::store()`
     * never re-checks the PIR's own status before calling
     * `FindingService::raise()`, so POSTing directly to
     * `bcms.findings.store` with a finalised PIR's own `aar_id` still raises
     * a finding against it. The presenter's own docblock
     * (IncidentPresenter.php:526-531) states the reason the control is
     * withdrawn is to stop someone "fixing" condition 6 after finalisation
     * "which is the point of the gate" — a gate enforced only in the
     * Inertia payload is not enforced.
     */
    #[Test]
    public function a_finding_cannot_be_raised_against_a_finalised_pir_by_posting_directly(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);

        $approver = $this->independentApprover();
        $this->givePermission($approver, 'bcms.finding.manage');

        $finalised = app(PirService::class)->finalise($aar->fresh(), $approver, 0);
        $this->assertSame('final', $finalised->status);

        $response = $this->actingAs($approver)->post(route('bcms.findings.store'), [
            'source' => 'incident',
            'classification' => 'observation',
            'description' => 'Posted directly against a finalised PIR.',
            'aar_id' => $aar->getKey(),
        ]);

        // Re-gate: refused server-side with a named error on `aar_id`, the
        // exact message `FindingController::store()` now returns.
        $response->assertSessionHasErrors([
            'aar_id' => 'That report has already been finalised and cannot have a new finding raised against it. '
                .'Reopen it first.',
        ]);

        $this->assertSame(
            0,
            Finding::query()->where('aar_id', $aar->getKey())->count(),
            'A finding was raised against a finalised PIR via a direct POST — the finalisation lock '
            .'IncidentPresenter::review() documents (IncidentPresenter.php:526-531) is enforced only by '
            .'omitting the URL from the Inertia payload, not by FindingController::store() itself.'
        );
    }

    /**
     * Re-gate check 3: the reopen → raise → re-finalise path for a PIR. The
     * finalised-report check fires ahead of the not-post-incident check
     * (both live in `FindingController::store()`), but the intent is that
     * `AarService::reopen()` — the SAME route both AAR kinds share
     * (`bcms.aars.reopen`) — is the one legitimate way back in, and a finding
     * can then be raised and the PIR re-finalised.
     */
    #[Test]
    public function a_finding_can_be_raised_after_reopening_a_finalised_pir_and_the_pir_can_be_refinalised(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);

        $approver = $this->independentApprover();
        $this->givePermission($approver, 'bcms.finding.manage');
        $this->givePermission($approver, 'bcms.aar.manage');

        $finalised = app(PirService::class)->finalise($aar->fresh(), $approver, 0);
        $this->assertSame('final', $finalised->status);

        // Reopening still refuses the raise (belt and braces: the guard is
        // keyed on `status === 'final'`, not on "was ever finalised").
        $blocked = $this->actingAs($approver)->post(route('bcms.findings.store'), [
            'source' => 'incident', 'classification' => 'observation',
            'description' => 'Attempted before reopening.', 'aar_id' => $aar->getKey(),
        ]);
        $blocked->assertSessionHasErrors('aar_id');

        $reopened = app(\App\Services\Bcms\Exercises\AarService::class)->reopen(
            $aar->fresh(), $approver, 'Needs a finding raised for the failed failover section.'
        );
        $this->assertSame('draft', $reopened->status);

        $this->actingAs($approver)->post(route('bcms.findings.store'), [
            'source' => 'incident', 'classification' => 'observation',
            'description' => 'Raised after reopening.', 'aar_id' => $aar->getKey(),
        ])->assertSessionHasNoErrors();

        $finding = Finding::query()->where('aar_id', $aar->getKey())->first();
        $this->assertNotNull($finding, 'A finding must be raisable once the PIR is reopened.');
        $this->assertSame($incident->getKey(), $finding->incident_id);

        // refreshMetrics/refreshPlanSections are normally run by the show()
        // route on GET; finalise() itself re-derives condition 7's timings
        // but conditions 4/5/6 read `quantitative_results['plan_sections']`
        // as already stored, which is still empty here, so re-finalising
        // needs no further disposition work.
        $refinalised = app(PirService::class)->finalise($aar->fresh(), $approver, 0);
        $this->assertSame('final', $refinalised->status);
    }

    /**
     * Not a defect: two distinct plan-section findings on the same PIR both
     * persist (`existingFor()` matches on source + record + description, and
     * the descriptions differ), and a genuinely repeated description against
     * the same incident is correctly folded into the existing row rather than
     * duplicated — `FindingService`'s own "idempotent on its source" contract.
     */
    #[Test]
    public function two_distinct_plan_section_findings_on_the_same_pir_both_persist_and_a_repeat_is_folded(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);

        $approver = $this->independentApprover();
        $this->givePermission($approver, 'bcms.finding.manage');

        $this->actingAs($approver)->post(route('bcms.findings.store'), [
            'source' => 'incident', 'classification' => 'observation',
            'description' => 'Section A did not hold.', 'aar_id' => $aar->getKey(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($approver)->post(route('bcms.findings.store'), [
            'source' => 'incident', 'classification' => 'observation',
            'description' => 'Section B did not hold.', 'aar_id' => $aar->getKey(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Finding::query()->where('aar_id', $aar->getKey())->count());
        $existing = Finding::query()->where('aar_id', $aar->getKey())
            ->where('description', 'Section A did not hold.')->sole();

        // A third post with a description already used against this same
        // incident is folded into the existing row, not duplicated — and
        // code review A3: the flash message says "already raised", not
        // "raised", so the officer is not told a second finding exists.
        $this->actingAs($approver)->post(route('bcms.findings.store'), [
            'source' => 'incident', 'classification' => 'observation',
            'description' => 'Section A did not hold.', 'aar_id' => $aar->getKey(),
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Finding {$existing->reference} already raised.");

        $this->assertSame(2, Finding::query()->where('aar_id', $aar->getKey())->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Code review gate 2, defect 1 — the record must be derived FROM
     *  `source`, and every mismatched pair refused, not just the one the
     *  live demo happened to hit. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function source_aar_with_a_pirs_aar_id_is_refused(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'aar',
            'classification' => 'observation',
            'description' => 'Filed under the wrong source for a PIR.',
            'aar_id' => $aar->getKey(),
        ])->assertSessionHasErrors('aar_id');

        $this->assertSame(0, Finding::query()->count());
    }

    /**
     * Re-gate check: `source = aar`'s `aar_id` is nullable BY DESIGN
     * (`FindingSource`'s own docblock) — this is exactly the shape
     * `Findings/Index.jsx`'s generic register form now relies on, since
     * `options.sources` still offers `aar` with no `aar_id` field to fill in.
     * Confirms the D1 switch did not turn an optional id into a mandatory one
     * for the one source the register itself still raises.
     */
    #[Test]
    public function source_aar_with_no_aar_id_at_all_is_still_accepted(): void
    {
        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'aar',
            'classification' => 'observation',
            'description' => 'Raised from the register with no AAR named.',
        ])->assertSessionHasNoErrors();

        $finding = Finding::query()->where('description', 'Raised from the register with no AAR named.')->first();
        $this->assertNotNull($finding);
        $this->assertSame('aar', $finding->source->value);
        $this->assertNull($finding->aar_id);
    }

    #[Test]
    public function source_incident_with_no_aar_id_at_all_is_refused(): void
    {
        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'incident',
            'classification' => 'observation',
            'description' => 'No review named at all.',
        ])->assertSessionHasErrors('aar_id');

        $this->assertSame(0, Finding::query()->count());
    }

    #[Test]
    public function source_incident_with_a_pirs_aar_id_and_a_dr_test_id_is_refused(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);
        $drTest = DrTest::factory()->create();

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'incident',
            'classification' => 'observation',
            'description' => 'Two records at once.',
            'aar_id' => $aar->getKey(),
            'dr_test_id' => $drTest->getKey(),
        ])->assertSessionHasErrors('dr_test_id');

        $this->assertSame(0, Finding::query()->count());
    }

    /**
     * QA gate 1, re-gate cycle 4 (a). Branch coverage: the `Aar` switch case
     * (FindingController.php:217-237) only ever had its `isPostIncident()`
     * arm exercised — its OWN `dr_test_id` guard
     * (`if ($drTestRecord !== null) { ... }`, line 218) had no test at all.
     * Without it, `source = aar` with a `dr_test_id` attached would fall
     * through to `$sourceRecord = $aarRecord` and silently drop the DR test
     * id on the floor rather than refusing a request that names two records
     * for one finding.
     */
    #[Test]
    public function source_aar_with_a_dr_test_id_is_refused(): void
    {
        $drTest = DrTest::factory()->create();

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'aar',
            'classification' => 'observation',
            'description' => 'An exercise finding does not take a DR test id.',
            'dr_test_id' => $drTest->getKey(),
        ])->assertSessionHasErrors('dr_test_id');

        $this->assertSame(0, Finding::query()->count());
    }

    /**
     * QA gate 1, re-gate cycle 4 (b). Branch coverage: the `DrTest` case's
     * own happy path (FindingController.php:293, `$sourceRecord =
     * $drTestRecord`) had every one of its guard clauses tested but never
     * the successful outcome itself.
     */
    #[Test]
    public function source_dr_test_with_a_valid_dr_test_id_and_no_aar_id_raises_a_finding(): void
    {
        $drTest = DrTest::factory()->create(['organization_id' => $this->organization->id]);

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'dr_test',
            'classification' => 'observation',
            'description' => 'A breached DR test raised its own finding.',
            'dr_test_id' => $drTest->getKey(),
        ])->assertSessionHasNoErrors();

        $finding = Finding::query()->where('description', 'A breached DR test raised its own finding.')->first();
        $this->assertNotNull($finding);
        $this->assertSame('dr_test', $finding->source->value);
        $this->assertSame($drTest->getKey(), $finding->dr_test_id);
        $this->assertNull($finding->aar_id);
    }

    /**
     * QA gate 1, re-gate cycle 4 (c). `a_cross_tenant_aar_id_is_refused_...`
     * only ever probed `aar_id` — `dr_test_id` reaches the same
     * `VisibleToUser` rule through a different model (`DrTest`, tenant-scoped
     * by `BelongsToOrganization` alone, no `BindsToVisibleRecord`) and had no
     * regression guard of its own.
     */
    #[Test]
    public function a_cross_tenant_dr_test_id_is_refused_by_validation_and_creates_no_finding(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Bank Two', 'short_name' => 'OB2',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        $otherDrTest = DrTest::factory()->create(['organization_id' => $otherOrg->id]);

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $response = $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'dr_test',
            'classification' => 'observation',
            'description' => 'Cross tenant DR test attempt.',
            'dr_test_id' => $otherDrTest->getKey(),
        ]);

        $response->assertSessionHasErrors('dr_test_id');
        // No title, name or id of the foreign record in the error — the rule
        // returns the same fixed string for every failure
        // (VisibleToUser::validate(), "The selected :attribute is invalid.").
        $this->assertSame(
            'The selected dr test id is invalid.',
            session('errors')->get('dr_test_id')[0]
        );

        $this->assertSame(0, Finding::query()->count());
    }

    /**
     * QA gate 1, re-gate cycle 4 (c). `Gate::authorize('bcms.finding.manage')`
     * is the one line standing between this route and anyone who can merely
     * VIEW the incident — a distinct grant, and the presenter already hides
     * `urls.raise_finding` for exactly this user
     * (`Phase10ScreensTest::raise_finding_url_is_present_only_for_a_finding_
     * manage_holder_on_a_draft_pir`). This is the surface test: posting
     * directly, past the hidden control, is refused at the HTTP layer too.
     */
    #[Test]
    public function a_user_who_can_view_the_incident_but_lacks_finding_manage_is_forbidden_from_posting_directly(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);

        $viewer = $this->user('viewer-only@khb.test');
        $this->givePermission($viewer, 'bcms.incident.view');
        $this->givePermission($viewer, 'bcms.incident.manage');
        $this->givePermission($viewer, 'bcms.aar.manage');
        // Deliberately NOT bcms.finding.manage.

        $this->actingAs($viewer)->post(route('bcms.findings.store'), [
            'source' => 'incident',
            'classification' => 'observation',
            'description' => 'Attempted without finding.manage.',
            'aar_id' => $aar->getKey(),
        ])->assertForbidden();

        $this->assertSame(0, Finding::query()->count());
    }

    /**
     * QA gate 1, re-gate cycle 4 (f). `Finding` uses `BcmsAuditable`
     * (app/Models/Bcms/Finding.php:65), which writes a `bcms_audit_logs` row
     * on `created` for every model, `FindingController::store()` included —
     * nothing in this test class had asserted it for the PIR path
     * specifically. Event name: `created` (BcmsAuditable::bootBcmsAuditable()).
     */
    #[Test]
    public function raising_a_finding_from_a_pir_writes_the_standard_bcms_audit_row(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);

        $approver = $this->independentApprover();
        $this->givePermission($approver, 'bcms.finding.manage');

        $this->actingAs($approver)->post(route('bcms.findings.store'), [
            'source' => 'incident', 'classification' => 'observation',
            'description' => 'Audited PIR finding.', 'aar_id' => $aar->getKey(),
        ])->assertSessionHasNoErrors();

        $finding = Finding::query()->where('aar_id', $aar->getKey())
            ->where('description', 'Audited PIR finding.')->sole();

        $row = AuditLog::query()
            ->where('auditable_type', Finding::class)
            ->where('auditable_id', $finding->getKey())
            ->where('event', 'created')
            ->sole();

        $this->assertSame($approver->getKey(), $row->actor_id);
        $this->assertSame($this->organization->id, $row->organization_id);
        $this->assertNotNull($row->after);
        $this->assertSame($finding->getKey(), $row->after['id'] ?? null);
        $this->assertNull($row->before, 'A creation row records no before state.');
    }

    #[Test]
    public function source_dr_test_with_only_an_aar_id_writes_nothing_and_is_refused(): void
    {
        $exerciseAar = Aar::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $this->fakeOccurrenceId(),
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);

        $this->givePermission($this->officer, 'bcms.finding.manage');

        // The exact probe from the code review: without the fix,
        // `sourceLink()` would write the AAR's own id into `dr_test_id`.
        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'dr_test',
            'classification' => 'observation',
            'description' => 'No DR test id, only an AAR one.',
            'aar_id' => $exerciseAar->getKey(),
        ])->assertSessionHasErrors('dr_test_id');

        $this->assertSame(0, Finding::query()->count());
    }

    #[Test]
    public function source_dr_test_with_both_a_dr_test_id_and_an_aar_id_is_refused(): void
    {
        $exerciseAar = Aar::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $this->fakeOccurrenceId(),
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);
        $drTest = DrTest::factory()->create();

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'dr_test',
            'classification' => 'observation',
            'description' => 'Both records at once.',
            'aar_id' => $exerciseAar->getKey(),
            'dr_test_id' => $drTest->getKey(),
        ])->assertSessionHasErrors('aar_id');

        $this->assertSame(0, Finding::query()->count());
    }

    #[Test]
    public function a_no_record_source_with_an_aar_id_is_refused(): void
    {
        $exerciseAar = Aar::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $this->fakeOccurrenceId(),
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'audit',
            'classification' => 'observation',
            'description' => 'An audit finding does not take an AAR id.',
            'aar_id' => $exerciseAar->getKey(),
        ])->assertSessionHasErrors('aar_id');

        $this->assertSame(0, Finding::query()->count());
    }

    #[Test]
    public function a_no_record_source_with_a_dr_test_id_is_refused(): void
    {
        $drTest = DrTest::factory()->create();

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'gap_analysis',
            'classification' => 'observation',
            'description' => 'A gap analysis finding does not take a DR test id.',
            'dr_test_id' => $drTest->getKey(),
        ])->assertSessionHasErrors('dr_test_id');

        $this->assertSame(0, Finding::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Code review gate 2, defect 4 — the finalisation lock has no test
     *  against an EXERCISE AAR, and `linkObjectiveToFinding()` used to
     *  `forceFill()` a final report even when the finding itself was
     *  refused. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_finalised_exercise_aar_refuses_a_finding_and_leaves_its_quantitative_results_untouched(): void
    {
        $aar = Aar::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $this->fakeOccurrenceId(),
            'status' => 'final', 'quantitative_results' => ['objectives' => [['index' => 0, 'objective_text' => 'Objective 1']]],
            'participant_feedback' => [],
        ]);
        $originalQr = $aar->quantitative_results;

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'aar',
            'classification' => 'observation',
            'description' => 'A late finding against a final report.',
            'aar_id' => $aar->getKey(),
            'objective_text' => 'Objective 1',
        ])->assertSessionHasErrors('aar_id');

        $this->assertSame(0, Finding::query()->where('aar_id', $aar->getKey())->count());
        $this->assertSame(
            $originalQr,
            $aar->fresh()->quantitative_results,
            'linkObjectiveToFinding() must never run against a request the finalisation lock already refused.'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Re-gate cycle 3, advisory — the A4 clause-select rollout left one
     *  screen with the control but no data to fill it, and the register's
     *  generic raise form now offers two sources D1 always refuses. */
    /* ------------------------------------------------------------------ */

    /**
     * REGRESSION (was the ADVISORY "the_exercise_aar_screen_ships_no_clause_
     * options_so_a_nonconformity_finding_cannot_be_raised_through_it",
     * inverted now the fix has shipped). `Exercises/Aar.jsx`'s "Raise a
     * finding" form renders `iso_clause_ref` as a `<select>` fed by
     * `options.clause_refs` (`RaiseFinding.jsx`), the same shape
     * `IncidentPresenter::review()` and `FindingController::index()` already
     * ship via `IsoClauseRef::options()`. `AarController::show()` now ships
     * it too, so the picker has real options and a `nonconformity` — which
     * `FindingService::resolveClauseRef()` refuses outright with no clause
     * named — can still be raised from the exercise AAR builder.
     */
    #[Test]
    public function the_exercise_aar_screen_ships_clause_options_and_a_nonconformity_can_be_raised_through_it(): void
    {
        $occurrence = $this->fakeOccurrenceId();

        $aar = Aar::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence,
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);

        $this->givePermission($this->officer, 'bcms.exercise.view');
        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->get(route('bcms.aars.show', $aar))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('options.clause_refs', count(IsoClauseRef::cases()))
                ->where('options.clause_refs.0.value', IsoClauseRef::cases()[0]->value)
                ->where('options.clause_refs.0.standard', IsoClauseRef::cases()[0]->standard()));

        // What the screen's own select can now actually send for a
        // nonconformity: a real clause, chosen from the options the screen
        // itself renders.
        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'aar', 'aar_id' => $aar->getKey(),
            'classification' => 'nonconformity',
            'iso_clause_ref' => IsoClauseRef::Iso22301_10_1_nonconformity->value,
            'description' => 'A nonconformity raised with a clause chosen from the screen.',
        ])->assertSessionHasNoErrors();

        $finding = Finding::query()->where('aar_id', $aar->getKey())->first();
        $this->assertNotNull(
            $finding,
            'A nonconformity finding should be raisable from the exercise AAR builder now its clause select '
            .'has real options — AarController::show() ships options.clause_refs.'
        );
        $this->assertSame(IsoClauseRef::Iso22301_10_1_nonconformity->value, $finding->iso_clause_ref);
    }

    /* ------------------------------------------------------------------ */
    /*  Code review gate 2, cycle 4 — D1 (BLOCKING, ADR 0017 Amendment 2).
     *  `FindingService::deriveBusinessUnitId()` had no `Incident` arm, so
     *  every PIR finding fell through to the null return and, by
     *  `ScopedToOrgHierarchy`'s null-is-visible-to-the-whole-tenant rule, was
     *  readable by any `bcms.finding.view` holder anywhere in the tenant even
     *  though the incident itself is unit-scoped (ADR 0020). The reviewer's
     *  probe proved it: a Lagos user 404s on the Kano incident's review
     *  screen yet reads the Kano finding's full description in the register.
     *  Unit membership is via `business_unit_user` pivot rows — NOT
     *  `users.business_unit_id`, which `RcsaScope::unitIdsFor()` ignores. */
    /* ------------------------------------------------------------------ */

    /**
     * D1(a). A finding raised from a PIR takes the underlying incident's own
     * `business_unit_id` (ADR 0020 gave `Incident` its own unit column, the
     * same anchor treatment as `Plan`), rather than falling through to null.
     */
    #[Test]
    public function d1_a_pir_finding_takes_its_incidents_business_unit(): void
    {
        $kano = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-KANO', 'name' => 'Kano', 'is_active' => true,
        ]);

        $finding = $this->raiseKanoPirFinding($kano);

        $this->assertSame(
            $kano->id,
            $finding->affected_business_unit_id,
            'D1: a finding raised from a PIR must take its incident\'s own business unit (ADR 0020), not fall '
            .'through to null and become visible to the whole tenant (ADR 0017 Amendment 2).'
        );
    }

    /**
     * D1(b). With the unit correctly stamped, `ScopedToOrgHierarchy`'s
     * ordinary org-hierarchy rule now actually applies to a PIR finding: a
     * Lagos `bcms.finding.view` holder neither sees it on the register nor
     * can reach it by its own route.
     */
    #[Test]
    public function d1_a_lagos_finding_view_holder_does_not_see_a_kano_pir_finding_on_the_register_or_its_own_route(): void
    {
        $kano = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-KANO', 'name' => 'Kano', 'is_active' => true,
        ]);
        $finding = $this->raiseKanoPirFinding(
            $kano, 'Kano branch failover section did not hold — Musa Ibrahim unreachable'
        );

        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS', 'name' => 'Lagos', 'is_active' => true,
        ]);
        $lagosUser = $this->user('lagos-finding-view@khb.test', $lagos);
        $this->assignToUnit($lagosUser, $lagos);
        $this->givePermission($lagosUser, 'bcms.finding.view');
        $this->givePermission($lagosUser, 'bcms.finding.manage');

        $this->actingAs($lagosUser)->get(route('bcms.findings.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('findings.data', 0));

        // A write verb 404s identically (SubstituteBindings runs ahead of
        // `permission:` in the web stack, and this user does hold
        // `bcms.finding.manage`, so the 404 below is the binding filter, not
        // a permission short-circuit).
        $this->actingAs($lagosUser)->post(route('bcms.findings.close', $finding))->assertNotFound();
    }

    /**
     * D1(c). A Lagos `bcms.finding.manage` holder posting the Kano PIR's own
     * `aar_id` directly is refused by the SAME `VisibleToUser(Aar::class)`
     * rule the earlier cross-tenant tests exercise — `Aar::
     * constrainToVisibleRecord()` ORs in `whereHas('incident', ...->
     * scopeVisibleTo())`, so a unit-scoped incident makes its PIR's own
     * `aar_id` unit-scoped too. Written as a regression guard alongside the
     * D1 fix: the fix stamps the unit on the CREATED finding; this confirms
     * the create path itself was already refused for a user with no access
     * to the incident in the first place.
     */
    #[Test]
    public function d1_a_lagos_finding_manage_holder_cannot_raise_against_a_kano_pirs_aar_id(): void
    {
        $kano = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-KANO', 'name' => 'Kano', 'is_active' => true,
        ]);
        $this->assignToUnit($this->officer, $kano);
        $incident = app(IncidentService::class)->declare([
            'title' => 'Kano branch outage', 'severity' => 'sev2',
            'business_unit_id' => $kano->id, 'detected_at' => now()->toIso8601String(),
        ], $this->officer);
        $aar = $this->readyToFinalisePir($incident);

        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS', 'name' => 'Lagos', 'is_active' => true,
        ]);
        $lagosUser = $this->user('lagos-finding-manage@khb.test', $lagos);
        $this->assignToUnit($lagosUser, $lagos);
        $this->givePermission($lagosUser, 'bcms.finding.manage');

        $this->actingAs($lagosUser)->post(route('bcms.findings.store'), [
            'source' => 'incident', 'classification' => 'observation',
            'description' => 'Lagos user attempting a Kano PIR finding.', 'aar_id' => $aar->getKey(),
        ])->assertSessionHasErrors('aar_id');

        $this->assertSame(0, Finding::query()->count());
    }

    /**
     * D1(d). An incident genuinely declared with no unit (`business_unit_id`
     * is nullable — `IncidentService::declare()`) is organisation-wide risk
     * information — the group crisis team's own incident, say — and its
     * finding stays visible to the whole tenant. This is the intended null
     * arm `ScopedToOrgHierarchy`'s own docblock describes, not the leak D1
     * fixes; the assertion proves the fix did not turn "genuinely no unit"
     * into "always derive one".
     */
    #[Test]
    public function d1_an_organisation_wide_incident_with_no_unit_still_produces_an_organisation_wide_finding(): void
    {
        $incident = $this->declareIncident();
        $this->assertNull($incident->fresh()->business_unit_id);

        $aar = $this->readyToFinalisePir($incident);
        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'incident', 'classification' => 'observation',
            'description' => 'Organisation-wide incident finding.', 'aar_id' => $aar->getKey(),
        ])->assertSessionHasNoErrors();

        $finding = Finding::query()->where('aar_id', $aar->getKey())->sole();
        $this->assertNull($finding->affected_business_unit_id);

        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS', 'name' => 'Lagos', 'is_active' => true,
        ]);
        $lagosUser = $this->user('lagos-orgwide@khb.test', $lagos);
        $this->assignToUnit($lagosUser, $lagos);
        $this->givePermission($lagosUser, 'bcms.finding.view');

        $this->actingAs($lagosUser)->get(route('bcms.findings.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('findings.data', 1));
    }

    /**
     * D1(e). Gate 2, cycle 5 re-gate item 2. `RcsaScope::expand()` walks
     * DOWN from an assignment with `includes_descendants` — a divisional
     * head assigned to the PARENT with `includes_descendants = true` has the
     * child unit added to their reachable set by that walk (ADR 0017 §7
     * point 2: "the user is assigned to the unit … with
     * `includes_descendants` for a divisional head"). A Kano PIR finding
     * must therefore be visible to a user assigned to Kano's own PARENT unit,
     * not merely to Kano itself — the opposite direction (a user assigned to
     * a CHILD of Kano) must NOT reach it, since the walk never climbs.
     */
    #[Test]
    public function d1_e_a_user_assigned_to_the_incidents_parent_unit_with_descendants_sees_its_pir_finding(): void
    {
        $region = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-REGION', 'name' => 'North Region', 'is_active' => true,
        ]);
        $kano = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-KANO', 'name' => 'Kano',
            'parent_id' => $region->id, 'is_active' => true,
        ]);
        $kanoBranch = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-KANO-BR', 'name' => 'Kano Branch A',
            'parent_id' => $kano->id, 'is_active' => true,
        ]);

        $finding = $this->raiseKanoPirFinding($kano);

        // The divisional head: assigned to the PARENT, descendants included.
        // Reaches Kano's finding through the downward walk.
        $regionalHead = $this->user('region-head@khb.test', $region);
        $this->assignToUnit($regionalHead, $region);
        $this->givePermission($regionalHead, 'bcms.finding.view');

        $this->actingAs($regionalHead)->get(route('bcms.findings.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('findings.data', 1)
                ->where('findings.data.0.uuid', $finding->uuid));

        // The reverse is NOT true: a user assigned to a CHILD of Kano (one
        // level further down than the incident's own unit) does not reach
        // Kano's finding — the walk expands downward from an assignment, it
        // never climbs to an ancestor.
        $branchUser = $this->user('kano-branch-user@khb.test', $kanoBranch);
        $this->assignToUnit($branchUser, $kanoBranch);
        $this->givePermission($branchUser, 'bcms.finding.view');

        $this->actingAs($branchUser)->get(route('bcms.findings.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('findings.data', 0));
    }

    /**
     * D1(f). Gate 2, cycle 5 re-gate item 2. Two separate `business_unit_user`
     * pivot rows for the same user, no unit hierarchy is built here, so descendants play no part — the
     * `unitIdsFor()`/`expand()` walk treats every assignment independently
     * and unions the results, so a user assigned to both Kano and Lagos sees
     * a PIR finding raised against either, and nothing raised against a
     * third, unassigned unit.
     */
    #[Test]
    public function d1_f_a_user_with_pivot_rows_in_two_units_sees_both_and_not_a_third(): void
    {
        $kano = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-KANO', 'name' => 'Kano', 'is_active' => true,
        ]);
        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS', 'name' => 'Lagos', 'is_active' => true,
        ]);
        $abuja = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-ABUJA', 'name' => 'Abuja', 'is_active' => true,
        ]);

        $kanoFinding = $this->raiseKanoPirFinding($kano, 'Kano finding for the two-unit pivot test.');
        $lagosFinding = $this->raiseKanoPirFinding($lagos, 'Lagos finding for the two-unit pivot test.');
        $this->raiseKanoPirFinding($abuja, 'Abuja finding the two-unit user must not see.');

        $twoUnitUser = $this->user('kano-and-lagos@khb.test', $kano);
        $this->assignToUnit($twoUnitUser, $kano);
        $this->assignToUnit($twoUnitUser, $lagos);
        $this->givePermission($twoUnitUser, 'bcms.finding.view');

        $response = $this->actingAs($twoUnitUser)->get(route('bcms.findings.index'))->assertOk();
        $response->assertInertia(fn ($p) => $p->has('findings.data', 2));

        $seenUuids = collect($response->viewData('page')['props']['findings']['data'])->pluck('uuid')->all();
        $this->assertContains($kanoFinding->uuid, $seenUuids);
        $this->assertContains($lagosFinding->uuid, $seenUuids);
    }

    /**
     * D1(g). Gate 2, cycle 5 re-gate item 2. `FindingController::index()`'s
     * `source` filter narrows within the already-`visibleTo()`-scoped query
     * (`Finding::query()->visibleTo($request->user())->...->when($filters
     * ['source'] ?? null, ...)`) — the filter and the scope are two `where`
     * clauses on the SAME builder, not two independent queries, so filtering
     * by `source=incident` cannot widen what the org-hierarchy scope already
     * excluded. Proven directly: a Lagos user filtering the register by
     * `source=incident` sees the Lagos PIR finding and not the Kano one, both
     * of which are `source=incident`.
     */
    #[Test]
    public function d1_g_filtering_the_register_by_source_incident_still_applies_the_unit_scope(): void
    {
        $kano = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-KANO', 'name' => 'Kano', 'is_active' => true,
        ]);
        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS', 'name' => 'Lagos', 'is_active' => true,
        ]);

        $this->raiseKanoPirFinding($kano, 'Kano PIR finding for the source filter test.');
        $lagosFinding = $this->raiseKanoPirFinding($lagos, 'Lagos PIR finding for the source filter test.');

        $lagosUser = $this->user('lagos-source-filter@khb.test', $lagos);
        $this->assignToUnit($lagosUser, $lagos);
        $this->givePermission($lagosUser, 'bcms.finding.view');

        $this->actingAs($lagosUser)->get(route('bcms.findings.index', ['source' => 'incident']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('findings.data', 1)
                ->where('findings.data.0.uuid', $lagosFinding->uuid)
                ->where('findings.data.0.source', 'incident'));
    }

    /* ------------------------------------------------------------------ */
    /*  Code review gate 2, cycle 4 — D2 (BLOCKING). A service-level refusal
     *  from `resolveClauseRef()` was flashed with `back()->with('error', ...)`
     *  — no error bag — so Inertia's `onSuccess` fired anyway and
     *  `RaiseFinding.jsx` reset and closed the form, discarding the typed
     *  description with a toast the only sign anything happened. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function d2_a_nonconformity_raised_from_a_pir_with_no_clause_is_refused_as_a_named_field_error(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);

        $approver = $this->independentApprover();
        $this->givePermission($approver, 'bcms.finding.manage');

        $response = $this->actingAs($approver)->post(route('bcms.findings.store'), [
            'source' => 'incident', 'classification' => 'nonconformity',
            'description' => 'A nonconformity raised with no clause.', 'aar_id' => $aar->getKey(),
        ]);

        $response->assertSessionHasErrors([
            'iso_clause_ref' => 'A nonconformity must name the requirement it failed (ISO 22301 clause 10.1). '
                .'Pass an iso_clause_ref.',
        ]);

        $this->assertSame(0, Finding::query()->where('aar_id', $aar->getKey())->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Code review gate 2, cycle 4 — A5 advisory. `linkObjectiveToFinding()`
     *  writes into `quantitative_results['objectives']`, a shape the exercise
     *  AAR builder owns; a PIR's `quantitative_results` holds different data
     *  (`readyToFinalisePir()`'s own fixture), so any direct POST of
     *  `objective_text` against a PIR's `aar_id` overwrote it with
     *  `objectives: []`. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a5_an_objective_text_posted_against_a_pirs_aar_id_does_not_touch_its_quantitative_results(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);
        $originalQr = $aar->quantitative_results;

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'incident', 'classification' => 'observation',
            'description' => 'PIR finding with an objective_text attached.', 'aar_id' => $aar->getKey(),
            'objective_text' => 'Objective 1',
        ])->assertSessionHasNoErrors();

        $finding = Finding::query()->where('aar_id', $aar->getKey())->sole();
        $this->assertNotNull($finding);
        $this->assertSame(
            $originalQr,
            $aar->fresh()->quantitative_results,
            'linkObjectiveToFinding() must never run against a PIR — it writes an exercise-AAR-shaped '
            .'"objectives" key a PIR does not carry.'
        );
    }

    /**
     * Regression guard, unchanged by A5: the exercise-AAR path this guard
     * still allows.
     */
    #[Test]
    public function a5_an_objective_text_posted_against_an_exercise_aars_id_still_links_the_finding(): void
    {
        $occurrence = $this->fakeOccurrenceId();

        $aar = Aar::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence,
            'status' => 'draft', 'quantitative_results' => ['objectives' => [['index' => 0, 'objective_text' => 'Objective 1']]],
            'participant_feedback' => [],
        ]);

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'aar', 'aar_id' => $aar->getKey(),
            'classification' => 'observation',
            'description' => 'Exercise finding against an objective.', 'objective_text' => 'Objective 1',
        ])->assertSessionHasNoErrors();

        $finding = Finding::query()->where('aar_id', $aar->getKey())->sole();
        $qr = $aar->fresh()->quantitative_results;
        $this->assertSame($finding->reference, $qr['objectives'][0]['finding_reference'] ?? null);
    }

    private function raiseKanoPirFinding(BusinessUnit $kano, string $description = 'Kano branch failover section did not hold.'): Finding
    {
        $this->assignToUnit($this->officer, $kano);

        $incident = app(IncidentService::class)->declare([
            'title' => 'Kano branch outage', 'severity' => 'sev2',
            'business_unit_id' => $kano->id, 'detected_at' => now()->toIso8601String(),
        ], $this->officer);

        $aar = $this->readyToFinalisePir($incident);

        $this->givePermission($this->officer, 'bcms.finding.manage');

        $this->actingAs($this->officer)->post(route('bcms.findings.store'), [
            'source' => 'incident', 'classification' => 'observation',
            'description' => $description, 'aar_id' => $aar->getKey(),
        ])->assertSessionHasNoErrors();

        return Finding::query()->where('aar_id', $aar->getKey())->sole();
    }

    private function assignToUnit(User $user, BusinessUnit $unit): void
    {
        \Illuminate\Support\Facades\DB::table('business_unit_user')->insertOrIgnore([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'business_unit_id' => $unit->id,
            'includes_descendants' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function declareIncident(IncidentSeverity $severity = IncidentSeverity::Sev2): Incident
    {
        return app(IncidentService::class)->declare([
            'title' => 'A test incident', 'severity' => $severity->value,
            'detected_at' => now()->toIso8601String(),
        ], $this->officer);
    }

    private function fakeOccurrenceId(): int
    {
        $type = \App\Models\Bcms\ExerciseType::query()->firstOrFail();
        $programme = \App\Models\Bcms\ExerciseProgramme::query()->create(['year' => 2027, 'name' => 'Programme 2027']);
        $definition = \App\Models\Bcms\ExerciseDefinition::query()->create([
            'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type->getKey(),
            'name' => 'Fire drill',
        ]);

        return \App\Models\Bcms\ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'status' => 'planned',
        ])->getKey();
    }

    private function user(string $email, ?BusinessUnit $unit = null): User
    {
        return User::query()->firstOrCreate(['email' => $email], [
            'name' => Str::title(Str::before($email, '@')),
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => ($unit ?? $this->unit)->id, 'is_active' => true,
        ]);
    }

    private function givePermission(User $user, string $permission): void
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate('bcms10pirgate-'.md5($permission.$user->email), 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        $user->assignRole($role);
    }

    private function independentApprover(): User
    {
        $approver = $this->user('independent-approver-'.Str::random(8).'@khb.test');
        $this->givePermission($approver, 'bcms.incident.manage');
        $this->givePermission($approver, 'bcms.aar.approve');

        return $approver;
    }

    private function readyToFinalisePir(Incident $incident): Aar
    {
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $incident = app(IncidentService::class)->standDown($incident, $this->officer, [
            'all_clear_message' => 'The situation is resolved; normal operations have resumed.',
            'reason' => 'Root cause fixed and verified.',
        ]);

        $aar = app(PirService::class)->ensureDraftFor($incident);
        $aar->update([
            'summary' => 'Summary text.', 'what_worked' => 'Comms worked well.', 'what_failed' => 'Detection was slow.',
            'quantitative_results' => [
                'metrics' => ['time_to_detect_minutes' => 5, 'time_to_declare_minutes' => 2, 'time_to_activate_minutes' => 3],
            ],
        ]);

        return $aar->fresh();
    }
}
