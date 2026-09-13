<?php

namespace Tests\Feature\LossEvents;

use App\Models\BusinessUnit;
use App\Models\LossEvent;
use App\Models\NearMiss;
use App\Models\Organization;
use App\Models\User;
use App\Policies\LossEventPolicy;
use App\Policies\NearMissPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * LossEventPolicy and NearMissPolicy (migration Phase 4.3), including the
 * `approve-loss-event` closure they absorbed — the last inline Gate::define a
 * risk module owned.
 */
class LossEventPolicyTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unit;

    private LossEvent $event;

    private Organization $otherOrg;

    private User $otherActor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach ([
            'loss_event.view', 'loss_event.create', 'loss_event.edit',
            'loss_event.delete', 'loss_event.approve', 'loss_event.cbn_notify',
        ] as $permission) {
            Permission::findOrCreate($permission);
        }

        foreach (['branch-manager', 'chief-risk-officer', 'loss-event-manager', 'compliance-officer'] as $role) {
            Role::findOrCreate($role);
        }

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->event = $this->makeLossEvent(['business_unit_id' => $this->unit->id]);

        TenantContext::bypass(function () {
            $this->otherOrg = Organization::create([
                'name' => 'Other Bank PLC',
                'short_name' => 'OTHB',
                'institution_type' => 'commercial_bank',
                'sector' => 'banking',
                'is_active' => true,
            ]);

            $this->otherActor = User::create([
                'name' => 'Other Officer',
                'email' => 'other@example.test',
                'password' => Hash::make('password'),
                'organization_id' => $this->otherOrg->id,
                'is_active' => true,
            ]);
        }, 'test fixture');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $email, array $roles = ['branch-manager']): User
    {
        $user = User::create([
            'name' => 'Scoped User',
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        foreach ($roles as $role) {
            Role::findOrCreate($role);
        }

        $user->syncRoles($roles);
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    #[Test]
    public function the_policies_are_discovered_for_their_models(): void
    {
        $this->assertInstanceOf(LossEventPolicy::class, Gate::getPolicyFor(LossEvent::class));
        $this->assertInstanceOf(NearMissPolicy::class, Gate::getPolicyFor(NearMiss::class));
    }

    /**
     * The last inline risk-module gate is gone. Only `view-grid` — Phase 2's
     * grid guard — remains in AppServiceProvider.
     */
    #[Test]
    public function the_absorbed_gate_closure_is_gone(): void
    {
        $this->assertFalse(Gate::has('approve-loss-event'));
    }

    /**
     * WorkflowEngine::canAct() asks for the hyphenated name through
     * LossEventBinding::gate(), and Laravel must land it on approveLossEvent().
     */
    #[Test]
    public function the_workflow_spelling_reaches_the_policy(): void
    {
        $cro = $this->userWith(['loss_event.view'], 'cro@example.test', ['chief-risk-officer']);
        $bystander = $this->userWith(['loss_event.view'], 'nobody@example.test');

        $this->assertTrue($cro->can('approve-loss-event', $this->event));
        $this->assertFalse($bystander->can('approve-loss-event', $this->event));
    }

    #[Test]
    public function each_ability_asks_for_its_own_permission(): void
    {
        foreach ([
            'view' => 'loss_event.view',
            'update' => 'loss_event.edit',
            'delete' => 'loss_event.delete',
            'recordRca' => 'loss_event.edit',
            'approveRca' => 'loss_event.approve',
            'notifyRegulator' => 'loss_event.cbn_notify',
        ] as $ability => $permission) {
            $holder = $this->userWith([$permission], "holds-{$ability}@example.test");
            $lacking = $this->userWith(['loss_event.view'], "lacks-{$ability}@example.test");

            $this->assertTrue($holder->can($ability, $this->event), "{$ability} allowed with {$permission}");

            if ($permission !== 'loss_event.view') {
                $this->assertFalse($lacking->can($ability, $this->event), "{$ability} denied without {$permission}");
            }
        }
    }

    /**
     * Writing the RCA and signing it off are different questions — an analysis
     * approved by whoever wrote it is not an approval.
     */
    #[Test]
    public function recording_an_rca_does_not_let_you_approve_it(): void
    {
        $analyst = $this->userWith(['loss_event.view', 'loss_event.edit'], 'analyst@example.test');

        $this->assertTrue($analyst->can('recordRca', $this->event));
        $this->assertFalse($analyst->can('approveRca', $this->event));
    }

    /** The assigned handler may approve without holding an approver role. */
    #[Test]
    public function the_assigned_handler_may_approve(): void
    {
        $handler = $this->userWith(['loss_event.view'], 'handler@example.test');

        $this->assertFalse($handler->can('approve', $this->event));

        $this->event->update(['assigned_to_id' => $handler->id]);

        $this->assertTrue($handler->fresh()->can('approve', $this->event->fresh()));
    }

    #[Test]
    public function an_approver_role_may_approve_without_the_module_permission(): void
    {
        // The workflow engine's own actor looks like this — it may hold
        // approval.act rather than loss_event.approve, which is why the ability
        // does not ask for the module permission.
        foreach (LossEventPolicy::APPROVER_ROLES as $index => $role) {
            $user = $this->userWith([], "approver{$index}@example.test", [$role]);

            $this->assertTrue($user->can('approve', $this->event), $role);
            $this->assertTrue($user->can('reject', $this->event), $role);
        }
    }

    #[Test]
    public function every_ability_stops_at_the_tenant_boundary(): void
    {
        $foreign = null;

        TenantContext::bypass(function () use (&$foreign) {
            $foreign = LossEvent::create([
                'organization_id' => $this->otherOrg->id,
                'event_reference' => 'LE-FOREIGN',
                'title' => 'Theirs',
                'description' => 'Another bank.',
                'basel_l1_category' => 'INTERNAL_FRAUD',
                'basel_l2_category' => 'INTERNAL_FRAUD',
                'cbn_risk_category' => 'OTHER',
                'loss_category' => 'actual_loss',
                'event_severity' => 'MAJOR',
                'date_of_loss' => now()->subDay(),
                'date_discovered' => now(),
                'gross_loss_amount_kobo' => 100,
                'current_status' => 'REPORTED',
                'created_by' => $this->otherActor->id,
            ]);
        }, 'test fixture');

        $cro = $this->userWith(['loss_event.view', 'loss_event.edit', 'loss_event.delete', 'loss_event.approve', 'loss_event.cbn_notify'], 'cro2@example.test', ['chief-risk-officer']);

        foreach (['view', 'update', 'delete', 'recordRca', 'approveRca', 'notifyRegulator', 'approve', 'reject'] as $ability) {
            $this->assertFalse($cro->can($ability, $foreign), $ability);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Near misses */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_near_miss_rides_on_the_loss_event_permissions(): void
    {
        $nearMiss = NearMiss::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'reference' => 'NM-0001',
            'title' => 'Nearly wrong account',
            'description' => 'Caught at checker stage.',
            'date_occurred' => now()->subDay()->toDateString(),
            'date_reported' => now()->toDateString(),
            'severity' => 'minor',
            'status' => 'OPEN',
            'reported_by' => $this->actor->id,
        ]);

        $reader = $this->userWith(['loss_event.view'], 'nm-reader@example.test');
        $reporter = $this->userWith(['loss_event.view', 'loss_event.create'], 'nm-reporter@example.test');

        $this->assertTrue($reader->can('view', $nearMiss));
        $this->assertFalse($reader->can('convert', $nearMiss));

        $this->assertTrue($reporter->can('convert', $nearMiss));
        $this->assertTrue($reporter->can('create', NearMiss::class));

        // And not across the tenant boundary.
        $this->assertFalse($this->otherActor->can('view', $nearMiss));
        $this->assertFalse($this->otherActor->can('convert', $nearMiss));
    }

    #[Test]
    public function super_admin_passes_through_gate_before(): void
    {
        $admin = $this->userWith([], 'admin@example.test', ['super-admin']);

        $this->assertTrue($admin->can('view', $this->event));
        $this->assertTrue($admin->can('approve', $this->event));
    }
}
