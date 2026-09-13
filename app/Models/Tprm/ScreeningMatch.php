<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\ScreeningDecision;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A possible hit from a screening run, and the human decision about it.
 *
 * NO DRIVER EVER SETS `decision`. A name-similarity score is not a
 * determination, and AC-08 hangs off this column: a true match suspends every
 * engagement with the vendor, forces the residual score to 100, notifies the
 * AML function and opens a 24-hour STR task. A provider — or a fuzzy matcher —
 * able to trigger that chain would produce those consequences on a common
 * surname.
 *
 * THE RATIONALE IS REQUIRED ON EVERY DECISION, including a false positive.
 * "Different date of birth, different nationality, no connection to the
 * entity" is what makes a dismissal reviewable two years later by an examiner
 * who cannot re-run the search as it was.
 */
class ScreeningMatch extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_screening_matches';

    protected $fillable = [
        'organization_id', 'check_id', 'list_name', 'matched_name',
        'match_score', 'entity_details',
    ];

    /**
     * The decision and everything that follows from it are written by
     * `ScreeningService`, never by a driver and never by mass assignment.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'decision', 'decided_by', 'decided_at', 'rationale', 'escalated', 'str_task_id',
    ];

    protected $casts = [
        'decision' => ScreeningDecision::class,
        'match_score' => 'decimal:2',
        'entity_details' => 'array',
        'decided_at' => 'datetime',
        'escalated' => 'boolean',
    ];

    protected $attributes = [
        'decision' => 'pending',
        'escalated' => false,
    ];

    /** @return BelongsTo<ScreeningCheck, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(ScreeningCheck::class, 'check_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isTrueMatch(): bool
    {
        return $this->decision === ScreeningDecision::TrueMatch;
    }

    public function isPending(): bool
    {
        return $this->decision === ScreeningDecision::Pending;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('decision', ScreeningDecision::Pending->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTrueMatches(Builder $query): Builder
    {
        return $query->where('decision', ScreeningDecision::TrueMatch->value);
    }
}
