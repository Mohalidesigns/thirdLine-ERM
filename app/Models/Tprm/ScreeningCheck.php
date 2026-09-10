<?php

namespace App\Models\Tprm;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One screening run against one subject — CBN AML/CFT Reg. 29.
 *
 * THE SUBJECT IS THE ENTITY *OR* ONE OWNERSHIP ROW. Reg. 29 requires directors
 * and ultimate beneficial owners to be screened in their own right, not
 * inferred from screening the company — which is the whole point, because a
 * sanctioned individual sits behind a company that is not itself listed.
 *
 * `raw_response` IS THE EVIDENCE, and it is kept verbatim. Reg. 35 requires
 * five years' retention retrievable within 48 hours, and what has to be
 * retrievable is the provider's answer rather than our summary of it: a
 * normalised match list is our reading, and a supervisor asking what the list
 * said in 2021 is not asking what we concluded.
 */
class ScreeningCheck extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_screening_checks';

    public const SUBJECT_THIRD_PARTY = 'third_party';

    public const SUBJECT_OWNERSHIP = 'ownership';

    public const STATUS_CLEAR = 'clear';

    public const STATUS_MATCHES = 'matches';

    public const STATUS_FAILED = 'failed';

    /**
     * Reg. 35: five years, retrievable within 48 hours. Stated here because
     * the retention job and the retrieval test both read it, and a number
     * living in two places drifts.
     */
    public const RETENTION_YEARS = 5;

    protected $fillable = [
        'organization_id', 'subject_type', 'subject_id', 'provider', 'list_types',
        'run_at', 'status', 'raw_response', 'next_due_at', 'created_by',
    ];

    protected $casts = [
        'list_types' => 'array',
        'raw_response' => 'array',
        'run_at' => 'datetime',
        'next_due_at' => 'datetime',
    ];

    /** @return HasMany<ScreeningMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(ScreeningMatch::class, 'check_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The subject, resolved.
     *
     * A local map rather than the enforced morph map, for the reason
     * `Document::ownerModels()` gives: `ownership` is a name this table
     * defines and not one the wider product should have to reserve.
     */
    public function subject(): ?Model
    {
        return match ($this->subject_type) {
            self::SUBJECT_THIRD_PARTY => ThirdParty::query()->find($this->subject_id),
            self::SUBJECT_OWNERSHIP => Ownership::query()->find($this->subject_id),
            default => null,
        };
    }

    /**
     * The third party this check ultimately concerns.
     *
     * An ownership row's screening is still evidence about the vendor, and a
     * retrieval that could only find checks filed directly against the entity
     * would miss exactly the directors Reg. 29 exists for.
     */
    public function thirdPartyId(): ?int
    {
        if ($this->subject_type === self::SUBJECT_THIRD_PARTY) {
            return $this->subject_id;
        }

        return Ownership::query()->whereKey($this->subject_id)->value('third_party_id');
    }

    public function isRetainable(): bool
    {
        return $this->run_at !== null
            && $this->run_at->isAfter(now()->subYears(self::RETENTION_YEARS));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForThirdParty(Builder $query, int $thirdPartyId): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where(fn (Builder $entity) => $entity
                ->where('subject_type', self::SUBJECT_THIRD_PARTY)
                ->where('subject_id', $thirdPartyId))
            ->orWhere(fn (Builder $owner) => $owner
                ->where('subject_type', self::SUBJECT_OWNERSHIP)
                ->whereIn('subject_id', Ownership::query()
                    ->where('third_party_id', $thirdPartyId)
                    ->select('id'))));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereNotNull('next_due_at')->where('next_due_at', '<=', now());
    }
}
