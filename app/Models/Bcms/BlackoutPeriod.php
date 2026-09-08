<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\BusinessUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A window in which an exercise must not be scheduled.
 *
 * The Nigerian calendar is the shipped content: month-end, year-end close, CBN
 * returns deadlines, salary days, Eid/Christmas/Easter, election days. A
 * generator that books a full-scale failover on the 27th has produced a
 * calendar nobody will run. `recurrence` carries the rules that have no fixed
 * date, such as the last three working days of every month.
 *
 * @property int $id
 * @property ?int $organization_id
 * @property string $name
 * @property ?string $category
 * @property ?\Illuminate\Support\Carbon $starts_on
 * @property ?\Illuminate\Support\Carbon $ends_on
 * @property array<array-key, mixed> $recurrence
 * @property bool $is_hard_block
 * @property ?int $business_unit_id
 * @property bool $is_system_default
 * @property bool $is_active
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class BlackoutPeriod extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'bcms_blackout_periods';

    /** System-owned rows (`organization_id = null`) are visible to every tenant. */
    protected bool $tenantIncludesGlobal = true;

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'name', 'category', 'starts_on', 'ends_on', 'recurrence',
        'is_hard_block', 'business_unit_id', 'is_system_default', 'is_active', 'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recurrence' => 'array',
            'organization_id' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_hard_block' => 'boolean',
            'business_unit_id' => 'integer',
            'is_system_default' => 'boolean',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }
}
