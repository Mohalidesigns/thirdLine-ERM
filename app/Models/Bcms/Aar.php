<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\BindsToVisibleRecord;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use LogicException;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * The after-action report — an ISO 22301 8.5 mandatory record, and the thing
 * that turns an exercise into an improvement. One per occurrence.
 *
 * ADR 0020 §1 (BCMS Phase 10): THIS TABLE ALSO HOLDS THE POST-INCIDENT REVIEW.
 * `occurrence_id` is nullable; `incident_id` is its nullable-unique twin.
 * EXACTLY ONE of the two is ever set — enforced in `AarService`/`PirService`
 * and, belt and braces, in the `saving` guard below, which throws rather than
 * silently persisting a row with both or neither. `subject()`/
 * `isPostIncident()` are the one place every caller is meant to branch on
 * which edge is set, rather than re-deriving it — Phase 9 and Phase 10 now
 * share this table, a service and an export, and every such branch is a place
 * the two can drift.
 *
 * `constrainToVisibleRecord()` IS OVERRIDDEN, NOT `orgAnchorPath()` ALONE.
 * `BindsToVisibleRecord` resolves a route-bound instance before any attribute
 * is loaded, so a conditional inside `orgAnchorPath()` on `$this->incident_id`
 * would always see a blank, unloaded model and always take the exercise arm —
 * silently 404ing every post-incident review. The override below ORs both
 * anchor paths into one predicate instead, which is correct for either kind of
 * row regardless of which one a given uuid turns out to be. `orgAnchorPath()`
 * itself is kept, unconditionally `occurrence.definition`, purely so the
 * generic classifiability guard (`BcmsRecordVisibilityTest`) has a path to
 * walk and verify terminates on an anchor.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $occurrence_id
 * @property ?int $incident_id
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
 * @property-read ExerciseOccurrence|null $occurrence
 */
class Aar extends Model
{
    use BcmsAuditable, BelongsToOrganization, BindsToVisibleRecord, HasBcmsUuid, HasFactory, SoftDeletes;

    /**
     * Derived (ADR 0017 §2 / ADR 0019 §4): an AAR has no unit column of its
     * own and takes the shortest path to an anchor — its occurrence, the same
     * path `Evidence` takes for the same reason (a report about an exercise is
     * as sensitive as the exercise it reports on).
     */
    public function orgAnchorPath(): string
    {
        return 'occurrence.definition';
    }

    /**
     * ADR 0017 Amendment 1, extended to Phase 9 (Gate 2 finding from Phase
     * 7.5's review): declared here, not inherited transitively from
     * `ExerciseOccurrence`'s own arm, for exactly the reason `ReadinessTask`'s
     * own docblock gives — `constrainAnchorPath()`'s `whereHas('occurrence',
     * …)` chain consults only the occurrence's `scopeVisibleTo()`, so a
     * cross-unit facilitator who reaches the occurrence through ITS arm still
     * 404s on `bcms.aars.show`/`.update` without this.
     *
     * Only `occurrence.facilitator_id`: the AAR's other actor, the approver,
     * is not a stored id ahead of approval (there is no `approver_id` column
     * to name — `approved_by` is written only once approval happens), so
     * there is nothing to declare for that role. A participant does not act
     * on the AAR routes at all (they score objectives, on a different model).
     *
     * @return list<string>
     */
    public function orgVisibilityNamedUsers(): array
    {
        return ['occurrence.facilitator_id'];
    }

    /**
     * ADR 0020 §1: neither the exercise arm nor the incident arm alone is
     * correct for every row, so this ORs both into one predicate rather than
     * picking one based on unloaded state.
     *
     * THE NAMED-USER ARM IS ADDED HERE TOO, NOT LEFT TO `BindsToVisibleRecord`'s
     * GENERIC BODY — this method overrides that trait's `constrainToVisibleRecord()`
     * wholesale (see the class docblock on why), so the generic body's own
     * `orgVisibilityNamedUsers()` check never runs unless this override calls
     * it itself. Omitting this line is exactly how the Phase 7.5 review found
     * the gap: `orgVisibilityNamedUsers()` declared on a model whose own
     * override never reads it is a contract nobody is honouring.
     *
     * @param  Builder<static>|Relation<static, Model, *>  $query
     */
    public function constrainToVisibleRecord(Builder|Relation $query): void
    {
        $user = Auth::user();
        $namedUsers = $this->orgVisibilityNamedUsers();

        $query->where(function (Builder|Relation $q) use ($user, $namedUsers) {
            $q->whereHas('occurrence', function (Builder $oq) use ($user) {
                $oq->whereHas('definition', function (Builder $dq) use ($user) {
                    /** @var ExerciseDefinition $anchor */
                    $anchor = $dq->getModel();
                    $anchor->scopeVisibleTo($dq, $user);
                });
            })->orWhereHas('incident', function (Builder $iq) use ($user) {
                /** @var Incident $anchor */
                $anchor = $iq->getModel();
                $anchor->scopeVisibleTo($iq, $user);
            });

            $this->orNamedUserVisibility($q, $namedUsers, $user);
        });
    }

    protected $table = 'bcms_aars';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'occurrence_id', 'incident_id', 'summary', 'what_worked', 'what_failed',
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
            'incident_id' => 'integer',
            'ai_generated' => 'boolean',
            'ai_draft_generated_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'distributed_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // ADR 0020 §1: the guard a service, a seeder and a tinker session all
        // go through alike — a CHECK constraint would be invisible to
        // `bcms:verify-schema`, which compares columns, not constraints.
        static::saving(function (self $aar): void {
            $hasOccurrence = $aar->occurrence_id !== null;
            $hasIncident = $aar->incident_id !== null;

            if ($hasOccurrence === $hasIncident) {
                throw new LogicException(
                    'An after-action report must have exactly one of occurrence_id or incident_id set — '
                    .'it is either an exercise AAR or a post-incident review, never both and never neither.'
                );
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<ExerciseOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ExerciseOccurrence::class, 'occurrence_id');
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
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

    /* ------------------------------------------------------------------ */
    /*  ADR 0020 §1 — the one place every caller branches */
    /* ------------------------------------------------------------------ */

    /** Whether this row is a post-incident review rather than an exercise AAR. */
    public function isPostIncident(): bool
    {
        return $this->incident_id !== null;
    }

    /** The occurrence or the incident this report is about — never both. */
    public function subject(): ExerciseOccurrence|Incident|null
    {
        return $this->isPostIncident() ? $this->incident : $this->occurrence;
    }
}
