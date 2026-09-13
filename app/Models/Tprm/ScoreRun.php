<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\RiskBand;
use App\Models\AuditTrailIsAppendOnly;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One computation of a score, with its complete input snapshot — TRD §7.9.
 *
 * IMMUTABLE, and guarded the same way the audit log is. A score run is an
 * event, not a record: it happened, and what it computed from does not change
 * afterwards. Scores are never recomputed retrospectively — a ruleset change
 * produces a NEW run and a reportable diff — which is only true if the old run
 * cannot be edited.
 *
 * This is what makes AC-15 achievable: the "Why this score" panel renders from
 * this row, so two users looking at the same score at the same time see the
 * same derivation because they are reading the same stored explanation rather
 * than each triggering their own recomputation.
 */
class ScoreRun extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_score_runs';

    public const UPDATED_AT = null;

    /** @var list<string> */
    public const RUN_TYPES = ['inherent', 'residual', 'scheduled', 'triggered'];

    protected $fillable = [
        'organization_id', 'engagement_id', 'run_type', 'engine_version', 'ruleset_version',
        'inputs', 'ir', 'ac', 'ec', 'm', 'fu', 'su', 'rr', 'band', 'dc', 'explanation',
        'triggered_by', 'created_at',
    ];

    protected $casts = [
        'inputs' => 'array',
        'explanation' => 'array',
        'band' => RiskBand::class,
        'created_at' => 'datetime',
        'ir' => 'decimal:2',
        'ac' => 'decimal:3',
        'ec' => 'decimal:3',
        'm' => 'decimal:3',
        'fu' => 'decimal:2',
        'su' => 'decimal:2',
        'rr' => 'decimal:2',
        'dc' => 'decimal:3',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->created_at ??= now();
            $run->engine_version ??= (string) config('tprm.engine_version');
        });

        static::updating(function (self $run): void {
            throw new AuditTrailIsAppendOnly(
                "tp_score_runs row {$run->getKey()} cannot be updated: a score run records what was computed, ".
                'and a rule change produces a new run rather than editing an old one (TRD §7.9).'
            );
        });

        static::deleting(function (self $run): void {
            throw new AuditTrailIsAppendOnly(
                "tp_score_runs row {$run->getKey()} cannot be deleted: score history is the explanation of every ".
                'figure that has ever been reported to a board or a supervisor.'
            );
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }
}
