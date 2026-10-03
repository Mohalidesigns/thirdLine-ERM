<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\BindsToVisibleRecord;
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
 * `BcmsAuditable`, the same way `ExerciseInject` carries it: `complete()` and
 * `override()` in `ReadinessService` both call `update()`, which gives every
 * completion and override a `bcms_audit_logs` row for free, on top of the
 * named `readiness_task_completed`/`readiness_task_overridden` events those
 * two methods record explicitly for the human-readable "who, and why" a
 * column diff alone does not carry.
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
    use BcmsAuditable, BelongsToOrganization, BindsToVisibleRecord, HasFactory;

    protected $table = 'bcms_readiness_tasks';

    /**
     * Derived (ADR 0017 §2): a task has no unit column of its own and takes
     * the shortest path to an anchor — through its occurrence to the
     * definition that carries the unit.
     */
    public function orgAnchorPath(): string
    {
        return 'occurrence.definition';
    }

    /**
     * ADR 0017 Amendment 1. The arm is declared here, not inherited
     * transitively from `ExerciseOccurrence`'s own arm: `constrainAnchorPath()`
     * walks `occurrence.definition` as a bare `whereHas` chain that consults
     * only the anchor's `scopeVisibleTo()`, so a cross-unit facilitator who
     * reaches the occurrence through ITS arm would otherwise get 404 from
     * `readiness-tasks/{task}/complete` and `.override` on every task on it —
     * including the ones they own (`ReadinessService.php:83` sets `owner_id`
     * to `occurrence.facilitator_id` by default when the ladder is generated).
     *
     * `owner_id`: a reassignee must be able to act on their own task.
     * `occurrence.facilitator_id`: the facilitator holds
     * `bcms.readiness.override` and answers for the gate — the person who
     * decides whether the exercise may start must be able to clear what
     * blocks it, including a task owned by somebody else.
     *
     * @return list<string>
     */
    public function orgVisibilityNamedUsers(): array
    {
        return ['owner_id', 'occurrence.facilitator_id'];
    }

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
