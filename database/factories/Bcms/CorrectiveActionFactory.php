<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\CorrectiveAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorrectiveAction>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE. Every attribute here is a column the
 * database will not accept as null; everything else is left unset so a test
 * states the facts it actually depends on. A factory that filled in a
 * criticality tier, an RTO or a delivery status would be a factory writing the
 * assertions.
 */
class CorrectiveActionFactory extends Factory
{
    protected $model = CorrectiveAction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'finding_id' => \App\Models\Bcms\Finding::factory(),
            'reference' => strtoupper($this->faker->unique()->bothify('??##-####')),
            'title' => $this->faker->sentence(3),
        ];
    }
}
