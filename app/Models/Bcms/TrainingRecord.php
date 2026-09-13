<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One person's completion of one curriculum — a clause 7.2 mandatory record.
 *
 * `occurrence_id` links attendance at a drill, which IS training evidence under
 * 7.3, and is what stops a customer keeping two registers.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $curriculum_id
 * @property int $user_id
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?string $score
 * @property bool $competency_assessed
 * @property ?int $assessor_id
 * @property ?\Illuminate\Support\Carbon $next_due_date
 * @property ?int $certificate_id
 * @property ?int $occurrence_id
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class TrainingRecord extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'bcms_training_records';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'curriculum_id', 'user_id', 'completed_at', 'score',
        'competency_assessed', 'assessor_id', 'next_due_date', 'certificate_id', 'occurrence_id',
        'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'curriculum_id' => 'integer',
            'user_id' => 'integer',
            'completed_at' => 'datetime',
            'score' => 'decimal:2',
            'competency_assessed' => 'boolean',
            'assessor_id' => 'integer',
            'next_due_date' => 'date',
            'certificate_id' => 'integer',
            'occurrence_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<TrainingCurriculum, $this> */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(TrainingCurriculum::class, 'curriculum_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    /** @return BelongsTo<ExerciseOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ExerciseOccurrence::class, 'occurrence_id');
    }
}
