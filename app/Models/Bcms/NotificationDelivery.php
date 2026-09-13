<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\DeliveryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One attempt to reach one person on one channel.
 *
 * Written as `queued` BEFORE the provider is called (standing rule 8). A worker
 * that crashes between the write and the provider call leaves a row the
 * watchdog can find; the reverse leaves an alert nobody knows was lost.
 *
 * `cost_minor` is NULLABLE, never defaulted to zero: a channel that cannot
 * price a send returns null, and zero is a claim that it was free.
 *
 * @property int $id
 * @property int $organization_id
 * @property ?int $alert_id
 * @property ?int $reminder_schedule_id
 * @property ?int $recipient_contact_id
 * @property \App\Enums\Bcms\ChannelKey $channel
 * @property ?string $address
 * @property ?string $provider
 * @property ?string $provider_message_id
 * @property \App\Enums\Bcms\DeliveryStatus $status
 * @property int $attempts
 * @property ?\Illuminate\Support\Carbon $sent_at
 * @property ?\Illuminate\Support\Carbon $delivered_at
 * @property ?\Illuminate\Support\Carbon $read_at
 * @property ?string $failed_reason
 * @property ?int $cost_minor
 * @property ?string $currency
 * @property array<array-key, mixed> $raw_response
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class NotificationDelivery extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_notification_deliveries';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'alert_id', 'reminder_schedule_id', 'recipient_contact_id', 'channel',
        'address', 'provider', 'provider_message_id', 'status', 'attempts', 'sent_at',
        'delivered_at', 'read_at', 'failed_reason', 'cost_minor', 'currency', 'raw_response',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'raw_response' => 'array',
            'organization_id' => 'integer',
            'alert_id' => 'integer',
            'reminder_schedule_id' => 'integer',
            'recipient_contact_id' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'cost_minor' => 'integer',
            'channel' => ChannelKey::class,
            'status' => DeliveryStatus::class,
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

    /** @return BelongsTo<ReminderSchedule, $this> */
    public function reminderSchedule(): BelongsTo
    {
        return $this->belongsTo(ReminderSchedule::class, 'reminder_schedule_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'recipient_contact_id');
    }
}
