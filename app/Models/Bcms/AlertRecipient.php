<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\RecipientStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One person's outcome for one alert — a statement about the PERSON, where a
 * delivery is a statement about one wire.
 *
 * `resolved_channels` and `contact_name_snapshot` are the audience snapshot of
 * ADR 0003: an examiner asking who was told gets the list that was told, not
 * the list the rule would resolve to today.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $alert_id
 * @property int $contact_id
 * @property array<array-key, mixed> $resolved_channels
 * @property ?string $contact_name_snapshot
 * @property \App\Enums\Bcms\RecipientStatus $status
 * @property ?\Illuminate\Support\Carbon $acknowledged_at
 * @property ?string $response_value
 * @property ?string $response_text
 * @property ?int $escalated_to_contact_id
 * @property ?\Illuminate\Support\Carbon $escalated_at
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class AlertRecipient extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_alert_recipients';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'alert_id', 'contact_id', 'resolved_channels', 'contact_name_snapshot',
        'status', 'acknowledged_at', 'response_value', 'response_text', 'escalated_to_contact_id',
        'escalated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'resolved_channels' => 'array',
            'organization_id' => 'integer',
            'alert_id' => 'integer',
            'contact_id' => 'integer',
            'acknowledged_at' => 'datetime',
            'escalated_to_contact_id' => 'integer',
            'escalated_at' => 'datetime',
            'status' => RecipientStatus::class,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Alert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class, 'alert_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'escalated_to_contact_id');
    }
}
