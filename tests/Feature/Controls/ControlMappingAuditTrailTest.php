<?php

namespace Tests\Feature\Controls;

use App\Models\Control;
use App\Models\RiskAuditTrail;
use App\Models\RiskControlMapping;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;

/**
 * Mapping a control onto a risk, or taking it off, changes the risk's control
 * picture, so each of the four paths that write or delete a
 * risk_control_mapping row leaves an entry on the RISK's audit trail: the
 * register's map-control, the control form's risk_ids, the control page's
 * link-risk and its unlink-risk. None of them wrote one before.
 */
class ControlMappingAuditTrailTest extends ControlsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // map-control sits behind permission:risk.edit and RiskPolicy::update.
        Permission::findOrCreate('risk.edit');
        $this->actor->givePermissionTo('risk.edit');
    }

    #[Test]
    public function mapping_a_control_from_the_register_writes_a_risk_audit_entry(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.register.map-control', $this->risk), [
                'control_id' => $this->control->id,
                'is_key_control' => true,
                'control_weight' => 40,
                'mapping_rationale' => 'Primary preventive control.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('risk.register.show', $this->risk));

        $entry = $this->onlyEntry('control_mapped');

        $this->assertNull($entry->old_value);
        $this->assertSame([
            'control_id' => $this->control->id,
            'control_code' => $this->control->control_code,
            'control_weight' => '40.00',
            'is_key_control' => true,
        ], json_decode($entry->new_value, true));

        // The mapping itself now names who made it too.
        $this->assertDatabaseHas('risk_control_mapping', [
            'risk_id' => $this->risk->id,
            'control_id' => $this->control->id,
            'created_by' => $this->actor->id,
        ]);
    }

    #[Test]
    public function a_mapping_without_a_weight_records_the_column_default_not_null(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.register.map-control', $this->risk), ['control_id' => $this->control->id])
            ->assertSessionHasNoErrors();

        $new = json_decode($this->onlyEntry('control_mapped')->new_value, true);

        $this->assertSame('1.00', $new['control_weight']);
        $this->assertFalse($new['is_key_control']);
    }

    #[Test]
    public function mapping_a_control_already_mapped_writes_no_second_entry(): void
    {
        $payload = ['control_id' => $this->control->id];

        $this->actingAs($this->actor)->post(route('risk.register.map-control', $this->risk), $payload);
        $this->actingAs($this->actor)
            ->post(route('risk.register.map-control', $this->risk), $payload)
            ->assertSessionHas('error');

        $this->onlyEntry('control_mapped');
    }

    #[Test]
    public function linking_risks_from_the_control_form_writes_an_entry_on_each_risk(): void
    {
        $second = $this->makeRisk(['risk_owner_id' => $this->actor->id]);

        $this->actingAs($this->actor)
            ->post(route('risk.controls.store'), $this->validControl([
                'risk_ids' => [$this->risk->id, $second->id],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $control = Control::where('name', 'Daily suspense account reconciliation')->firstOrFail();

        foreach ([$this->risk, $second] as $risk) {
            $entry = $this->onlyEntry('control_mapped', $risk->id);

            $this->assertSame([
                'control_id' => $control->id,
                'control_code' => $control->control_code,
                'control_weight' => '1.00',
                'is_key_control' => false,
            ], json_decode($entry->new_value, true));
        }
    }

    #[Test]
    public function linking_a_risk_from_the_control_page_writes_a_risk_audit_entry(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.controls.link-risk', $this->control), [
                'risk_id' => $this->risk->id,
                'weight' => 25,
                'rationale' => 'Secondary detective layer.',
                'is_key_control' => false,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame([
            'control_id' => $this->control->id,
            'control_code' => $this->control->control_code,
            'control_weight' => '25.00',
            'is_key_control' => false,
        ], json_decode($this->onlyEntry('control_mapped')->new_value, true));
    }

    #[Test]
    public function linking_a_risk_already_linked_writes_no_second_entry(): void
    {
        $payload = ['risk_id' => $this->risk->id];

        $this->actingAs($this->actor)->post(route('risk.controls.link-risk', $this->control), $payload);
        $this->actingAs($this->actor)
            ->post(route('risk.controls.link-risk', $this->control), $payload)
            ->assertSessionHas('error');

        $this->onlyEntry('control_mapped');
    }

    #[Test]
    public function unlinking_writes_a_risk_audit_entry_holding_what_was_removed(): void
    {
        $this->attachControl($this->risk, $this->control, weight: 30, isKey: true);

        $this->actingAs($this->actor)
            ->delete(route('risk.controls.unlink-risk', [$this->control, $this->risk]))
            ->assertRedirect();

        $this->assertSame(0, RiskControlMapping::where('control_id', $this->control->id)->count());

        $entry = $this->onlyEntry('control_unmapped');

        $this->assertNull($entry->new_value);
        $this->assertSame([
            'control_id' => $this->control->id,
            'control_code' => $this->control->control_code,
            'control_weight' => '30.00',
            'is_key_control' => true,
        ], json_decode($entry->old_value, true));
    }

    #[Test]
    public function the_entries_appear_on_the_risk_detail_audit_trail(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.register.map-control', $this->risk), ['control_id' => $this->control->id]);
        $this->actingAs($this->actor)
            ->delete(route('risk.controls.unlink-risk', [$this->control, $this->risk]));

        $actions = $this->risk->auditTrails()->pluck('action_type')->all();

        $this->assertContains('control_mapped', $actions);
        $this->assertContains('control_unmapped', $actions);
    }

    #[Test]
    public function the_entries_keep_the_append_only_chain_intact(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.controls.link-risk', $this->control), ['risk_id' => $this->risk->id]);
        $this->actingAs($this->actor)
            ->delete(route('risk.controls.unlink-risk', [$this->control, $this->risk]));

        // Re-read so the hash is checked against what the database stored —
        // the text it returns, not the attributes the model was built with.
        foreach (RiskAuditTrail::where('organization_id', $this->organization->id)->orderBy('id')->get() as $row) {
            $this->assertSame($row->expectedHash(), $row->hash, "row {$row->id} does not verify");
        }
    }

    #[Test]
    public function a_failed_audit_write_rolls_the_mapping_back(): void
    {
        RiskAuditTrail::creating(function () {
            throw new \RuntimeException('audit store down');
        });

        foreach ([
            fn () => app(\App\Services\Register\RiskRegisterService::class)
                ->mapControl($this->risk, ['control_id' => $this->control->id], $this->actor->id),
            fn () => app(\App\Services\Controls\ControlService::class)
                ->linkRisk($this->control, ['risk_id' => $this->risk->id], $this->actor->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('the audit failure was swallowed');
            } catch (\RuntimeException $e) {
                $this->assertSame('audit store down', $e->getMessage());
            }
        }

        $this->assertSame(0, RiskControlMapping::where('control_id', $this->control->id)->count());
    }

    #[Test]
    public function a_failed_audit_write_on_unlink_keeps_the_mapping(): void
    {
        $this->attachControl($this->risk, $this->control, weight: 30, isKey: true);

        RiskAuditTrail::creating(function () {
            throw new \RuntimeException('audit store down');
        });

        $this->expectException(\RuntimeException::class);

        try {
            app(\App\Services\Controls\ControlService::class)
                ->unlinkRisk($this->control, $this->risk, $this->actor->id);
        } finally {
            $this->assertSame(1, RiskControlMapping::where('control_id', $this->control->id)->count());
        }
    }

    #[Test]
    public function a_failed_audit_write_on_control_create_leaves_no_control_and_no_mapping(): void
    {
        RiskAuditTrail::creating(function () {
            throw new \RuntimeException('audit store down');
        });

        $before = Control::count();

        try {
            app(\App\Services\Controls\ControlService::class)->create(
                $this->validControl(['risk_ids' => [$this->risk->id]]),
                $this->organization->id,
                $this->actor->id,
            );
            $this->fail('the audit failure was swallowed');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($before, Control::count());
        $this->assertSame(0, RiskControlMapping::count());
    }

    #[Test]
    public function a_system_caller_with_no_user_writes_an_unattributed_entry(): void
    {
        app(\App\Services\Controls\ControlService::class)
            ->linkRisk($this->control, ['risk_id' => $this->risk->id], null);

        $entry = RiskAuditTrail::where('entity_id', $this->risk->id)->where('action_type', 'control_mapped')->sole();
        $this->assertNull($entry->changed_by);

        app(\App\Services\Controls\ControlService::class)->unlinkRisk($this->control, $this->risk);

        $this->assertSame(1, RiskAuditTrail::where('entity_id', $this->risk->id)->where('action_type', 'control_unmapped')->count());
    }

    #[Test]
    public function a_cross_tenant_control_or_risk_is_refused_and_writes_nothing(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.register.map-control', $this->risk), ['control_id' => $this->foreignControl->id]);

        $this->actingAs($this->actor)
            ->post(route('risk.controls.link-risk', $this->control), ['risk_id' => $this->foreignRisk->id]);

        \ThirdLine\Platform\Tenancy\TenantContext::bypass(function () {
            $this->assertSame(0, RiskControlMapping::withoutGlobalScopes()->count());
            $this->assertSame(0, RiskAuditTrail::withoutGlobalScopes()->whereIn('action_type', ['control_mapped', 'control_unmapped'])->count());
        }, 'test assertion');
    }

    #[Test]
    public function a_control_form_with_a_foreign_risk_id_writes_no_entry_on_it(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.controls.store'), $this->validControl(['risk_ids' => [$this->foreignRisk->id]]));

        \ThirdLine\Platform\Tenancy\TenantContext::bypass(function () {
            $this->assertSame(0, RiskControlMapping::withoutGlobalScopes()->count());
            $this->assertSame(0, RiskAuditTrail::withoutGlobalScopes()->where('entity_id', $this->foreignRisk->id)->where('action_type', 'control_mapped')->count());
        }, 'test assertion');
    }

    /**
     * The single entry of this action on the risk, attributed to the actor.
     */
    private function onlyEntry(string $action, ?int $riskId = null): RiskAuditTrail
    {
        $riskId ??= $this->risk->id;

        $entries = RiskAuditTrail::query()
            ->where('entity_type', $this->risk->getMorphClass())
            ->where('entity_id', $riskId)
            ->where('action_type', $action)
            ->get();

        $this->assertCount(1, $entries, "expected one {$action} entry on risk {$riskId}");

        $entry = $entries->first();

        $this->assertSame('controls', $entry->field_changed);
        $this->assertSame($this->actor->id, (int) $entry->changed_by);
        $this->assertSame($this->organization->id, (int) $entry->organization_id);

        return $entry;
    }
}
