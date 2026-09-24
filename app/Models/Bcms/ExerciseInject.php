<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BindsToVisibleRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A scripted event released to participants during an exercise.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $occurrence_id
 * @property int $sequence
 * @property int $release_offset_minutes
 * @property string $title
 * @property ?string $content
 * @property ?string $delivery_channel
 * @property array<array-key, mixed> $target_rule
 * @property ?\Illuminate\Support\Carbon $released_at
 * @property ?int $released_by
 * @property bool $ai_generated
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class ExerciseInject extends Model
{
    use BelongsToOrganization, BindsToVisibleRecord, HasFactory;

    /**
     * Derived (ADR 0017 §2): an inject has no unit column of its own and takes
     * the shortest path to an anchor — through its occurrence to the
     * definition that carries the unit, the same path `ReadinessTask` takes.
     */
    public function orgAnchorPath(): string
    {
        return 'occurrence.definition';
    }

    /**
     * ADR 0017 Amendment 1, extended to Phase 9 (Gate 2 finding from Phase
     * 7.5's review): declared here, not inherited transitively from
     * `ExerciseOccurrence`'s own arm — `constrainAnchorPath()`'s
     * `whereHas('occurrence', …)` chain consults only the occurrence's
     * `scopeVisibleTo()`, so a cross-unit facilitator who reaches the
     * occurrence through ITS arm still 404s on `bcms.occurrences.injects.
     * release` without this. Only the facilitator releases an inject
     * (`bcms.exercise.facilitate`); no other role writes this table.
     *
     * @return list<string>
     */
    public function orgVisibilityNamedUsers(): array
    {
        return ['occurrence.facilitator_id'];
    }

    protected $table = 'bcms_exercise_injects';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'occurrence_id', 'sequence', 'release_offset_minutes', 'title',
        'content', 'delivery_channel', 'target_rule', 'released_at', 'released_by', 'ai_generated',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_rule' => 'array',
            'organization_id' => 'integer',
            'occurrence_id' => 'integer',
            'sequence' => 'integer',
            'release_offset_minutes' => 'integer',
            'released_at' => 'datetime',
            'released_by' => 'integer',
            'ai_generated' => 'boolean',
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

    /** @return BelongsTo<User, $this> */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
