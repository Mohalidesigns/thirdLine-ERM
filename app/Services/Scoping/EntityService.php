<?php

namespace App\Services\Scoping;

use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\Risk;
use App\Models\User;
use App\Policies\EntityPolicy;
use App\Services\AuditTrailService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection as BaseCollection;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * What ScopingController used to do inline (migration Phase 3.1): the
 * dashboard figures, the entity detail, the hierarchy tree, and the
 * create / update / delete rules.
 *
 * THE TWO RISK-SCORE FORMULAS ARE CARRIED UNCHANGED — see
 * tests/Feature/Characterisation/EntityRiskScoreTest.php. The dashboard
 * weights all four bands; the sub-entity table on the detail page weights
 * critical and high only. Fixing the second is a product decision, not a
 * migration one.
 *
 * NODE SCOPE: every list here is narrowed to the caller's subtree when they
 * are pinned to a node, using the same hierarchy_path prefix EntityPolicy
 * checks for a single record. Entity itself is not ScopedToGraph (it has no
 * entity_id column — it IS the graph), so the narrowing lives here.
 */
class EntityService
{
    public const FRAMEWORKS = ['CBN ORMS', 'Basel III', 'NDPA', 'NFIU', 'BOFIA', 'SEC Rules'];

    public const APPETITE_LEVELS = [
        'averse' => 'Averse',
        'minimal' => 'Minimal',
        'cautious' => 'Cautious',
        'open' => 'Open',
        'hungry' => 'Hungry',
    ];

    public const APPETITE_CATEGORIES = [
        'credit' => 'Credit Risk',
        'operational' => 'Operational Risk',
        'market' => 'Market Risk',
        'compliance' => 'Compliance Risk',
        'technology' => 'Technology Risk',
    ];

    /* ------------------------------------------------------------------ */
    /*  Dashboard */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed> the Scoping/Dashboard page props
     */
    public function dashboard(User $user): array
    {
        $entities = $this->scoped(Entity::query(), $user)
            ->with(['entityType'])
            ->get();

        $counts = $this->riskCountsByEntity();

        $heatmap = $entities
            ->map(fn (Entity $entity) => $this->heatmapRow($entity, $counts->get($entity->id)))
            ->sortByDesc('risk_score')
            ->values();

        $typeDistribution = EntityType::query()
            ->withCount(['entities' => fn ($query) => $this->scoped($query, $user)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (EntityType $type) => ['name' => $type->name, 'value' => (int) $type->entities_count])
            ->values()
            ->all();

        $recent = $this->scoped(Entity::query(), $user)
            ->with('entityType')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (Entity $entity) => [
                'id' => $entity->id,
                'name' => $entity->name,
                'type' => $entity->entityType->name ?? 'Entity',
                'updated_at' => $entity->updated_at?->toIso8601String(),
                'updated_human' => $entity->updated_at?->diffForHumans(),
            ])
            ->values()
            ->all();

        $pendingAssessments = $this->scopedRisks($user)
            ->whereNotNull('entity_id')
            ->whereNull('last_assessment_date')
            ->count();

        return [
            'kpis' => [
                'totalEntities' => $entities->count(),
                'activeOwners' => $entities->whereNotNull('owner_id')->unique('owner_id')->count(),
                // Nothing computes this yet; the page shows it as unavailable
                // rather than as a zero that reads like a measurement.
                'exceedingAppetite' => null,
                'pendingAssessments' => $pendingAssessments,
            ],
            'tree' => $this->tree($user),
            'heatmap' => $heatmap->take(10)->values()->all(),
            'typeDistribution' => $typeDistribution,
            'recentActivity' => $recent,
        ];
    }

