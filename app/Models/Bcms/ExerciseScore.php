<?php

namespace App\Models\Bcms;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * An evaluator's score against one objective.
 *
 * `objective_text` snapshots the wording, because an objective edited after
 * the exercise would otherwise rewrite what the evaluator was scoring.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $occurrence_id
 * @property ?int $objective_id
 * @property ?string $objective_text
 * @property ?int $evaluator_id
 * @property ?int $score
 * @property ?string $commentary
 * @property ?int $evidence_file_id
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class ExerciseScore extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_exercise_scores';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'occurrence_id', 'objective_id', 'objective_text', 'evaluator_id',
        'score', 'commentary', 'evidence_file_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'occurrence_id' => 'integer',
            'objective_id' => 'integer',
            'evaluator_id' => 'integer',
            'score' => 'integer',
            'evidence_file_id' => 'integer',
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

    /** @return BelongsTo<Objective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class, 'objective_id');
    }

    /** @return BelongsTo<User, $this> */
    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }
}
