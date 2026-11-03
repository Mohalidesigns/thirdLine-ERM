<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * The record that the monthly cycle opened for an organisation and period
 * (ADR 0026 section 4). One row per (organisation, period); a re-run reuses it.
 * It is what kri:cycle-watchdog looks for.
 */
class KriCycleRun extends Model
{
    use BelongsToOrganization;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'kri_cycle_runs';

    protected $fillable = [
        'organization_id',
        'period_id',
        'run_on',
        'trigger',
        'forced',
        'status',
        'started_at',
        'finished_at',
        'departments_due',
        'returns_opened',
        'requests_queued',
        'departments_without_champion',
        'error',
        'triggered_by',
    ];

    protected $casts = [
        'run_on' => 'date',
        'forced' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'departments_due' => 'integer',
        'returns_opened' => 'integer',
        'requests_queued' => 'integer',
        'departments_without_champion' => 'integer',
    ];

    /** @return BelongsTo<Period, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** @return HasMany<KriReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(KriReturn::class);
    }
}