    /**
     * The hierarchy, nested in one pass, shaped for Components/EntityTree.jsx.
     *
     * @return list<array{id:int,name:string,code:string,type:?string,icon:?string,level:int,depth:int,children:array}>
     */
    public function tree(User $user): array
    {
        $nodes = $this->scoped(Entity::query(), $user)
            ->with('entityType')
            ->orderBy('name')
            ->get(['id', 'name', 'entity_code', 'parent_id', 'entity_type_id', 'level', 'status']);

        $byParent = $nodes->groupBy('parent_id');
        $ids = $nodes->pluck('id')->flip();

        $build = function ($parentId, int $depth) use (&$build, $byParent): array {
            if ($depth > 8) {
                return [];
            }

            return ($byParent[$parentId] ?? collect())
                ->map(fn (Entity $node) => $this->treeNode($node, $depth, $build($node->id, $depth + 1)))
                ->values()
                ->all();
        };

        // Roots: no parent, or a parent outside what this caller may see (a
        // pinned user's own node is a root of THEIR tree).
        return $nodes
            ->filter(fn (Entity $node) => $node->parent_id === null || ! $ids->has($node->parent_id))
            ->map(fn (Entity $node) => $this->treeNode($node, 0, $build($node->id, 1)))
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Detail */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed> the Scoping/Show page props, less the schema
     */
    public function detail(Entity $entity): array
    {
        $entity->load(['entityType', 'parent.entityType', 'owner', 'delegateOwner', 'creator']);

        $subEntities = Entity::query()
            ->where('parent_id', $entity->id)
            ->with('entityType')
            ->withCount(['risks', 'issues', 'keyRiskIndicators'])
            ->orderBy('name')
            ->get();

        $stats = $entity->risks()
            ->toBase()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN inherent_rating = 'Critical' THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN inherent_rating = 'High' THEN 1 ELSE 0 END) as high,
                SUM(CASE WHEN inherent_rating = 'Medium' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN inherent_rating = 'Low' THEN 1 ELSE 0 END) as low
            ")->first();

        $risks = $entity->risks()
            ->with(['category', 'riskOwner'])
            ->orderByDesc('inherent_score')
            ->limit(20)
            ->get()
            // data_get(): Risk, Issue and KeyRiskIndicator belong to later
            // Phase 3 modules and do not declare their columns or relation
            // types yet; reading through the accessor keeps this file honest
            // without touching theirs.
            ->map(fn (Risk $risk) => [
                'id' => $risk->getKey(),
                'code' => data_get($risk, 'risk_code'),
                'title' => data_get($risk, 'title'),
                'category' => data_get($risk, 'category.name'),
                'rating' => data_get($risk, 'inherent_rating'),
                'owner' => data_get($risk, 'riskOwner.name'),
                'url' => route('risk.register.show', $risk),
            ])
            ->values()
            ->all();

        $issues = $entity->issues()
            ->with('responsibleOwner')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Issue $issue) => [
                'id' => $issue->getKey(),
                'reference' => data_get($issue, 'issue_reference'),
                'title' => data_get($issue, 'title'),
                'severity' => data_get($issue, 'severity') ?? data_get($issue, 'priority'),
                'status' => data_get($issue, 'status') ?? data_get($issue, 'issue_status'),
                'owner' => data_get($issue, 'responsibleOwner.name'),
                'url' => route('risk.issues.show', $issue),
            ])
            ->values()
            ->all();

        $kris = $entity->keyRiskIndicators()
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (KeyRiskIndicator $kri) => [
                'id' => $kri->getKey(),
                'code' => data_get($kri, 'kri_code'),
                'name' => data_get($kri, 'name') ?? data_get($kri, 'kri_name'),
                'current_value' => data_get($kri, 'current_value'),
                'status' => data_get($kri, 'current_status'),
                'trend' => data_get($kri, 'trend_direction'),
                'url' => route('risk.kri.show', $kri),
            ])
            ->values()
            ->all();

        $counts = $this->riskCountsByEntity($subEntities->pluck('id')->all());

        $subEntityHeatmap = $subEntities
            ->map(function (Entity $sub) use ($counts) {
                $row = $this->heatmapRow($sub, $counts->get($sub->id));
                $total = $row['risk_total'];

                // Detail-page formula: critical and high only. Characterised.
                $row['risk_score'] = $total > 0
                    ? round((($row['critical_count'] * 5) + ($row['high_count'] * 4)) / $total, 1)
                    : 0;

                return $row;
            })
            ->values()
            ->all();

        $categoryDistribution = $entity->risks()
            ->toBase()
            ->join('risk_categories', 'risks.category_id', '=', 'risk_categories.id')
            ->selectRaw('risk_categories.name as category_name, COUNT(*) as count')
            ->groupBy('risk_categories.name')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['name' => $row->category_name, 'value' => (int) $row->count])
            ->values()
            ->all();

        return [
            'entity' => $this->present($entity),
            'riskStats' => [
                'total' => (int) ($stats->total ?? 0),
                'critical' => (int) ($stats->critical ?? 0),
                'high' => (int) ($stats->high ?? 0),
                'medium' => (int) ($stats->medium ?? 0),
                'low' => (int) ($stats->low ?? 0),
            ],
            'risks' => $risks,
            'issues' => $issues,
            'kris' => $kris,
            'subEntities' => $subEntities->map(fn (Entity $sub) => [
                'id' => $sub->id,
                'code' => $sub->entity_code,
                'name' => $sub->name,
                'type' => $sub->entityType?->name,
                'status' => $sub->status ?? 'active',
                'risks_count' => (int) $sub->risks_count,
                'issues_count' => (int) $sub->issues_count,
                'kris_count' => (int) $sub->key_risk_indicators_count,
            ])->values()->all(),
            'subEntityHeatmap' => $subEntityHeatmap,
            'categoryDistribution' => $categoryDistribution,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Entity $entity): array
    {
        return [
            'id' => $entity->id,
            'entity_code' => $entity->entity_code,
            'name' => $entity->name,
            'description' => $entity->description,
            'status' => $entity->status ?? 'active',
            'level' => (int) $entity->level,
            'entity_type_id' => $entity->entity_type_id,
            'type' => $entity->entityType?->name,
            'parent_id' => $entity->parent_id,
            'parent' => $entity->parent ? [
                'id' => $entity->parent->id,
                'name' => $entity->parent->name,
                'code' => $entity->parent->entity_code,
            ] : null,
            'owner_id' => $entity->owner_id,
            'owner' => $entity->owner?->name,
            'delegate_owner_id' => $entity->delegate_owner_id,
            'delegate' => $entity->delegateOwner?->name,
            'creator' => $entity->creator?->name,
            'regulatory_frameworks' => array_values((array) ($entity->regulatory_frameworks ?? [])),
            'risk_appetite_level' => $entity->risk_appetite_level,
            'category_appetites' => (array) ($entity->category_appetites ?? []),
            'created_at' => $entity->created_at?->toIso8601String(),
            'created_human' => $entity->created_at?->diffForHumans(),
            'updated_at' => $entity->updated_at?->toIso8601String(),
            'updated_human' => $entity->updated_at?->diffForHumans(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Form options */
    /* ------------------------------------------------------------------ */

    /**
     * The lookups a create/edit form offers, narrowed to the caller's subtree.
     *
     * @return array<string, mixed>
     */
    public function formOptions(User $user, ?Entity $except = null): array
    {
        $entityTypes = EntityType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $parents = $this->scoped(Entity::query(), $user)
            ->when($except !== null, function (Builder $query) use ($except) {
                $ownPath = $except->hierarchy_path ?: '/'.$except->getKey().'/';

                $query->where('id', '!=', $except->getKey())
                    ->where(fn (Builder $q) => $q->whereNull('hierarchy_path')->orWhere('hierarchy_path', 'not like', $ownPath.'%'));
            })
            ->with('entityType')
            ->orderBy('name')
            ->get();

        return [
            'entityTypes' => $entityTypes->map(fn (EntityType $type) => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'level' => (int) $type->level,
            ])->values()->all(),
            'parentEntities' => $parents->map(fn (Entity $entity) => [
                'id' => $entity->id,
                'code' => $entity->entity_code,
                'name' => $entity->name,
                'type' => $entity->entityType?->name,
            ])->values()->all(),
            'users' => User::query()
                ->where('organization_id', TenantContext::organizationId())
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->values()
                ->all(),
            'frameworks' => self::FRAMEWORKS,
            'appetiteLevels' => collect(self::APPETITE_LEVELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'appetiteCategories' => collect(self::APPETITE_CATEGORIES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Writes */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $data  validated StoreEntityRequest input
     */
    public function create(array $data, User $user): Entity
    {
        $type = EntityType::query()->findOrFail($data['entity_type_id']);

        $entity = Entity::create(array_merge($this->columns($data), [
            'organization_id' => TenantContext::organizationId(),
            'entity_code' => $this->nextEntityCode(),
            'level' => $type->level,
            'created_by' => $user->id,
        ]));

        AuditTrailService::record($entity, 'create');

        return $entity;
    }

    /**
     * @param  array<string, mixed>  $data  validated UpdateEntityRequest input
     */
    public function update(Entity $entity, array $data): Entity
    {
        $type = EntityType::query()->findOrFail($data['entity_type_id']);

        $original = $entity->getAttributes();

        $entity->update(array_merge($this->columns($data), ['level' => $type->level]));

        AuditTrailService::recordChanges($entity, $original);

        return $entity;
    }

    /**
     * Remove an entity, or say why it cannot be removed.
     *
     * @return string|null the reason it was NOT deleted, or null on success
     */
    public function delete(Entity $entity): ?string
    {
        $children = Entity::query()->where('parent_id', $entity->id)->count();

        if ($children > 0) {
            return "Cannot delete entity \"{$entity->name}\" because it has {$children} sub-entities. Please reassign or delete them first.";
        }

        $risks = $entity->risks()->count();

        if ($risks > 0) {
            return "Cannot delete entity \"{$entity->name}\" because it has {$risks} linked risks. Please reassign them first.";
        }

        $entity->delete();

        return null;
    }

    /**
     * ENT-0001 style, one past the tenant's newest entity — as before.
     */
    public function nextEntityCode(): string
    {
        $last = Entity::query()->orderByDesc('id')->first();

        return sprintf('ENT-%04d', $last ? $last->id + 1 : 1);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Only the entity's own columns; configured attributes travel separately.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $columns = [
            'entity_type_id', 'name', 'parent_id', 'description', 'owner_id', 'delegate_owner_id',
            'status', 'regulatory_frameworks', 'risk_appetite_level', 'category_appetites',
        ];

        $out = [];

        foreach ($columns as $column) {
            if (array_key_exists($column, $data)) {
                $out[$column] = $data[$column];
            }
        }

        if (isset($out['category_appetites']) && is_array($out['category_appetites'])) {
            $out['category_appetites'] = array_filter($out['category_appetites'], fn ($v) => $v !== null && $v !== '');
        }

        if (isset($out['regulatory_frameworks']) && is_array($out['regulatory_frameworks'])) {
            $out['regulatory_frameworks'] = array_values($out['regulatory_frameworks']);
        }

        return $out;
    }

    /**
     * Narrow an entities query to the caller's subtree, if they have one.
     *
     * @param  Builder<Entity>  $query
     * @return Builder<Entity>
     */
    private function scoped(Builder $query, User $user): Builder
    {
        $rootPath = EntityPolicy::subtreePathFor($user);

        if ($rootPath === null) {
            return $query;
        }

        if ($rootPath === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('hierarchy_path', 'like', $rootPath.'%');
    }

    /**
     * @return Builder<Risk>
     */
    private function scopedRisks(User $user): Builder
    {
        return Risk::query()->visibleTo($user);
    }

    /**
     * Risk counts by inherent rating for every entity in one query.
     *
     * @param  list<int>|null  $entityIds
     * @return BaseCollection<int|string, \stdClass>
     */
    private function riskCountsByEntity(?array $entityIds = null): BaseCollection
    {
        // toBase() after the tenant scope is applied: plain rows, one per
        // entity, rather than Risk models carrying aggregate pseudo-columns.
        return Risk::query()
            ->whereNotNull('entity_id')
            ->when($entityIds !== null, fn (Builder $q) => $q->whereIn('entity_id', $entityIds))
            ->toBase()
            ->selectRaw("
                entity_id,
                COUNT(*) as total,
                SUM(CASE WHEN inherent_rating = 'Critical' THEN 1 ELSE 0 END) as critical_count,
                SUM(CASE WHEN inherent_rating = 'High' THEN 1 ELSE 0 END) as high_count,
                SUM(CASE WHEN inherent_rating = 'Medium' THEN 1 ELSE 0 END) as medium_count,
                SUM(CASE WHEN inherent_rating = 'Low' THEN 1 ELSE 0 END) as low_count
            ")
            ->groupBy('entity_id')
            ->get()
            ->keyBy('entity_id');
    }

    /**
     * One heatmap row with the DASHBOARD formula: all four bands weighted.
     *
     * @return array<string, mixed>
     */
    private function heatmapRow(Entity $entity, ?\stdClass $counts): array
    {
        $total = (int) ($counts->total ?? 0);
        $critical = (int) ($counts->critical_count ?? 0);
        $high = (int) ($counts->high_count ?? 0);
        $medium = (int) ($counts->medium_count ?? 0);
        $low = (int) ($counts->low_count ?? 0);

        return [
            'id' => $entity->id,
            'name' => $entity->name,
            'code' => $entity->entity_code,
            'type' => $entity->entityType?->name,
            'status' => $entity->status ?? 'active',
            'risk_total' => $total,
            'critical_count' => $critical,
            'high_count' => $high,
            'medium_count' => $medium,
            'low_count' => $low,
            'risk_score' => $total > 0
                ? round((($critical * 5) + ($high * 4) + ($medium * 3) + ($low * 2)) / $total, 1)
                : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function treeNode(Entity $node, int $depth, array $children): array
    {
        return [
            'id' => $node->id,
            'name' => $node->name,
            'code' => $node->entity_code,
            'type' => $node->entityType?->name,
            'icon' => $node->entityType?->icon,
            'level' => (int) $node->level,
            'status' => $node->status ?? 'active',
            'depth' => $depth,
            'children' => $children,
        ];
    }
}
