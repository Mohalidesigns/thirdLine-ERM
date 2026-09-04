<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One number, for one measure, on one object, in one period, under one
 * scenario.
 *
 * This is the fact table the whole work package exists to create. Everything
 * else — trends, "as at" queries, the animated heat map, a board pack that
 * reconciles — is a query over these rows.
 */
class MeasureValue extends Model
{
    use BelongsToOrganization, HasFactory;

    /** ISO 4217's code for "no currency is involved". See the migration. */
    public const NO_CURRENCY = 'XXX';

    protected $fillable = [
        'organization_id',
        'measure_id',
        'object_id',
        'period_id',
        'scenario',
        'value',
        'currency_code',
        'fx_rate_used',
        'status',
        'rag_band',
        'entered_by',
        'entered_at',
        'source',
        'evidence_ref',
        'note',
    ];

    protected $casts = [
        'value' => 'decimal:6',
        'fx_rate_used' => 'decimal:8',
        'entered_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        // currency_key is derived, never supplied. Keeping it in a saving hook
        // rather than asking callers to set it is the only way two columns
        // holding the same fact stay honest.
        static::saving(function (self $model): void {
            $model->currency_key = $model->currency_code ?: self::NO_CURRENCY;
        });

        // A locked value belongs to a closed period. Refusing the write here,
        // rather than in whichever service happens to be calling, means an
        // importer and a controller are held to the same rule.
        static::updating(function (self $model): void {
            if ($model->getOriginal('status') === 'locked' && ! $model->isDirty('status')) {
                throw new RuntimeException(
                    'Measure value '.$model->getKey().' is locked: its period is closed. '
                    .'Reopen the period before correcting the value.'
                );
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Measure, $this> */
    public function measure(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Measure::class);
    }

    public function object()
    {
        return $this->belongsTo(GraphObject::class, 'object_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Period, $this> */
    public function period(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function enteredBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActual(Builder $query): Builder
    {
        return $query->where('scenario', 'actual');
    }

    public function scopeForMeasureCode(Builder $query, string $code): Builder
    {
        return $query->whereIn('measure_id', Measure::query()->where('code', $code)->select('id'));
    }

    public function scopeInPeriods(Builder $query, array $periodIds): Builder
    {
        return $query->whereIn('period_id', $periodIds);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }
}
