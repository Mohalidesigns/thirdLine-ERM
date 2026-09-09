<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A BC training curriculum (ISO 22301 clauses 7.2 and 7.3).
 *
 * `requires_assessment` is the difference between awareness and COMPETENCE. A
 * curriculum that asserts competence without saying how it is assessed produces
 * a record that proves attendance and nothing else.
 *
 * @property int $id
 * @property ?int $organization_id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property array<array-key, mixed> $target_roles
 * @property array<array-key, mixed> $modules
 * @property int $frequency_months
 * @property bool $is_mandatory
 * @property bool $requires_assessment
 * @property ?int $pass_mark
 * @property bool $is_system_default
 * @property bool $is_active
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class TrainingCurriculum extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'bcms_training_curricula';

    /** System-owned rows (`organization_id = null`) are visible to every tenant. */
    protected bool $tenantIncludesGlobal = true;

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'code', 'name', 'description', 'target_roles', 'modules',
        'frequency_months', 'is_mandatory', 'requires_assessment', 'pass_mark',
        'is_system_default', 'is_active', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_roles' => 'array',
            'modules' => 'array',
            'organization_id' => 'integer',
            'frequency_months' => 'integer',
            'is_mandatory' => 'boolean',
            'requires_assessment' => 'boolean',
            'pass_mark' => 'integer',
            'is_system_default' => 'boolean',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return HasMany<TrainingRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(TrainingRecord::class, 'curriculum_id');
    }
}
