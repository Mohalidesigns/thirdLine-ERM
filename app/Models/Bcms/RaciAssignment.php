<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\RaciRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One person's RACI letter on one programme or process.
 *
 * ACCOUNTABLE IS SINGULAR. That is the whole content of the model and it is
 * what the gap report tests: a process with three responsible people and nobody
 * accountable has no owner, and one with two accountable people has no owner
 * either.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $assignable_type
 * @property int $assignable_id
 * @property int $user_id
 * @property \App\Enums\Bcms\RaciRole $raci_role
 * @property ?string $note
 * @property ?int $created_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class RaciAssignment extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_raci_assignments';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'assignable_type', 'assignable_id', 'user_id', 'raci_role', 'note',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'assignable_id' => 'integer',
            'user_id' => 'integer',
            'created_by' => 'integer',
            'raci_role' => RaciRole::class,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The programme or process this letter is held on.
     *
     * `assignable_type` stores a short alias — `bcms_process`, `bcms_programme`
     * — never a class name (ADR 0002).
     *
     * @return MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }
}
