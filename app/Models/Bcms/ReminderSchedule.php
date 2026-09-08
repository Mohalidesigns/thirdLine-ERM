<?php

namespace App\Models\Bcms;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One intended send in the T-10 countdown ladder.
 *
 * MATERIALISED, one row per intended send, written when the occurrence is
 * generated (ADR 0005). `idempotency_key` is unique in the DATABASE, so two
 * workers racing on the same hourly tick lose one insert rather than both
 * succeeding — which is Gate G1's "a dispatcher re-run sends nothing twice".
 * Rescheduling VOIDS unsent rows and writes a new ladder; it never mutates
 * them, so the trail shows what was planned as well as what happened.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $occurrence_id
 * @property \Illuminate\Support\Carbon $send_at
 * @property int $day_offset
 * @property array<array-key, mixed> $audience_rule
 * @property array<array-key, mixed> $channel_set
 * @property string $template_key
 * @property string $mode
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $dispatched_at
 * @property ?string $skip_reason
 * @property ?int $recipient_count
 * @property string $idempotency_key
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class ReminderSchedule extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_reminder_schedules';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'occurrence_id', 'send_at', 'day_offset', 'audience_rule',
        'channel_set', 'template_key', 'mode', 'status', 'dispatched_at', 'skip_reason',
        'recipient_count', 'idempotency_key',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'audience_rule' => 'array',
            'channel_set' => 'array',
            'organization_id' => 'integer',
            'occurrence_id' => 'integer',
            'send_at' => 'datetime',
            'day_offset' => 'integer',
            'dispatched_at' => 'datetime',
            'recipient_count' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<ExerciseOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ExerciseOccurrence::class, 'occurrence_id');
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'reminder_schedule_id');
    }
}
