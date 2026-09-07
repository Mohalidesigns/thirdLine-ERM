<?php

namespace App\Models\Rcsa;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Before and after, for one material field on one line. Rule 7 of the process
 * flow.
 *
 * APPEND-ONLY, and the schema says so: no `updated_at`, no soft delete. A
 * revision that can be edited or removed is not an audit trail. The model
 * enforces the same thing — there is no update path and `$timestamps` is off
 * for the half that does not exist.
 */
class RcsaLineRevision extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $table = 'rcsa_line_revisions';

    protected $fillable = [
        'organization_id', 'line_id', 'user_id', 'field',
        'old_value', 'new_value', 'reason', 'request_id', 'ip_address',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<RcsaAssessmentLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(RcsaAssessmentLine::class, 'line_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
