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
            // ADR 0022: attested_at is DATETIME NOT NULL with no database
            // default (a bare timestamp() used to silently rewrite itself on
            // any unrelated UPDATE). The application always writes this
            // explicitly; the factory must too.
            'attested_at' => now(),
        ];
    }
}
