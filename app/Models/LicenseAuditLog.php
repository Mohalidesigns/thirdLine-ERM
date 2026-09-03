<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
