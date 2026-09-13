<?php

namespace App\Models\Tprm;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * An external feed the module watches a portfolio through.
 *
 * `credentials` IS ENCRYPTED AT REST AND NEVER LEAVES THE SERVER. The settings
 * form writes it, the driver reads it, and the source-health panel reports
 * only WHETHER it is set. A screen that echoed a stored API key back into a
 * form field — the ordinary way this gets built — puts the key in a page
 * source, a browser cache and a support screenshot.
 *
 * A SOURCE THAT FAILS GOES STALE, NOT DOWN. `last_status` and `last_error`
 * exist so the console can say "this feed has not answered since Tuesday"
 * rather than throwing on a page a user opened to look at something else.
 * Every driver degrades to a badge; none of them can take a screen out.
 */
class MonitoringSource extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_monitoring_sources';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNCONFIGURED = 'unconfigured';

    protected $fillable = [
        'organization_id', 'driver', 'name', 'is_enabled', 'credentials',
        'config', 'signal_types', 'refresh_cron', 'created_by',
    ];

    /**
     * Written by the runner after a poll. A form that could set `last_status`
     * could make a dead feed report healthy.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = ['last_run_at', 'last_status', 'last_error'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config' => 'array',
        'signal_types' => 'array',
        'last_run_at' => 'datetime',
        // Encrypted by the framework on the way in and out. Never rendered.
        'credentials' => 'encrypted:array',
    ];

    protected $attributes = [
        'is_enabled' => false,
    ];

    protected $hidden = ['credentials'];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<MonitoringSignal, $this> */
    public function signals(): HasMany
    {
        return $this->hasMany(MonitoringSignal::class, 'source_id');
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->credentials);
    }

    /**
     * Whether the feed has answered recently enough to be believed.
     *
     * A source that has never run is NOT stale — it is new, and a console
     * showing a red badge against a feed somebody configured five minutes ago
     * teaches them to ignore the badge.
     */
    public function isStale(int $hours = 48): bool
    {
        if (! $this->is_enabled || $this->last_run_at === null) {
            return false;
        }

        return $this->last_run_at->isBefore(now()->subHours($hours));
    }

    /**
     * @return array<string, mixed>
     */
    public function healthReport(): array
    {
        return [
            'id' => $this->getKey(),
            'driver' => $this->driver,
            'name' => $this->name,
            'enabled' => $this->is_enabled,
            // Whether, never what.
            'credentials_set' => $this->hasCredentials(),
            'last_run_at' => $this->last_run_at?->toDayDateTimeString(),
            'last_status' => $this->last_status,
            'last_error' => $this->last_error,
            'stale' => $this->isStale(),
            'signal_types' => $this->signal_types ?? [],
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }
}
