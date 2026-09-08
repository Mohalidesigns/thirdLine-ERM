<?php

namespace App\Models\Bcms;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One section of a plan, optionally bound to live data.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property string $section_key
 * @property string $title
 * @property ?string $body
 * @property int $sort_order
 * @property array<array-key, mixed> $source_binding
 * @property bool $is_overridden
 * @property bool $ai_generated
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class PlanSection extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_plan_sections';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'plan_id', 'section_key', 'title', 'body', 'sort_order',
        'source_binding', 'is_overridden', 'ai_generated',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_binding' => 'array',
            'organization_id' => 'integer',
            'plan_id' => 'integer',
            'sort_order' => 'integer',
            'is_overridden' => 'boolean',
            'ai_generated' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
