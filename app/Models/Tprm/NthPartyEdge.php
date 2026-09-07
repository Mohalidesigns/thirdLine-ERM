<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\DisclosureSource;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One dependency from a third party to one of its own — FR-NTH.
 *
 * `child_third_party_id` IS NULLABLE AND `child_name_raw` IS NOT. A vendor's
 * SOC 2 naming "a large public cloud provider" is a real disclosure and has to
 * be recordable before anybody matches it to a register row. Requiring the
 * child to exist first would mean the most common disclosure — a name in a
 * document, not yet a vendor we have onboarded — could not be captured at all,
 * which is precisely the fourth-party exposure this table exists to hold.
 *
 * `disclosure_source` IS THE COLUMN THAT MAKES THE BEST FEATURE POSSIBLE. An
 * entity that appears in a SOC 2 carve-out or a discovery pass but NEVER in
 * anything the vendor declared is an undeclared sub-processor — a broken
 * disclosure obligation the module can prove from two rows rather than assert.
 *
 * `rank` IS DISTANCE FROM US: 1 is our vendor's vendor. It is stored rather
 * than computed because the graph is walked from many directions and a rank
 * derived at read time would disagree with itself depending on the path taken.
 */
class NthPartyEdge extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_nth_party_edges';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'organization_id', 'parent_third_party_id', 'child_third_party_id', 'child_name_raw',
        'engagement_id', 'rank', 'service_description', 'data_categories',
        'country_of_processing', 'criticality', 'disclosure_source', 'disclosed_at',
        'created_by',
    ];

    /** @var list<string> */
    public const GUARDED_STATE = ['confirmation_status', 'confirmed_by', 'is_active', 'ceased_at'];

    protected $casts = [
        'data_categories' => 'array',
        'disclosure_source' => DisclosureSource::class,
        'disclosed_at' => 'date',
        'ceased_at' => 'date',
        'is_active' => 'boolean',
        'rank' => 'integer',
    ];

    protected $attributes = [
        'rank' => 1,
        'confirmation_status' => self::STATUS_PROPOSED,
        'is_active' => true,
    ];

    /** @return BelongsTo<ThirdParty, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'parent_third_party_id');
    }

    /** @return BelongsTo<ThirdParty, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'child_third_party_id');
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmation_status === self::STATUS_CONFIRMED;
    }

    /**
     * Whether the vendor itself told us about this.
     *
     * An edge known ONLY from sources where this is false is undeclared — the
     * finding in its own right that FR-NTH's best feature turns on.
     */
    public function isVendorDisclosed(): bool
    {
        return $this->disclosure_source->isVendorDisclosure();
    }

    public function displayName(): string
    {
        return $this->child->legal_name ?? $this->child_name_raw;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('confirmation_status', '!=', self::STATUS_REJECTED);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->live()->where('confirmation_status', self::STATUS_CONFIRMED);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeProposed(Builder $query): Builder
    {
        return $query->where('confirmation_status', self::STATUS_PROPOSED)->where('is_active', true);
    }
}
