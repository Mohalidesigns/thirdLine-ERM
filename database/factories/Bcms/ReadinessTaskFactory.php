<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\ReadinessTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadinessTask>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE. Every attribute here is a column the
 * database will not accept as null; everything else is left unset so a test
 * states the facts it actually depends on. A factory that filled in a
 * criticality tier, an RTO or a delivery status would be a factory writing the
 * assertions.
 */
class ReadinessTaskFactory extends Factory
{
    protected $model = ReadinessTask::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'occurrence_id' => \App\Models\Bcms\ExerciseOccurrence::factory(),
            'title' => $this->faker->sentence(3),
        ];
    }
}
