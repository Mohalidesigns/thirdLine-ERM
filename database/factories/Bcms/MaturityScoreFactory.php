<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\MaturityScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaturityScore>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE — the same rule the Phase 0
 * factories follow. Every attribute here is a column the database will not
 * accept as null; everything else is left unset so a test states the facts it
 * actually depends on.
 */
class MaturityScoreFactory extends Factory
{
    protected $model = MaturityScore::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assessment_id' => \App\Models\Bcms\MaturityAssessment::factory(),
            'clause_group' => '8.5',
        ];
    }
}
