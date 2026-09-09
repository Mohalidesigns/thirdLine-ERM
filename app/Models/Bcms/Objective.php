<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\KeyRiskIndicator;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A measurable business continuity objective (ISO 22301 clause 6.2).
 *
 * `key_risk_indicator_id` is how measurement actually happens: Blueprint §4.2
 * makes resilience metrics KRIs in the existing module, not a second metrics
 * engine here.
 *
 * @property int $id
 * @property int $organization_id
 * @property ?int $programme_id
 * @property string $title
 * @property ?string $description
 * @property ?string $measure_description
 * @property ?string $target_value
 * @property ?string $target_unit
 * @property ?string $baseline_value
 * @property ?\Illuminate\Support\Carbon $baseline_captured_at
 * @property ?int $key_risk_indicator_id
 * @property ?\Illuminate\Support\Carbon $target_date
 * @property ?int $owner_id
 * @property string $status
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Objective extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'bcms_objectives';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'programme_id', 'title', 'description', 'measure_description',
        'target_value', 'target_unit', 'baseline_value', 'baseline_captured_at', 'key_risk_indicator_id', 'target_date', 'owner_id',
        'status', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'programme_id' => 'integer',
            'target_value' => 'decimal:2',
            'key_risk_indicator_id' => 'integer',
            'target_date' => 'date',
            'owner_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Programme, $this> */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<KeyRiskIndicator, $this> */
    public function keyRiskIndicator(): BelongsTo
    {
        return $this->belongsTo(KeyRiskIndicator::class, 'key_risk_indicator_id');
    }
}
