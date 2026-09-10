<?php

namespace App\Models;

use App\Models\Concerns\ProjectsGraphEdge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * risk_kri_mapping — "this KRI monitors that risk".
 *
 * The table had no model at all: it was reachable only as an anonymous pivot
 * behind Risk::kriMappings() and KeyRiskIndicator::risks(), and written only
 * by DemoDataSeeder's raw insert. That is why nothing could hook it, and why
 * every KRI link made at runtime was invisible to the object graph.
 *
 * It is NOT tenant-scoped: the table carries no organization_id (its two
 * endpoints do, and both are constrained to the same tenant by the code that
 * writes it). Adding BelongsToOrganization here would filter every read on a
 * column that does not exist.
 *
 * Note the deprecation on Risk::kriMappings(): the direct FK
 * key_risk_indicators.risk_id is the association this product actually uses.
 * This model exists so that the rows which DO live in the pivot reach the
 * graph, not to encourage new ones.
 */
class RiskKriMapping extends Pivot
{
    use HasFactory, ProjectsGraphEdge;

    protected $table = 'risk_kri_mapping';

    public $incrementing = true;

    protected $fillable = [
        'risk_id',
        'kri_id',
        'correlation_type',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function risk()
    {
        return $this->belongsTo(Risk::class);
    }

    public function kri()
    {
        return $this->belongsTo(KeyRiskIndicator::class, 'kri_id');
    }
}
