<?php

namespace App\Models\Tprm;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One concentration run — TRD §7.8.
 *
 * IMMUTABLE, like a score run. Comparing this quarter's concentration to last
 * quarter's requires last quarter's numbers to have survived unchanged, and a
 * table that could be updated would let a portfolio look as though it had
 * always been diversified.
 */
class ConcentrationAnalysis extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_concentration_analyses';

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'run_at', 'dimension', 'results', 'hhi',
        'spof_list', 'threshold_breaches', 'created_at',
    ];

    protected $casts = [
        'run_at' => 'datetime',
        'created_at' => 'datetime',
        'results' => 'array',
        'spof_list' => 'array',
        'threshold_breaches' => 'array',
        'hhi' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // A snapshot is an event, not a record. There is no update path to
        // this table and the model refuses one rather than trusting that
        // nobody writes it.
        static::updating(function (): bool {
            throw new \RuntimeException(
                'A concentration run is a snapshot and cannot be edited. Run the analysis again — the '
                .'comparison between two quarters is only meaningful if neither has been rewritten.'
            );
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLatestFor(Builder $query, string $dimension): Builder
    {
        return $query->where('dimension', $dimension)->orderByDesc('run_at');
    }
}
