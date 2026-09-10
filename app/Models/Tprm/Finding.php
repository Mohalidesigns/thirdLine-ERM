<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\FindingStatus;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A gap in a third party's control environment — FR-FND-01.
 *
 * `target_date` IS SET ONCE AND LEFT ALONE. It comes from the tier policy's
 * remediation SLA at the moment the finding is raised, and a later policy
 * change does not move it. Recomputing it would rewrite the overdue status of
 * every historic finding, and "we were compliant under the policy in force at
 * the time" is a claim a supervisor is entitled to test — which they cannot do
 * against a register that silently re-dates itself.
 *
 * OVERDUE AND OVERDUE-BEYOND-THRESHOLD ARE DIFFERENT QUESTIONS, and the
 * residual score cares about the second. A finding one day past its date is
 * late; a finding past twice its SLA is a finding nobody is working, and TRD
 * §7.5 multiplies its penalty by 1.5 for exactly that reason. Both are
 * computed here so the score panel and the findings board cannot disagree
 * about the same finding.
 */
class Finding extends Model
{
    use BelongsToOrganization, HasTprmUuid, SoftDeletes, TprmAuditable;

    protected $table = 'tp_findings';

    /** @var list<string> */
    public const SOURCES = [
        'assessment', 'evidence', 'contract', 'monitoring', 'incident',
        'site_visit', 'audit', 'due_diligence', 'manual',
    ];

    /** @var list<string> */
    public const CLOSURE_TYPES = ['remediated', 'risk_accepted', 'false_positive', 'superseded'];

    protected $fillable = [
        'organization_id', 'engagement_id', 'third_party_id', 'source', 'source_id',
        'reference', 'title', 'description', 'severity', 'control_refs',
        'regulatory_citation', 'identified_at', 'owner_id', 'vendor_owner_contact_id',
        'target_date', 'sla_days', 'remediation_plan', 'vendor_response',
        'evidence_document_id', 'created_by', 'updated_by',
    ];

    /**
     * Written by the service that owns each transition. A form that could set
     * `status` could close a finding without the verification the closure
     * requires, and one that could set `escalation_level` could quietly
     * un-escalate a breach.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'status', 'escalation_level', 'verified_by', 'verified_at',
        'closure_type', 'risk_acceptance_id', 'erm_issue_id', 'erm_synced_at',
    ];

    protected $casts = [
        'severity' => FindingSeverity::class,
        'status' => FindingStatus::class,
        'control_refs' => 'array',
        'identified_at' => 'datetime',
        'target_date' => 'date',
        'verified_at' => 'datetime',
        'erm_synced_at' => 'datetime',
        'sla_days' => 'integer',
        'escalation_level' => 'integer',
        'flagged_for_audit_verification' => 'boolean',
    ];

    protected $attributes = [
        'status' => 'open',
        'escalation_level' => 0,
        'flagged_for_audit_verification' => false,
    ];

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

    /** @return BelongsTo<ThirdParty, $this> */
    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<Contact, $this> */
    public function vendorOwner(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'vendor_owner_contact_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }

    /** @return HasMany<RiskAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(RiskAcceptance::class, 'finding_id');
    }

    /** @return BelongsTo<RiskAcceptance, $this> */
    public function acceptance(): BelongsTo
    {
        return $this->belongsTo(RiskAcceptance::class, 'risk_acceptance_id');
    }

    /**
     * The issue this finding is mirrored as in the ERM register — TRD §15.
     *
     * @return BelongsTo<Issue, $this>
     */
    public function ermIssue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'erm_issue_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Timing */
    /* ------------------------------------------------------------------ */

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function daysUntilTarget(): ?int
    {
        return $this->target_date === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->target_date, false);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->target_date !== null
            && $this->target_date->isBefore(now()->startOfDay());
    }

    /**
     * Past `multiple` × its SLA — the state TRD §7.5 multiplies by 1.5.
     *
     * Measured from `identified_at` rather than from `target_date`, because
     * the SLA is the window the finding was given and doubling that window is
     * what "nobody is working this" means. Measuring from the target date
     * would make a 7-day Critical and a 90-day Low reach the multiplier at the
     * same absolute lateness.
     */
    public function isOverdueBeyond(int $multiple = 2): bool
    {
        if (! $this->isOpen() || $this->sla_days === null || $this->identified_at === null) {
            return false;
        }

        return now()->startOfDay()->greaterThan(
            $this->identified_at->copy()->startOfDay()->addDays($this->sla_days * $multiple)
        );
    }

    /**
     * Inside its SLA WITH an accepted remediation plan — the ×0.5 discount.
     *
     * Both halves are required. Being early is not mitigation; a finding with
     * no plan is one nobody has decided how to fix, and discounting it because
     * the clock has not run out yet would reward silence.
     */
    public function isWithinSlaWithAcceptedPlan(): bool
    {
        if (! $this->isOpen() || $this->isOverdue()) {
            return false;
        }

        return filled($this->remediation_plan)
            && in_array($this->status, [
                FindingStatus::InRemediation,
                FindingStatus::EvidenceSubmitted,
                FindingStatus::UnderVerification,
            ], true);
    }

    public function isRiskAccepted(): bool
    {
        return $this->status === FindingStatus::ClosedRiskAccepted
            && $this->acceptance?->isInForce() === true;
    }

    /**
     * Whether this finding enters the residual score at all.
     *
     * A remediated or false-positive closure contributes nothing. A
     * risk-accepted one contributes at half while its acceptance HOLDS, and at
     * full weight once it expires — which is why `isRiskAccepted()` checks the
     * acceptance's own expiry rather than the finding's status alone. An
     * expired acceptance is an open finding again, and the score should say so
     * before anybody gets round to re-approving it.
     */
    public function contributesToUplift(): bool
    {
        return $this->status->contributesToUplift();
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (FindingStatus $status) => $status->value,
            array_filter(FindingStatus::cases(), fn (FindingStatus $status) => $status->isOpen()),
        ));
    }

    /**
     * Everything the residual score has to look at: open findings plus
     * risk-accepted ones, which still count at half.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeScoring(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->open()
            ->orWhere('status', FindingStatus::ClosedRiskAccepted->value));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('target_date')
            ->whereDate('target_date', '<', now()->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->open()
            ->whereNotNull('target_date')
            ->whereDate('target_date', '>=', now()->toDateString())
            ->whereDate('target_date', '<=', now()->addDays($days)->toDateString());
    }
}
