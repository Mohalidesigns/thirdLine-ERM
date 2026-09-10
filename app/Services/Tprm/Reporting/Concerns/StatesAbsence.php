<?php

namespace App\Services\Tprm\Reporting\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * How a regulatory return says "nothing here".
 *
 * A supervisory register's worst failure is a confident blank — an LEI column
 * empty because this product does not hold LEIs, read by a supervisor as an
 * institution that has none. Every builder in this namespace therefore prints
 * a stated absence rather than an empty cell, and this is the one place that
 * decision is implemented.
 *
 * IT IS AN EXPLICIT NULL CHECK RATHER THAN `?->x ?? $fallback` ON PURPOSE.
 * larastan types every belongsTo as non-nullable whether or not its foreign
 * key is, so a builder written with nullsafe chains produces twenty
 * `nullsafe.neverNull` findings, and twenty baseline entries hide the two that
 * would have meant something. Written this way the analyser is right and the
 * code still cannot fatal on a null relation.
 */
trait StatesAbsence
{
    protected function labelOf(?Model $related, string $attribute, string $fallback): string
    {
        if ($related === null) {
            return $fallback;
        }

        $value = $related->getAttribute($attribute);

        return blank($value) ? $fallback : (string) $value;
    }
}
