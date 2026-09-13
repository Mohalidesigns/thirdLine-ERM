<?php

namespace App\Models\Bcms\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Stamps a UUID on create, and makes it the route key.
 *
 * Blueprint §9 implies externally-referenceable ids; this product answers that
 * the way `risks`, `controls` and `tp_engagements` already do — a bigint key
 * plus a unique `uuid` column — so foreign keys stay narrow. Only aggregate
 * roots carry one. A line table (an impact score, a timeline entry, a delivery
 * receipt) is never addressed from outside its parent and a uuid on it would be
 * a column nothing reads.
 */
trait HasBcmsUuid
{
    public static function bootHasBcmsUuid(): void
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
