<?php

namespace Tests\Feature\Controls;

use App\Models\Control;
use App\Models\Risk;
use App\Models\RiskAuditTrail;
use App\Models\RiskControlMapping;
use App\Services\ControlEffectivenessService;
use App\Services\Controls\ControlService;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;

/**
 * ControlEffectivenessService::recalculateForRisk() rewrites a risk's
 * control_effectiveness_pct, residual_score and residual_rating. It was the
 * one change to a risk that left nothing on the risk's audit trail. It now
 * writes a `residual_recalculated` entry whenever one of the three moves, and
 * nothing when none does.
 *
 * Also pinned here: every path that maps or unmaps a control now
 * recalculates. Only link-risk and unlink-risk used to; map-control and the
 * control form's risk_ids left the residual score stale.
 *
 * The fixture: inherent 20 (Critical) with no controls, so the residual is 20.
 * The one control is `effective` (95%), so mapping it gives
 * 20 × (1 − 0.95) = 1, rated Low. (`effective` because the library form
 * offers only Control::EFFECTIVENESS_RATINGS.)
 */
class ResidualRecalculationAuditTrailTest extends ControlsTestCase
{
    private const UNCONTROLLED = [
        'control_effectiveness_pct' => '0.00',
        'residual_score' => 20,
        'residual_rating' => 'Critical',
    ];

    private const CONTROLLED = [
        'control_effectiveness_pct' => '95.00',
        'residual_score' => 1,
        'residual_rating' => 'Low',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('risk.edit');
        $this->actor->givePermissionTo('risk.edit');

        $this->risk->update([
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_score' => 20,
            'inherent_rating' => 'Critical',
            'control_effectiveness_pct' => 0,
            'residual_score' => 20,
            'residual_rating' => 'Critical',
        ]);

        $this->control->update(['effectiveness_rating' => 'effective', 'effectiveness_pct' => null]);
    }

    #[Test]
    public function mapping_a_control_from_the_register_recalculates_and_records_the_move(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.register.map-control', $this->risk), ['control_id' => $this->control->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('risk.register.show', $this->risk));

        $this->assertResidual(self::CONTROLLED, $this->risk);

        $entry = $this->onlyEntry($this->risk);
        $this->assertSame(self::UNCONTROLLED, json_decode($entry->old_value, true));
        $this->assertSame(self::CONTROLLED, json_decode($entry->new_value, true));
        $this->assertSame($this->actor->id, (int) $entry->changed_by);

        // The control_mapped entry comes first, then the move it caused.
        $this->assertSame(
            ['control_mapped', 'residual_recalculated'],
            $this->actionsOn($this->risk),
        );
    }

