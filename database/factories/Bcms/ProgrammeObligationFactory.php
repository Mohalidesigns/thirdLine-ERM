<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\ProgrammeObligation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgrammeObligation>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE — the same rule the Phase 0
 * factories follow. Every attribute here is a column the database will not
 * accept as null; everything else is left unset so a test states the facts it
 * actually depends on.
 */
class ProgrammeObligationFactory extends Factory
{
    protected $model = ProgrammeObligation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'clause_ref' => 'cbn.rcf.bc_dr',
        ];
    }
}
