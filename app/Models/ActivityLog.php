<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

class ActivityLog extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'activity_logs';

    protected $fillable = [
        'organization_id',
        'user_id',
        'user_name',
        'user_email',
        'event',
        'auditable_type',
        'auditable_id',
        'subject_label',
        'description',
        'route_name',
        'url',
        'method',
        'ip_address',
        'user_agent',
        'properties',
        'occurred_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'properties' => 'array',
        'occurred_at' => 'datetime',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
