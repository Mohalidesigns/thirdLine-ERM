<?php

namespace Tests\Feature\Characterisation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * CHARACTERISATION — crown jewel.
 *
 * Pins LossEvent::netLossAmountKobo() against the five recovery columns the
 * loss table carries: insurance_recovery_kobo, other_recovery_kobo,
 * pending_recovery_kobo, actual_recovery_kobo and provision_amount_kobo.
 *
 * Only the first two enter the net figure today. That is a deliberate reading
 * of Basel recovery treatment — a *pending* recovery has not been received, an
 * *actual* recovery double-counts the insurance and other columns that make it
 * up, and a provision is an accounting entry rather than a recovery — but it
 * is a reading, and it is written down here so that changing it has to be a
 * decision rather than an accident. See docs/schema/canonical-columns.md.
 */
class LossEventNetLossTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function net_loss_is_gross_less_insurance_and_other_recoveries(): void
    {
        $event = $this->makeLossEvent([
            'gross_loss_amount_kobo' => 1_000_000_00,
            'insurance_recovery_kobo' => 250_000_00,
            'other_recovery_kobo' => 50_000_00,
        ]);

        $this->assertSame(700_000_00, $event->fresh()->net_loss_amount_kobo);
    }

    #[Test]
    public function a_pending_recovery_does_not_reduce_the_net_loss(): void
    {
        // Money that has not arrived is not a recovery.
        $event = $this->makeLossEvent([
            'gross_loss_amount_kobo' => 1_000_000_00,
            'pending_recovery_kobo' => 900_000_00,
        ]);

        $this->assertSame(1_000_000_00, $event->fresh()->net_loss_amount_kobo);
    }

    #[Test]
    public function the_actual_recovery_roll_up_does_not_reduce_the_net_loss_a_second_time(): void
    {
        // actual_recovery_kobo is the roll-up of the insurance and other
        // columns; subtracting it as well would double-count.
        $event = $this->makeLossEvent([
            'gross_loss_amount_kobo' => 1_000_000_00,
            'insurance_recovery_kobo' => 200_000_00,
            'other_recovery_kobo' => 100_000_00,
            'actual_recovery_kobo' => 300_000_00,
        ]);

        $this->assertSame(700_000_00, $event->fresh()->net_loss_amount_kobo);
    }

    #[Test]
    public function a_provision_does_not_reduce_the_net_loss(): void
    {
        $event = $this->makeLossEvent([
            'gross_loss_amount_kobo' => 1_000_000_00,
            'provision_amount_kobo' => 400_000_00,
        ]);

        $this->assertSame(1_000_000_00, $event->fresh()->net_loss_amount_kobo);
    }

    #[Test]
    public function a_fully_recovered_event_nets_to_zero(): void
    {
        $event = $this->makeLossEvent([
            'gross_loss_amount_kobo' => 500_000_00,
            'insurance_recovery_kobo' => 300_000_00,
            'other_recovery_kobo' => 200_000_00,
        ]);

        $this->assertSame(0, $event->fresh()->net_loss_amount_kobo);
    }

    #[Test]
    public function an_over_recovery_produces_a_negative_net_loss_rather_than_being_clamped(): void
    {
        // A gain event is a real outcome (a recovery exceeding the loss), and
        // the accessor reports it rather than flooring at zero. The controller
        // path clamps separately; this is the model's answer.
        $event = $this->makeLossEvent([
            'gross_loss_amount_kobo' => 100_000_00,
            'insurance_recovery_kobo' => 150_000_00,
        ]);

        $this->assertSame(-50_000_00, $event->fresh()->net_loss_amount_kobo);
    }

    #[Test]
    public function an_event_with_no_recoveries_nets_to_its_gross_loss(): void
    {
        $event = $this->makeLossEvent(['gross_loss_amount_kobo' => 750_000_00]);

        $this->assertSame(750_000_00, $event->fresh()->net_loss_amount_kobo);
    }

    #[Test]
    public function a_zero_value_event_nets_to_zero(): void
    {
        $this->assertSame(0, $this->makeLossEvent()->fresh()->net_loss_amount_kobo);
    }
}
