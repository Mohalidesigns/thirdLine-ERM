<?php

namespace ThirdLine\Platform\Licensing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
