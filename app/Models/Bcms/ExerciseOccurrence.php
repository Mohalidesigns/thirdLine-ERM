<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\ExerciseOutcome;
use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One generated instance of an exercise definition — a row on the calendar.
 *
 * `needs_scheduling` is the state that makes the engine honest: when every
 * candidate date is blacked out or collides, the occurrence appears UNPLACED
 * rather than booked anyway or dropped. A plan with a visible gap beats a plan
 * that hides one.
 *
 * The AAR is reached through `hasOne`. Blueprint §9.3 also puts an `aar_id` on
 * this table; two pointers at one relationship can disagree and nothing in the
 * database stops them, so only `bcms_aars.occurrence_id` exists.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $definition_id
 * @property int $sequence_no
 * @property ?\Illuminate\Support\Carbon $scheduled_date
 * @property ?\Illuminate\Support\Carbon $scheduled_start
 * @property ?\Illuminate\Support\Carbon $scheduled_end
 * @property \App\Enums\Bcms\OccurrenceStatus $status
 * @property ?\Illuminate\Support\Carbon $actual_start
 * @property ?\Illuminate\Support\Carbon $actual_end
 * @property ?int $facilitator_id
 * @property ?int $site_id
 * @property ?string $location
 * @property int $reschedule_count
 * @property ?\Illuminate\Support\Carbon $originally_scheduled_date
 * @property ?string $cancellation_reason
 * @property bool $readiness_complete
 * @property int $blocking_tasks_open
 * @property ?\App\Enums\Bcms\ExerciseOutcome $outcome
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class ExerciseOccurrence extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_exercise_occurrences';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'definition_id', 'sequence_no', 'scheduled_date', 'scheduled_start',
        'scheduled_end', 'status', 'actual_start', 'actual_end', 'facilitator_id', 'site_id',
        'location', 'reschedule_count', 'originally_scheduled_date', 'cancellation_reason',
        'readiness_complete', 'blocking_tasks_open', 'outcome', 'iso_clause_ref', 'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'definition_id' => 'integer',
            'sequence_no' => 'integer',
            'scheduled_date' => 'date',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'facilitator_id' => 'integer',
            'site_id' => 'integer',
            'reschedule_count' => 'integer',
            'originally_scheduled_date' => 'date',
            'readiness_complete' => 'boolean',
            'blocking_tasks_open' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'status' => OccurrenceStatus::class,
            'outcome' => ExerciseOutcome::class,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<ExerciseDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ExerciseDefinition::class, 'definition_id');
    }

    /** @return BelongsTo<User, $this> */
    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** @return HasMany<ExerciseParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ExerciseParticipant::class, 'occurrence_id');
    }

    /** @return HasMany<ReadinessTask, $this> */
    public function readinessTasks(): HasMany
    {
        return $this->hasMany(ReadinessTask::class, 'occurrence_id');
    }

    /** @return HasMany<ReminderSchedule, $this> */
    public function reminderSchedules(): HasMany
    {
        return $this->hasMany(ReminderSchedule::class, 'occurrence_id');
    }

    /** @return HasMany<ExerciseInject, $this> */
    public function injects(): HasMany
    {
        return $this->hasMany(ExerciseInject::class, 'occurrence_id');
    }

    /** @return HasMany<TimelineEntry, $this> */
    public function timeline(): HasMany
    {
        return $this->hasMany(TimelineEntry::class, 'occurrence_id');
    }

    /** @return HasMany<ExerciseScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(ExerciseScore::class, 'occurrence_id');
    }

    /** @return HasOne<Aar, $this> */
    public function aar(): HasOne
    {
        return $this->hasOne(Aar::class, 'occurrence_id');
    }

    /** @return HasMany<CorrectiveAction, $this> */
    public function carriedActions(): HasMany
    {
        return $this->hasMany(CorrectiveAction::class, 'carried_to_occurrence_id');
    }
}
