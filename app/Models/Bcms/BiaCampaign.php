<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A round of business impact analysis across the process catalogue.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $programme_id
 * @property string $name
 * @property string $cycle
 * @property ?\Illuminate\Support\Carbon $opens_at
 * @property ?\Illuminate\Support\Carbon $closes_at
 * @property string $status
 * @property ?string $response_rate
 * @property ?string $iso_clause_ref
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class BiaCampaign extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory, SoftDeletes;

    protected $table = 'bcms_bia_campaigns';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'programme_id', 'name', 'cycle', 'opens_at', 'closes_at', 'status',
        'response_rate', 'iso_clause_ref', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'programme_id' => 'integer',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'response_rate' => 'decimal:2',
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

    /** @return HasMany<BiaAssessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(BiaAssessment::class, 'campaign_id');
    }
}
