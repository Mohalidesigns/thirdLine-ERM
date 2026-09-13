<?php

namespace ThirdLine\Platform\Licensing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The licensing audit trail.
 *
 * Columns are documented here rather than inferred from the migration:
 * larastan reads the consumer's database/migrations, and this table's
 * migration ships inside the package, so without these a consumer's static
 * analysis reports every column as an undefined property.
 *
 * @property string $id
 * @property string $action
 * @property array<string, mixed>|null $metadata
 * @property bool $synced
 * @property \Illuminate\Support\Carbon|null $synced_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class LicenseAuditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'action',
        'metadata',
        'synced',
        'synced_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'synced' => 'boolean',
        'synced_at' => 'datetime',
    ];
}
