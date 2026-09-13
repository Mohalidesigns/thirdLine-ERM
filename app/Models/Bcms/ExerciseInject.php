<?php

namespace App\Models\Bcms;

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
    use BelongsToOrganization, HasFactory;

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
