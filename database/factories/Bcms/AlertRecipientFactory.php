<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\AlertRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRecipient>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE. Every attribute here is a column the
 * database will not accept as null; everything else is left unset so a test
 * states the facts it actually depends on. A factory that filled in a
 * criticality tier, an RTO or a delivery status would be a factory writing the
 * assertions.
 */
class AlertRecipientFactory extends Factory
{
    protected $model = AlertRecipient::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'alert_id' => \App\Models\Bcms\Alert::factory(),
            'contact_id' => \App\Models\Bcms\Contact::factory(),
            'resolved_channels' => $this->faker->word(),
        ];
    }
}
