<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\Finding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Finding>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE. Every attribute here is a column the
 * database will not accept as null; everything else is left unset so a test
 * states the facts it actually depends on. A factory that filled in a
 * criticality tier, an RTO or a delivery status would be a factory writing the
 * assertions.
 */
class FindingFactory extends Factory
{
    protected $model = Finding::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => strtoupper($this->faker->unique()->bothify('??##-####')),
            'classification' => 'observation',
            'description' => $this->faker->paragraph(),
        ];
    }
}
