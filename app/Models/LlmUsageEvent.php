<?php

namespace App\Models;

use App\Enums\Llm\Outcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One gateway call, successful or refused — phase-11a-ai-contract.md §3.1.
 *
 * APPEND-ONLY. Nothing in this product updates or deletes a row here except
 * `App\Console\Commands\PruneLlmUsageEvents`, which is a retention job, not a
 * correction — this is telemetry, not audit evidence, and carries no
 * `TprmAuditable`/`BcmsAuditable` trait for that reason: `tp_audit_logs` and
 * `bcms_audit_logs` hold the governance events, this table holds the traffic.
 *
 * `$timestamps = false` because there is no `updated_at` column: `created_at`
 * only, per the frozen schema.
 */
class LlmUsageEvent extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $table = 'llm_usage_events';

    protected $fillable = [
        'organization_id', 'module', 'service', 'prompt_key', 'prompt_version',
        'endpoint_profile', 'model', 'outcome', 'attempts',
        'prompt_tokens', 'completion_tokens', 'total_tokens', 'duration_ms',
        'unit_cost_minor', 'currency', 'usage_month',
        'subject_type', 'subject_id', 'user_id', 'created_at',
    ];

    protected $casts = [
        'outcome' => Outcome::class,
        'attempts' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'duration_ms' => 'integer',
        'unit_cost_minor' => 'integer',
        'subject_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * `user_id` is nullable (queue/system calls and deleted users), but this
     * relation's own `@return BelongsTo<User, $this>` annotation resolves
     * `$this->user` to a non-nullable `User` for static analysis — reading it
     * through this method instead carries the true nullability in the return
     * type itself, so a caller's `?->` is honoured rather than flagged as
     * redundant. Reads the already-loaded relation; does not issue a query
     * when the caller has eager-loaded `user`.
     */
    public function loadedUser(): ?User
    {
        return $this->getRelationValue('user');
    }
}
