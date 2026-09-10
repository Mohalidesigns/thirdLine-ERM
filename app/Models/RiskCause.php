<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Step 2 of the assessment chain: Root Cause.
 *
 * A cause belongs to the RISK, not to the assessment that recorded it. A cause
 * outlives the quarter that found it, and the question worth asking of a risk
 * register — "which causes recur across the portfolio?" — is only answerable if
 * causes accumulate on the risk. Each assessment freezes the causes it reasoned
 * about into `risk_assessments.cause_snapshot`.
 */
class RiskCause extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * Where a cause came from. Recorded because provenance changes how much
     * weight a cause carries: one derived from a realised loss is evidence,
     * one from a workshop is judgement.
     */
    public const SOURCES = [
        'workshop' => 'Risk Workshop',
        'loss_event' => 'Loss Event',
        'near_miss' => 'Near Miss',
        'audit_finding' => 'Audit Finding',
        'kri_breach' => 'KRI Breach',
        'incident' => 'Incident',
        'regulatory' => 'Regulatory Finding',
        'other' => 'Other',
    ];

    protected $fillable = [
        'organization_id',
        'risk_id',
        'cause_category_id',
        'description',
        'source',
        'evidence_ref',
        'is_primary',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
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
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function risk()
    {
        return $this->belongsTo(Risk::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<RiskCauseCategory, $this> */
    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RiskCauseCategory::class, 'cause_category_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation */
    /* ------------------------------------------------------------------ */

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCES[$this->source] ?? 'Not stated';
    }

    public function getCategoryNameAttribute(): string
    {
        return $this->category?->name ?? 'Unclassified';
    }

    /**
     * The shape frozen into an assessment's cause_snapshot. Denormalised on
     * purpose: the snapshot has to stay readable after the cause is edited,
     * reclassified or retired.
     */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'category' => $this->category?->name,
            'source' => $this->source,
            'is_primary' => (bool) $this->is_primary,
        ];
    }
}
