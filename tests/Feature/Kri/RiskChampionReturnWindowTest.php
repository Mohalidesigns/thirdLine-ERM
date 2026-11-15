<?php

namespace Tests\Feature\Kri;

use App\Enums\KriReturnStatus;
use App\Models\BusinessUnit;
use App\Models\KriCycleRun;
use App\Models\KriReturn;
use App\Models\User;
use App\Presenters\Kri\KriReturnPresenter;
use App\Services\Kri\KriCycleService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

class RiskChampionReturnWindowTest extends KriCycleTestCase
{
    private User $champion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->champion = $this->makeStaff('Chioma Champion', ['kri.submit_return', 'kri.view']);
        $this->makeContact($this->unit, $this->champion);
    }

    #[Test]
    public function return_remains_open_for_recording_for_the_entire_month_when_due_window_is_month(): void
    {
        Config::set('risk.kri_due_window', 'month');

        $this->deptKri(null, ['name' => 'IT Uptime']);

        $service = app(KriCycleService::class);
        $return = TenantContext::actingAs($this->organization->id, function () use ($service) {
            return $service->ensureDepartmentReturn($this->unit);
        });

        $this->assertNotNull($return);
        $this->assertSame('draft', $return->status->value);
        $this->assertSame('2026-11-30', $return->due_on->toDateString(), 'Return is open for recording for the full month of November');
        $this->assertNull($return->daysOverdue(\Carbon\CarbonImmutable::parse('2026-11-15')), 'Not overdue in the middle of November');
        $this->assertNull($return->daysOverdue(\Carbon\CarbonImmutable::parse('2026-11-30')), 'Not overdue on the last day of November');
    }

    #[Test]
    public function visiting_my_return_automatically_opens_return_for_champion_who_has_not_recorded_part(): void
    {
        Config::set('risk.kri_due_window', 'month');

        $this->deptKri(null, ['name' => 'Cyber Incidents']);

        // Before visiting, no return exists for this department
        $this->assertSame(0, KriReturn::query()->where('business_unit_id', $this->unit->id)->count());

        // Champion visits /risk/kri/my-return
        $response = $this->actingAs($this->champion)->get(route('risk.kri.my-return'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Kri/MyReturn')
            ->has('return')
            ->where('return.status', 'draft')
            ->where('department.name', $this->unit->name)
            ->has('lines', 1)
        );

        // A return row has been opened in the database
        $return = KriReturn::query()->where('business_unit_id', $this->unit->id)->first();
        $this->assertNotNull($return);
        $this->assertSame('draft', $return->status->value);
        $this->assertSame(1, $return->lines()->count());
    }

    #[Test]
    public function dashboard_card_recognizes_open_cycle_when_returns_exist(): void
    {
        $this->deptKri(null, ['name' => 'Branch Cash Difference']);

        $service = app(KriCycleService::class);
        TenantContext::actingAs($this->organization->id, function () use ($service) {
            $service->ensureDepartmentReturn($this->unit);
        });

        $presenter = app(KriReturnPresenter::class);
        $card = TenantContext::actingAs($this->organization->id, function () use ($presenter) {
            return $presenter->card($this->champion, null);
        });

        $this->assertTrue($card['cycleOpened'], 'Dashboard reports cycle is opened when returns exist');
        $this->assertSame(1, $card['summary']['denominator']);
        $this->assertSame(1, $card['summary']['not_submitted']);
        $this->assertSame(0, $card['summary']['submitted']);
    }
}
