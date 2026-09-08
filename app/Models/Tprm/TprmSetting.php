<?php

namespace App\Models\Tprm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * The per-tenant figures the module cannot ship a default for.
 *
 * IT EXISTS FOR ONE NUMBER. The CBN cyber-incident definition (Framework
 * Appendix I) turns on a financial loss exceeding 0.01% of SHAREHOLDERS'
 * FUNDS. The percentage is statutory and lives in `config/tprm.php`; the funds
 * figure is a property of the bank and cannot.
 *
 * `hasMaterialityBasis()` IS THE METHOD THAT MATTERS. Where the figure is
 * absent the test is UNCOMPUTABLE, and the module must say so rather than
 * guess — an incident silently assessed as non-reportable is a missed
 * twenty-four-hour deadline that nobody knows they have missed until the
 * supervisor asks.
 */
class TprmSetting extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_settings';

    protected $fillable = [
        'organization_id', 'shareholders_funds_minor', 'shareholders_funds_currency',
        'shareholders_funds_as_at', 'regulatory_contact_name', 'regulatory_contact_title', 'updated_by',
    ];

    protected $casts = [
        'shareholders_funds_minor' => 'integer',
        'shareholders_funds_as_at' => 'date',
    ];

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function hasMaterialityBasis(): bool
    {
        return $this->shareholders_funds_minor !== null && $this->shareholders_funds_minor > 0;
    }

    /**
     * The loss, in minor units, at which an incident becomes CBN-reportable on
     * materiality alone.
     */
    public function cbnMaterialityThresholdMinor(): ?int
    {
        if (! $this->hasMaterialityBasis()) {
            return null;
        }

        $pct = (float) config('tprm.clocks.cbn_materiality_pct_of_shareholders_funds', 0.01);

        return (int) round(((int) $this->shareholders_funds_minor) * ($pct / 100));
    }

    /**
     * How stale the figure is, in months.
     *
     * Shown beside it because shareholders' funds move with every audited
     * account, and a threshold computed from a figure four years old is a
     * threshold nobody should rely on.
     */
    public function ageInMonths(): ?int
    {
        return $this->shareholders_funds_as_at === null
            ? null
            : (int) $this->shareholders_funds_as_at->diffInMonths(now());
    }

    public static function forOrganization(int $organizationId): self
    {
        return self::firstOrCreate(['organization_id' => $organizationId]);
    }
}
