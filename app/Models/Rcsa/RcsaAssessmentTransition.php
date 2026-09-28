<?php

namespace App\Models\Rcsa;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One movement of an assessment through the state machine of §9.1.
 *
 * APPEND-ONLY. `$timestamps` is off because the table has no `updated_at`, and
 * the table has no `updated_at` because a transition that can be edited is not
 * an audit record. Nothing in the module updates or deletes one; the only
 * write is the insert RcsaWorkflowService makes inside the same transaction as
 * the status change, so a status the log does not explain cannot exist.
 */
class RcsaAssessmentTransition extends Model
{
    use BelongsToOrganization;

    /**
     * §9.1's diagram labels the assessment's creation "draft ──open──▶
     * in_progress"; RcsaCycleService is the only writer, and it writes
     * straight to `in_progress` with no `draft` row ever persisted, so this is
     * a `none → in_progress` row rather than a transition FROM anything.
     */
    public const OPEN = 'open';

    public const SUBMIT = 'submit';

    public const APPROVE = 'approve';

    public const CLAIM = 'claim';

    public const VALIDATE = 'validate';

    public const RETURN = 'return';

    public const ESCALATE = 'escalate';

    public const CLOSE = 'close';

    protected $table = 'rcsa_assessment_transitions';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'assessment_id',
        'user_id',
        'from_status',
        'to_status',
        'event',
        'reason',
        'request_id',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<RcsaAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RcsaAssessment::class, 'assessment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
