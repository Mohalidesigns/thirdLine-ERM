<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

class IncidentTimelineEntry extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'incident_timeline';

    protected $fillable = [
        'organization_id', 'incident_id', 'occurred_at', 'actor_id',
        'event_type', 'description', 'is_material',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'is_material' => 'boolean',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
