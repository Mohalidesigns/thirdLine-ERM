<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\ManagementReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagementReview>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE — the same rule the Phase 0
 * factories follow. Every attribute here is a column the database will not
 * accept as null; everything else is left unset so a test states the facts it
 * actually depends on.
 */
class ManagementReviewFactory extends Factory
{
    protected $model = ManagementReview::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => strtoupper($this->faker->unique()->bothify('BCMR-2026-####')),
            'title' => $this->faker->sentence(4),
            'held_on' => now()->toDateString(),
        ];
    }
}
