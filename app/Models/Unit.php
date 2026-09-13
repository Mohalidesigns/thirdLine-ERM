<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A unit of measure, shared across every tenant.
 *
 * Not tenant-scoped: a percentage is a percentage. Currency units live here too
 * so that a monetary measure can name NGN or kobo the same way a count names
 * "events" — the FX side of currency is fx_rates' problem, not this table's.
 */
class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'category',
        'base_unit_id',
        'conversion_factor',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:12',
    ];

    public function baseUnit()
    {
        return $this->belongsTo(self::class, 'base_unit_id');
    }

    public function isMonetary(): bool
    {
        return $this->category === 'currency';
    }
}
