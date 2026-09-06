<?php

namespace App\Models;

use App\Services\Workflow\WorkflowGraph;
use App\Support\MorphTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A workflow process, at one version.
 *
 * VERSIONING IS BY ROW. Publishing does not overwrite: it writes a new row
 * with the same `code` and version + 1, and unpublishes the previous one. An
 * instance holds definition_id, so it is pinned to the exact graph it started
 * on — a process edited mid-flight cannot change a decision already in
 * progress, which on an audited platform is the difference between a workflow
 * engine and a liability.
 *
 * `stages` (the WP-06 predecessor's linear array) is still readable and still
 * rendered by the legacy instance screen. New definitions write `definition`.
 */
class WorkflowDefinition extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id', 'code', 'version', 'name', 'description', 'entity_type',
        'object_type_id', 'stages', 'definition', 'escalation_rules', 'trigger',
        'trigger_config', 'scope_filter', 'bpmn_xml', 'is_active', 'is_published',
        'published_at', 'published_by', 'is_system', 'created_by',
    ];

    protected $casts = [
        'stages' => 'array',
        'definition' => 'array',
        'escalation_rules' => 'array',
        'trigger_config' => 'array',
        'scope_filter' => 'array',
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'is_system' => 'boolean',
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function objectType()
    {
        return $this->belongsTo(ObjectType::class, 'object_type_id');
    }

    public function instances()
    {
        return $this->hasMany(WorkflowInstance::class, 'definition_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->where('is_active', true);
    }

    public function scopeForSubject(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', MorphTypes::normalise($entityType) ?? $entityType);
    }

    /* ------------------------------------------------------------------ */
    /*  Graph */
    /* ------------------------------------------------------------------ */

    public function graph(): WorkflowGraph
    {
        return WorkflowGraph::make($this->definition);
    }

    /** True when this definition drives the v2 engine rather than the legacy stage cursor. */
    public function isGraphBased(): bool
    {
        return ! $this->graph()->isEmpty();
    }

    /**
     * The published definition for a code within an organization.
     *
     * There is at most one: publishing a new version unpublishes its
     * predecessor inside the same transaction.
     */
    public static function published(string $code, ?int $organizationId = null): ?self
    {
        return static::query()
            ->when($organizationId !== null, fn (Builder $q) => $q->where('organization_id', $organizationId))
            ->where('code', $code)
            ->published()
            ->orderByDesc('version')
            ->first();
    }

    /** The highest version number written for this code, published or not. */
    public function latestVersionNumber(): int
    {
        return (int) static::withTrashed()
            ->where('organization_id', $this->organization_id)
            ->where('code', $this->code)
            ->max('version');
    }
}
