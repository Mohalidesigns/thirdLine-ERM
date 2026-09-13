<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\SignalSeverity;
use App\Enums\Tprm\SignalType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One observation about a third party — FR-MON-03.
 *
 * `dedupe_key` IS UNIQUE ACROSS THE WHOLE TABLE AND THAT IS THE FEATURE. A
 * monitoring stream that reports the same expired certificate every night for
 * six weeks is a stream people stop reading, and once they stop reading it the
 * programme has continuous monitoring in name only. The key is built from what
 * makes the observation the same observation — the subject, the type, and
 * whatever identifies the specific thing observed — so the same fact re-derived
 * tomorrow silently collides instead of arriving again.
 *
 * `source_id` IS NULL FOR INTERNALLY DERIVED SIGNALS. They come from data the
 * system already holds rather than from anything anyone configured, and
 * inventing a synthetic source row for them would put a permanently green
 * entry on the source-health panel that represents nothing.
 *
 * `ai_relevance` NULL MEANS "NOT ASSESSED", NEVER "NOT RELEVANT". Triage is off
 * by default, and a screen that read a null as a zero would silently hide every
 * adverse-media item on an installation with no AI.
 */
class MonitoringSignal extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_monitoring_signals';

    protected $fillable = [
        'organization_id', 'source_id', 'third_party_id', 'engagement_id',
        'signal_type', 'severity', 'title', 'payload', 'url', 'confidence',
        'observed_at', 'ingested_at', 'dedupe_key', 'ai_relevance', 'ai_materiality',
    ];

    /**
     * Set by the rules engine when it has finished with a signal, never by an
     * ingest path — a driver that could mark its own output processed could
     * stop every rule firing.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = ['is_processed'];

    protected $casts = [
        'signal_type' => SignalType::class,
        'severity' => SignalSeverity::class,
        'payload' => 'array',
        'observed_at' => 'datetime',
        'ingested_at' => 'datetime',
        'is_processed' => 'boolean',
        'confidence' => 'decimal:3',
        'ai_relevance' => 'decimal:3',
    ];

    protected $attributes = [
        'is_processed' => false,
    ];

    /** @return BelongsTo<MonitoringSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MonitoringSource::class, 'source_id');
    }

    /** @return BelongsTo<ThirdParty, $this> */
    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /**
     * Build the key that makes two observations the same observation.
     *
     * The subject and the type are always in it. `$discriminator` is whatever
     * identifies the specific thing — a document id, a finding reference, a
     * news article URL — and where it is absent the key falls back to the DAY,
     * so a source with no stable identifier reports at most once per subject
     * per type per day rather than once per poll.
     */
    public static function keyFor(
        string $signalType,
        ?int $thirdPartyId,
        ?int $engagementId,
        ?string $discriminator = null,
    ): string {
        return implode(':', [
            $signalType,
            'tp'.($thirdPartyId ?? 0),
            'eng'.($engagementId ?? 0),
            $discriminator ?? now()->toDateString(),
        ]);
    }

    /**
     * Whether triage has looked at this and found it immaterial.
     *
     * Explicitly NOT `ai_relevance < threshold`, because a null relevance is
     * an unassessed signal and treating it as irrelevant would hide every
     * adverse-media item on an installation with triage switched off.
     */
    public function suppressedByTriage(float $threshold = 0.4): bool
    {
        return $this->ai_relevance !== null && (float) $this->ai_relevance < $threshold;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->where('is_processed', false);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->whereNull('source_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSince(Builder $query, int $days): Builder
    {
        return $query->where('observed_at', '>=', now()->subDays($days));
    }
}
