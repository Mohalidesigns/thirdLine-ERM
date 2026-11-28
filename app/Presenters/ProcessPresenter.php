<?php

namespace App\Presenters;

use App\Grids\GridRegistry;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Entity;
use App\Models\Organization;
use App\Models\ProcessGroup;
use App\Models\Risk;
use App\Models\RiskAuditTrail;
use App\Models\User;
use App\Policies\EntityPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Presenter for Process Dashboard, Process Catalogue, Process Detail,
 * Process Create/Edit, and Process Groups Admin (Standard §1, ADR 0028 Phase 2).
 */
class ProcessPresenter
{
    /** Linked-risk rows shipped to the process Show page; the rest are counted, not listed. */
    private const LINKED_ROW_LIMIT = 200;

    public function __construct(
        private readonly GridPresenter $grids,
    ) {}

    /**
     * Props for Processes/Dashboard.
     *
     * Standard §5: `kpis.risk_coverage_pct` is `null` when `total_processes === 0`.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Organization $org, User $user): array
    {
        $orgId = (int) $org->id;

        $totalProcesses = BusinessProcess::query()
            ->where('organization_id', $orgId)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->count();

        $totalSubProcesses = BusinessProcess::query()
            ->where('organization_id', $orgId)
            ->whereNotNull('parent_id')
            ->where('is_active', true)
            ->count();

        $totalGroups = ProcessGroup::query()
            ->where('organization_id', $orgId)
            ->where('status', ProcessGroup::STATUS_ACTIVE)
            ->count();

        $criticalProcesses = BusinessProcess::query()
            ->where('organization_id', $orgId)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->where('criticality', 'critical')
            ->count();

        // Active top-level processes with at least one non-deleted risk in the same org.
        $processesWithRisks = (int) DB::table('business_processes as bp')
            ->where('bp.organization_id', $orgId)
            ->whereNull('bp.deleted_at')
            ->whereNull('bp.parent_id')
            ->where('bp.is_active', true)
            ->whereExists(function ($sub) use ($orgId): void {
                $sub->select(DB::raw(1))
                    ->from('risks as r')
                    ->whereColumn('r.process_id', 'bp.id')
                    ->where('r.organization_id', $orgId)
                    ->whereNull('r.deleted_at');
            })
            ->count();

        $unmappedProcessesCount = max(0, $totalProcesses - $processesWithRisks);

        $riskCoveragePct = $totalProcesses > 0
            ? round(($processesWithRisks / $totalProcesses) * 100, 1)
            : null;

        // Pre-aggregate counts per process_group_id in plain SQL (MariaDB 10.4 ∩ MySQL 8.0.46, zero N+1).
        $topByGroup = DB::table('business_processes')
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->selectRaw('process_group_id, COUNT(*) as cnt')
            ->groupBy('process_group_id')
            ->get()
            ->keyBy(static fn ($row) => $row->process_group_id === null ? 'ungrouped' : (string) $row->process_group_id);

        $subByGroup = DB::table('business_processes')
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->whereNotNull('parent_id')
            ->where('is_active', true)
            ->selectRaw('process_group_id, COUNT(*) as cnt')
            ->groupBy('process_group_id')
            ->get()
            ->keyBy(static fn ($row) => $row->process_group_id === null ? 'ungrouped' : (string) $row->process_group_id);

        $risksByGroup = DB::table('risks as r')
            ->join('business_processes as bp', 'bp.id', '=', 'r.process_id')
            ->where('r.organization_id', $orgId)
            ->whereNull('r.deleted_at')
            ->where('bp.organization_id', $orgId)
            ->whereNull('bp.deleted_at')
            ->where('bp.is_active', true)
            ->selectRaw('bp.process_group_id, COUNT(DISTINCT r.id) as cnt')
            ->groupBy('bp.process_group_id')
            ->get()
            ->keyBy(static fn ($row) => $row->process_group_id === null ? 'ungrouped' : (string) $row->process_group_id);

        $mappedByGroup = DB::table('business_processes as bp')
            ->where('bp.organization_id', $orgId)
            ->whereNull('bp.deleted_at')
            ->whereNull('bp.parent_id')
            ->where('bp.is_active', true)
            ->whereExists(function ($sub) use ($orgId): void {
                $sub->select(DB::raw(1))
                    ->from('risks as r')
                    ->whereColumn('r.process_id', 'bp.id')
                    ->where('r.organization_id', $orgId)
                    ->whereNull('r.deleted_at');
            })
            ->selectRaw('bp.process_group_id, COUNT(*) as cnt')
            ->groupBy('bp.process_group_id')
            ->get()
            ->keyBy(static fn ($row) => $row->process_group_id === null ? 'ungrouped' : (string) $row->process_group_id);

        $groups = ProcessGroup::query()
            ->with('businessUnit:id,name')
            ->where('organization_id', $orgId)
            ->active()
            ->ordered()
            ->get(['id', 'code', 'name', 'business_unit_id', 'sort_order']);

        $byGroup = $groups->map(static function (ProcessGroup $group) use ($topByGroup, $subByGroup, $risksByGroup, $mappedByGroup): array {
            $key = (string) $group->id;
            $procCount = (int) ($topByGroup->get($key)?->cnt ?? 0);
            $mappedCount = (int) ($mappedByGroup->get($key)?->cnt ?? 0);

            return [
                'group_id' => $group->id,
                'group_code' => $group->code,
                'group_name' => $group->name,
                'business_unit_name' => $group->businessUnit?->name,
                'processes_count' => $procCount,
                'sub_processes_count' => (int) ($subByGroup->get($key)?->cnt ?? 0),
                'linked_risks_count' => (int) ($risksByGroup->get($key)?->cnt ?? 0),
                'unmapped_count' => max(0, $procCount - $mappedCount),
            ];
        })->values()->all();

        $ungroupedTop = (int) ($topByGroup->get('ungrouped')?->cnt ?? 0);
        if ($ungroupedTop > 0) {
            $ungroupedMapped = (int) ($mappedByGroup->get('ungrouped')?->cnt ?? 0);
            $byGroup[] = [
                'group_id' => null,
                'group_code' => null,
                'group_name' => 'Ungrouped',
                'business_unit_name' => null,
                'processes_count' => $ungroupedTop,
                'sub_processes_count' => (int) ($subByGroup->get('ungrouped')?->cnt ?? 0),
                'linked_risks_count' => (int) ($risksByGroup->get('ungrouped')?->cnt ?? 0),
                'unmapped_count' => max(0, $ungroupedTop - $ungroupedMapped),
            ];
        }

        // Breakdown by ERM criticality (never bcms_processes.criticality_tier).
        $criticalityCounts = BusinessProcess::query()
            ->where('organization_id', $orgId)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->whereNotNull('criticality')
            ->selectRaw('criticality, COUNT(*) as cnt')
            ->groupBy('criticality')
            ->pluck('cnt', 'criticality');

        $byCriticality = array_map(static fn (string $level) => [
            'criticality' => $level,
            'count' => (int) ($criticalityCounts[$level] ?? 0),
        ], BusinessProcess::CRITICALITIES);

        // Attention table: Critical / High active top-level processes with zero linked risks.
        $unmappedCritical = BusinessProcess::query()
            ->with([
                'processGroup:id,name',
                'businessUnit:id,name',
                'owner:id,name',
            ])
            ->where('organization_id', $orgId)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->whereIn('criticality', ['critical', 'high'])
            ->doesntHave('risks')
            ->orderByRaw("CASE criticality WHEN 'critical' THEN 1 WHEN 'high' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'code', 'name', 'process_group_id', 'business_unit_id', 'owner_id', 'criticality'])
            ->map(static fn (BusinessProcess $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'group_name' => $p->processGroup?->name ?? 'Ungrouped',
                'department_name' => $p->businessUnit?->name ?? 'Enterprise / Cross-Departmental',
                'owner_name' => $p->owner?->name ?? '—',
                'criticality' => $p->criticality,
                'url' => route('risk.processes.show', $p),
            ])
            ->values()
            ->all();

        return [
            'kpis' => [
                'total_processes' => $totalProcesses,
                'total_sub_processes' => $totalSubProcesses,
                'total_groups' => $totalGroups,
                'critical_processes' => $criticalProcesses,
                'processes_with_risks' => $processesWithRisks,
                'unmapped_processes_count' => $unmappedProcessesCount,
                'risk_coverage_pct' => $riskCoveragePct,
            ],
            'byGroup' => $byGroup,
            'byCriticality' => $byCriticality,
            'unmappedCritical' => $unmappedCritical,
            'can' => [
                'create' => $user->can('create', BusinessProcess::class),
                'manageGroups' => $user->can('create', ProcessGroup::class),
            ],
        ];
    }

    /**
     * Props for Processes/Index (Process Catalogue).
     *
     * @return array<string, mixed>
     */
    public function catalogue(Request $request, User $user): array
    {
        $orgId = (int) TenantContext::organizationId();

        $processesCount = BusinessProcess::query()
            ->where('organization_id', $orgId)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->count();

        $subProcessesCount = BusinessProcess::query()
            ->where('organization_id', $orgId)
            ->whereNotNull('parent_id')
            ->where('is_active', true)
            ->count();

        $unmappedCount = BusinessProcess::query()
            ->where('organization_id', $orgId)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->doesntHave('risks')
            ->count();

        return [
            'counts' => [
                'processes' => $processesCount,
                'sub_processes' => $subProcessesCount,
                'unmapped' => $unmappedCount,
            ],
            'grid' => fn () => $this->grids->present(GridRegistry::resolve('processes'), $request, $user),
            'can' => [
                'create' => $user->can('create', BusinessProcess::class),
                'update' => $user->can('process.update'),
                'manageGroups' => $user->can('create', ProcessGroup::class),
            ],
        ];
    }

