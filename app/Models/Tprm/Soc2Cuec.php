<?php

namespace App\Models\Tprm;

use App\Models\Control;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A complementary user entity control — a control the SOC 2 report assumes WE
 * operate, and which the service auditor did not test.
 *
 * "A bank that files the SOC 2 without reading its CUECs has accepted duties
 * it does not know it has." That is the whole case for this table. The report
 * says the vendor's controls achieve the criteria ONLY IF the customer does
 * these things; nobody at the customer necessarily read the list, and nobody
 * owns the items.
 *
 * `internal_owner_id` unset is therefore a finding in its own right, not an
 * empty field: it is an assumption the auditor made on our behalf that no
 * person here has agreed to. `internal_control_id` links to the ERM control
 * register when we already operate something that satisfies it — the join
 * that makes TPRM part of the wider programme rather than beside it.
 */
class Soc2Cuec extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_soc2_cuecs';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ATTESTED = 'attested';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const STATUS_GAP = 'gap';

    protected $fillable = [
        'organization_id', 'soc2_id', 'cuec_reference', 'description',
        'internal_owner_id', 'internal_control_id',
        'attestation_status', 'last_attested_at', 'next_due_at',
    ];

    protected $casts = [
        'last_attested_at' => 'datetime',
        'next_due_at' => 'date',
    ];

    protected $attributes = [
        'attestation_status' => self::STATUS_PENDING,
    ];

    /** @return BelongsTo<Soc2Detail, $this> */
    public function soc2(): BelongsTo
    {
        return $this->belongsTo(Soc2Detail::class, 'soc2_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'internal_owner_id');
    }

    /** @return BelongsTo<Control, $this> */
    public function internalControl(): BelongsTo
    {
        return $this->belongsTo(Control::class, 'internal_control_id');
    }

    /**
     * An unowned CUEC — the state this table exists to surface.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnowned(Builder $query): Builder
    {
        return $query->whereNull('internal_owner_id')
            ->where('attestation_status', '!=', self::STATUS_NOT_APPLICABLE);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('next_due_at')
            ->whereDate('next_due_at', '<', now()->toDateString())
            ->where('attestation_status', '!=', self::STATUS_NOT_APPLICABLE);
    }
}
