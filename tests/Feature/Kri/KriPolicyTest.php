<?php

namespace Tests\Feature\Kri;

use App\Models\KeyRiskIndicator;
use App\Models\MeasureBreach;
use App\Policies\KeyRiskIndicatorPolicy;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantFixture;
use ThirdLine\Platform\Tenancy\TenantContext;

/** KeyRiskIndicatorPolicy (migration Phase 4.1). */
class KriPolicyTest extends KriTestCase
{
    #[Test]
    public function the_policies_are_discovered_for_their_models(): void
    {
        $this->assertInstanceOf(KeyRiskIndicatorPolicy::class, Gate::getPolicyFor(KeyRiskIndicator::class));

        // A breach ability has to live on the BREACH's policy: Laravel
        // resolves from the subject's class, so one parked on the KRI's policy
        // would never be reached and would deny everyone silently.
        $this->assertInstanceOf(\App\Policies\MeasureBreachPolicy::class, Gate::getPolicyFor(MeasureBreach::class));
    }

    #[Test]
    public function each_ability_asks_for_its_own_permission(): void
    {
        $cases = [
            'view' => 'kri.view',
            'update' => 'kri.edit',
            'delete' => 'kri.delete',
            'recordMeasurement' => 'kri.record_measurement',
        ];

        foreach ($cases as $ability => $permission) {
            $holder = $this->userWith([$permission], "holds-{$permission}@example.test");
            $other = $this->userWith(['kri.view'], "lacks-{$permission}@example.test");

            $this->assertTrue($holder->can($ability, $this->kri), "{$ability} allowed with {$permission}");

            if ($permission !== 'kri.view') {
                $this->assertFalse($other->can($ability, $this->kri), "{$ability} denied without {$permission}");
            }
        }
    }

    /**
     * Acknowledging carries its own permission rather than riding on kri.edit:
     * it is what makes mean time to acknowledge a real number, and it is a
     * different job from editing the indicator's definition.
     */
    #[Test]
    public function acknowledging_a_breach_is_its_own_permission(): void
    {
        $breach = $this->makeBreach($this->organization->id);

        $editor = $this->userWith(['kri.view', 'kri.edit'], 'editor@example.test');
        $acknowledger = $this->userWith(['kri.view', 'kri.acknowledge_breach'], 'ack@example.test');

        $this->assertFalse($editor->can('acknowledge', $breach));
        $this->assertTrue($acknowledger->can('acknowledge', $breach));
        $this->assertTrue($acknowledger->can('resolve', $breach));
    }

    #[Test]
    public function every_ability_stops_at_the_tenant_boundary(): void
    {
        $foreignKri = null;

        TenantContext::bypass(function () use (&$foreignKri) {
            $foreignKri = KeyRiskIndicator::create([
                'organization_id' => $this->otherOrg->id,
                'risk_id' => $this->foreignRisk->id,
                'kri_code' => 'KRI-FOREIGN',
                'name' => 'Theirs',
                'metric_formula' => '',
                'data_source' => '',
                'measurement_frequency' => 'monthly',
                'unit_of_measure' => '%',
                'threshold_direction' => 'higher_worse',
                'owner_id' => $this->otherActor->id,
                'current_status' => 'green',
                'is_active' => true,
                'created_by' => $this->otherActor->id,
            ]);
        }, 'test fixture');

        foreach (['view', 'update', 'delete', 'recordMeasurement'] as $ability) {
            $this->assertFalse($this->actor->can($ability, $foreignKri), $ability);
        }

        $foreignBreach = $this->makeBreach($this->otherOrg->id);

        $this->assertFalse($this->actor->can('acknowledge', $foreignBreach));
    }

    #[Test]
    public function managing_thresholds_asks_for_kri_edit(): void
    {
        $reader = $this->userWith(['kri.view'], 'reader@example.test');
        $editor = $this->userWith(['kri.view', 'kri.edit'], 'editor2@example.test');

        $this->assertFalse($reader->can('manageThresholds', KeyRiskIndicator::class));
        $this->assertTrue($editor->can('manageThresholds', KeyRiskIndicator::class));
    }

    #[Test]
    public function super_admin_passes_through_gate_before(): void
    {
        $admin = $this->userWith([], 'admin@example.test', ['super-admin']);

        $this->assertTrue($admin->can('view', $this->kri));
        $this->assertTrue($admin->can('delete', $this->kri));
    }

    /**
     * measure_id, object_id and period_id are all NOT NULL with foreign keys,
     * so a breach needs the three rows it points at. TenantFixture makes them.
     */
    private function makeBreach(int $organizationId): MeasureBreach
    {
        static $sequence = 0;
        $sequence++;

        $fixture = new TenantFixture;

        return MeasureBreach::withoutGlobalScopes()->create([
            'organization_id' => $organizationId,
            'measure_id' => $fixture->make('measures', $organizationId, [
                'name' => "Measure {$sequence}",
                'code' => sprintf('MSR-%03d', $sequence),
            ]),
            'object_id' => $fixture->make('objects', $organizationId),
            'period_id' => $fixture->make('periods', $organizationId),
            'band_from' => 'green',
            'band_to' => 'red',
            'value' => 42,
            'threshold_value' => 10,
            'severity' => 'high',
            'status' => 'open',
            'breached_at' => now(),
        ]);
    }
}
