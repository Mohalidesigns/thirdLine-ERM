<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A reporting calendar. One per organisation in practice; the table is keyed to
 * allow a second — a regulatory calendar that differs from the management one
 * is common enough in banking to be worth not designing out.
 */
class PeriodCalendar extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'fiscal_year_start_month',
        'is_default',
    ];

    protected $casts = [
        'fiscal_year_start_month' => 'integer',
        'is_default' => 'boolean',
    ];

    public function periods()
    {
        return $this->hasMany(Period::class, 'calendar_id');
    }
}
