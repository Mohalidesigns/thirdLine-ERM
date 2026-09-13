<?php

namespace App\Models\Tprm;

use App\Models\User;
use App\Support\Tprm\OffboardingTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One thing that has to happen before a relationship is over.
 *
 * `reconcilesWith()` IS WHAT STOPS THIS BEING A TICK-BOX SHEET. Two of the
 * nine items — access revocation and connection closure — are already answered
 * by the Phase 7 register, and a checklist that let somebody tick them by hand
 * would let a bank complete its offboarding while the termination guard still
 * refused the transition. Those items read their answer rather than accepting
 * one.
 */
class OffboardingItem extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_offboarding_items';

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_EXCEPTED = 'excepted';

    protected $fillable = [
        'organization_id', 'checklist_id', 'code', 'title', 'item_type',
        'is_mandatory', 'owner_id', 'due_date',
    ];

    /** @var list<string> */
    public const GUARDED_STATE = [
        'status', 'evidence_document_id', 'exception_reason', 'exception_approver_id', 'completed_at',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'due_date' => 'date',
        'completed_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_OPEN, 'is_mandatory' => true];

    /** @return BelongsTo<OffboardingChecklist, $this> */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(OffboardingChecklist::class, 'checklist_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Which register answers this item, if any.
     *
     * `access_grants`, `connections`, or null for an item somebody completes
     * by hand with evidence.
     */
    public function reconcilesWith(): ?string
    {
        return OffboardingTemplate::byCode((string) $this->code)['reconciles_with'] ?? null;
    }

    public function isReconciled(): bool
    {
        return $this->reconcilesWith() !== null;
    }

    public function guidance(): ?string
    {
        return OffboardingTemplate::byCode((string) $this->code)['guidance'] ?? null;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETE, self::STATUS_EXCEPTED], true);
    }
}
