<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\LadderLevel;
use App\Models\Bcms\Concerns\BcmsAuditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * An exercise type, and its rung on the ISO 22398 ladder.
 *
 * NOT A DROPDOWN. `ladder_level` is an ordered maturity progression and
 * `LadderLevel::rank()` is what lets the engine warn about a full-scale
 * exercise for a process that has never had a successful tabletop.
 * System-owned: the shipped catalogue is read by every tenant.
 *
 * @property int $id
 * @property ?int $organization_id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property \App\Enums\Bcms\LadderLevel $ladder_level
 * @property int $default_duration_minutes
 * @property int $default_frequency_per_year
 * @property int $default_lead_time_days
 * @property ?int $readiness_template_id
 * @property array<array-key, mixed> $objectives_template
 * @property ?string $cadence_clause_ref
 * @property bool $is_system_default
 * @property bool $is_active
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class ExerciseType extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'bcms_exercise_types';

    /** System-owned rows (`organization_id = null`) are visible to every tenant. */
    protected bool $tenantIncludesGlobal = true;

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'code', 'name', 'description', 'ladder_level',
        'default_duration_minutes', 'default_frequency_per_year', 'default_lead_time_days',
        'readiness_template_id', 'objectives_template', 'cadence_clause_ref', 'is_system_default',
        'is_active', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'objectives_template' => 'array',
            'organization_id' => 'integer',
            'default_duration_minutes' => 'integer',
            'default_frequency_per_year' => 'integer',
            'default_lead_time_days' => 'integer',
            'readiness_template_id' => 'integer',
            'is_system_default' => 'boolean',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'ladder_level' => LadderLevel::class,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<ReadinessTemplate, $this> */
    public function readinessTemplate(): BelongsTo
    {
        return $this->belongsTo(ReadinessTemplate::class, 'readiness_template_id');
    }

    /** @return HasMany<ExerciseDefinition, $this> */
    public function definitions(): HasMany
    {
        return $this->hasMany(ExerciseDefinition::class, 'exercise_type_id');
    }
}
