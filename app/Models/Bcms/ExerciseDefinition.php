<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchy;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * "Fire Drill × 2 per year, for the Kano branch." The whole calendar engine
 * follows from `frequency_per_year` plus `distribution_mode`, bounded by the
 * blackout calendar.
 *
 * `unannounced` suppresses PARTICIPANT reminders only; the facilitator's
 * readiness ladder still runs, because an unannounced drill is unannounced to
 * the people being tested, not to the people preparing it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $exercise_programme_id
 * @property int $exercise_type_id
 * @property string $name
 * @property ?int $business_unit_id
 * @property ?int $site_id
 * @property array<array-key, mixed> $process_ids
 * @property ?int $owner_id
 * @property ?int $facilitator_id
 * @property int $frequency_per_year
 * @property string $distribution_mode
 * @property array<array-key, mixed> $preferred_window
 * @property int $duration_minutes
 * @property int $lead_time_days
 * @property int $min_notice_days
 * @property bool $daily_reminder_enabled
 * @property ?string $reminder_send_time
 * @property string $reminder_mode
 * @property bool $readiness_gating
 * @property bool $unannounced
 * @property bool $mandatory
 * @property array<array-key, mixed> $regulatory_drivers
 * @property array<array-key, mixed> $objectives
 * @property ?int $scenario_id
 * @property array<array-key, mixed> $blackout_overrides
 * @property array<array-key, mixed> $default_channel_set
 * @property array<array-key, mixed> $default_audience_rule
 * @property string $status
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class ExerciseDefinition extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, ScopedToOrgHierarchy, SoftDeletes;

    protected $table = 'bcms_exercise_definitions';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'exercise_programme_id', 'exercise_type_id', 'name', 'business_unit_id',
        'site_id', 'process_ids', 'owner_id', 'facilitator_id', 'frequency_per_year',
        'distribution_mode', 'preferred_window', 'duration_minutes', 'lead_time_days',
        'min_notice_days', 'daily_reminder_enabled', 'reminder_send_time', 'reminder_mode',
        'readiness_gating', 'unannounced', 'mandatory', 'regulatory_drivers', 'objectives',
        'scenario_id', 'blackout_overrides', 'default_channel_set', 'default_audience_rule',
        'status', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'process_ids' => 'array',
            'preferred_window' => 'array',
            'regulatory_drivers' => 'array',
            'objectives' => 'array',
            'blackout_overrides' => 'array',
            'default_channel_set' => 'array',
            'default_audience_rule' => 'array',
            'organization_id' => 'integer',
            'exercise_programme_id' => 'integer',
            'exercise_type_id' => 'integer',
            'business_unit_id' => 'integer',
            'site_id' => 'integer',
            'owner_id' => 'integer',
            'facilitator_id' => 'integer',
            'frequency_per_year' => 'integer',
            'duration_minutes' => 'integer',
            'lead_time_days' => 'integer',
            'min_notice_days' => 'integer',
            'daily_reminder_enabled' => 'boolean',
            'readiness_gating' => 'boolean',
            'unannounced' => 'boolean',
            'mandatory' => 'boolean',
            'scenario_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<ExerciseProgramme, $this> */
    public function exerciseProgramme(): BelongsTo
    {
        return $this->belongsTo(ExerciseProgramme::class, 'exercise_programme_id');
    }

    /** @return BelongsTo<ExerciseType, $this> */
    public function exerciseType(): BelongsTo
    {
        return $this->belongsTo(ExerciseType::class, 'exercise_type_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_id');
    }

    /** @return BelongsTo<Scenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'scenario_id');
    }

    /** @return HasMany<ExerciseOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ExerciseOccurrence::class, 'definition_id');
    }
}
