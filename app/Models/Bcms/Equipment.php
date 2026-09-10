<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Physical equipment a process depends on: generators, UPS, VSAT, ATMs,
 * vehicles. A seam register (ADR 0001).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $code
 * @property string $name
 * @property ?string $equipment_type
 * @property ?int $site_id
 * @property int $quantity
 * @property ?string $external_ref
 * @property bool $is_active
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Equipment extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'bcms_equipment';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'code', 'name', 'equipment_type', 'site_id', 'quantity', 'external_ref',
        'is_active', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'site_id' => 'integer',
            'quantity' => 'integer',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
