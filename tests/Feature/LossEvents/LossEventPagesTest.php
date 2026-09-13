<?php

namespace Tests\Feature\LossEvents;

use App\Models\BusinessUnit;
use App\Models\LossEvent;
use App\Models\User;
use App\Support\Migration\Ported;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/** The eight ported loss-event screens (migration Phase 4.3). */
class LossEventPagesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unit;

    private LossEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach ([
            'loss_event.view', 'loss_event.create', 'loss_event.edit',
            'loss_event.delete', 'loss_event.approve', 'report.view',
        ] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        Role::findOrCreate('branch-manager');

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->event = $this->makeLossEvent([
            'business_unit_id' => $this->unit->id,
            'event_reference' => 'LE-0001',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $email): User
    {
        $user = User::create([
            'name' => 'Scoped User',
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $user->syncRoles(['branch-manager']);
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    #[Test]
    public function every_ported_route_is_registered_as_ported(): void
    {
        foreach ([
            'risk.loss-events.dashboard',
            'risk.loss-events.create',
            'risk.loss-events.show',
            'risk.loss-events.edit',
            'risk.loss-events.approvals',
            'risk.loss-events.rca',
            'risk.loss-events.reports',
            'risk.loss-events.create-near-miss',
        ] as $name) {
            $this->assertTrue(Ported::isRoute($name), $name);
        }
    }

    #[Test]
    public function the_blade_views_are_gone(): void
    {
        $this->assertDirectoryDoesNotExist(resource_path('views/risk/loss-events'));
    }

    #[Test]
    public function every_screen_renders(): void
    {
        foreach ([
            ['risk.loss-events.dashboard', [], 'LossEvents/Dashboard'],
            ['risk.loss-events.create', [], 'LossEvents/Create'],
            ['risk.loss-events.approvals', [], 'LossEvents/Approvals'],
            ['risk.loss-events.rca', [], 'LossEvents/Rca'],
            ['risk.loss-events.reports', [], 'LossEvents/Reports'],
            ['risk.loss-events.create-near-miss', [], 'LossEvents/CreateNearMiss'],
            ['risk.loss-events.show', [$this->event], 'LossEvents/Show'],
            ['risk.loss-events.edit', [$this->event], 'LossEvents/Edit'],
        ] as [$name, $params, $component]) {
            $this->actingAs($this->actor)
                ->get(route($name, $params))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    #[Test]
    public function a_user_without_loss_event_view_is_refused(): void
    {
        $nobody = $this->userWith([], 'nobody@example.test');

        foreach (['risk.loss-events.dashboard', 'risk.loss-events.approvals', 'risk.loss-events.rca'] as $route) {
            $this->actingAs($nobody)->get(route($route))->assertForbidden();
        }
    }

    /**
     * The edit form is seeded in the FORM's field names — the 200038 ones —
     * because that is what the write path accepts.
     */
    #[Test]
    public function the_edit_page_hands_back_the_form_field_names(): void
    {
        $event = $this->makeLossEvent([
            'business_unit_id' => $this->unit->id,
            'title' => 'Wire fraud',
            'basel_l1_category' => 'EXTERNAL_FRAUD',
            'event_severity' => 'MAJOR',
            'gross_loss_amount_kobo' => 25_000_000,
            'insurance_recovery_kobo' => 5_000_000,
        ]);

        $this->actingAs($this->actor)
            ->get(route('risk.loss-events.edit', $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LossEvents/Edit')
                ->where('event.event_title', 'Wire fraud')
                // Lower-cased back to what the select offers; the column is
                // upper case because the regulatory matcher needs it so.
                ->where('event.basel_event_type', 'external_fraud')
                ->where('event.severity', 'major')
                // Kobo back to naira for the input.
                ->where('event.gross_loss_amount', 250000)
                ->where('event.insurance_recovery', 50000));
    }

    /** The show page computes the net once, server-side. */
    #[Test]
    public function the_show_page_reports_the_net_loss(): void
    {
        $event = $this->makeLossEvent([
            'business_unit_id' => $this->unit->id,
            'gross_loss_amount_kobo' => 40_000_000,
            'insurance_recovery_kobo' => 10_000_000,
            'other_recovery_kobo' => 2_500_000,
        ]);

        $this->actingAs($this->actor)
            ->get(route('risk.loss-events.show', $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.grossLoss', 400000)
                ->where('event.insuranceRecovery', 100000)
                ->where('event.otherRecovery', 25000)
                ->where('event.netLoss', 275000)
                ->where('can.update', true));
    }

    #[Test]
    public function the_approvals_queue_lists_only_pending_events(): void
    {
        $pending = $this->makeLossEvent([
            'business_unit_id' => $this->unit->id,
            'event_reference' => 'LE-PEND',
            'current_status' => 'PENDING_APPROVAL',
        ]);
        $this->makeLossEvent(['business_unit_id' => $this->unit->id, 'current_status' => 'CLOSED']);

        $this->actingAs($this->actor)
            ->get(route('risk.loss-events.approvals'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('pending.data', 1)
                ->where('pending.data.0.reference', 'LE-PEND'));
    }

    /** The severity filter is reachable now — the Blade page drew no control. */
    #[Test]
    public function the_approvals_queue_filters_by_severity(): void
    {
        $this->makeLossEvent([
            'business_unit_id' => $this->unit->id,
            'event_reference' => 'LE-MAJ',
            'current_status' => 'PENDING_APPROVAL',
            'event_severity' => 'MAJOR',
        ]);
        $this->makeLossEvent([
            'business_unit_id' => $this->unit->id,
            'event_reference' => 'LE-MIN',
            'current_status' => 'PENDING_APPROVAL',
            'event_severity' => 'MINOR',
        ]);

        $this->actingAs($this->actor)
            ->get(route('risk.loss-events.approvals', ['severity' => 'major']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('pending.data', 1)
                ->where('pending.data.0.reference', 'LE-MAJ'));
    }

    #[Test]
    public function the_reports_page_lists_only_exports_that_exist(): void
    {
        $props = $this->actingAs($this->actor)
            ->get(route('risk.loss-events.reports'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNotEmpty($props['exports'], 'the regulatory exports are listed');

        foreach ($props['exports'] as $export) {
            $this->assertArrayHasKey('url', $export);
            $this->assertNotEmpty($export['url']);
        }
    }

    #[Test]
    public function no_form_request_uses_the_untenanted_exists_rule(): void
    {
        foreach (glob(app_path('Http/Requests/LossEvents/*.php')) as $file) {
            $source = file_get_contents($file);

            foreach (["'exists:", '"exists:'] as $quoted) {
                $this->assertStringNotContainsString($quoted, $source, basename($file));
            }
        }
    }

    #[Test]
    public function creating_rejects_another_tenants_business_unit(): void
    {
        $foreignUnit = null;

        TenantContext::bypass(function () use (&$foreignUnit) {
            $org = \App\Models\Organization::create([
                'name' => 'Other Bank PLC',
                'short_name' => 'OTHB',
                'institution_type' => 'commercial_bank',
                'sector' => 'banking',
                'is_active' => true,
            ]);

            $foreignUnit = BusinessUnit::create([
                'organization_id' => $org->id,
                'code' => 'THEIRS',
                'name' => 'Their Unit',
                'is_active' => true,
            ]);
        }, 'test fixture');

        $this->actingAs($this->actor)
            ->post(route('risk.loss-events.store'), [
                'event_title' => 'Cross-tenant attempt',
                'event_description' => 'Should be rejected.',
                'date_of_loss' => now()->subDay()->toDateString(),
                'date_discovered' => now()->toDateString(),
                'business_unit_id' => $foreignUnit->id,
                'reported_by' => $this->actor->id,
                'basel_event_type' => 'internal_fraud',
                'event_type' => 'actual_loss',
                'severity' => 'minor',
                'gross_loss_amount' => 1000,
                'currency' => 'NGN',
            ])
            ->assertSessionHasErrors('business_unit_id');
    }
}
