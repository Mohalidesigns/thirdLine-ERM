<?php

namespace App\Models\Rcsa;

use App\Models\Control;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A control mitigating a universe risk — workbook column N.
 *
 * MANY PER RISK, where the workbook has one cell. A risk mitigated by three
 * controls is the normal case, and flattening them into one paragraph at data
 * entry loses which one is key, who owns it, and how often it runs. The export
 * concatenates them back into the single column for template parity, so the
 * workbook still round-trips.
 *
 * `control_library_id` IS NULLABLE AND USUALLY NULL AT FIRST. Most controls are
 * captured during an RCSA before anyone has taken them into the control
 * library, and requiring a library row first would mean a risk champion filing
 * sixty risks has to stop and raise sixty library entries. The link is made
 * later, by whoever curates the library; until then the description stands on
 * its own.
 */
class RcsaRegisterControl extends Model
{
    /** Matches Control::TYPES so a control promoted into the library needs no translation. */
    public const TYPES = ['preventive', 'detective', 'corrective', 'directive'];

    /** Matches Control::FREQUENCIES, for the same reason. */
    public const FREQUENCIES = ['continuous', 'daily', 'weekly', 'monthly', 'quarterly', 'annually', 'ad_hoc'];

    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'rcsa_register_controls';

    protected $fillable = [
        'organization_id',
        'register_risk_id',
        'control_library_id',
        'description',
        'control_type',
        'frequency',
        'control_owner_id',
        'is_key',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'is_key' => 'boolean',
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

    /** @return BelongsTo<RcsaRegisterRisk, $this> */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(RcsaRegisterRisk::class, 'register_risk_id');
    }

    /** @return BelongsTo<Control, $this> */
    public function libraryControl(): BelongsTo
    {
        return $this->belongsTo(Control::class, 'control_library_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'control_owner_id');
    }
}
