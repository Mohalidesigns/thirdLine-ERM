<?php

namespace App\Models\Tprm;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sanctions list held locally so screening works without a subscription.
 *
 * NOT TENANT-SCOPED, deliberately. A sanctions list is the same list for every
 * institution in the country; storing it per tenant would hold the UN
 * consolidated list once per customer and let one tenant's stale refresh give
 * a different answer from another's.
 */
class SanctionsList extends Model
{
    protected $table = 'tp_sanctions_lists';

    public const UNSCR = 'unscr';

    public const NIGSAC = 'nigsac';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'code', 'name', 'publisher', 'source_url', 'is_built_in',
        'published_at', 'is_active',
    ];

    /** @var list<string> */
    public const GUARDED_STATE = [
        'last_refreshed_at', 'last_refresh_status', 'last_refresh_error', 'entry_count',
    ];

    protected $casts = [
        'is_built_in' => 'boolean',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'entry_count' => 'integer',
    ];

    /** @return HasMany<SanctionsEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(SanctionsEntry::class, 'list_id');
    }

    /**
     * Whether this list is fresh enough to screen against.
     *
     * A list that has NEVER been refreshed is not stale, it is empty — and the
     * console has to say which, because "we screened against a list with no
     * entries in it" and "we screened against last month's list" are different
     * failures and only one of them looks like a clear result.
     */
    public function isStale(int $days = 7): bool
    {
        return $this->last_refreshed_at !== null
            && $this->last_refreshed_at->isBefore(now()->subDays($days));
    }

    public function isEmpty(): bool
    {
        return $this->entry_count === 0;
    }

    /** @return array<string, mixed> */
    public function healthReport(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'built_in' => $this->is_built_in,
            'entries' => $this->entry_count,
            'last_refreshed_at' => $this->last_refreshed_at?->toDayDateTimeString(),
            'status' => $this->last_refresh_status,
            'error' => $this->last_refresh_error,
            'stale' => $this->isStale(),
            'empty' => $this->isEmpty(),
            // The sentence a console shows rather than a colour alone.
            'note' => match (true) {
                $this->isEmpty() => 'This list holds no entries, so screening against it finds nothing. '
                    .'It has to be refreshed before a clear result means anything.',
                $this->isStale() => 'Last refreshed '.$this->last_refreshed_at?->diffForHumans()
                    .'. A designation made since then would not be found.',
                default => null,
            },
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