    /**
     * Props for Processes/Show (zero N+1).
     *
     * @return array<string, mixed>
     */
    public function show(BusinessProcess $process, User $user): array
    {
        $process->load([
            'processGroup:id,code,name,apqc_code',
            'businessUnit:id,code,name',
            'owner:id,name,email',
            'parent:id,code,name',
            'subProcesses' => fn ($q) => $q
                ->with('owner:id,name')
                ->withCount('risks')
                ->orderBy('name'),
            'entities' => fn ($q) => $q
                ->with('entityType:id,code,name')
                ->orderBy('name'),
            'auditTrail' => fn ($q) => $q
                ->with('changedByUser:id,name')
                ->orderByDesc('changed_at')
                ->limit(50),
        ]);

        // `process.view` is not `risk.view` and not `entity.view`: a role can hold the
        // first without the others (no shipped role does since the 2026-11-27 owner
        // decision withheld it from risk champions — this is defence in depth), and a
        // branch-pinned user holds the others only for their own subtree. Linked risks and entities are register and scoping data,
        // so they are listed on THOSE modules' terms — permission plus graph reach — the
        // same rule their own index pages and route bindings apply. Counts stay
        // process-wide; rows do not.
        $canViewRisks = $user->can('risk.view');
        $riskTotal = $process->risks()->count();
        $visibleRisks = $canViewRisks
            ? $process->risks()
                ->visibleTo($user)
                ->with([
                    'entity:id,entity_code,name',
                    'riskOwner:id,name',
                    'category:id,name',
                    'libraryItem:id,code,status',
                ])
                ->orderBy('risk_code')
                ->limit(self::LINKED_ROW_LIMIT)
                ->get()
            : collect();

        $entityTotal = $process->entities->count();
        $rootPath = EntityPolicy::subtreePathFor($user);
        $visibleEntities = $user->can('entity.view')
            ? $process->entities->filter(static fn (Entity $entity) => EntityPolicy::withinResolvedReach($user, $rootPath, $entity))->values()
            : collect();

        return [
            'process' => [
                'id' => $process->id,
                'code' => $process->code,
                'apqc_code' => $process->apqc_code,
                'name' => $process->name,
                'description' => $process->description,
                'is_sub_process' => $process->parent_id !== null,
                'parent' => $process->parent ? [
                    'id' => $process->parent->id,
                    'code' => $process->parent->code,
                    'name' => $process->parent->name,
                    'url' => route('risk.processes.show', $process->parent),
                ] : null,
                'process_group' => $process->processGroup ? [
                    'id' => $process->processGroup->id,
                    'code' => $process->processGroup->code,
                    'name' => $process->processGroup->name,
                    'apqc_code' => $process->processGroup->apqc_code,
                ] : null,
                'business_unit' => $process->businessUnit ? [
                    'id' => $process->businessUnit->id,
                    'code' => $process->businessUnit->code,
                    'name' => $process->businessUnit->name,
                ] : null,
                'owner' => $process->owner ? [
                    'id' => $process->owner->id,
                    'name' => $process->owner->name,
                    'email' => $process->owner->email,
                ] : null,
                'criticality' => $process->criticality,
                'is_active' => (bool) $process->is_active,
                'updated_at' => optional($process->updated_at)->toIso8601String(),
            ],
            'subProcesses' => $process->subProcesses->map(static fn (BusinessProcess $sub) => [
                'id' => $sub->id,
                'code' => $sub->code,
                'apqc_code' => $sub->apqc_code,
                'name' => $sub->name,
                'owner_name' => $sub->owner?->name,
                'criticality' => $sub->criticality,
                'linked_risks_count' => (int) ($sub->risks_count ?? 0),
                'is_active' => (bool) $sub->is_active,
                'url' => route('risk.processes.show', $sub),
            ])->values()->all(),
            'entities' => $visibleEntities->map(static fn (Entity $entity) => [
                'id' => $entity->id,
                'entity_code' => $entity->entity_code,
                'name' => $entity->name,
                'entity_type' => $entity->entityType?->name,
                'is_primary' => (bool) ($entity->pivot?->is_primary ?? false),
                'url' => route('risk.scoping.show', $entity),
            ])->values()->all(),
            'risks' => $visibleRisks->map(static fn (Risk $risk) => [
                'id' => $risk->id,
                'risk_code' => $risk->risk_code,
                'title' => $risk->title,
                'category_name' => $risk->category?->name,
                'entity_name' => $risk->entity?->name,
                'owner_name' => $risk->riskOwner?->name,
                'residual_rating' => $risk->residual_rating,
                'status' => $risk->status,
                'library_code' => $risk->libraryItem?->code,
                'url' => route('risk.register.show', $risk),
            ])->values()->all(),
            'linkedCounts' => [
                'risks' => $riskTotal,
                'risks_hidden' => max(0, $riskTotal - $visibleRisks->count()),
                'can_view_risks' => $canViewRisks,
                'entities' => $entityTotal,
                'entities_hidden' => max(0, $entityTotal - $visibleEntities->count()),
            ],
            'auditTrail' => $process->auditTrail->map(static fn (RiskAuditTrail $trail) => [
                'id' => $trail->id,
                'action_type' => $trail->action_type,
                'actor_name' => $trail->changedByUser?->name ?? 'System',
                'created_at' => optional($trail->changed_at)->toIso8601String(),
                'summary' => $trail->change_reason ?? $trail->field_changed ?? '',
            ])->values()->all(),
            'can' => [
                'update' => $user->can('update', $process),
                'createSubProcess' => $process->parent_id === null && $user->can('create', BusinessProcess::class),
            ],
        ];
    }

