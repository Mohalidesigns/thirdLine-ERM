<?php

namespace App\Presenters;

use App\Enums\BaselEventTypeL1;
use App\Enums\BaselEventTypeL2;
use App\Grids\GridRegistry;
use App\Models\BusinessProcess;
use App\Models\Control;
use App\Models\Entity;
use App\Models\KeyRiskIndicator;
use App\Models\Risk;
use App\Models\RiskAuditTrail;
use App\Models\RiskCategory;
use App\Models\RiskCauseCategory;
use App\Models\RiskLibraryItem;
use App\Models\RiskLibraryItemVersion;
use App\Models\User;
use App\Policies\EntityPolicy;
use Illuminate\Http\Request;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Presenter for Risk Library screens and Register/Show library context (Standard §1, ADR 0028).
 */
class RiskLibraryPresenter
{
    /** Linked-instance rows shipped to the Show page; the rest are counted, not listed. */
    private const INSTANCE_ROW_LIMIT = 200;

    public function __construct(
        private readonly GridPresenter $grids,
    ) {}

    /**
     * Props for RiskLibrary/Index.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request, User $user): array
    {
        $orgId = TenantContext::organizationId();

        $statusCounts = RiskLibraryItem::query()
            ->where('organization_id', $orgId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $active = (int) ($statusCounts[RiskLibraryItem::STATUS_ACTIVE] ?? 0);
        $retired = (int) ($statusCounts[RiskLibraryItem::STATUS_RETIRED] ?? 0);

        $categories = RiskCategory::query()
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(static fn (RiskCategory $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
            ])
            ->values()
            ->all();

        return [
            'counts' => [
                'active' => $active,
                'retired' => $retired,
                'total' => $active + $retired,
            ],
            'grid' => fn () => $this->grids->present(GridRegistry::resolve('risk_library'), $request, $user),
            'filterOptions' => [
                'categories' => $categories,
                'baselL1' => BaselEventTypeL1::options(),
                'baselL2' => BaselEventTypeL2::options(),
            ],
            'can' => [
                'create' => $user->can('create', RiskLibraryItem::class),
                'update' => $user->can('risk_library.update'),
                'retire' => $user->can('risk_library.retire'),
                'bulkApply' => $user->can('create', Risk::class),
                'export' => $user->can('risk_library.view'),
            ],
        ];
    }

    /**
     * Props for RiskLibrary/Show (zero N+1).
     *
     * @return array<string, mixed>
     */
    public function show(RiskLibraryItem $item, User $user): array
    {
        $item->load([
            'category:id,code,name,cbn_mapping_code',
            'creator:id,name',
            'retiredBy:id,name',
            'versions' => fn ($q) => $q->with(['category:id,name', 'creator:id,name'])->orderByDesc('version_number'),
            'auditTrail' => fn ($q) => $q->with('changedByUser:id,name')->orderByDesc('changed_at')->limit(50),
        ]);

        $causeCategories = RiskCauseCategory::query()
            ->pluck('name', 'id')
            ->all();

        $formatCauses = static function (?array $causes) use ($causeCategories): array {
            if (! is_array($causes)) {
                return [];
            }

            return array_values(array_map(static fn (array $row) => [
                'cause' => $row['cause'] ?? '',
                'cause_category_id' => $row['cause_category_id'] ?? null,
                'cause_category_name' => isset($row['cause_category_id'])
                    ? ($causeCategories[$row['cause_category_id']] ?? null)
                    : null,
            ], $causes));
        };

        $l1 = $item->baselL1Enum();
        $l2 = $item->baselL2Enum();

        // LINKED INSTANCES ARE REGISTER ROWS, so they are shown on the register's terms,
        // not the library's. A role can hold `risk_library.view` without `risk.view`
        // (no shipped role does since the 2026-11-27 owner decision withheld it from risk
        // champions — this is defence in depth), and a branch-pinned user holds
        // `risk.view` only for their own subtree. Rows are therefore built only for a caller who may read
        // the register, and only for risks inside their reach (ScopedToGraph::visibleTo)
        // — the same rule the register index and the {risk} binding apply.
        //
        // The TOTAL stays organisation-wide: it is a count, not a disclosure of any one
        // risk, and the retire warning must tell a library administrator how many live
        // instances a retirement touches whether or not they can open each of them.
        $instanceTotal = $item->risks()->count();
        $canViewInstances = $user->can('risk.view');

        $visibleRisks = $canViewInstances
            ? $item->risks()
                ->visibleTo($user)
                ->with(['entity:id,entity_code,name', 'process:id,code,name', 'riskOwner:id,name'])
                ->orderBy('risk_code')
                ->limit(self::INSTANCE_ROW_LIMIT + 1)
                ->get()
            : collect();

        $instancesTruncated = $visibleRisks->count() > self::INSTANCE_ROW_LIMIT;
        $visibleRisks = $visibleRisks->take(self::INSTANCE_ROW_LIMIT);

        $outdatedCount = $item->risks()
            ->whereNotNull('library_version')
            ->where('library_version', '<', $item->current_version)
            ->count();

        $instanceRows = $visibleRisks->map(static function (Risk $risk) use ($item): array {
            $isOutdated = $risk->library_version !== null && $risk->library_version < $item->current_version;

            return [
                'id' => $risk->id,
                'risk_code' => $risk->risk_code,
                'title' => $risk->title,
                'entity_name' => $risk->entity?->name,
                'process_name' => $risk->process?->name,
                'owner_name' => $risk->riskOwner?->name,
                'residual_rating' => $risk->residual_rating,
                'library_version' => $risk->library_version,
                'is_outdated' => $isOutdated,
                'library_synced_at' => optional($risk->library_synced_at)->toIso8601String(),
                'url' => route('risk.register.show', $risk),
            ];
        })->values()->all();

        return [
            'item' => [
                'id' => $item->id,
                'code' => $item->code,
                'title' => $item->title,
                'description' => $item->description,
                'category' => $item->category ? [
                    'id' => $item->category->id,
                    'code' => $item->category->code,
                    'name' => $item->category->name,
                    'cbn_mapping_code' => $item->category->cbn_mapping_code,
                ] : null,
                'risk_source' => $item->risk_source,
                'basel_l1' => $l1 ? ['value' => $l1->value, 'label' => $l1->label()] : null,
                'basel_l2' => $l2 ? ['value' => $l2->value, 'label' => $l2->label()] : null,
                'typical_causes' => $formatCauses($item->typical_causes),
                'typical_consequences' => $item->typical_consequences ?? [],
                'suggested_controls' => $item->suggested_controls ?? [],
                'suggested_kris' => $item->suggested_kris ?? [],
                'current_version' => $item->current_version,
                'status' => $item->status,
                'retired_at' => optional($item->retired_at)->toIso8601String(),
                'retired_by_name' => $item->retiredBy?->name,
                'retirement_reason' => $item->retirement_reason,
                'created_by_name' => $item->creator?->name,
                'updated_at' => optional($item->updated_at)->toIso8601String(),
            ],
            'versions' => $item->versions->map(static fn (RiskLibraryItemVersion $v) => [
                'version_number' => $v->version_number,
                'title' => $v->title,
                'description' => $v->description,
                'category_name' => $v->category?->name,
                'risk_source' => $v->risk_source,
                'basel_l1_label' => $v->baselL1Enum()?->label(),
                'basel_l2_label' => $v->baselL2Enum()?->label(),
                'typical_causes' => $formatCauses($v->typical_causes),
                'typical_consequences' => $v->typical_consequences ?? [],
                'suggested_controls' => $v->suggested_controls ?? [],
                'suggested_kris' => $v->suggested_kris ?? [],
                'change_summary' => $v->change_summary,
                'created_by_name' => $v->creator?->name,
                'created_at' => optional($v->created_at)->toIso8601String(),
            ])->values()->all(),
            'instances' => [
                'total' => $instanceTotal,
                'outdated_count' => $outdatedCount,
                'rows' => $instanceRows,
                'can_view_rows' => $canViewInstances,
                'hidden_count' => max(0, $instanceTotal - count($instanceRows)),
                'truncated' => $instancesTruncated,
            ],
            'auditTrail' => $item->auditTrail->map(static fn (RiskAuditTrail $trail) => [
                'id' => $trail->id,
                'action_type' => $trail->action_type,
                'actor_name' => $trail->changedByUser?->name ?? 'System',
                'created_at' => optional($trail->changed_at)->toIso8601String(),
                'summary' => $trail->change_reason ?? $trail->field_changed ?? '',
            ])->values()->all(),
            'can' => [
                'update' => $user->can('update', $item),
                'retire' => $user->can('retire', $item),
                'bulkApply' => $user->can('create', Risk::class),
            ],
        ];
    }

