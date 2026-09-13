<?php

namespace App\Models\Bcms;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One entry in an exercise's timeline. The `bcms_exercise_timeline` table is
 * singular in the blueprint and is kept that way; the model is named for what
 * a row is.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $occurrence_id
 * @property \Illuminate\Support\Carbon $logged_at
 * @property ?int $logged_by
 * @property string $entry_type
 * @property ?string $content
 * @property array<array-key, mixed> $metadata
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class TimelineEntry extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_exercise_timeline';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'occurrence_id', 'logged_at', 'logged_by', 'entry_type', 'content',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'organization_id' => 'integer',
            'occurrence_id' => 'integer',
            'logged_at' => 'datetime',
            'logged_by' => 'integer',
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
    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
