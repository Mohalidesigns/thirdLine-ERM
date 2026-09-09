<?php

namespace App\Models\Bcms;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One readiness task on one occurrence.
 *
 * A blocking task that is not closed stops the exercise. It can be overridden,
 * never silently: the reason and the person are recorded and the AAR reports
 * the override.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $occurrence_id
 * @property ?int $template_task_id
 * @property string $title
 * @property ?string $description
 * @property ?int $owner_id
 * @property int $due_offset_days
 * @property ?\Illuminate\Support\Carbon $due_date
 * @property string $status
 * @property bool $is_blocking
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?int $completed_by
 * @property ?int $evidence_file_id
 * @property ?string $override_reason
 * @property ?int $overridden_by
 * @property ?\Illuminate\Support\Carbon $overridden_at
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class ReadinessTask extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_readiness_tasks';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'occurrence_id', 'template_task_id', 'title', 'description', 'owner_id',
        'due_offset_days', 'due_date', 'status', 'is_blocking', 'completed_at', 'completed_by',
        'evidence_file_id', 'override_reason', 'overridden_by', 'overridden_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'occurrence_id' => 'integer',
            'template_task_id' => 'integer',
            'owner_id' => 'integer',
            'due_offset_days' => 'integer',
            'due_date' => 'date',
            'is_blocking' => 'boolean',
            'completed_at' => 'datetime',
            'completed_by' => 'integer',
            'evidence_file_id' => 'integer',
            'overridden_by' => 'integer',
            'overridden_at' => 'datetime',
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

    /** @return BelongsTo<ReadinessTemplateTask, $this> */
    public function templateTask(): BelongsTo
    {
        return $this->belongsTo(ReadinessTemplateTask::class, 'template_task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
