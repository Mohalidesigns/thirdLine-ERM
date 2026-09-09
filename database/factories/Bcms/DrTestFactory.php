<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\DrTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrTest>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE. Every attribute here is a column the
 * database will not accept as null; everything else is left unset so a test
 * states the facts it actually depends on. A factory that filled in a
 * criticality tier, an RTO or a delivery status would be a factory writing the
 * assertions.
 */
class DrTestFactory extends Factory
{
    protected $model = DrTest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'dr_system_id' => \App\Models\Bcms\DrSystem::factory(),
            'test_type' => 'failover',
            'test_date' => now()->toDateString(),
        ];
    }
}
