<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LossEventRca extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'loss_event_rca';

    /**
     * Canonical columns only. The 200038 duplicates — root_cause_description,
     * contributing_factors_text, status, performed_by and analysis_date — are
     * no longer written; see docs/schema/canonical-columns.md.
     */
    protected $fillable = [
        'loss_event_id',
        'organization_id',
        'methodology',
        'root_cause_category',
        'analysis_details',
        'recommendations',
        'lessons_learned',
        'rca_status',
        'completed_by',
        'completed_at',
        'approved_by',
        'approved_at',
        // 5-Whys fields
        'why_1_question', 'why_1_answer',
        'why_2_question', 'why_2_answer',
        'why_3_question', 'why_3_answer',
        'why_4_question', 'why_4_answer',
        'why_5_question', 'why_5_answer',
        'root_cause_statement',
        'contributory_factors',
    ];

    protected $casts = [
        'contributory_factors' => 'array',
        'completed_at' => 'datetime',
        'approved_at' => 'datetime',
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

    public function lossEvent()
    {
        return $this->belongsTo(LossEvent::class);
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function remediationActions()
    {
        return $this->hasMany(RcaRemediationAction::class, 'rca_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Deprecated-column bridges (WP-01 TASK 1) — read-only, removed with */
    /*  the columns in Migration B. */
    /* ------------------------------------------------------------------ */

    protected function rootCauseDescription(): Attribute
    {
        return Attribute::make(get: fn () => $this->root_cause_statement);
    }

    protected function contributingFactorsText(): Attribute
    {
        return Attribute::make(
            get: fn () => is_array($this->contributory_factors)
                ? implode("\n", $this->contributory_factors)
                : null,
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->rca_status === null ? null : strtolower($this->rca_status),
        );
    }

    protected function performedBy(): Attribute
    {
        return Attribute::make(get: fn () => $this->completed_by);
    }

    protected function analysisDate(): Attribute
    {
        return Attribute::make(get: fn () => $this->completed_at);
    }
}
