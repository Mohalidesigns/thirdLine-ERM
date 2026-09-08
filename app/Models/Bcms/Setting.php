<?php

namespace App\Models\Bcms;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One tenant's BCMS settings.
 *
 * A TYPED ROW, not a key/value bag and not a config file. Quiet hours and the
 * reminder send time are decisions with legal weight in an emergency; they do
 * not belong somewhere nothing validates them. Read through
 * `App\Services\Bcms\BcmsSettings`, never directly — Gate G0 criterion 4.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $timezone
 * @property int $default_lead_time_days
 * @property string $reminder_send_time
 * @property string $default_reminder_mode
 * @property ?string $quiet_hours_start
 * @property ?string $quiet_hours_end
 * @property int $escalation_day_offset
 * @property array<array-key, mixed> $default_channel_set
 * @property array<array-key, mixed> $life_safety_channel_set
 * @property bool $ai_enabled
 * @property array<array-key, mixed> $ai_capabilities
 * @property bool $exercise_simulation_default
 * @property bool $require_dual_approval_for_live
 * @property string $alert_currency
 * @property int $contact_verification_days
 * @property int $impact_intolerable_score
 * @property ?string $critical_service_rto_ceiling_hours
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class Setting extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_settings';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'timezone', 'default_lead_time_days', 'reminder_send_time',
        'default_reminder_mode', 'quiet_hours_start', 'quiet_hours_end', 'escalation_day_offset',
        'default_channel_set', 'life_safety_channel_set', 'ai_enabled', 'ai_capabilities',
        'exercise_simulation_default', 'require_dual_approval_for_live', 'alert_currency',
        'contact_verification_days', 'impact_intolerable_score', 'critical_service_rto_ceiling_hours', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'default_channel_set' => 'array',
            'life_safety_channel_set' => 'array',
            'ai_capabilities' => 'array',
            'organization_id' => 'integer',
            'default_lead_time_days' => 'integer',
            'escalation_day_offset' => 'integer',
            'ai_enabled' => 'boolean',
            'exercise_simulation_default' => 'boolean',
            'require_dual_approval_for_live' => 'boolean',
            'contact_verification_days' => 'integer',
            'impact_intolerable_score' => 'integer',
            'critical_service_rto_ceiling_hours' => 'decimal:2',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }
}
