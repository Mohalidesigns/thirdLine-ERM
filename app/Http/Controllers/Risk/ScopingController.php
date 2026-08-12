<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Risk;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

class ScopingController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Dashboard */
    /* ------------------------------------------------------------------ */

    public function dashboard()
    {
        $orgId = TenantContext::organizationId();

        // KPI data
        $totalEntities = Entity::where('organization_id', $orgId)->count();
        $activeOwners = Entity::where('organization_id', $orgId)->whereNotNull('owner_id')->distinct('owner_id')->count('owner_id');
        $exceedingAppetite = 0; // computed below
        $pendingAssessments = Risk::where('organization_id', $orgId)
            ->whereNotNull('entity_id')
            ->whereNull('last_assessment_date')
            ->count();

        // Hierarchy tree: root entities with recursive descendants
        $hierarchyTree = Entity::where('organization_id', $orgId)
            ->whereNull('parent_id')
            ->with(['entityType', 'descendants.entityType'])
            ->orderBy('name')
            ->get();

        // Entity risk heatmap: entities with risk counts by rating
        $entities = Entity::where('organization_id', $orgId)
            ->with(['entityType', 'owner'])
            ->withCount(['risks', 'issues', 'keyRiskIndicators'])
            ->get();

        // Compute risk counts by rating per entity
        $entityHeatmap = $entities->map(function ($entity) {
            $risksByRating = $entity->risks()
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN inherent_rating = 'Critical' THEN 1 ELSE 0 END) as critical_count,
                    SUM(CASE WHEN inherent_rating = 'High' THEN 1 ELSE 0 END) as high_count,
                    SUM(CASE WHEN inherent_rating = 'Medium' THEN 1 ELSE 0 END) as medium_count,
                    SUM(CASE WHEN inherent_rating = 'Low' THEN 1 ELSE 0 END) as low_count
                ")->first();

            $entity->risk_total = $risksByRating->total ?? 0;
            $entity->critical_count = $risksByRating->critical_count ?? 0;
            $entity->high_count = $risksByRating->high_count ?? 0;
            $entity->medium_count = $risksByRating->medium_count ?? 0;
            $entity->low_count = $risksByRating->low_count ?? 0;

            // Simple risk score: weighted average (Critical=5, High=4, Medium=3, Low=2)
            $total = $entity->risk_total;
            if ($total > 0) {
                $entity->risk_score = round(
                    (($entity->critical_count * 5) + ($entity->high_count * 4) + ($entity->medium_count * 3) + ($entity->low_count * 2)) / $total,
                    1
                );
            } else {
                $entity->risk_score = 0;
            }

            return $entity;
        })->sortByDesc('risk_score')->values();

        // Entity type distribution for chart
        $entityTypes = EntityType::where('organization_id', $orgId)
            ->withCount('entities')
            ->orderBy('sort_order')
            ->get();

        $typeDistribution = [
            'labels' => $entityTypes->pluck('name')->toArray(),
            'data' => $entityTypes->pluck('entities_count')->toArray(),
        ];

        // Recent entity activity (latest 5 entities by updated_at)
        $recentActivity = Entity::where('organization_id', $orgId)
            ->with('entityType')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        return view('risk.scoping.dashboard', compact(
            'totalEntities',
            'activeOwners',
            'exceedingAppetite',
            'pendingAssessments',
            'hierarchyTree',
            'entityHeatmap',
            'typeDistribution',
            'recentActivity',
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Index (Entity Register) */
    /* ------------------------------------------------------------------ */

    /**
     * Entity register. Search, filters, sorting and pagination all moved
     * into the shared data grid (WP-09) — see
     * App\Grids\Definitions\EntitiesGrid. The controller now only feeds
     * the header count and the quick-filter pill row.
     */
    public function index(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $entityTypes = EntityType::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Type counts for quick-filter pills
        $typeCounts = Entity::where('organization_id', $orgId)
            ->selectRaw('entity_type_id, COUNT(*) as count')
            ->groupBy('entity_type_id')
            ->pluck('count', 'entity_type_id');

        $totalCount = Entity::where('organization_id', $orgId)->count();

        return view('risk.scoping.index', compact(
            'entityTypes',
            'typeCounts',
            'totalCount',
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Create */
    /* ------------------------------------------------------------------ */

    public function create()
    {
        $orgId = TenantContext::organizationId();

        $entityTypes = EntityType::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $parentEntities = Entity::where('organization_id', $orgId)
            ->with('entityType')
            ->orderBy('name')
            ->get();

        $users = User::orderBy('name')->get(['id', 'name', 'email']);

        return view('risk.scoping.create', compact('entityTypes', 'parentEntities', 'users'));
    }

    /* ------------------------------------------------------------------ */
    /*  Store */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validate([
            'entity_type_id' => 'required|exists:entity_types,id',
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:entities,id',
            'description' => 'nullable|string|max:5000',
            'owner_id' => 'nullable|exists:users,id',
            'delegate_owner_id' => 'nullable|exists:users,id',
            'status' => 'required|in:active,inactive',
            'regulatory_frameworks' => 'nullable|array',
            'regulatory_frameworks.*' => 'string|max:50',
            'risk_appetite_level' => 'nullable|in:averse,minimal,cautious,open,hungry',
            'category_appetites' => 'nullable|array',
        ]);

        // Auto-generate entity code
        $lastEntity = Entity::where('organization_id', $orgId)
            ->orderByDesc('id')
            ->first();
        $nextNumber = $lastEntity ? ($lastEntity->id + 1) : 1;
        $entityCode = sprintf('ENT-%04d', $nextNumber);

        // Get level from entity type
        $entityType = EntityType::findOrFail($validated['entity_type_id']);

        $entity = Entity::create(array_merge($validated, [
            'organization_id' => $orgId,
            'entity_code' => $entityCode,
            'level' => $entityType->level,
            'created_by' => auth()->id(),
        ]));

        // Audit trail
        if (class_exists(\App\Services\AuditTrailService::class)) {
            \App\Services\AuditTrailService::record($entity, 'create');
        }

        return redirect()
            ->route('risk.scoping.show', $entity)
            ->with('success', "Entity {$entityCode} — {$entity->name} has been created successfully.");
    }

    /* ------------------------------------------------------------------ */
    /*  Show (Entity Detail) */
    /* ------------------------------------------------------------------ */

    public function show(Entity $scoping)
    {
        $entity = $scoping;
        $orgId = TenantContext::organizationId();

        if ($entity->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this entity.');
        }

        $entity->load(['entityType', 'parent.entityType', 'owner', 'delegateOwner', 'creator']);

        // Sub-entities
        $subEntities = Entity::where('parent_id', $entity->id)
            ->with('entityType')
            ->withCount(['risks', 'issues', 'keyRiskIndicators'])
            ->get();

        // Risk posture stats
        $riskStats = $entity->risks()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN inherent_rating = 'Critical' THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN inherent_rating = 'High' THEN 1 ELSE 0 END) as high,
                SUM(CASE WHEN inherent_rating = 'Medium' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN inherent_rating = 'Low' THEN 1 ELSE 0 END) as low
            ")->first();

        // Risks list
        $risks = $entity->risks()
            ->with(['category', 'riskOwner'])
            ->orderByDesc('inherent_score')
            ->limit(20)
            ->get();

        // Issues list
        $issues = $entity->issues()
            ->with('responsibleOwner')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // KRIs
        $kris = $entity->keyRiskIndicators()
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        // Sub-entity heatmap
        $subEntityHeatmap = $subEntities->map(function ($sub) {
            $stats = $sub->risks()
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN inherent_rating = 'Critical' THEN 1 ELSE 0 END) as critical,
                    SUM(CASE WHEN inherent_rating = 'High' THEN 1 ELSE 0 END) as high
                ")->first();

            $sub->risk_total = $stats->total ?? 0;
            $sub->critical_count = $stats->critical ?? 0;
            $sub->high_count = $stats->high ?? 0;

            $total = $sub->risk_total;
            if ($total > 0) {
                $sub->risk_score = round(
                    (($sub->critical_count * 5) + ($sub->high_count * 4)) / $total,
                    1
                );
            } else {
                $sub->risk_score = 0;
            }

            return $sub;
        });

        // Risk distribution by category for chart
        $riskByCategory = $entity->risks()
            ->join('risk_categories', 'risks.category_id', '=', 'risk_categories.id')
            ->selectRaw('risk_categories.name as category_name, COUNT(*) as count')
            ->groupBy('risk_categories.name')
            ->orderByDesc('count')
            ->get();

        $categoryDistribution = [
            'labels' => $riskByCategory->pluck('category_name')->toArray(),
            'data' => $riskByCategory->pluck('count')->toArray(),
        ];

        return view('risk.scoping.show', compact(
            'entity',
            'subEntities',
            'riskStats',
            'risks',
            'issues',
            'kris',
            'subEntityHeatmap',
            'categoryDistribution',
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Edit */
    /* ------------------------------------------------------------------ */

    public function edit(Entity $scoping)
    {
        $entity = $scoping;
        $orgId = TenantContext::organizationId();

        if ($entity->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this entity.');
        }

        $entity->load(['entityType', 'parent']);

        $entityTypes = EntityType::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $parentEntities = Entity::where('organization_id', $orgId)
            ->where('id', '!=', $entity->id)
            ->with('entityType')
            ->orderBy('name')
            ->get();

        $users = User::orderBy('name')->get(['id', 'name', 'email']);

        return view('risk.scoping.edit', compact('entity', 'entityTypes', 'parentEntities', 'users'));
    }

    /* ------------------------------------------------------------------ */
    /*  Update */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, Entity $scoping)
    {
        $entity = $scoping;
        $orgId = TenantContext::organizationId();

        if ($entity->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this entity.');
        }

        $validated = $request->validate([
            'entity_type_id' => 'required|exists:entity_types,id',
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:entities,id',
            'description' => 'nullable|string|max:5000',
            'owner_id' => 'nullable|exists:users,id',
            'delegate_owner_id' => 'nullable|exists:users,id',
            'status' => 'required|in:active,inactive,archived',
            'regulatory_frameworks' => 'nullable|array',
            'regulatory_frameworks.*' => 'string|max:50',
            'risk_appetite_level' => 'nullable|in:averse,minimal,cautious,open,hungry',
            'category_appetites' => 'nullable|array',
        ]);

        // Get level from entity type
        $entityType = EntityType::findOrFail($validated['entity_type_id']);
        $validated['level'] = $entityType->level;

        // Prevent self-referencing parent
        if (isset($validated['parent_id']) && $validated['parent_id'] == $entity->id) {
            return back()->withErrors(['parent_id' => 'An entity cannot be its own parent.'])->withInput();
        }

        $original = $entity->getAttributes();
        $entity->update($validated);

        // Audit trail
        if (class_exists(\App\Services\AuditTrailService::class)) {
            \App\Services\AuditTrailService::recordChanges($entity, $original);
        }

        return redirect()
            ->route('risk.scoping.show', $entity)
            ->with('success', "Entity {$entity->entity_code} has been updated successfully.");
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy */
    /* ------------------------------------------------------------------ */

    public function destroy(Entity $scoping)
    {
        $entity = $scoping;
        $orgId = TenantContext::organizationId();

        if ($entity->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this entity.');
        }

        // Check for child entities
        $childCount = Entity::where('parent_id', $entity->id)->count();
        if ($childCount > 0) {
            return back()->with('error', "Cannot delete entity \"{$entity->name}\" because it has {$childCount} sub-entities. Please reassign or delete them first.");
        }

        // Check for linked risks
        $riskCount = $entity->risks()->count();
        if ($riskCount > 0) {
            return back()->with('error', "Cannot delete entity \"{$entity->name}\" because it has {$riskCount} linked risks. Please reassign them first.");
        }

        $entity->delete();

        return redirect()
            ->route('risk.scoping.index')
            ->with('success', "Entity {$entity->entity_code} — {$entity->name} has been deleted.");
    }
}
