<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\Regulator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A record that an escalation fired — AC-07's "escalate at 50% and 80%".
 *
 * A ROW RATHER THAN A FLAG, and unique on (incident, regulator, threshold).
 * The sweep runs on a schedule; without the row an escalation that fired at
 * 50% fires again at every subsequent run, and an inbox full of the same
 * warning is an inbox in which the 80% one is not noticed.
 *
 * It also answers the question an examiner asks after a missed deadline — "who
 * was told, and when" — which a boolean cannot.
 */
class IncidentEscalation extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_incident_escalations';

    protected $fillable = [
        'organization_id', 'incident_id', 'regulator', 'threshold_pct',
        'fired_at', 'deadline_at', 'notified_user_ids',
    ];

    protected $casts = [
        'regulator' => Regulator::class,
        'threshold_pct' => 'integer',
        'fired_at' => 'datetime',
        'deadline_at' => 'datetime',
        'notified_user_ids' => 'array',
    ];

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }
}
