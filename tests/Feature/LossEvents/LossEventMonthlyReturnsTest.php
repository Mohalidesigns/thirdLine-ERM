<?php

namespace Tests\Feature\LossEvents;

use App\Models\BusinessUnit;
use App\Models\KriDepartmentContact;
use App\Models\LossEvent;
use App\Models\LossEventReturn;
use App\Models\User;
use App\Presenters\NavPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

class LossEventMonthlyReturnsTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unit;

    private User $champion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach ([
            'loss_event.view', 'loss_event.create', 'loss_event.edit',
            'loss_event.approve', 'loss_event.submit_return', 'loss_event.send_reminder',
            'kri.view', 'kri.submit_return', 'rcsa.view', 'rcsa.submit', 'rcsa_cycle.view',
            'rcsa_assessment.view', 'rcsa_assessment.complete', 'rcsa_assessment.submit',
        ] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        Role::findOrCreate('risk-champion');

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'OPS',
            'name' => 'Operations Department',
            'is_active' => true,
        ]);

        $this->champion = User::factory()->create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'is_active' => true,
        ]);
        $this->champion->assignRole('risk-champion');

        KriDepartmentContact::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'user_id' => $this->champion->id,
            'role' => 'risk_champion',
            'assigned_by' => $this->actor->id,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function risk_champion_nav_includes_rcsa_and_loss_events(): void
    {
        $presenter = new NavPresenter();
        $nav = $presenter->for($this->champion);

        $keys = array_column($nav['sections'], 'key');
        $this->assertContains('rcsa', $keys);
        $this->assertContains('loss_events', $keys);
        $this->assertContains('kri_monitoring', $keys);
        $this->assertContains('workflows', $keys);

        // Does NOT see admin or Command Center tabs
        $this->assertEmpty($nav['primary']);
        $this->assertNull($nav['admin']);
    }

    #[Test]
    public function monthly_returns_page_renders_with_departments(): void
    {
        $response = $this->actingAs($this->champion)->get(route('risk.loss-events.returns.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LossEvents/Returns')
                ->has('returns')
                ->has('periods')
                ->has('stats')
                ->where('stats.total_departments', 1)
                ->where('stats.pending', 1)
            );
    }

    #[Test]
    public function champion_can_submit_nil_loss_return_with_comment(): void
    {
        $periodCode = now()->format('Y-m');

        // Initial check creates pending return
        $this->actingAs($this->champion)->get(route('risk.loss-events.returns.index'));

        $return = LossEventReturn::where('business_unit_id', $this->unit->id)
            ->where('period_code', $periodCode)
            ->firstOrFail();

        $this->assertSame('pending', $return->status);

        // Submit nil return
        $response = $this->actingAs($this->champion)->post(route('risk.loss-events.returns.submit', $return), [
            'has_loss' => false,
            'comment' => 'no loss was recorded',
        ]);

        $response->assertRedirect();
        $return->refresh();

        $this->assertSame('submitted', $return->status);
        $this->assertFalse($return->has_loss);
        $this->assertSame('no loss was recorded', $return->comment);
        $this->assertSame($this->champion->id, $return->submitted_by);
        $this->assertNotNull($return->submitted_at);
    }

    #[Test]
    public function logging_a_loss_event_automatically_updates_monthly_return(): void
    {
        $periodCode = now()->format('Y-m');

        $return = LossEventReturn::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'period_code' => $periodCode,
            'status' => 'pending',
            'has_loss' => false,
            'loss_events_count' => 0,
        ]);

        TenantContext::set($this->organization->id);

        $response = $this->actingAs($this->actor)->post(route('risk.loss-events.store'), [
            'event_title' => 'ATM Hardware Dispense Discrepancy',
            'event_description' => 'Discrepancy observed at branch ATM.',
            'date_of_loss' => now()->toDateString(),
            'date_discovered' => now()->toDateString(),
            'business_unit_id' => $this->unit->id,
            'basel_event_type' => 'clients_products',
            'event_type' => 'actual_loss',
            'severity' => 'minor',
            'gross_loss_amount' => 50000,
            'currency' => 'NGN',
            'reported_by' => $this->actor->id,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $return->refresh();

        $this->assertSame('submitted', $return->status);
        $this->assertTrue($return->has_loss);
        $this->assertSame(1, $return->loss_events_count);
    }

    #[Test]
    public function orm_can_send_reminders_to_pending_departments(): void
    {
        $periodCode = now()->format('Y-m');

        // Ensure pending return
        $this->actingAs($this->champion)->get(route('risk.loss-events.returns.index'));

        // Admin sends reminders
        $response = $this->actingAs($this->actor)->post(route('risk.loss-events.returns.remind'), [
            'period_code' => $periodCode,
        ]);

        $response->assertRedirect();

        $return = LossEventReturn::where('business_unit_id', $this->unit->id)
            ->where('period_code', $periodCode)
            ->firstOrFail();

        $this->assertNotNull($return->reminded_at);
    }
}
