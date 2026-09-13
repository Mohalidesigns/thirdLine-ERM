<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\ReadinessTemplateTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadinessTemplateTask>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE. Every attribute here is a column the
 * database will not accept as null; everything else is left unset so a test
 * states the facts it actually depends on. A factory that filled in a
 * criticality tier, an RTO or a delivery status would be a factory writing the
 * assertions.
 */
class ReadinessTemplateTaskFactory extends Factory
{
    protected $model = ReadinessTemplateTask::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'template_id' => \App\Models\Bcms\ReadinessTemplate::factory(),
            'title' => $this->faker->sentence(3),
        ];
    }
}
