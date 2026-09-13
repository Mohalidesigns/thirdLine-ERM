<?php

namespace App\Models\Tprm\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Stamps a UUID on create for the tables that carry one.
 *
 * The TRD asks for UUID primary keys on the externally-referenceable objects.
 * This product answers that need the way `risks`, `controls` and
 * `key_risk_indicators` already do — a bigint key plus a unique `uuid` column
 * — so foreign keys stay narrow and `HasObjectIdentity` can index rows by
 * numeric key. This trait is the stamping half of that pattern.
 */
trait HasTprmUuid
{
    public static function bootHasTprmUuid(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
