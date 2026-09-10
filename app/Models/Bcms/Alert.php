<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One dispatch: a message, an audience rule, a channel set, an approval trail.
 *
 * `is_simulation` DEFAULTS TRUE. Standing rule 5: an exercise-linked alert is a
 * simulation unless somebody with dual approval says otherwise. Defaulting the
 * other way means one mis-set flag sends a real evacuation order to four
 * thousand people.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $template_id
 * @property ?int $incident_id
 * @property ?int $occurrence_id
 * @property string $title
 * @property string $message
 * @property \App\Enums\Bcms\AlertSeverity $severity
 * @property bool $is_simulation
 * @property array<array-key, mixed> $audience_rule
 * @property array<array-key, mixed> $channels
 * @property ?int $initiated_by
 * @property ?int $approved_by
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?int $second_approved_by
 * @property ?\Illuminate\Support\Carbon $second_approved_at
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $dispatched_at
 * @property int $recipient_count
 * @property ?int $estimated_cost_minor
 * @property ?int $actual_cost_minor
 * @property string $currency
 * @property bool $response_required
 * @property array<array-key, mixed> $response_options
 * @property ?int $ack_window_minutes
 * @property bool $escalation_enabled
 * @property bool $ai_generated
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Alert extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_alerts';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'template_id', 'incident_id', 'occurrence_id', 'title', 'message',
        'severity', 'is_simulation', 'audience_rule', 'channels', 'initiated_by', 'approved_by',
        'approved_at', 'second_approved_by', 'second_approved_at', 'status', 'dispatched_at',
        'recipient_count', 'estimated_cost_minor', 'actual_cost_minor', 'currency',
        'response_required', 'response_options', 'ack_window_minutes', 'escalation_enabled',
        'ai_generated', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'audience_rule' => 'array',
            'channels' => 'array',
            'response_options' => 'array',
            'organization_id' => 'integer',
            'template_id' => 'integer',
            'incident_id' => 'integer',
            'occurrence_id' => 'integer',
            'is_simulation' => 'boolean',
            'initiated_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'second_approved_by' => 'integer',
            'second_approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'recipient_count' => 'integer',
            'estimated_cost_minor' => 'integer',
            'actual_cost_minor' => 'integer',
            'response_required' => 'boolean',
            'ack_window_minutes' => 'integer',
            'escalation_enabled' => 'boolean',
            'ai_generated' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'severity' => AlertSeverity::class,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<AlertTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AlertTemplate::class, 'template_id');
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    /** @return BelongsTo<ExerciseOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ExerciseOccurrence::class, 'occurrence_id');
    }

    /** @return HasMany<AlertRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(AlertRecipient::class, 'alert_id');
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'alert_id');
    }

    /** The first authoriser. The second is `second_approved_by` (ADR 0004). */
    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
