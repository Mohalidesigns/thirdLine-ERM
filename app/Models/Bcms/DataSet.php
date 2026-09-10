<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A body of data a process depends on.
 *
 * `contains_personal_data` and `residency_country` are here rather than in a
 * note because NDPA residency is a question every plan depending on the data
 * set inherits. A seam register (ADR 0001).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $code
 * @property string $name
 * @property ?string $classification
 * @property bool $contains_personal_data
 * @property ?string $residency_country
 * @property ?int $primary_application_id
 * @property ?string $external_ref
 * @property bool $is_active
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class DataSet extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'bcms_data_sets';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'code', 'name', 'classification', 'contains_personal_data',
        'residency_country', 'primary_application_id', 'external_ref', 'is_active', 'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'contains_personal_data' => 'boolean',
            'primary_application_id' => 'integer',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Application, $this> */
    public function primaryApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'primary_application_id');
    }
}
