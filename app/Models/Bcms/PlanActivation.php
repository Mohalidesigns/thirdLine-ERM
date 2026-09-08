<?php

namespace App\Models\Bcms;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A record that a plan was activated.
 *
 * `is_exercise` separates a rehearsal from an activation in anger. A management
 * review that cannot tell them apart reports a plan as battle-tested when it
 * was only walked through.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property ?int $incident_id
 * @property ?int $occurrence_id
 * @property bool $is_exercise
 * @property ?int $activated_by
 * @property \Illuminate\Support\Carbon $activated_at
 * @property ?\Illuminate\Support\Carbon $deactivated_at
 * @property ?string $activation_reason
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class PlanActivation extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_plan_activations';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'plan_id', 'incident_id', 'occurrence_id', 'is_exercise',
        'activated_by', 'activated_at', 'deactivated_at', 'activation_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'plan_id' => 'integer',
            'incident_id' => 'integer',
            'occurrence_id' => 'integer',
            'is_exercise' => 'boolean',
            'activated_by' => 'integer',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    /** @return BelongsTo<ExerciseOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ExerciseOccurrence::class, 'occurrence_id');
    }

    /** @return BelongsTo<User, $this> */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
