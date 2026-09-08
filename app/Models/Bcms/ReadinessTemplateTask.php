<?php

namespace App\Models\Bcms;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One task on a readiness template. `due_offset_days` is signed: negative is
 * before the exercise, positive is a follow-up after it.
 *
 * @property int $id
 * @property ?int $organization_id
 * @property int $template_id
 * @property string $title
 * @property ?string $description
 * @property int $due_offset_days
 * @property bool $is_blocking
 * @property bool $requires_evidence
 * @property ?string $default_owner_role
 * @property int $sort_order
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class ReadinessTemplateTask extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_readiness_template_tasks';

    /** System-owned rows (`organization_id = null`) are visible to every tenant. */
    protected bool $tenantIncludesGlobal = true;

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'template_id', 'title', 'description', 'due_offset_days', 'is_blocking',
        'requires_evidence', 'default_owner_role', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'template_id' => 'integer',
            'due_offset_days' => 'integer',
            'is_blocking' => 'boolean',
            'requires_evidence' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<ReadinessTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReadinessTemplate::class, 'template_id');
    }
}
