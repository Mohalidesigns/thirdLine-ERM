<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\ProjectsGraphEdge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * risk_control_mapping — "this control mitigates that risk".
 *
 * ProjectsGraphEdge is what makes the row also exist as a `mitigates` edge in
 * object_relationships. Every controller that writes this table already writes
 * it through this model, so they all got the projection without being touched.
 */
class RiskControlMapping extends Pivot
{
    use BelongsToOrganization, HasFactory, ProjectsGraphEdge;

    protected $table = 'risk_control_mapping';

    public $incrementing = true;

    protected $fillable = [
        'risk_id',
        'control_id',
        'organization_id',
        'mapping_rationale',
        'control_weight',
        'is_key_control',
        'created_by',
    ];

    protected $casts = [
        'is_key_control' => 'boolean',
        'control_weight' => 'decimal:2',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Risk, $this> */
    public function risk(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Control, $this> */
    public function control(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Control::class);
    }
}
