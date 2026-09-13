<?php

namespace App\Models\Bcms;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A dated, signed attestation of a plan — in practice, the board's annual
 * attestation of the BC policy (CBN Corporate Governance Guidelines 2023).
 *
 * THE STATEMENT IS STORED WITH THE SIGNATURE. An attestation whose wording can
 * be edited afterwards attests to nothing. The signer's name and role are
 * snapshotted for the same reason: a director who has since left the board
 * still attested on the day they attested.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property string $attestation_type
 * @property int $period_year
 * @property int $attested_by
 * @property string $attested_by_name
 * @property ?string $attested_by_role
 * @property \Illuminate\Support\Carbon $attested_at
 * @property string $statement
 * @property ?string $ip_address
 * @property ?string $iso_clause_ref
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class PlanAttestation extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_plan_attestations';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'plan_id', 'attestation_type', 'period_year', 'attested_by',
        'attested_by_name', 'attested_by_role', 'attested_at', 'statement', 'ip_address',
        'iso_clause_ref',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'plan_id' => 'integer',
            'period_year' => 'integer',
            'attested_by' => 'integer',
            'attested_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function attestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attested_by');
    }
}
