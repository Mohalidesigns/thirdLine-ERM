<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\Tprm\Concerns\TprmAuditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A third-party incident — FR-PRT-07 here, and the regulatory clocks in
 * Phase 9.
 *
 * THIS PHASE BUILDS THE VENDOR'S SUBMISSION AND ITS RECEIPT, NOT THE CLOCK
 * ENGINE. Phase 9 owns `ObligationClockService`, the 24-hour CBN countdown,
 * the 72-hour NDPA countdown, the escalations and the pre-filled notification
 * draft. Building any of that here would mean writing it twice and having the
 * second version disagree with the first.
 *
 * `reported_to_us_at` IS THE CLOCK-START EVIDENCE AND IT IS WRITE-ONCE. NDPA
 * §40(1) makes the processor's notification to the controller the recorded
 * start; if that timestamp can be edited afterwards, the bank's whole defence
 * for when its own 72 hours began is a number somebody could have changed. It
 * is set by the service at the moment of submission and is not fillable.
 *
 * The REPORTABILITY FLAGS are set from what the vendor declares. The
 * DEADLINES are deliberately left null for Phase 9 — a deadline computed from
 * a half-built rule is worse than no deadline, because a screen showing "18
 * hours remaining" gets believed.
 */
class Incident extends Model
{
    use BelongsToOrganization, HasTprmUuid, SoftDeletes, TprmAuditable;

    protected $table = 'tp_incidents';

    public const SOURCE_PORTAL = 'portal';

    public const SOURCE_INTERNAL = 'internal';

    public const SOURCE_MEDIA = 'media';

    public const SOURCE_REGULATOR = 'regulator';

    protected $fillable = [
        'organization_id', 'third_party_id', 'engagement_ids', 'reference', 'type', 'title', 'description',
        'detected_at', 'severity', 'customer_impact', 'customers_affected',
        'personal_data_involved', 'data_subjects_affected', 'estimated_loss_minor', 'currency',
        'root_cause', 'created_by',
    ];

    /**
     * `reported_to_us_at` is here for the reason in the class comment: it is
     * the evidence for when a statutory clock started, and evidence a form can
     * post is not evidence.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'reported_to_us_at', 'reported_by', 'status',
        'cbn_reportable', 'cbn_deadline_at', 'cbn_reported_at', 'cbn_reference',
        'ndpc_reportable', 'ndpc_deadline_at', 'ndpc_reported_at',
        'data_subject_notification_required', 'data_subject_notified_at',
    ];

    protected $casts = [
        'engagement_ids' => 'array',
        'detected_at' => 'datetime',
        'reported_to_us_at' => 'datetime',
        'customer_impact' => 'boolean',
        'personal_data_involved' => 'boolean',
        'cbn_reportable' => 'boolean',
        'cbn_deadline_at' => 'datetime',
        'cbn_reported_at' => 'datetime',
        'ndpc_reportable' => 'boolean',
        'ndpc_deadline_at' => 'datetime',
        'ndpc_reported_at' => 'datetime',
        'data_subject_notification_required' => 'boolean',
        'data_subject_notified_at' => 'datetime',
    ];

    /** @return BelongsTo<ThirdParty, $this> */
    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    /**
     * The acknowledgement the vendor is shown.
     *
     * A RECEIPT WITH A TIMESTAMP, because the vendor's own regulator may ask
     * them to evidence that they notified their controller in time. Giving
     * them nothing to keep makes our portal the reason they cannot.
     */
    public function receipt(): string
    {
        return sprintf(
            'Received %s. Your reference is %s.',
            $this->reported_to_us_at?->toDayDateTimeString() ?? 'now',
            $this->reference,
        );
    }
}
