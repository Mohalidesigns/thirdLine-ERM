<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\RaciAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaciAssignment>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE — the same rule the Phase 0
 * factories follow. Every attribute here is a column the database will not
 * accept as null; everything else is left unset so a test states the facts it
 * actually depends on.
 */
class RaciAssignmentFactory extends Factory
{
    protected $model = RaciAssignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assignable_type' => 'bcms_process',
            'assignable_id' => \App\Models\Bcms\Process::factory(),
            'user_id' => \App\Models\User::factory(),
            'raci_role' => 'R',
        ];
    }
}
