<?php

namespace App\Models\Tprm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One row of the PCI DSS 12.8.5 responsibility matrix — FR-CTR-08.
 *
 * The deliverable a QSA asks for and almost nobody has: for every PCI
 * requirement, who is responsible — the service provider, us, both, or nobody
 * because it does not apply.
 *
 * `source` MATTERS AS MUCH AS `responsibility`. A row pre-populated from a
 * vendor's CAIQ SSRM answer is the vendor's opinion of who is responsible;
 * a row a person confirmed is the agreed position. A matrix that could not
 * tell the two apart would hand a QSA a document in which the vendor had
 * quietly assigned duties to us, and `last_confirmed_at` being null is what
 * says so.
 */
class PciResponsibility extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_pci_responsibility_matrix';

    public const TPSP = 'tpsp';

    public const ENTITY = 'entity';

    public const SHARED = 'shared';

    public const NOT_APPLICABLE = 'na';

    /** @var list<string> */
    public const RESPONSIBILITIES = [self::TPSP, self::ENTITY, self::SHARED, self::NOT_APPLICABLE];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_CAIQ = 'caiq_ssrm';

    protected $fillable = [
        'organization_id', 'engagement_id', 'pci_requirement', 'responsibility',
        'notes', 'source', 'last_confirmed_at', 'confirmed_by',
    ];

    protected $casts = [
        'last_confirmed_at' => 'datetime',
    ];

    protected $attributes = [
        'source' => self::SOURCE_MANUAL,
    ];

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Whether a person has agreed this row, as against a machine having
     * proposed it.
     */
    public function isConfirmed(): bool
    {
        return $this->last_confirmed_at !== null;
    }

    public function responsibilityLabel(): string
    {
        return match ($this->responsibility) {
            self::TPSP => 'Service provider',
            self::ENTITY => 'Us',
            self::SHARED => 'Shared',
            self::NOT_APPLICABLE => 'Not applicable',
            default => (string) $this->responsibility,
        };
    }
}
