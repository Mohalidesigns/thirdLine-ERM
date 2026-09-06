<?php

namespace App\Models;

use App\Models\Concerns\HasObjectIdentity;
use App\Models\Concerns\ScopedToGraph;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * The columns 2026_02_22_200038_align_schema_with_controllers adds through its
 * own addColumns() helper. Larastan reads schema from Schema::create/table
 * calls it can see statically, so a column added in a loop is invisible to it.
 *
 * @property bool $is_regulatory_reportable
 * @property bool $is_near_miss
 * @property string|null $regulatory_body
 * @property string|null $reporting_deadline
 * @property string|null $corrective_action_summary
 * @property string|null $root_cause_summary
 * @property string|null $currency
 * @property int|null $updated_by
 * @property int|null $status_changed_by
 * @property \Illuminate\Support\Carbon|null $status_changed_at
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 */
class LossEvent extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, ScopedToGraph, SoftDeletes;

    /**
     * The Basel level-1 event types, from LossEventController's inline `in:`
     * rule (migration Phase 4.3). Stored UPPER CASE — see the note on
     * LossEventService::canonicalAttributes() for why that matters.
     *
     * @var list<string>
     */
    public const BASEL_EVENT_TYPES = [
        'internal_fraud', 'external_fraud', 'employment_practices', 'clients_products',
        'damage_physical_assets', 'business_disruption', 'execution_delivery',
    ];

    /** @var list<string> */
    public const EVENT_TYPES = ['actual_loss', 'potential_loss', 'near_miss', 'gain_event'];

    /** @var list<string> */
    public const SEVERITIES = ['insignificant', 'minor', 'moderate', 'major', 'catastrophic'];

    /**
     * The lifecycle states the status action may target. The legal TRANSITIONS
     * between them are LossEventController::STATUS_TRANSITIONS — an illegal one
     * is a flash message, not a validation error.
     *
     * @var list<string>
     */
    public const STATUSES = [
        'reported', 'under_investigation', 'pending_approval', 'approved', 'closed', 'reopened',
    ];

    protected $fillable = [
        // --- Original migration columns (actual DB columns) ---
        'organization_id',
        'event_reference',
        'title',
        'description',
        'initial_root_cause',
        'date_of_loss',
        'date_discovered',
        'date_reported',
        'business_unit_id',
        'department',
        'branch_name',
        'responsible_officer_id',
        'basel_l1_category',
        'basel_l2_category',
        'basel_l3_detail',
        'cbn_risk_category',
        'cbn_orms_event_type',
        'cbn_product_line',
        'gross_loss_amount_kobo',
        'insurance_recovery_kobo',
        'other_recovery_kobo',
        'pending_recovery_kobo',
        'actual_recovery_kobo',
        'provision_amount_kobo',
        'cost_centre',
        'gl_account_code',
        'loss_category',
        'insurance_covered',
        'insurance_policy_ref',
        'insured_amount_kobo',
        'insurance_provider',
        'insurance_claim_status',
        'event_severity',
        'is_near_miss',
        'current_status',
        'cbn_reportable',
        'cbn_reporting_deadline',
        'cbn_notification_sent',
        'cbn_notification_date',
        'cbn_notification_ref',
        'nfiu_reportable',
        'nfiu_report_type',
        'nfiu_str_reference',
        'nfiu_report_filed',
        'bofia_reportable',
        'ndic_reportable',
        'law_enforcement_notified',
        'police_report_ref',
        'efcc_reported',
        'customer_impact_rating',
        'customers_affected_count',
        'reputational_impact_rating',
        'operational_disruption_hrs',
        'indirect_cost_kobo',
        'risk_register_id',
        'assigned_to_id',
        'current_approval_stage',
        'created_by',
        // --- Alignment migration columns with no canonical counterpart ---
        // The rest of the 200038 set (event_title, status, gross_loss_amount,
        // severity, basel_event_type, …) is deliberately absent: those columns
        // are deprecated duplicates and are no longer written. See
        // docs/schema/canonical-columns.md.
        'currency',
        'is_regulatory_reportable',
        'regulatory_body',
        'reporting_deadline',
        'corrective_action_summary',
        'reported_by',
        'approved_by',
        'approved_at',
        'status_changed_at',
        'status_changed_by',
        'updated_by',
    ];

    protected $casts = [
        // Original columns
        'date_of_loss' => 'date',
        'date_discovered' => 'date',
        'date_reported' => 'date',
        'insurance_covered' => 'boolean',
        'cbn_reportable' => 'boolean',
        'cbn_reporting_deadline' => 'date',
        'cbn_notification_sent' => 'boolean',
        'cbn_notification_date' => 'date',
        'nfiu_reportable' => 'boolean',
        'nfiu_report_filed' => 'boolean',
        'bofia_reportable' => 'boolean',
        'ndic_reportable' => 'boolean',
        'law_enforcement_notified' => 'boolean',
        'efcc_reported' => 'boolean',
        'is_near_miss' => 'boolean',
        // Alignment columns
        'reporting_deadline' => 'date',
        'is_regulatory_reportable' => 'boolean',
        'approved_at' => 'datetime',
        'status_changed_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Aggregate expressions */
    /* ------------------------------------------------------------------ */

    /**
     * Net loss in kobo, as SQL.
     *
     * Aggregates run in the database, so they cannot go through the
     * netLossAmountKobo accessor. Before WP-01 they summed the naira
     * `net_loss_amount` column, which only the loss-event CRUD screen ever
     * wrote — every other creation path left it NULL and the totals silently
     * under-reported. Deriving it from the kobo columns is the same
     * definition the accessor uses, and it holds for every row.
     */
    public static function netLossKoboSql(): Expression
    {
        return DB::raw('(COALESCE(gross_loss_amount_kobo, 0) - COALESCE(insurance_recovery_kobo, 0) - COALESCE(other_recovery_kobo, 0))');
    }

    /**
     * Net loss in naira, as SQL, for screens that report in major units.
     */
    public static function netLossNairaSql(): Expression
    {
        return DB::raw('(COALESCE(gross_loss_amount_kobo, 0) - COALESCE(insurance_recovery_kobo, 0) - COALESCE(other_recovery_kobo, 0)) / 100');
    }

    /**
     * Gross loss in naira, as SQL.
     */
    public static function grossLossNairaSql(): Expression
    {
        return DB::raw('COALESCE(gross_loss_amount_kobo, 0) / 100');
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function responsibleOfficer()
    {
        return $this->belongsTo(User::class, 'responsible_officer_id');
    }

    public function riskRegister()
    {
        return $this->belongsTo(Risk::class, 'risk_register_id');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<LossEventControl, $this> */
    public function failedControls(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LossEventControl::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<LossEventAttachment, $this> */
    public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LossEventAttachment::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<LossEventApproval, $this> */
    public function approvals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LossEventApproval::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasOne<LossEventRca, $this> */
    public function rca(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LossEventRca::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function reporter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->responsibleOfficer();
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Risk, $this> */
    public function risk(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->riskRegister();
    }

    public function controls()
    {
        return $this->failedControls();
    }

    public function rootCauseAnalysis()
    {
        return $this->rca();
    }

    public function convertedNearMisses()
    {
        return $this->hasMany(NearMiss::class, 'converted_loss_event_id');
    }

    public function issuesLinked()
    {
        return $this->hasMany(Issue::class, 'loss_event_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors */
    /* ------------------------------------------------------------------ */

    // Bridge legacy column names used by some views.
    protected function reference(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->event_reference,
        );
    }

    /**
     * Net loss = gross − insurance recovery − other recovery.
     *
     * Deliberately excludes pending_recovery_kobo (not yet received),
     * actual_recovery_kobo (the roll-up of the two subtracted here, so
     * subtracting it too would double-count) and provision_amount_kobo (an
     * accounting entry, not a recovery). Pinned by
     * tests/Feature/Characterisation/LossEventNetLossTest.php.
     */
    protected function netLossAmountKobo(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?? (
                (int) $this->gross_loss_amount_kobo
                - (int) $this->insurance_recovery_kobo
                - (int) $this->other_recovery_kobo
            ),
        );
    }

    /**
     * @deprecated Duplicates the near_misses table, which is a first-class
     * entity with its own reference codes, workflow and link back to
     * loss_events. Removal is tracked in docs/schema/deprecations.md; read
     * LossEvent::convertedNearMisses() or the NearMiss model instead.
     */
    protected function isNearMiss(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                self::warnDeprecatedNearMissRead();

                return (bool) $value;
            },
        );
    }

    /**
     * Warn once per request rather than once per read.
     *
     * The accessor sits on a real column, so it fires on toArray() and
     * toJson() too — a listing that serialises 500 loss events would
     * otherwise write 500 identical lines and bury the signal it exists to
     * give. One line naming the first caller is the useful form.
     */
    private static bool $nearMissDeprecationWarned = false;

    private static function warnDeprecatedNearMissRead(): void
    {
        if (self::$nearMissDeprecationWarned) {
            return;
        }

        self::$nearMissDeprecationWarned = true;

        $frame = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8))
            ->first(fn (array $f) => ($f['class'] ?? null) !== self::class);

        logger()->warning('Deprecated column read: loss_events.is_near_miss', [
            'replacement' => 'the near_misses table',
            'caller' => trim(($frame['file'] ?? 'unknown').':'.($frame['line'] ?? '?')),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Deprecated-column bridges (WP-01 TASK 1) */
    /* */
    /*  Each of these is named after a column that migration 200038 added */
    /*  as a duplicate. Nothing writes those columns any more; the */
    /*  accessors read the canonical column instead, so the Blade views */
    /*  that still use the old names keep working unchanged. */
    /* */
    /*  These are READ-ONLY and temporary. Migration B drops the columns */
    /*  and these accessors go with them — see */
    /*  docs/schema/canonical-columns.md. */
    /* ------------------------------------------------------------------ */

    protected function eventTitle(): Attribute
    {
        return Attribute::make(get: fn () => $this->title);
    }

    protected function eventDescription(): Attribute
    {
        return Attribute::make(get: fn () => $this->description);
    }

    /** Canonical current_status is upper case; the old contract was lower. */
    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->current_status === null ? null : strtolower($this->current_status),
        );
    }

    /** Canonical event_severity is upper case; the old contract was lower. */
    protected function severity(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->event_severity === null ? null : strtolower($this->event_severity),
        );
    }

    /**
     * Canonical basel_l1_category is upper case — that is what
     * RegulatoryThresholdService matches on. The forms and views use the lower
     * case enum, so lower-case it here.
     */
    protected function baselEventType(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->basel_l1_category === null ? null : strtolower($this->basel_l1_category),
        );
    }

    protected function cbnLossCategory(): Attribute
    {
        return Attribute::make(get: fn () => $this->cbn_risk_category);
    }

    protected function eventType(): Attribute
    {
        return Attribute::make(get: fn () => $this->loss_category);
    }

    protected function rootCauseSummary(): Attribute
    {
        return Attribute::make(get: fn () => $this->initial_root_cause);
    }

    // Money bridges: canonical is kobo, the old columns were naira.

    protected function grossLossAmount(): Attribute
    {
        return Attribute::make(get: fn () => (int) $this->gross_loss_amount_kobo / 100);
    }

    protected function insuranceRecovery(): Attribute
    {
        return Attribute::make(get: fn () => (int) $this->insurance_recovery_kobo / 100);
    }

    protected function recoveryAmount(): Attribute
    {
        return Attribute::make(get: fn () => (int) $this->other_recovery_kobo / 100);
    }

    protected function netLossAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->net_loss_amount_kobo / 100);
    }

    protected function daysOpen(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->event_date) {
                    return null;
                }

                $end = $this->closed_at ? Carbon::parse($this->closed_at) : Carbon::now();

                return Carbon::parse($this->event_date)->diffInDays($end);
            },
        );
    }
}
