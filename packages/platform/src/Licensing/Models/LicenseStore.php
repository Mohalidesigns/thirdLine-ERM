<?php

namespace ThirdLine\Platform\Licensing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The activated licence for this deployment.
 *
 * Columns are documented here rather than inferred from the migration, for the
 * reason given on LicenseAuditLog.
 *
 * @property string $id
 * @property string $license_key
 * @property string $plan
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string|null $device_fingerprint
 * @property int $max_users
 * @property array<string, mixed>|null $features
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class LicenseStore extends Model
{
    use HasUuids;

    protected $fillable = [
        'license_key',
        'plan',
        'status',
        'activated_at',
        'expires_at',
        'device_fingerprint',
        'max_users',
        'features',
        'metadata',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'features' => 'array',
        'metadata' => 'array',
    ];
}
