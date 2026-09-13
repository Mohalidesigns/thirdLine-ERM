<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A disruption scenario an exercise can be built on — a shipped content pack
 * plus the tenant's own. `ai_generated` marks a scenario a model drafted;
 * standing rule 4 keeps it a draft until a human edits or approves it.
 *
 * @property int $id
 * @property ?int $organization_id
 * @property string $code
 * @property string $name
 * @property ?string $category
 * @property ?string $summary
 * @property ?string $narrative
 * @property array<array-key, mixed> $suggested_injects
 * @property array<array-key, mixed> $suggested_objectives
 * @property ?string $ladder_level_min
 * @property array<array-key, mixed> $regulatory_drivers
 * @property bool $is_system_default
 * @property bool $ai_generated
 * @property bool $is_active
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Scenario extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'bcms_scenarios';

    /** System-owned rows (`organization_id = null`) are visible to every tenant. */
    protected bool $tenantIncludesGlobal = true;

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'code', 'name', 'category', 'summary', 'narrative', 'suggested_injects',
        'suggested_objectives', 'ladder_level_min', 'regulatory_drivers', 'is_system_default',
        'ai_generated', 'is_active', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'suggested_injects' => 'array',
            'suggested_objectives' => 'array',
            'regulatory_drivers' => 'array',
            'organization_id' => 'integer',
            'is_system_default' => 'boolean',
            'ai_generated' => 'boolean',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }
}
