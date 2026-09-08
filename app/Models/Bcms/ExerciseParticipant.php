<?php

namespace App\Models\Bcms;

use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One person's part in one occurrence.
 *
 * `contact_id` as well as `user_id`, because a reminder resolves against a
 * CONTACT — a user has no channel (ADR 0003) — and because a security guard
 * or a contractor participates in a fire drill without a platform login.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $occurrence_id
 * @property ?int $user_id
 * @property ?int $contact_id
 * @property string $role
 * @property ?int $business_unit_id
 * @property string $invitation_status
 * @property string $attendance_status
 * @property ?\Illuminate\Support\Carbon $checked_in_at
 * @property ?string $check_in_method
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class ExerciseParticipant extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_exercise_participants';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'occurrence_id', 'user_id', 'contact_id', 'role', 'business_unit_id',
        'invitation_status', 'attendance_status', 'checked_in_at', 'check_in_method',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'occurrence_id' => 'integer',
            'user_id' => 'integer',
            'contact_id' => 'integer',
            'business_unit_id' => 'integer',
            'checked_in_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }
}
