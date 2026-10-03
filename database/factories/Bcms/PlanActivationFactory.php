<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\PlanActivation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanActivation>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE. Every attribute here is a column the
 * database will not accept as null; everything else is left unset so a test
 * states the facts it actually depends on. A factory that filled in a
 * criticality tier, an RTO or a delivery status would be a factory writing the
 * assertions.
 */
class PlanActivationFactory extends Factory
{
    protected $model = PlanActivation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'plan_id' => \App\Models\Bcms\Plan::factory(),
            // ADR 0022: activated_at is DATETIME NOT NULL with no database
            // default (a bare timestamp() used to silently rewrite itself on
            // any unrelated UPDATE). The application always writes this
            // explicitly; the factory must too.
            'activated_at' => now(),
        ];
    }
}
