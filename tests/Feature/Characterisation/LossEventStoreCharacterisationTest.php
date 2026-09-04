<?php

namespace Tests\Feature\Characterisation;

use App\Models\BusinessUnit;
use App\Models\LossEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * CHARACTERISATION — the FULL STORED ROW a loss event create writes, pinned
 * BEFORE Phase 4.3 extracted App\Services\LossEventService from
 * LossEventController::store().
 *
 * The phase prompt asks for this specifically, and for a reason: `loss_events`
 * carries two columns per concept — the originals and the 200038 duplicates the
 * forms post — and the mapping between them decides whether a bank's CBN, NFIU
 * and EFCC alerts fire at all. WP-01 found the fraud alerts had never fired
 * because the category was written in lower case while
 * RegulatoryThresholdService matched upper. A refactor that quietly changed one
 * of these columns would be the same defect again, so both column sets are
 * asserted, not just the ones the screens read.
 *
 * Three fixtures, chosen to differ in every branch store() takes: an ordinary
 * loss under the reportable threshold, one over it, and a near miss (which is
 * forced to zero money and reclassified whatever the form said).
 */
class LossEventStoreCharacterisationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['loss_event.view', 'loss_event.create'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_title' => 'Cash shortage at branch till',
            'event_description' => 'A teller till was short at close of business.',
            'date_of_loss' => now()->subDays(3)->toDateString(),
            'date_discovered' => now()->subDays(2)->toDateString(),
            'business_unit_id' => $this->unit->id,
            'reported_by' => $this->actor->id,
            'basel_event_type' => 'internal_fraud',
            'cbn_loss_category' => 'cash_operations',
            'event_type' => 'actual_loss',
            'severity' => 'major',
            'gross_loss_amount' => 250000,
            'recovery_amount' => 50000,
            'insurance_recovery' => 25000,
            'currency' => 'NGN',
            'root_cause_summary' => 'Till was not balanced at handover.',
            'corrective_action_summary' => 'Dual custody at handover.',
        ], $overrides);
    }

    private function store(array $overrides = []): LossEvent
    {
        $this->post(route('risk.loss-events.store'), $this->payload($overrides))->assertRedirect();

        return LossEvent::latest('id')->firstOrFail();
    }

    /**
     * The canonical columns, INCLUDING the upper-casing that decides whether a
     * regulatory alert matches.
     */
    #[Test]
    public function the_canonical_columns(): void
    {
        $event = $this->store();

        $this->assertSame('Cash shortage at branch till', $event->title);
        $this->assertSame('A teller till was short at close of business.', $event->description);
        $this->assertSame('Till was not balanced at handover.', $event->initial_root_cause);

        // Upper case, and matched upper case by RegulatoryThresholdService.
        $this->assertSame('INTERNAL_FRAUD', $event->basel_l1_category);
        $this->assertSame('CASH_OPERATIONS', $event->cbn_risk_category);
        $this->assertSame('MAJOR', $event->event_severity);

        // The form collects one Basel classification; L2 mirrors L1 to satisfy
        // the NOT NULL constraint.
        $this->assertSame('INTERNAL_FRAUD', $event->basel_l2_category);

        $this->assertSame('actual_loss', $event->loss_category);
        $this->assertSame('REPORTED', $event->current_status);
        $this->assertNotNull($event->event_reference);
    }

    /** Money is stored in MINOR units, with the currency beside it. */
    #[Test]
    public function money_is_stored_in_kobo(): void
    {
        $event = $this->store();

        $this->assertSame(25_000_000, (int) $event->gross_loss_amount_kobo);
        $this->assertSame(2_500_000, (int) $event->insurance_recovery_kobo);
        $this->assertSame(5_000_000, (int) $event->other_recovery_kobo);
        $this->assertSame('NGN', $event->currency);
    }

    /** The 200038 columns the forms post are written too. */
    #[Test]
    public function the_passthrough_columns(): void
    {
        $event = $this->store();

        $this->assertSame($this->unit->id, (int) $event->business_unit_id);
        $this->assertSame($this->actor->id, (int) $event->reported_by);
        $this->assertSame('Dual custody at handover.', $event->corrective_action_summary);
        $this->assertNotNull($event->date_of_loss);
        $this->assertNotNull($event->date_discovered);
    }

    /**
     * `risk_id` on the form is `risk_register_id` in the table — the one field
     * whose name changes without going through the canonical mapping.
     */
    #[Test]
    public function the_risk_link_lands_in_risk_register_id(): void
    {
        $risk = $this->makeRisk();

        $event = $this->store(['risk_id' => $risk->id]);

        $this->assertSame($risk->id, (int) $event->risk_register_id);
    }

    /**
     * Under the reportable threshold the flag stays false; over it, the
     * controller sets it without being asked.
     */
    #[Test]
    public function the_reportable_flag_is_derived_from_the_amount(): void
    {
        $small = $this->store(['gross_loss_amount' => 250000]);
        $this->assertFalse((bool) $small->is_regulatory_reportable);

        $large = $this->store(['gross_loss_amount' => 12_000_000]);
        $this->assertTrue((bool) $large->is_regulatory_reportable);
    }

    /** An explicit answer on the form wins over the derived one. */
    #[Test]
    public function an_explicit_reportable_flag_is_respected(): void
    {
        $event = $this->store([
            'gross_loss_amount' => 250000,
            'is_regulatory_reportable' => true,
            'regulatory_body' => 'CBN',
        ]);

        $this->assertTrue((bool) $event->is_regulatory_reportable);
        $this->assertSame('CBN', $event->regulatory_body);
    }

    /**
     * A near miss is forced to zero money and reclassified, whatever the form
     * said — it is the branch with the most rewriting in it.
     */
    #[Test]
    public function a_near_miss_is_zeroed_and_reclassified(): void
    {
        $event = $this->store([
            'is_near_miss' => true,
            'event_type' => 'actual_loss',
            'gross_loss_amount' => 900000,
            'recovery_amount' => 100000,
            'insurance_recovery' => 50000,
        ]);

        $this->assertTrue((bool) $event->is_near_miss);
        $this->assertSame('near_miss', $event->loss_category);
        $this->assertSame(0, (int) $event->gross_loss_amount_kobo);
        $this->assertSame(0, (int) $event->insurance_recovery_kobo);
        $this->assertSame(0, (int) $event->other_recovery_kobo);
        $this->assertFalse((bool) $event->is_regulatory_reportable);
    }
}
