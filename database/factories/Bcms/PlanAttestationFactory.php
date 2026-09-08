<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\PlanAttestation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanAttestation>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE — the same rule the Phase 0
 * factories follow. Every attribute here is a column the database will not
 * accept as null; everything else is left unset so a test states the facts it
 * actually depends on.
 */
class PlanAttestationFactory extends Factory
{
    protected $model = PlanAttestation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'plan_id' => \App\Models\Bcms\Plan::factory(),
            'period_year' => (int) now()->year,
            'attested_by' => \App\Models\User::factory(),
            'attested_by_name' => $this->faker->name(),
            'statement' => $this->faker->sentence(),
        ];
    }
}
