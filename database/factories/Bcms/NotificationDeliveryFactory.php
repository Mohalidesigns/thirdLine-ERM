<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\NotificationDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDelivery>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE. Every attribute here is a column the
 * database will not accept as null; everything else is left unset so a test
 * states the facts it actually depends on. A factory that filled in a
 * criticality tier, an RTO or a delivery status would be a factory writing the
 * assertions.
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'channel' => 'sms',
        ];
    }
}
