<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\ReminderSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderSchedule>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE. Every attribute here is a column the
 * database will not accept as null; everything else is left unset so a test
 * states the facts it actually depends on. A factory that filled in a
 * criticality tier, an RTO or a delivery status would be a factory writing the
 * assertions.
 */
class ReminderScheduleFactory extends Factory
{
    protected $model = ReminderSchedule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'occurrence_id' => \App\Models\Bcms\ExerciseOccurrence::factory(),
            'send_at' => now(),
            'day_offset' => $this->faker->numberBetween(1, 5),
            'audience_rule' => $this->faker->word(),
            'channel_set' => $this->faker->word(),
            'template_key' => $this->faker->word(),
            'idempotency_key' => $this->faker->word(),
        ];
    }
}