    #[Test]
    public function linking_risks_from_the_control_form_recalculates_each_one(): void
    {
        $second = $this->makeRisk([
            'risk_owner_id' => $this->actor->id,
            'inherent_likelihood' => 4,
            'inherent_impact' => 4,
            'inherent_score' => 16,
            'residual_score' => 16,
            'residual_rating' => 'High',
            'control_effectiveness_pct' => 0,
        ]);

        $this->actingAs($this->actor)
            ->post(route('risk.controls.store'), $this->validControl([
                'risk_ids' => [$this->risk->id, $second->id],
                'effectiveness_rating' => 'effective',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertResidual(self::CONTROLLED, $this->risk);
        $this->assertSame(self::CONTROLLED, json_decode($this->onlyEntry($this->risk)->new_value, true));

        // 16 × (1 − 0.95) = 0.8 → the score the service derives, whatever its rounding.
        $this->assertNotSame(16, $second->fresh()->residual_score);
        $this->assertSame(16, json_decode($this->onlyEntry($second)->old_value, true)['residual_score']);
    }

    #[Test]
    public function linking_from_the_control_page_records_the_move(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.controls.link-risk', $this->control), ['risk_id' => $this->risk->id])
            ->assertSessionHasNoErrors();

        $entry = $this->onlyEntry($this->risk);
        $this->assertSame(self::UNCONTROLLED, json_decode($entry->old_value, true));
        $this->assertSame(self::CONTROLLED, json_decode($entry->new_value, true));
    }

    #[Test]
    public function unlinking_records_the_residual_moving_back(): void
    {
        $this->attachControl($this->risk, $this->control);
        app(ControlEffectivenessService::class)->recalculateForRisk($this->risk);
        $this->assertResidual(self::CONTROLLED, $this->risk);

        $this->actingAs($this->actor)
            ->delete(route('risk.controls.unlink-risk', [$this->control, $this->risk]))
            ->assertRedirect();

        $this->assertResidual(self::UNCONTROLLED, $this->risk);

        $last = $this->entries($this->risk)->last();
        $this->assertSame(self::CONTROLLED, json_decode($last->old_value, true));
        $this->assertSame(self::UNCONTROLLED, json_decode($last->new_value, true));
        $this->assertSame($this->actor->id, (int) $last->changed_by);
    }

    #[Test]
    public function a_recalculation_that_moves_nothing_writes_nothing(): void
    {
        $service = app(ControlEffectivenessService::class);

        // Uncontrolled and already at its inherent score: nothing to move.
        $service->recalculateForRisk($this->risk);
        $this->assertCount(0, $this->entries($this->risk));

        $this->attachControl($this->risk, $this->control);
        $service->recalculateForRisk($this->risk);
        $service->recalculateForRisk($this->risk->fresh());

        $this->assertCount(1, $this->entries($this->risk));
    }

    #[Test]
    public function rating_a_control_writes_one_entry_per_risk_despite_recalculating_twice(): void
    {
        // ControlService::update() recalculates every mapped risk, then fires
        // ControlUpdated, and RecalculateResidualRisk recalculates them again.
        // The second pass moves nothing, so it must write nothing.
        $this->attachControl($this->risk, $this->control);
        app(ControlEffectivenessService::class)->recalculateForRisk($this->risk);
        $before = $this->entries($this->risk)->count();

        $this->actingAs($this->actor)
            ->put(route('risk.controls.update', $this->control), $this->validControl([
                'name' => $this->control->name,
                'effectiveness_rating' => 'partially_effective',
            ]))
            ->assertSessionHasNoErrors();

        $entries = $this->entries($this->risk);
        $this->assertCount($before + 1, $entries);
        $this->assertNotSame('95.00', json_decode($entries->last()->new_value, true)['control_effectiveness_pct']);
        $this->assertSame(self::CONTROLLED, json_decode($entries->last()->old_value, true));
    }

    #[Test]
    public function a_system_recalculation_is_recorded_without_an_actor(): void
    {
        $this->attachControl($this->risk, $this->control);

        app(ControlEffectivenessService::class)->recalculateForRisk($this->risk);

        $this->assertNull($this->onlyEntry($this->risk)->changed_by);
    }

    #[Test]
    public function an_unscored_risk_records_its_effectiveness_moving(): void
    {
        $unscored = $this->makeRisk(['inherent_score' => null, 'inherent_likelihood' => null, 'inherent_impact' => null]);
        $this->attachControl($unscored, $this->control);

        app(ControlEffectivenessService::class)->recalculateForRisk($unscored);

        $new = json_decode($this->onlyEntry($unscored)->new_value, true);
        $this->assertSame('95.00', $new['control_effectiveness_pct']);
        $this->assertNull($new['residual_score']);
        $this->assertNull($new['residual_rating']);
    }

    #[Test]
    public function a_failed_residual_entry_rolls_the_mapping_back(): void
    {
        RiskAuditTrail::creating(function (RiskAuditTrail $row) {
            if ($row->action_type === 'residual_recalculated') {
                throw new \RuntimeException('audit store unavailable');
            }
        });

        try {
            app(ControlService::class)->linkRisk($this->control, ['risk_id' => $this->risk->id], $this->actor->id);
            $this->fail('the failed audit write should have propagated');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, RiskControlMapping::where('control_id', $this->control->id)->count());
        $this->assertResidual(self::UNCONTROLLED, $this->risk);
    }

    #[Test]
    public function a_failed_residual_entry_rolls_back_a_register_mapping_and_a_control_create_and_an_unlink(): void
    {
        $service = app(ControlService::class);

        // Unlink: the mapping, its unmap entry and the residual move together.
        $this->attachControl($this->risk, $this->control);
        app(ControlEffectivenessService::class)->recalculateForRisk($this->risk);
        $this->assertResidual(self::CONTROLLED, $this->risk);

        RiskAuditTrail::creating(function (RiskAuditTrail $row) {
            if ($row->action_type === 'residual_recalculated') {
                throw new \RuntimeException('audit store unavailable');
            }
        });

        try {
            $service->unlinkRisk($this->control, $this->risk, $this->actor->id);
            $this->fail('unlink should have propagated the failure');
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, RiskControlMapping::where('control_id', $this->control->id)->count());
        $this->assertResidual(self::CONTROLLED, $this->risk);

        // Register map-control.
        $other = $this->makeControl(['effectiveness_rating' => 'effective', 'effectiveness_pct' => null]);
        $second = $this->makeRisk([
            'inherent_score' => 16, 'residual_score' => 16, 'residual_rating' => 'High',
            'control_effectiveness_pct' => 0,
        ]);
        try {
            app(\App\Services\Register\RiskRegisterService::class)
                ->mapControl($second, ['control_id' => $other->id], $this->actor->id);
            $this->fail('map should have propagated the failure');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, RiskControlMapping::where('risk_id', $second->id)->count());
        $this->assertSame(16, $second->fresh()->residual_score);

        // Control create with risk_ids.
        $controlsBefore = Control::count();
        try {
            $service->create($this->validControl([
                'risk_ids' => [$second->id], 'effectiveness_rating' => 'effective',
            ]), $this->organization->id, $this->actor->id);
            $this->fail('create should have propagated the failure');
        } catch (\RuntimeException) {
        }
        $this->assertSame($controlsBefore, Control::count());
        $this->assertSame(0, RiskControlMapping::where('risk_id', $second->id)->count());
    }

    #[Test]
    public function the_entries_keep_the_append_only_chain_intact(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.register.map-control', $this->risk), ['control_id' => $this->control->id]);
        $this->actingAs($this->actor)
            ->delete(route('risk.controls.unlink-risk', [$this->control, $this->risk]));

        $rows = RiskAuditTrail::where('organization_id', $this->organization->id)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(4, $rows->count());

        foreach ($rows as $row) {
            $this->assertSame($row->expectedHash(), $row->hash, "row {$row->id} does not verify");
        }
    }

    /* ------------------------------------------------------------------ */

    /** @param  array<string, mixed>  $expected */
    private function assertResidual(array $expected, Risk $risk): void
    {
        $this->assertSame($expected, $risk->fresh()->only(array_keys($expected)));
    }

    /** @return \Illuminate\Support\Collection<int, RiskAuditTrail> */
    private function entries(Risk $risk)
    {
        return RiskAuditTrail::query()
            ->where('entity_type', $risk->getMorphClass())
            ->where('entity_id', $risk->id)
            ->where('action_type', 'residual_recalculated')
            ->orderBy('id')
            ->get();
    }

    private function onlyEntry(Risk $risk): RiskAuditTrail
    {
        $entries = $this->entries($risk);

        $this->assertCount(1, $entries, "expected one residual_recalculated entry on risk {$risk->id}");
        $this->assertSame('residual', $entries->first()->field_changed);

        return $entries->first();
    }

    /** @return list<string> */
    private function actionsOn(Risk $risk): array
    {
        return RiskAuditTrail::query()
            ->where('entity_type', $risk->getMorphClass())
            ->where('entity_id', $risk->id)
            ->whereIn('action_type', ['control_mapped', 'control_unmapped', 'residual_recalculated'])
            ->orderBy('id')
            ->pluck('action_type')
            ->all();
    }
}
