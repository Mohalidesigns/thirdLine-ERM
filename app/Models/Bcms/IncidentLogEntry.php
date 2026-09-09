<?php

namespace App\Models\Bcms;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One entry in an incident's decision log — the artefact ISO 22361 asks for.
 *
 * It is only evidence if it cannot be rewritten. Edits APPEND:
 * `supersedes_entry_id` records that an entry replaces an earlier one, and the
 * earlier one stays.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $incident_id
 * @property \Illuminate\Support\Carbon $logged_at
 * @property ?int $logged_by
 * @property string $entry_type
 * @property string $content
 * @property array<array-key, mixed> $attachments
 * @property ?int $supersedes_entry_id
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class IncidentLogEntry extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_incident_log';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'incident_id', 'logged_at', 'logged_by', 'entry_type', 'content',
        'attachments', 'supersedes_entry_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'organization_id' => 'integer',
            'incident_id' => 'integer',
            'logged_at' => 'datetime',
            'logged_by' => 'integer',
            'supersedes_entry_id' => 'integer',
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
    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    /** @return BelongsTo<IncidentLogEntry, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(IncidentLogEntry::class, 'supersedes_entry_id');
    }
}
