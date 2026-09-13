<?php

namespace App\Models\Bcms;

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
 * The annual exercise programme — the ISO 22301 8.5 record an examiner
 * compares delivery against. `total_planned` and `total_completed` are stored
 * for that reason: a count recomputed on read cannot show what was approved.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $programme_id
 * @property int $year
 * @property string $name
 * @property string $status
 * @property ?int $approved_by
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property int $total_planned
 * @property int $total_completed
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class ExerciseProgramme extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_exercise_programmes';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'programme_id', 'year', 'name', 'status', 'approved_by', 'approved_at',
        'total_planned', 'total_completed', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'programme_id' => 'integer',
            'year' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'total_planned' => 'integer',
            'total_completed' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Programme, $this> */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    /** @return HasMany<ExerciseDefinition, $this> */
    public function definitions(): HasMany
    {
        return $this->hasMany(ExerciseDefinition::class, 'exercise_programme_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
