<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A reusable readiness checklist an exercise type instantiates.
 *
 * SYSTEM-OWNED ROWS: `organization_id` is null on the shipped templates and
 * `$tenantIncludesGlobal` is what makes them visible to a tenant. Without it a
 * seeder that writes one and a screen that reads it disagree forever, silently
 * (ADR 0006).
 *
 * @property int $id
 * @property ?int $organization_id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property bool $is_system_default
 * @property bool $is_active
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class ReadinessTemplate extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'bcms_readiness_templates';

    /** System-owned rows (`organization_id = null`) are visible to every tenant. */
    protected bool $tenantIncludesGlobal = true;

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'code', 'name', 'description', 'is_system_default', 'is_active',
        'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'is_system_default' => 'boolean',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return HasMany<ReadinessTemplateTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(ReadinessTemplateTask::class, 'template_id');
    }
}
