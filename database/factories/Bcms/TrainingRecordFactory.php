<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\TrainingRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingRecord>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE. Every attribute here is a column the
 * database will not accept as null; everything else is left unset so a test
 * states the facts it actually depends on. A factory that filled in a
 * criticality tier, an RTO or a delivery status would be a factory writing the
 * assertions.
 */
class TrainingRecordFactory extends Factory
{
    protected $model = TrainingRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'curriculum_id' => \App\Models\Bcms\TrainingCurriculum::factory(),
            'user_id' => \App\Models\User::factory(),
        ];
    }
}