    /**
     * Props for RiskLibrary/Create and RiskLibrary/Edit.
     *
     * @return array<string, mixed>
     */
    public function formOptions(?RiskLibraryItem $item = null): array
    {
        $orgId = TenantContext::organizationId();

        $categories = RiskCategory::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'basel_category'])
            ->map(static fn (RiskCategory $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'is_operational' => str_contains(strtolower((string) $c->name), 'operational')
                    || str_contains(strtolower((string) $c->basel_category), 'operational')
                    || str_starts_with(strtoupper((string) $c->code), 'OR'),
            ])
            ->values()
            ->all();

        $causeCategories = RiskCauseCategory::options()
            ->map(static fn (RiskCauseCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
            ])
            ->values()
            ->all();

        $existingControls = Control::query()
            ->where('organization_id', $orgId)
            ->orderBy('control_code')
            ->limit(250)
            ->get(['id', 'control_code', 'name'])
            ->map(static fn (Control $c) => [
                'id' => $c->id,
                'code' => $c->control_code,
                'title' => $c->name,
            ])
            ->values()
            ->all();

        $existingKris = KeyRiskIndicator::query()
            ->where('organization_id', $orgId)
            ->orderBy('kri_code')
            ->limit(250)
            ->get(['id', 'kri_code', 'kri_name'])
            ->map(static fn (KeyRiskIndicator $k) => [
                'id' => $k->id,
                'code' => $k->kri_code,
                'name' => $k->kri_name,
            ])
            ->values()
            ->all();

        return [
            'mode' => $item === null ? 'create' : 'edit',
            'item' => $item === null ? null : [
                'id' => $item->id,
                'code' => $item->code,
                'title' => $item->title,
                'description' => $item->description,
                'category_id' => $item->category_id,
                'risk_source' => $item->risk_source,
                'basel_l1_event_type' => $item->basel_l1_event_type,
                'basel_l2_event_type' => $item->basel_l2_event_type,
                'typical_causes' => $item->typical_causes ?? [],
                'typical_consequences' => $item->typical_consequences ?? [],
                'suggested_controls' => $item->suggested_controls ?? [],
                'suggested_kris' => $item->suggested_kris ?? [],
                'current_version' => $item->current_version,
                'status' => $item->status,
            ],
            'options' => [
                'categories' => $categories,
                'riskSources' => RiskLibraryItem::RISK_SOURCES,
                'baselL1' => BaselEventTypeL1::options(),
                'baselL2' => BaselEventTypeL2::options(),
                'causeCategories' => $causeCategories,
                'impactDomains' => RiskLibraryItem::IMPACT_DOMAINS,
                'controlTypes' => RiskLibraryItem::CONTROL_TYPES,
                'existingControls' => $existingControls,
                'existingKris' => $existingKris,
            ],
        ];
    }

    /**
     * Build the `libraryContext` prop for Register/Show.jsx (ADR 0028 Decision 4).
     *
     * Computes the field-by-field diff ONLY when `hasPendingLibraryUpdate()` is true.
     *
     * @return array<string, mixed>|null
     */
    public function libraryContextForRisk(Risk $risk): ?array
    {
        if ($risk->library_item_id === null) {
            return null;
        }

        $item = $risk->relationLoaded('libraryItem')
            ? $risk->libraryItem
            : $risk->libraryItem()->first();

        if ($item === null) {
            return null;
        }

        $hasPendingUpdate = $risk->hasPendingLibraryUpdate();
        $diff = null;

        if ($hasPendingUpdate) {
            $snapshots = RiskLibraryItemVersion::query()
                ->with(['category:id,name', 'creator:id,name'])
                ->where('library_item_id', $item->id)
                ->whereIn('version_number', [$risk->library_version, $item->current_version])
                ->get()
                ->keyBy('version_number');

            /** @var RiskLibraryItemVersion|null $fromSnap */
            $fromSnap = $snapshots->get($risk->library_version);
            /** @var RiskLibraryItemVersion|null $toSnap */
            $toSnap = $snapshots->get($item->current_version);

            $fields = [];
            if ($fromSnap !== null && $toSnap !== null) {
                $comparisons = [
                    ['field' => 'title', 'label' => 'Title', 'before' => $fromSnap->title, 'after' => $toSnap->title],
                    ['field' => 'description', 'label' => 'Description', 'before' => $fromSnap->description, 'after' => $toSnap->description],
                    ['field' => 'category', 'label' => 'Category', 'before' => $fromSnap->category?->name, 'after' => $toSnap->category?->name],
                    ['field' => 'risk_source', 'label' => 'Risk Source', 'before' => $fromSnap->risk_source, 'after' => $toSnap->risk_source],
                    ['field' => 'basel_l1_event_type', 'label' => 'Basel L1 Event Type', 'before' => $fromSnap->baselL1Enum()?->label(), 'after' => $toSnap->baselL1Enum()?->label()],
                    ['field' => 'basel_l2_event_type', 'label' => 'Basel L2 Event Type', 'before' => $fromSnap->baselL2Enum()?->label(), 'after' => $toSnap->baselL2Enum()?->label()],
                    [
                        'field' => 'typical_causes',
                        'label' => 'Typical Causes',
                        'before' => array_values(array_map(static fn (array $r) => $r['cause'] ?? '', $fromSnap->typical_causes ?? [])),
                        'after' => array_values(array_map(static fn (array $r) => $r['cause'] ?? '', $toSnap->typical_causes ?? [])),
                    ],
                    [
                        'field' => 'typical_consequences',
                        'label' => 'Typical Consequences',
                        'before' => array_values(array_map(static fn (array $r) => $r['consequence'] ?? '', $fromSnap->typical_consequences ?? [])),
                        'after' => array_values(array_map(static fn (array $r) => $r['consequence'] ?? '', $toSnap->typical_consequences ?? [])),
                    ],
                ];

                foreach ($comparisons as $entry) {
                    if ($entry['before'] !== $entry['after']) {
                        $fields[] = $entry;
                    }
                }
            }

            $diff = [
                'from_version' => $risk->library_version,
                'to_version' => $item->current_version,
                'change_summary' => $toSnap?->change_summary,
                'updated_by' => $toSnap?->creator?->name ?? 'System',
                'updated_at' => optional($toSnap?->created_at ?? $item->updated_at)->toIso8601String(),
                'fields' => $fields,
            ];
        }

        return [
            'library_item_id' => $item->id,
            'code' => $item->code,
            'show_url' => route('risk.library.show', $item),
            'status' => $item->status,
            'instance_version' => $risk->library_version,
            'current_version' => $item->current_version,
            'synced_at' => optional($risk->library_synced_at)->toIso8601String(),
            'has_pending_update' => $hasPendingUpdate,
            'diff' => $diff,
            'suggested_controls' => $item->suggested_controls ?? [],
            'suggested_kris' => $item->suggested_kris ?? [],
        ];
    }

    /**
     * Lazy-loaded active library items for Add-from-Library & Bulk Apply pickers.
     * Retired items are strictly excluded (ADR 0028 Decision 6).
     *
     * @return array<string, mixed>
     */
    public function pickerOptions(Request $request): array
    {
        $orgId = TenantContext::organizationId();
        $entityId = $request->filled('entity_id') ? (int) $request->query('entity_id') : null;
        $categoryId = $request->filled('category_id') ? (int) $request->query('category_id') : null;
        $baselL1 = $request->filled('basel_l1') ? (string) $request->query('basel_l1') : null;
        $search = trim((string) $request->query('search', ''));

        $query = RiskLibraryItem::query()
            ->with('category:id,name')
            ->where('organization_id', $orgId)
            ->active()
            ->orderBy('code');

        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }
        if ($baselL1 !== null) {
            $query->where('basel_l1_event_type', $baselL1);
        }
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escaped): void {
                $q->where('code', 'LIKE', '%'.$escaped.'%')
                    ->orWhere('title', 'LIKE', '%'.$escaped.'%');
            });
        }

        $items = $query->limit(50)->get([
            'id',
            'code',
            'title',
            'category_id',
            'basel_l1_event_type',
            'current_version',
        ]);

        $attachedIds = [];
        $entityProcesses = [];
        if ($entityId !== null) {
            if ($items->isNotEmpty()) {
                $attachedIds = Risk::query()
                    ->where('organization_id', $orgId)
                    ->where('entity_id', $entityId)
                    ->whereIn('library_item_id', $items->pluck('id')->all())
                    ->pluck('library_item_id')
                    ->flip()
                    ->all();
            }

            $entity = Entity::query()
                ->where('organization_id', $orgId)
                ->find($entityId);

            if ($entity !== null) {
                $entityProcesses = $entity->businessProcesses()
                    ->where('business_processes.organization_id', $orgId)
                    ->where('business_processes.is_active', true)
                    ->orderBy('business_processes.name')
                    ->get(['business_processes.id', 'business_processes.code', 'business_processes.name'])
                    ->map(static fn (BusinessProcess $p) => [
                        'id' => $p->id,
                        'code' => $p->code,
                        'name' => $p->name,
                    ])
                    ->values()
                    ->all();
            }
        }

        return [
            'items' => $items->map(static fn (RiskLibraryItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'title' => $item->title,
                'category_name' => $item->category?->name,
                'basel_l1_label' => $item->baselL1Enum()?->label(),
                'current_version' => $item->current_version,
                'already_attached_to_entity' => isset($attachedIds[$item->id]),
            ])->values()->all(),
            'categories' => RiskCategory::query()
                ->where('organization_id', $orgId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (RiskCategory $c) => ['id' => $c->id, 'name' => $c->name])
                ->values()
                ->all(),
            'baselL1' => BaselEventTypeL1::options(),
            'entityProcesses' => $entityProcesses,
            'owners' => User::query()
                ->where('organization_id', $orgId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                ->values()
                ->all(),
            // Only entities the caller may create risks on: BulkApplyLibraryItemRequest
            // refuses the rest, so offering them would only produce a validation error.
            'entities' => Entity::query()
                ->where('organization_id', $orgId)
                ->when(
                    EntityPolicy::subtreePathFor($request->user()),
                    static fn ($q, string $path) => $q->where('hierarchy_path', 'like', $path.'%'),
                )
                ->when(
                    EntityPolicy::subtreePathFor($request->user()) === '',
                    static fn ($q) => $q->whereRaw('1 = 0'),
                )
                ->orderBy('name')
                ->limit(250)
                ->get(['id', 'entity_code', 'name'])
                ->map(static fn (Entity $e) => [
                    'id' => $e->id,
                    'code' => $e->entity_code,
                    'name' => $e->name,
                ])
                ->values()
                ->all(),
            'processes' => BusinessProcess::query()
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(250)
                ->get(['id', 'code', 'name'])
                ->map(static fn (BusinessProcess $p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Build examiner-ready export headers & rows respecting active index filters.
     *
     * @return array{headers: list<string>, rows: list<list<mixed>>}
     */
    public function exportRows(Request $request): array
    {
        $orgId = TenantContext::organizationId();
        $filters = (array) $request->query('filters', []);

        $query = RiskLibraryItem::query()
            ->with('category:id,name,cbn_mapping_code')
            ->withCount('risks')
            ->where('organization_id', $orgId)
            ->orderBy('code');

        $status = $filters['status'] ?? $request->query('status');
        if (is_string($status) && in_array($status, RiskLibraryItem::STATUSES, true)) {
            $query->where('status', $status);
        }

        $categoryId = $filters['category_id'] ?? $request->query('category_id');
        if ($categoryId !== null && $categoryId !== '') {
            $query->where('category_id', (int) $categoryId);
        }

        $baselL1 = $filters['basel_l1'] ?? $request->query('basel_l1');
        if (is_string($baselL1) && $baselL1 !== '') {
            $query->where('basel_l1_event_type', $baselL1);
        }

        $baselL2 = $filters['basel_l2'] ?? $request->query('basel_l2');
        if (is_string($baselL2) && $baselL2 !== '') {
            $query->where('basel_l2_event_type', $baselL2);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escaped): void {
                $q->where('code', 'LIKE', '%'.$escaped.'%')
                    ->orWhere('title', 'LIKE', '%'.$escaped.'%')
                    ->orWhere('description', 'LIKE', '%'.$escaped.'%');
            });
        }

        $headers = [
            'Code',
            'Title',
            'Version',
            'Status',
            'Category',
            'CBN Mapping Code',
            'Basel L1 Event Type',
            'Basel L2 Event Type',
            'Instances Count',
        ];

        $rows = $query->get()->map(static fn (RiskLibraryItem $item) => [
            $item->code,
            $item->title,
            'v'.$item->current_version,
            ucfirst($item->status),
            $item->category?->name ?? '',
            $item->category?->cbn_mapping_code ?? '',
            $item->baselL1Enum()?->label() ?? '',
            $item->baselL2Enum()?->label() ?? '',
            (int) ($item->risks_count ?? 0),
        ])->values()->all();

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }
}
