<?php

namespace Tests\Feature\Risk;

use App\Models\BusinessUnit;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\IncidentNotification;
use App\Models\User;
use App\Presenters\NavPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

class IncidentRegisterTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach ([
            'incident.view', 'incident.create', 'incident.edit',
            'incident.triage', 'incident.close', 'incident.notify', 'incident.delete',
        ] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function nav_presenter_replaces_issues_with_incident_register(): void
    {
        $presenter = new NavPresenter();
        $nav = $presenter->for($this->actor);

        $keys = array_column($nav['sections'], 'key');
        $this->assertContains('incidents', $keys);
        $this->assertNotContains('issues', $keys);

        $incidentSection = collect($nav['sections'])->firstWhere('key', 'incidents');
        $this->assertSame('Incident Register', $incidentSection['label']);
        $this->assertSame('/risk/incidents', $incidentSection['prefixes'][0]);
    }

    #[Test]
    public function index_page_loads_with_incidents_and_metrics(): void
    {
        Incident::create([
            'organization_id' => $this->organization->id,
            'incident_ref' => 'INC-2026-001',
            'title' => 'Core Banking DB Failover Spike',
            'description' => 'System experienced high latency after DB failover.',
            'incident_type' => 'operational',
            'severity' => 'High',
            'status' => 'Reported',
            'occurred_at' => now()->subHours(2),
            'detected_at' => now()->subHour(),
            'reported_at' => now(),
            'business_unit_id' => $this->unit->id,
            'reporter_id' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)->get(route('risk.incidents.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incidents/Index')
                ->has('incidents.data', 1)
                ->where('incidents.data.0.incident_ref', 'INC-2026-001')
                ->has('stats')
            );
    }

    #[Test]
    public function create_page_loads_successfully(): void
    {
        $response = $this->actingAs($this->actor)->get(route('risk.incidents.create'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incidents/Create')
                ->has('types')
                ->has('severities')
            );
    }

    #[Test]
    public function reporting_an_incident_creates_record_timeline_and_statutory_notifications(): void
    {
        $data = [
            'title' => 'Customer Data Exposure on S3 Bucket',
            'description' => 'Misconfigured cloud bucket exposed customer KYC details for 2 hours.',
            'incident_type' => 'data_breach',
            'severity' => 'Critical',
            'occurred_at' => now()->subHours(3)->toDateTimeString(),
            'detected_at' => now()->subHours(2)->toDateTimeString(),
            'business_unit_id' => $this->unit->id,
            'currency' => 'NGN',
            'gross_loss_minor' => 50000000,
            'recovery_minor' => 10000000,
        ];

        $response = $this->actingAs($this->actor)->post(route('risk.incidents.store'), $data);

        $incident = Incident::where('title', 'Customer Data Exposure on S3 Bucket')->first();
        $this->assertNotNull($incident);
        $this->assertSame('data_breach', $incident->incident_type);
        $this->assertSame('Critical', $incident->severity);
        $this->assertSame('Reported', $incident->status);
        $this->assertSame(40000000, $incident->net_loss_minor);

        $response->assertRedirect(route('risk.incidents.show', $incident));

        // Initial timeline entry
        $this->assertDatabaseHas('incident_timeline', [
            'incident_id' => $incident->id,
            'event_type' => 'reported',
        ]);

        // Statutory notification (NDPC 72h data breach)
        $this->assertDatabaseHas('incident_notifications', [
            'incident_id' => $incident->id,
            'regulator_code' => 'NDPC',
            'status' => 'Pending',
        ]);
    }

    #[Test]
    public function triage_and_investigation_lifecycle(): void
    {
        $incident = Incident::create([
            'organization_id' => $this->organization->id,
            'incident_ref' => 'INC-2026-002',
            'title' => 'Unauthorized Card Transactions',
            'description' => 'Suspicious wave of debit card transactions.',
            'incident_type' => 'fraud',
            'severity' => 'Medium',
            'status' => 'Reported',
            'occurred_at' => now()->subHours(5),
            'detected_at' => now()->subHours(3),
            'reported_at' => now(),
            'business_unit_id' => $this->unit->id,
            'reporter_id' => $this->actor->id,
        ]);

        // Triage
        $triageResponse = $this->actingAs($this->actor)->post(route('risk.incidents.triage', $incident), [
            'severity' => 'High',
            'incident_type' => 'fraud',
            'owner_id' => $this->actor->id,
            'investigator_id' => $this->actor->id,
        ]);

        $triageResponse->assertRedirect();
        $incident->refresh();
        $this->assertSame('Triaged', $incident->status);
        $this->assertSame('High', $incident->severity);

        // Progress to investigation
        $progressResponse = $this->actingAs($this->actor)->post(route('risk.incidents.progress', $incident), [
            'action' => 'investigate',
        ]);

        $progressResponse->assertRedirect();
        $incident->refresh();
        $this->assertSame('Under Investigation', $incident->status);
    }

    #[Test]
    public function closure_gate_prevents_closing_with_open_mandatory_actions(): void
    {
        $incident = Incident::create([
            'organization_id' => $this->organization->id,
            'incident_ref' => 'INC-2026-003',
            'title' => 'Wire Transfer Processing Failure',
            'description' => 'Payments dropped during maintenance window.',
            'incident_type' => 'operational',
            'severity' => 'Medium',
            'status' => 'Under Investigation',
            'occurred_at' => now()->subDays(2),
            'detected_at' => now()->subDays(2),
            'reported_at' => now(),
            'business_unit_id' => $this->unit->id,
            'reporter_id' => $this->actor->id,
        ]);

        $action = IncidentAction::create([
            'organization_id' => $this->organization->id,
            'incident_id' => $incident->id,
            'action_type' => 'corrective',
            'title' => 'Patch payment queue retry logic',
            'status' => 'Open',
            'is_mandatory' => true,
            'due_at' => now()->addDays(5),
        ]);

        // Attempt to close should fail
        $closeResponse = $this->actingAs($this->actor)->post(route('risk.incidents.close', $incident));
        $closeResponse->assertSessionHasErrors(['actions']);
        $incident->refresh();
        $this->assertSame('Under Investigation', $incident->status);

        // Complete and verify action
        $action->update(['status' => 'Verified']);

        // Now closure should succeed
        $closeSuccessResponse = $this->actingAs($this->actor)->post(route('risk.incidents.close', $incident));
        $closeSuccessResponse->assertRedirect();
        $incident->refresh();
        $this->assertSame('Closed', $incident->status);
    }
}