    /**
     * Props for Processes/Create and Processes/Edit.
     *
     * @param  array{default_level?: string, default_parent_id?: ?int, default_entity_id?: ?int}  $context
     * @return array<string, mixed>
     */
    public function formOptions(Organization $org, ?BusinessProcess $process = null, array $context = []): array
    {
        $orgId = (int) $org->id;

        $processGroups = ProcessGroup::query()
            ->where('organization_id', $orgId)
            ->active()
            ->ordered()
            ->get(['id', 'code', 'name', 'business_unit_id'])
            ->map(static fn (ProcessGroup $g) => [
                'id' => $g->id,
                'code' => $g->code,
                'name' => $g->name,
                'business_unit_id' => $g->business_unit_id,
            ])
            ->values()
            ->all();

        $businessUnits = BusinessUnit::query()
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(static fn (BusinessUnit $bu) => [
                'id' => $bu->id,
                'code' => $bu->code,
                'name' => $bu->name,
            ])
            ->values()
            ->all();

        $parentQuery = BusinessProcess::query()
            ->where('organization_id', $orgId)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('name');

        if ($process !== null) {
            $parentQuery->where('id', '!=', $process->id);
        }

        $parentProcesses = $parentQuery
            ->get(['id', 'code', 'name', 'business_unit_id', 'process_group_id'])
            ->map(static fn (BusinessProcess $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'business_unit_id' => $p->business_unit_id,
                'process_group_id' => $p->process_group_id,
            ])
            ->values()
            ->all();

        $owners = User::query()
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
            ])
            ->values()
            ->all();

        $subProcessesCount = $process !== null ? $process->subProcesses()->count() : 0;

        return [
            'mode' => $process === null ? 'create' : 'edit',
            'process' => $process === null ? null : [
                'id' => $process->id,
                'level' => $process->parent_id !== null ? 'sub_process' : 'process',
                'parent_id' => $process->parent_id,
                'process_group_id' => $process->process_group_id,
                'business_unit_id' => $process->business_unit_id,
                'code' => $process->code,
                'apqc_code' => $process->apqc_code,
                'name' => $process->name,
                'description' => $process->description,
                'owner_id' => $process->owner_id,
                'criticality' => $process->criticality,
                'is_active' => (bool) $process->is_active,
                'sub_processes_count' => $subProcessesCount,
            ],
            'context' => [
                'default_level' => $context['default_level'] ?? ($process?->parent_id !== null ? 'sub_process' : 'process'),
                'default_parent_id' => $context['default_parent_id'] ?? $process?->parent_id,
                'default_entity_id' => $context['default_entity_id'] ?? null,
            ],
            'options' => [
                'processGroups' => $processGroups,
                'businessUnits' => $businessUnits,
                'parentProcesses' => $parentProcesses,
                'owners' => $owners,
                'criticalities' => [
                    ['value' => 'critical', 'label' => 'Critical'],
                    ['value' => 'high', 'label' => 'High'],
                    ['value' => 'medium', 'label' => 'Medium'],
                    ['value' => 'low', 'label' => 'Low'],
                ],
            ],
        ];
    }

    /**
     * Props for ProcessGroups/Index.
     *
     * @return array<string, mixed>
     */
    public function groupsIndex(Request $request, User $user): array
    {
        $orgId = (int) TenantContext::organizationId();

        $statusCounts = ProcessGroup::query()
            ->where('organization_id', $orgId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $active = (int) ($statusCounts[ProcessGroup::STATUS_ACTIVE] ?? 0);
        $inactive = (int) ($statusCounts[ProcessGroup::STATUS_INACTIVE] ?? 0);

        $businessUnits = BusinessUnit::query()
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(static fn (BusinessUnit $bu) => [
                'id' => $bu->id,
                'code' => $bu->code,
                'name' => $bu->name,
            ])
            ->values()
            ->all();

        $owners = User::query()
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
            ])
            ->values()
            ->all();

        // Compact editable rows for the inline Create/Edit slide-over (zero extra HTTP requests).
        $groups = ProcessGroup::query()
            ->with(['businessUnit:id,name', 'owner:id,name'])
            ->withCount(['topLevelProcesses as processes_count'])
            ->where('organization_id', $orgId)
            ->ordered()
            ->get([
                'id',
                'code',
                'apqc_code',
                'name',
                'description',
                'business_unit_id',
                'owner_id',
                'sort_order',
                'status',
            ])
            ->map(static fn (ProcessGroup $g) => [
                'id' => $g->id,
                'code' => $g->code,
                'apqc_code' => $g->apqc_code,
                'name' => $g->name,
                'description' => $g->description,
                'business_unit_id' => $g->business_unit_id,
                'business_unit_name' => $g->businessUnit?->name,
                'owner_id' => $g->owner_id,
                'owner_name' => $g->owner?->name,
                'processes_count' => (int) ($g->processes_count ?? 0),
                'sort_order' => (int) $g->sort_order,
                'status' => $g->status,
            ])
            ->values()
            ->all();

        return [
            'counts' => [
                'active' => $active,
                'inactive' => $inactive,
                'total' => $active + $inactive,
            ],
            'grid' => fn () => $this->grids->present(GridRegistry::resolve('process_groups'), $request, $user),
            'groups' => $groups,
            'options' => [
                'businessUnits' => $businessUnits,
                'owners' => $owners,
            ],
            'can' => [
                'manage' => $user->can('create', ProcessGroup::class),
            ],
        ];
    }

    /**
     * Build examiner-ready Process Catalogue export headers & rows respecting active filters.
     *
     * @return array{headers: list<string>, rows: list<list<mixed>>}
     */
    public function exportRows(Request $request): array
    {
        $orgId = (int) TenantContext::organizationId();
        $filters = (array) $request->query('filters', []);

        $query = BusinessProcess::query()
            ->with([
                'processGroup:id,code,name',
                'businessUnit:id,name',
                'owner:id,name',
                'parent:id,code',
            ])
            ->withCount(['subProcesses', 'risks'])
            ->where('organization_id', $orgId)
            ->orderBy('code');

        $level = $filters['level'] ?? $request->query('level');
        if ($level === 'process') {
            $query->whereNull('parent_id');
        } elseif ($level === 'sub_process') {
            $query->whereNotNull('parent_id');
        }

        $groupId = $filters['process_group_id'] ?? $request->query('process_group_id');
        if ($groupId === 'ungrouped') {
            $query->whereNull('process_group_id');
        } elseif ($groupId !== null && $groupId !== '') {
            $query->where('process_group_id', (int) $groupId);
        }

        $buId = $filters['business_unit_id'] ?? $request->query('business_unit_id');
        if ($buId === 'enterprise') {
            $query->whereNull('business_unit_id');
        } elseif ($buId !== null && $buId !== '') {
            $query->where('business_unit_id', (int) $buId);
        }

        $ownerId = $filters['owner_id'] ?? $request->query('owner_id');
        if ($ownerId !== null && $ownerId !== '') {
            $query->where('owner_id', (int) $ownerId);
        }

        $criticality = $filters['criticality'] ?? $request->query('criticality');
        if (is_string($criticality) && in_array($criticality, BusinessProcess::CRITICALITIES, true)) {
            $query->where('criticality', $criticality);
        }

        $hasRisks = $filters['has_risks'] ?? $request->query('has_risks');
        if ($hasRisks === 'none') {
            $query->doesntHave('risks');
        } elseif ($hasRisks === 'has') {
            $query->has('risks');
        }

        $isActive = $filters['is_active'] ?? $request->query('is_active');
        if ($isActive === '1' || $isActive === 1 || $isActive === true) {
            $query->where('is_active', true);
        } elseif ($isActive === '0' || $isActive === 0 || $isActive === false) {
            $query->where('is_active', false);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escaped): void {
                $q->where('code', 'LIKE', '%'.$escaped.'%')
                    ->orWhere('apqc_code', 'LIKE', '%'.$escaped.'%')
                    ->orWhere('name', 'LIKE', '%'.$escaped.'%');
            });
        }

        $headers = [
            'Group Code',
            'Group Name',
            'Process Code',
            'APQC Code',
            'Process Name',
            'Parent Code',
            'Department',
            'Owner',
            'Criticality',
            'Sub-Processes Count',
            'Linked Risks Count',
            'Status',
        ];

        $rows = $query->get()->map(static fn (BusinessProcess $p) => [
            $p->processGroup?->code ?? '',
            $p->processGroup?->name ?? 'Ungrouped',
            $p->code,
            $p->apqc_code ?? '',
            $p->name,
            $p->parent?->code ?? '',
            $p->businessUnit?->name ?? 'Enterprise / Cross-Departmental',
            $p->owner?->name ?? '',
            $p->criticality ? ucfirst($p->criticality) : '',
            $p->parent_id !== null ? '' : (int) ($p->sub_processes_count ?? 0),
            (int) ($p->risks_count ?? 0),
            $p->is_active ? 'Active' : 'Inactive',
        ])->values()->all();

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * Active org business processes not yet attached to the given entity,
     * for the lazy-loaded Attach Existing Process slide-over (entity-processes-tab.md §2).
     *
     * @return array<string, mixed>
     */
    public function attachableForEntity(Entity $entity, Request $request): array
    {
        $orgId = (int) $entity->organization_id;
        $search = trim((string) $request->query('search', ''));
        $groupId = $request->filled('process_group_id') ? (int) $request->query('process_group_id') : null;

        $attachedIds = DB::table('entity_business_process')
            ->where('organization_id', $orgId)
            ->where('entity_id', $entity->id)
            ->pluck('business_process_id')
            ->all();

        $query = BusinessProcess::query()
            ->with(['processGroup:id,code,name', 'parent:id,code,name'])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->when($attachedIds !== [], fn ($q) => $q->whereNotIn('id', $attachedIds))
            ->orderBy('name');

        if ($groupId !== null) {
            $query->where('process_group_id', $groupId);
        }

        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escaped): void {
                $q->where('code', 'LIKE', '%'.$escaped.'%')
                    ->orWhere('apqc_code', 'LIKE', '%'.$escaped.'%')
                    ->orWhere('name', 'LIKE', '%'.$escaped.'%');
            });
        }

        $items = $query->limit(50)->get([
            'id',
            'code',
            'apqc_code',
            'name',
            'parent_id',
            'process_group_id',
            'criticality',
        ]);

        return [
            'processes' => $items->map(static fn (BusinessProcess $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'apqc_code' => $p->apqc_code,
                'name' => $p->name,
                'is_sub_process' => $p->parent_id !== null,
                'parent_name' => $p->parent?->name,
                'process_group_name' => $p->processGroup?->name,
                'criticality' => $p->criticality,
            ])->values()->all(),
        ];
    }
}
