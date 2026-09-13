<?php

namespace App\Models;

use App\Enums\WorkflowTaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A decision somebody owes, at a named node of a running instance.
 *
 * This row is what the old engine did not have. Without it there was nowhere
 * to put a due date, so "overdue" was not a fact the platform held; nowhere to
 * put an assignee, so delegate had nothing to change; and no way to have two
 * open decisions on one subject, so a parallel review was unrepresentable.
 */
class WorkflowTask extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'instance_id', 'node_code', 'node_name', 'node_type',
        'assignee_id', 'assignee_role', 'candidate_roles', 'candidate_user_ids',
        'status', 'due_at', 'escalated_at', 'completed_at', 'outcome', 'comments',
        'delegated_from', 'delegated_to', 'completed_by', 'form_data',
    ];

    protected $casts = [
        'status' => WorkflowTaskStatus::class,
        'candidate_roles' => 'array',
        'candidate_user_ids' => 'array',
        'due_at' => 'datetime',
        'escalated_at' => 'datetime',
        'completed_at' => 'datetime',
        'form_data' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\WorkflowInstance, $this> */
    public function instance(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this> */
    public function assignee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this> */
    public function delegatedFrom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_from');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this> */
    public function delegatedTo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_to');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this> */
    public function completedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WorkflowAction, $this> */
    public function actions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkflowAction::class, 'task_id')->orderBy('acted_at');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', WorkflowTaskStatus::openValues());
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    /**
     * Tasks this user can act on: assigned to them by name, or offered to a
     * role they hold and not yet claimed by anyone.
     *
     * The unassigned-role case is why the platform can seed a definition
     * without knowing the org chart — "the risk-manager approves this" is a
     * statement about a role, and the first holder of that role to act claims it.
     */
    public function scopeActionableBy(Builder $query, User $user): Builder
    {
        $roles = $user->getRoleNames()->all();

        return $query->where(function (Builder $q) use ($user, $roles) {
            $q->where('assignee_id', $user->id);

            $q->orWhere(function (Builder $unclaimed) use ($user, $roles) {
                $unclaimed->whereNull('assignee_id')
                    ->where(function (Builder $offer) use ($user, $roles) {
                        if ($roles !== []) {
                            $offer->whereIn('assignee_role', $roles);

                            foreach ($roles as $role) {
                                $offer->orWhereJsonContains('candidate_roles', $role);
                            }
                        }

                        $offer->orWhereJsonContains('candidate_user_ids', $user->id);
                    });
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }

    /**
     * Hours past due, or null when not overdue. Negative is not returned —
     * "how late" and "how much time is left" are different questions and the
     * SLA screens ask only the first.
     */
    public function hoursOverdue(): ?float
    {
        if (! $this->isOverdue()) {
            return null;
        }

        return round($this->due_at->diffInMinutes(now()) / 60, 1);
    }

    /** The node definition this task was created from, on the pinned version. */
    public function node(): ?array
    {
        return $this->instance?->definitionSnapshot()->node($this->node_code);
    }
}
