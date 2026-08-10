<?php

namespace Tests\Support;

use App\Models\Control;
use App\Models\LossEvent;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

/**
 * Hand-written, semantically meaningful fixtures for the calculation-service
 * tests.
 *
 * TenantFixture walks the schema and fills every NOT NULL column with a
 * type-valid placeholder, which is right for isolation assertions and useless
 * here: a VaR or an effectiveness percentage has to be computed from numbers
 * somebody chose. These builders take the fields the calculation reads as
 * arguments and default the rest.
 */
trait CreatesDomainFixtures
{
    protected Organization $organization;

    protected User $actor;

    protected RiskCategory $category;

    private int $fixtureSequence = 0;

    protected function bootDomainFixtures(string $name = 'Test Bank PLC'): void
    {
        $this->organization = Organization::create([
            'name' => $name,
            'short_name' => 'TSTB'.($this->fixtureSequence++),
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);

        $this->actor = User::create([
            'name' => 'Risk Officer',
            'email' => 'risk-officer-'.$this->organization->id.'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->category = RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OPS',
            'name' => 'Operational Risk',
        ]);
    }

    protected function makeRisk(array $attributes = []): Risk
    {
        $n = ++$this->fixtureSequence;

        return Risk::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_code' => 'RK-TEST-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'title' => 'Risk '.$n,
            'description' => 'Fixture risk '.$n,
            'category_id' => $this->category->id,
            'status' => 'active',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    protected function makeControl(array $attributes = []): Control
    {
        $n = ++$this->fixtureSequence;

        return Control::create(array_merge([
            'organization_id' => $this->organization->id,
            'control_code' => 'CTL-TEST-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => 'Control '.$n,
            'status' => 'active',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    /**
     * Attach a control to a risk with an explicit pivot weight.
     */
    protected function attachControl(Risk $risk, Control $control, float $weight = 1.0, bool $isKey = false): void
    {
        $risk->controls()->attach($control->id, [
            'control_weight' => $weight,
            'is_key_control' => $isKey,
            'mapping_rationale' => 'fixture',
        ]);
    }

    /**
     * A loss event carrying only the columns the regulatory engine reads,
     * with schema-required columns defaulted to non-triggering values.
     */
    protected function makeLossEvent(array $attributes = []): LossEvent
    {
        $n = ++$this->fixtureSequence;

        return LossEvent::create(array_merge([
            'organization_id' => $this->organization->id,
            'event_reference' => 'LE-TEST-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'title' => 'Loss event '.$n,
            'description' => 'Fixture loss event '.$n,
            'date_of_loss' => now()->subDays(3)->toDateString(),
            'date_discovered' => now()->subDay()->toDateString(),
            'date_reported' => now()->toDateString(),
            'basel_l1_category' => 'EXECUTION_DELIVERY',
            'basel_l2_category' => 'TRANSACTION_CAPTURE',
            'cbn_risk_category' => 'PROCESS_RISK',
            'gross_loss_amount_kobo' => 0,
            'insurance_recovery_kobo' => 0,
            'other_recovery_kobo' => 0,
            'pending_recovery_kobo' => 0,
            'actual_recovery_kobo' => 0,
            'loss_category' => 'actual_loss',
            'event_severity' => 'MODERATE',
            'current_status' => 'NEW',
            'created_by' => $this->actor->id,
        ], $attributes));
    }
}
