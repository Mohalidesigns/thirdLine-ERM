<?php

namespace App\Models\Bcms;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A task assigned during an incident.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $incident_id
 * @property string $title
 * @property ?string $description
 * @property ?int $owner_id
 * @property ?\Illuminate\Support\Carbon $due_at
 * @property ?string $priority
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class IncidentTask extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_incident_tasks';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'incident_id', 'title', 'description', 'owner_id', 'due_at', 'priority',
        'status', 'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'incident_id' => 'integer',
            'owner_id' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
