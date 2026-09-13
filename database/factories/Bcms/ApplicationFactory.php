<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE. Every attribute here is a column the
 * database will not accept as null; everything else is left unset so a test
 * states the facts it actually depends on. A factory that filled in a
 * criticality tier, an RTO or a delivery status would be a factory writing the
 * assertions.
 */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'code' => strtoupper($this->faker->unique()->bothify('??##-####')),
            'name' => $this->faker->sentence(3),
        ];
    }
}
