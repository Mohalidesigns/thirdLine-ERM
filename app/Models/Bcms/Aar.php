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
 * The after-action report — an ISO 22301 8.5 mandatory record, and the thing
 * that turns an exercise into an improvement. One per occurrence.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $occurrence_id
 * @property ?string $summary
 * @property ?string $what_worked
 * @property ?string $what_failed
 * @property array<array-key, mixed> $quantitative_results
 * @property array<array-key, mixed> $participant_feedback
 * @property bool $ai_generated
 * @property ?\Illuminate\Support\Carbon $ai_draft_generated_at
 * @property string $status
 * @property ?int $approved_by
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?\Illuminate\Support\Carbon $distributed_at
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Aar extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_aars';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'occurrence_id', 'summary', 'what_worked', 'what_failed',
        'quantitative_results', 'participant_feedback', 'ai_generated', 'ai_draft_generated_at',
        'status', 'approved_by', 'approved_at', 'distributed_at', 'iso_clause_ref', 'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantitative_results' => 'array',
            'participant_feedback' => 'array',
            'organization_id' => 'integer',
            'occurrence_id' => 'integer',
            'ai_generated' => 'boolean',
            'ai_draft_generated_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'distributed_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
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

    /** @return HasMany<Finding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'aar_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
