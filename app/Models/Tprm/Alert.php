<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\AlertStatus;
use App\Enums\Tprm\SignalSeverity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A rule fired against a signal, and what was done about it.
 *
 * MUTING NEEDS A REASON AND AN EXPIRY, BOTH. A mute with neither is how a
 * monitoring programme quietly stops covering what it claims to: somebody
 * silences a noisy rule during an incident, the incident ends, and two years
 * later nobody knows why that vendor produces no alerts. The expiry makes the
 * silence temporary by construction and the reason makes it reviewable.
 *
 * `actions_taken` RECORDS WHAT ACTUALLY HAPPENED, not what the rule asked for.
 * A rule may ask for a targeted assessment on an engagement with no published
 * template, or a suspension on one already terminated. The alert records the
 * outcome per action, so a console can show "notified, finding raised,
 * assessment not built — no template covers the implicated controls" rather
 * than a green tick over a half-completed response.
 */
class Alert extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_alerts';

    protected $fillable = [
        'organization_id', 'rule_id', 'signal_id', 'third_party_id', 'engagement_id',
        'severity', 'assigned_to', 'actions_taken',
        'created_finding_id', 'created_assessment_id',
    ];

    /** @var list<string> */
    public const GUARDED_STATE = ['status', 'acknowledged_at', 'muted_until', 'mute_reason'];

    protected $casts = [
        'severity' => SignalSeverity::class,
        'status' => AlertStatus::class,
        'actions_taken' => 'array',
        'acknowledged_at' => 'datetime',
        'muted_until' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'new',
    ];

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'rule_id');
    }

    /** @return BelongsTo<MonitoringSignal, $this> */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(MonitoringSignal::class, 'signal_id');
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

    /** @return BelongsTo<Finding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class, 'created_finding_id');
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'created_assessment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [AlertStatus::New, AlertStatus::Acknowledged], true)
            && ! $this->isMuted();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [AlertStatus::New->value, AlertStatus::Acknowledged->value])
            ->where(fn (Builder $inner) => $inner
                ->whereNull('muted_until')
                ->orWhere('muted_until', '<=', now()));
    }

    /**
     * The mute register — every silenced rule, with who silenced it and until
     * when.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMuted(Builder $query): Builder
    {
        return $query->whereNotNull('muted_until')->where('muted_until', '>', now());
    }
}
