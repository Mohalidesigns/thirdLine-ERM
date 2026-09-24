<?php

namespace Database\Factories\Bcms;

use App\Models\Bcms\MaturityAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaturityAssessment>
 *
 * MINIMUM VIABLE ROW, NOT A PLAUSIBLE ONE — the same rule the Phase 0
 * factories follow. Every attribute here is a column the database will not
 * accept as null; everything else is left unset so a test states the facts it
 * actually depends on.
 */
class MaturityAssessmentFactory extends Factory
{
    protected $model = MaturityAssessment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            // ADR 0022: assessed_at is DATETIME NOT NULL with no database
            // default (a bare timestamp() used to silently rewrite itself on
            // any unrelated UPDATE — this was the column with the largest
            // observed drift, +60 minutes on every seeded row in `risk`). The
            // application always writes this explicitly; the factory must
            // too.
            'assessed_at' => now(),
        ];
    }
}
