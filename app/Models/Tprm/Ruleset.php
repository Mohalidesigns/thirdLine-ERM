<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use App\Services\Tprm\Scoring\Ruleset as RulesetValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A versioned tiering ruleset — the factor weights, the knockout rules and the
 * band edges that decided a tier.
 *
 * A PUBLISHED RULESET IS IMMUTABLE. Editing one would break the promise
 * `tp_score_runs.ruleset_version` makes: that a score can be explained by
 * fetching the rules that produced it. Superseding writes a new row and points
 * `supersedes_id` at the old one, so the diff report has both sides.
 *
 * Exactly one published ruleset per tenant is current. `draft` rows are the
 * sandbox's working copies and may be edited freely, because nothing has been
 * scored with them yet.
 */
class Ruleset extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_rulesets';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'organization_id', 'version', 'name', 'notes', 'status',
        'factors', 'knockouts', 'band_edges',
        'published_at', 'published_by', 'supersedes_id', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'factors' => 'array',
        'knockouts' => 'array',
        'band_edges' => 'array',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $ruleset): void {
            // A published ruleset may change status (to retired) and nothing
            // else. The rules themselves are frozen the moment a score cites
            // the version.
            if ($ruleset->getOriginal('status') !== self::STATUS_PUBLISHED) {
                return;
            }

            $frozen = array_intersect(
                array_keys($ruleset->getDirty()),
                ['version', 'factors', 'knockouts', 'band_edges']
            );

            if ($frozen !== []) {
                throw new RuntimeException(
                    "Ruleset {$ruleset->version} is published and cannot be edited (".implode(', ', $frozen).'). '
                    .'Scores already cite this version; publish a new ruleset instead, which produces a reportable diff.'
                );
            }
        });
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @return BelongsTo<self, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** The pure value object the calculators take. */
    public function toValue(): RulesetValue
    {
        return RulesetValue::fromRow((object) [
            'version' => $this->version,
            'factors' => $this->factors,
            'knockouts' => $this->knockouts,
            'band_edges' => $this->band_edges,
        ]);
    }

    /**
     * The ruleset in force for a tenant, falling back to the shipped defaults.
     *
     * The fallback is not a convenience: a tenant provisioned before the
     * seeder ran, or one whose ruleset was retired without a successor, must
     * still be able to tier an engagement. Scoring with the shipped ruleset
     * and saying so on the run is better than refusing to score at all.
     */
    public static function currentValue(?int $organizationId = null): RulesetValue
    {
        $query = static::query()->published()->latest('published_at');

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $row = $query->first();

        return $row?->toValue() ?? RulesetValue::shipped();
    }
}
