<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\ProgrammeScopeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgrammeScopeItem>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE — the same rule the Phase 0
 * factories follow. Every attribute here is a column the database will not
 * accept as null; everything else is left unset so a test states the facts it
 * actually depends on.
 */
class ProgrammeScopeItemFactory extends Factory
{
    protected $model = ProgrammeScopeItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'programme_id' => \App\Models\Bcms\Programme::factory(),
            'scopable_type' => 'bcms_process',
            'scopable_id' => \App\Models\Bcms\Process::factory(),
        ];
    }
}
