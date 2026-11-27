<?php

namespace App\Services;

use App\Models\BusinessProcess;
use App\Models\Entity;
use App\Models\Risk;
use App\Models\RiskCause;
use App\Models\RiskLibraryItem;
use App\Models\RiskLibraryItemVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Domain service for the master Risk Library (ADR 0028).
 *
 * Enforces:
 * - Immutable version snapshots on every create/update.
 * - No silent propagation of edits to existing Risk instances.
 * - Retire/reactivate lifecycle without hard deletion.
 * - Explicit Accept/Dismiss review flow preserving all instance assessment data.
 */
class RiskLibraryService
{
    /**
     * Create a new master Risk Library definition (v1) + initial immutable snapshot.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): RiskLibraryItem
    {
        return DB::transaction(function () use ($data, $actor): RiskLibraryItem {
            $orgId = (int) ($actor->organization_id ?? TenantContext::organizationId());
            $code = ! empty($data['code'])
                ? trim((string) $data['code'])
                : $this->generateNextCode($orgId);

            $causes = $this->normaliseCauses($data['typical_causes'] ?? null);
            $consequences = $this->normaliseConsequences($data['typical_consequences'] ?? null);
            $controls = $this->normaliseSuggestedControls($data['suggested_controls'] ?? null);
            $kris = $this->normaliseSuggestedKris($data['suggested_kris'] ?? null);

            $item = RiskLibraryItem::create([
                'organization_id' => $orgId,
                'code' => $code,
                'title' => trim((string) $data['title']),
                'description' => trim((string) $data['description']),
                'category_id' => (int) $data['category_id'],
                'risk_source' => $data['risk_source'] ?? null,
                'basel_l1_event_type' => $data['basel_l1_event_type'] ?? null,
                'basel_l2_event_type' => $data['basel_l2_event_type'] ?? null,
                'typical_causes' => $causes,
                'typical_consequences' => $consequences,
                'suggested_controls' => $controls,
                'suggested_kris' => $kris,
                'current_version' => 1,
                'status' => RiskLibraryItem::STATUS_ACTIVE,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->recordVersionSnapshot($item, null, $actor);

            AuditTrailService::record(
                $item,
                'library_item_created',
                'all',
                null,
                [
                    'code' => $item->code,
                    'title' => $item->title,
                    'version' => 1,
                ],
                'Created master risk library definition v1.'
            );

            return $item;
        });
    }

    /**
     * Publish a new version (n+1) of an active Risk Library definition.
     *
     * Does NOT silently propagate changes to linked Risk instances (ADR 0028 Decision 4).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(RiskLibraryItem $item, array $data, User $actor): RiskLibraryItem
    {
        if ($item->isRetired()) {
            throw new LogicException('Cannot edit a retired risk library item. Reactivate it first.');
        }

        return DB::transaction(function () use ($item, $data, $actor): RiskLibraryItem {
            $previousVersion = (int) $item->current_version;
            $nextVersion = $previousVersion + 1;
            $changeSummary = trim((string) ($data['change_summary'] ?? ''));

            $before = [
                'version' => $previousVersion,
                'title' => $item->title,
                'category_id' => $item->category_id,
                'basel_l1_event_type' => $item->basel_l1_event_type,
                'basel_l2_event_type' => $item->basel_l2_event_type,
            ];

            $item->update([
                'title' => trim((string) $data['title']),
                'description' => trim((string) $data['description']),
                'category_id' => (int) $data['category_id'],
                'risk_source' => $data['risk_source'] ?? null,
                'basel_l1_event_type' => $data['basel_l1_event_type'] ?? null,
                'basel_l2_event_type' => $data['basel_l2_event_type'] ?? null,
                'typical_causes' => $this->normaliseCauses($data['typical_causes'] ?? null),
                'typical_consequences' => $this->normaliseConsequences($data['typical_consequences'] ?? null),
                'suggested_controls' => $this->normaliseSuggestedControls($data['suggested_controls'] ?? null),
                'suggested_kris' => $this->normaliseSuggestedKris($data['suggested_kris'] ?? null),
                'current_version' => $nextVersion,
                'updated_by' => $actor->id,
            ]);

            $this->recordVersionSnapshot($item, $changeSummary, $actor);

            $after = [
                'version' => $nextVersion,
                'title' => $item->title,
                'category_id' => $item->category_id,
                'basel_l1_event_type' => $item->basel_l1_event_type,
                'basel_l2_event_type' => $item->basel_l2_event_type,
            ];

            AuditTrailService::record(
                $item,
                'library_item_updated',
                'definition',
                $before,
                $after,
                $changeSummary
            );

            AuditTrailService::record(
                $item,
                'library_item_versioned',
                'current_version',
                (string) $previousVersion,
                (string) $nextVersion,
                $changeSummary
            );

            return $item->refresh();
        });
    }

    /**
     * Retire a Risk Library item without affecting existing linked Risk instances.
     */
    public function retire(RiskLibraryItem $item, string $reason, User $actor): RiskLibraryItem
    {
        return DB::transaction(function () use ($item, $reason, $actor): RiskLibraryItem {
            $trimmedReason = trim($reason);

            $item->update([
                'status' => RiskLibraryItem::STATUS_RETIRED,
                'retired_at' => now()->utc(),
                'retired_by' => $actor->id,
                'retirement_reason' => $trimmedReason,
                'updated_by' => $actor->id,
            ]);

            AuditTrailService::record(
                $item,
                'library_item_retired',
                'status',
                RiskLibraryItem::STATUS_ACTIVE,
                RiskLibraryItem::STATUS_RETIRED,
                $trimmedReason
            );

            return $item->refresh();
        });
    }

    /**
     * Reactivate a retired Risk Library item.
     */
    public function reactivate(RiskLibraryItem $item, User $actor): RiskLibraryItem
    {
        return DB::transaction(function () use ($item, $actor): RiskLibraryItem {
            $item->update([
                'status' => RiskLibraryItem::STATUS_ACTIVE,
                'retired_at' => null,
                'retired_by' => null,
                'retirement_reason' => null,
                'updated_by' => $actor->id,
            ]);

            AuditTrailService::record(
                $item,
                'library_item_reactivated',
                'status',
                RiskLibraryItem::STATUS_RETIRED,
                RiskLibraryItem::STATUS_ACTIVE,
                'Reactivated master risk library definition.'
            );

            return $item->refresh();
        });
    }

    /**
     * Advisory duplicate detection using plain SQL LIKE token matching
     * (MariaDB 10.4 ∩ MySQL 8.0.46 compatible) + PHP overlap scoring.
     *
     * @return list<array<string, mixed>>
     */
    public function findPotentialDuplicates(string $title, ?int $categoryId = null, ?int $excludeId = null): array
    {
        $normalised = self::normaliseTitle($title);
        if (mb_strlen($normalised) < 3) {
            return [];
        }

        $tokens = array_values(array_unique(array_filter(
            explode(' ', $normalised),
            static fn (string $token) => mb_strlen($token) >= 3
        )));

        if ($tokens === []) {
            $tokens = [$normalised];
        }

        $orgId = TenantContext::organizationId();

        $query = RiskLibraryItem::query()
            ->with('category:id,name')
            ->where('organization_id', $orgId);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $query->where(function ($q) use ($tokens): void {
            foreach (array_slice($tokens, 0, 8) as $token) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token);
                $q->orWhere('title', 'LIKE', '%'.$escaped.'%');
            }
        });

        $candidates = $query->limit(50)->get([
            'id',
            'code',
            'title',
            'category_id',
            'status',
            'current_version',
        ]);

        $scored = [];
        foreach ($candidates as $candidate) {
            $candidateNorm = self::normaliseTitle($candidate->title);
            $candidateTokens = array_values(array_unique(array_filter(
                explode(' ', $candidateNorm),
                static fn (string $token) => mb_strlen($token) >= 3
            )));

            $overlap = count(array_intersect($tokens, $candidateTokens));
            $union = count(array_unique(array_merge($tokens, $candidateTokens)));
            $score = $union > 0 ? ($overlap / $union) : 0.0;

            if ($candidateNorm === $normalised) {
                $score += 1.0;
            } elseif (str_contains($candidateNorm, $normalised) || str_contains($normalised, $candidateNorm)) {
                $score += 0.4;
            }

            if ($categoryId !== null && (int) $candidate->category_id === $categoryId) {
                $score += 0.15;
            }

            if ($score > 0.0) {
                $scored[] = [
                    'score' => $score,
                    'item' => $candidate,
                ];
            }
        }

        usort($scored, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_map(static function (array $entry): array {
            /** @var RiskLibraryItem $item */
            $item = $entry['item'];

            return [
                'id' => $item->id,
                'code' => $item->code,
                'title' => $item->title,
                'category_name' => $item->category?->name,
                'status' => $item->status,
                'current_version' => $item->current_version,
                'show_url' => route('risk.library.show', $item),
            ];
        }, array_slice($scored, 0, 5));
    }

    /**
     * Instantiate a single independent Risk record from a master RiskLibraryItem.
     *
     * Copies statement fields per ADR 0028 Decision 4.1; leaves all score columns NULL.
     *
     * @param  array{entity_id?: ?int, business_unit_id?: ?int, process_id?: ?int, risk_owner_id?: ?int}  $target
     */
    public function attachToTarget(RiskLibraryItem $item, array $target, User $actor): Risk
    {
        if ($item->isRetired()) {
            throw new LogicException('Retired risk library items cannot be instantiated onto new targets.');
        }

        return DB::transaction(function () use ($item, $target, $actor): Risk {
            $orgId = (int) $item->organization_id;
            $riskCode = ReferenceCodeService::generate('risks', 'risk_code', 'RK', 4, $orgId);

            $risk = Risk::create([
                'organization_id' => $orgId,
                'risk_code' => $riskCode,
                'title' => $item->title,
                'description' => $item->description,
                'category_id' => $item->category_id,
                'library_item_id' => $item->id,
                'library_version' => $item->current_version,
                'library_synced_at' => now()->utc(),
                'library_update_dismissed_version' => null,
                'entity_id' => $target['entity_id'] ?? null,
                'business_unit_id' => $target['business_unit_id'] ?? null,
                'process_id' => $target['process_id'] ?? null,
                'risk_owner_id' => $target['risk_owner_id'] ?? null,
                'risk_source' => $item->risk_source ? Str::limit($item->risk_source, 20, '') : 'internal',
                'basel_l1_event_type' => $item->basel_l1_event_type,
                'basel_l2_event_type' => $item->basel_l2_event_type,
                'consequences' => $item->typical_consequences ?? null,
                'status' => 'active',
                'date_identified' => now()->toDateString(),
                'identified_by' => $actor->id,
                'created_by' => $actor->id,
            ]);

            $this->seedCausesFromLibrary($risk, $item->typical_causes ?? [], $actor);

            AuditTrailService::record(
                $risk,
                'risk_instantiated_from_library',
                'library_item_id',
                null,
                [
                    'library_item_id' => $item->id,
                    'library_code' => $item->code,
                    'library_version' => $item->current_version,
                    'entity_id' => $risk->entity_id,
                    'process_id' => $risk->process_id,
                ],
                "Instantiated from Risk Library {$item->code} (v{$item->current_version})."
            );

            return $risk;
        });
    }

    /**
     * Attach 1..N active Risk Library items to a single Entity, skipping
     * items already attached to that entity (and optional process) gracefully.
     *
     * @param  list<int>  $libraryItemIds
     * @param  array{process_id?: ?int, risk_owner_id?: ?int, business_unit_id?: ?int}  $options
     * @return array{created_count: int, skipped_count: int, created_ids: list<int>}
     */
    public function attachManyToEntity(
        Entity $entity,
        array $libraryItemIds,
        array $options,
        User $actor
    ): array {
        $orgId = (int) $entity->organization_id;
        $uniqueIds = array_values(array_unique(array_map('intval', $libraryItemIds)));
        $processId = isset($options['process_id']) && $options['process_id'] !== ''
            ? (int) $options['process_id']
            : null;
        $ownerId = isset($options['risk_owner_id']) && $options['risk_owner_id'] !== ''
            ? (int) $options['risk_owner_id']
            : null;
        $businessUnitId = isset($options['business_unit_id']) && $options['business_unit_id'] !== ''
            ? (int) $options['business_unit_id']
            : null;

        return DB::transaction(function () use ($entity, $orgId, $uniqueIds, $processId, $ownerId, $businessUnitId, $actor): array {
            $items = RiskLibraryItem::query()
                ->where('organization_id', $orgId)
                ->active()
                ->whereIn('id', $uniqueIds)
                ->orderBy('code')
                ->get();

            $existingQuery = Risk::query()
                ->where('organization_id', $orgId)
                ->where('entity_id', $entity->id)
                ->whereIn('library_item_id', $items->pluck('id')->all());

            if ($processId !== null) {
                $existingQuery->where('process_id', $processId);
            }

            $alreadyAttached = $existingQuery
                ->pluck('library_item_id')
                ->flip()
                ->all();

            $createdIds = [];
            $skippedCount = 0;

            foreach ($items as $item) {
                if (isset($alreadyAttached[$item->id])) {
                    $skippedCount++;

                    continue;
                }

                $risk = $this->attachToTarget($item, [
                    'entity_id' => $entity->id,
                    'process_id' => $processId,
                    'risk_owner_id' => $ownerId,
                    'business_unit_id' => $businessUnitId,
                ], $actor);

                $createdIds[] = $risk->id;
            }

            return [
                'created_count' => count($createdIds),
                'skipped_count' => $skippedCount,
                'created_ids' => $createdIds,
            ];
        });
    }

    /**
     * Dry-run preview for Bulk Apply (1..N active items x 1..N entities or processes).
     *
     * @param  list<int>  $libraryItemIds
     * @param  list<int>  $entityIds
     * @param  list<int>  $processIds
     * @return array{
     *   summary: array{new_count: int, skip_count: int, total_pairs: int},
     *   will_create: list<array<string, mixed>>,
     *   will_skip: list<array<string, mixed>>
     * }
     */
    public function previewBulkApply(
        array $libraryItemIds,
        string $targetMode,
        array $entityIds = [],
        array $processIds = []
    ): array {
        $orgId = TenantContext::organizationId();

        $items = RiskLibraryItem::query()
            ->where('organization_id', $orgId)
            ->active()
            ->whereIn('id', $libraryItemIds)
            ->orderBy('code')
            ->get(['id', 'code', 'title', 'current_version']);

        $targets = $targetMode === 'processes'
            ? BusinessProcess::query()
                ->where('organization_id', $orgId)
                ->whereIn('id', $processIds)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'business_unit_id'])
            : Entity::query()
                ->where('organization_id', $orgId)
                ->whereIn('id', $entityIds)
                ->orderBy('name')
                ->get(['id', 'entity_code', 'name']);

        $targetIds = $targets->pluck('id')->all();
        $itemIds = $items->pluck('id')->all();

        $existingMap = [];
        if ($itemIds !== [] && $targetIds !== []) {
            $targetColumn = $targetMode === 'processes' ? 'process_id' : 'entity_id';
            $existing = Risk::query()
                ->where('organization_id', $orgId)
                ->whereIn('library_item_id', $itemIds)
                ->whereIn($targetColumn, $targetIds)
                ->get(['id', 'risk_code', 'library_item_id', $targetColumn]);

            foreach ($existing as $risk) {
                $key = $risk->library_item_id.':'.$risk->{$targetColumn};
                $existingMap[$key] = $risk->risk_code;
            }
        }

        $willCreate = [];
        $willSkip = [];
        $targetType = $targetMode === 'processes' ? 'process' : 'entity';

        foreach ($items as $item) {
            foreach ($targets as $target) {
                $key = $item->id.':'.$target->id;
                if (isset($existingMap[$key])) {
                    $willSkip[] = [
                        'library_item_id' => $item->id,
                        'library_code' => $item->code,
                        'library_title' => $item->title,
                        'target_type' => $targetType,
                        'target_id' => $target->id,
                        'target_name' => $target->name,
                        'existing_risk_code' => $existingMap[$key],
                        'reason' => 'Already attached',
                    ];
                } else {
                    $willCreate[] = [
                        'library_item_id' => $item->id,
                        'library_code' => $item->code,
                        'library_title' => $item->title,
                        'target_type' => $targetType,
                        'target_id' => $target->id,
                        'target_name' => $target->name,
                    ];
                }
            }
        }

        return [
            'summary' => [
                'new_count' => count($willCreate),
                'skip_count' => count($willSkip),
                'total_pairs' => count($willCreate) + count($willSkip),
            ],
            'will_create' => $willCreate,
            'will_skip' => $willSkip,
        ];
    }

    /**
     * Atomically apply 1..N active library items to 1..N entities or processes,
     * skipping any pair that is already attached.
     *
     * @param  list<int>  $libraryItemIds
     * @param  list<int>  $entityIds
     * @param  list<int>  $processIds
     * @return array{created_count: int, skipped_count: int, created_ids: list<int>}
     */
    public function bulkApply(
        array $libraryItemIds,
        string $targetMode,
        array $entityIds,
        array $processIds,
        ?int $defaultOwnerId,
        User $actor
    ): array {
        return DB::transaction(function () use (
            $libraryItemIds,
            $targetMode,
            $entityIds,
            $processIds,
            $defaultOwnerId,
            $actor
        ): array {
            $preview = $this->previewBulkApply($libraryItemIds, $targetMode, $entityIds, $processIds);
            $orgId = (int) ($actor->organization_id ?? TenantContext::organizationId());

            $itemsById = RiskLibraryItem::query()
                ->where('organization_id', $orgId)
                ->active()
                ->whereIn('id', $libraryItemIds)
                ->get()
                ->keyBy('id');

            $processesById = $targetMode === 'processes'
                ? BusinessProcess::query()
                    ->where('organization_id', $orgId)
                    ->whereIn('id', $processIds)
                    ->get()
                    ->keyBy('id')
                : collect();

            $createdIds = [];

            foreach ($preview['will_create'] as $pair) {
                /** @var RiskLibraryItem|null $item */
                $item = $itemsById->get($pair['library_item_id']);
                if ($item === null) {
                    continue;
                }

                $target = [
                    'risk_owner_id' => $defaultOwnerId,
                ];

                if ($targetMode === 'processes') {
                    /** @var BusinessProcess|null $process */
                    $process = $processesById->get($pair['target_id']);
                    $target['process_id'] = (int) $pair['target_id'];
                    $target['business_unit_id'] = $process?->business_unit_id;
                } else {
                    $target['entity_id'] = (int) $pair['target_id'];
                }

                $risk = $this->attachToTarget($item, $target, $actor);
                $createdIds[] = $risk->id;
            }

            return [
                'created_count' => count($createdIds),
                'skipped_count' => $preview['summary']['skip_count'],
                'created_ids' => $createdIds,
            ];
        });
    }

    /**
     * Explicitly accept the latest Risk Library version on a Risk instance.
     *
     * Updates ONLY copied statement fields; NEVER overwrites scores, owner,
     * controls, treatments, or review dates (ADR 0028 Decision 4.2).
     */
    public function acceptLibraryUpdate(Risk $risk, User $actor): Risk
    {
        $item = $risk->libraryItem;
        if ($item === null) {
            throw new LogicException('This risk is not linked to a Risk Library item.');
        }

        return DB::transaction(function () use ($risk, $item, $actor): Risk {
            $previousVersion = $risk->library_version;

            $before = [
                'library_version' => $previousVersion,
                'title' => $risk->title,
                'description' => $risk->description,
                'category_id' => $risk->category_id,
                'risk_source' => $risk->risk_source,
                'basel_l1_event_type' => $risk->basel_l1_event_type,
                'basel_l2_event_type' => $risk->basel_l2_event_type,
                'consequences' => $risk->consequences,
            ];

            $risk->update([
                'title' => $item->title,
                'description' => $item->description,
                'category_id' => $item->category_id,
                'risk_source' => $item->risk_source ? Str::limit($item->risk_source, 20, '') : $risk->risk_source,
                'basel_l1_event_type' => $item->basel_l1_event_type,
                'basel_l2_event_type' => $item->basel_l2_event_type,
                'consequences' => $item->typical_consequences ?? null,
                'library_version' => $item->current_version,
                'library_synced_at' => now()->utc(),
                'library_update_dismissed_version' => null,
            ]);

            $this->appendMissingCausesFromLibrary($risk, $item->typical_causes ?? [], $actor);

            $after = [
                'library_version' => $item->current_version,
                'title' => $risk->title,
                'description' => $risk->description,
                'category_id' => $risk->category_id,
                'risk_source' => $risk->risk_source,
                'basel_l1_event_type' => $risk->basel_l1_event_type,
                'basel_l2_event_type' => $risk->basel_l2_event_type,
                'consequences' => $risk->consequences,
            ];

            AuditTrailService::record(
                $risk,
                'library_update_accepted',
                'library_version',
                $before,
                $after,
                "Accepted Risk Library {$item->code} update from v{$previousVersion} to v{$item->current_version}."
            );

            return $risk->refresh();
        });
    }

    /**
     * Dismiss the pending library update banner for the current library version only.
     * Banner will automatically reappear if the library item advances to current_version + 1.
     */
    public function dismissLibraryUpdate(Risk $risk, User $actor): Risk
    {
        $item = $risk->libraryItem;
        if ($item === null) {
            throw new LogicException('This risk is not linked to a Risk Library item.');
        }

        return DB::transaction(function () use ($risk, $item): Risk {
            $risk->update([
                'library_update_dismissed_version' => $item->current_version,
            ]);

            AuditTrailService::record(
                $risk,
                'library_update_dismissed',
                'library_update_dismissed_version',
                (string) ($risk->library_version ?? ''),
                (string) $item->current_version,
                "Dismissed Risk Library {$item->code} v{$item->current_version} update; kept instance at v{$risk->library_version}."
            );

            return $risk->refresh();
        });
    }

    /**
     * Normalize a risk title for duplicate detection and cluster seeding.
     */
    public static function normaliseTitle(string $title): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/', ' ', $title)));
    }

    /**
     * Generate the next unique RL-NNNN code for the organization.
     */
    public function generateNextCode(int $orgId): string
    {
        $existingCodes = RiskLibraryItem::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('code', 'LIKE', 'RL-%')
            ->pluck('code');

        $max = 0;
        foreach ($existingCodes as $code) {
            if (preg_match('/^RL-(\d+)$/', (string) $code, $matches)) {
                $num = (int) $matches[1];
                if ($num > $max) {
                    $max = $num;
                }
            }
        }

        $next = $max + 1;
        do {
            $candidate = sprintf('RL-%04d', $next);
            $exists = RiskLibraryItem::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('code', $candidate)
                ->exists();
            $next++;
        } while ($exists);

        return $candidate;
    }

    /**
     * Write an immutable snapshot row into risk_library_item_versions.
     */
    public function recordVersionSnapshot(RiskLibraryItem $item, ?string $changeSummary, ?User $actor): RiskLibraryItemVersion
    {
        return RiskLibraryItemVersion::create([
            'organization_id' => $item->organization_id,
            'library_item_id' => $item->id,
            'version_number' => $item->current_version,
            'title' => $item->title,
            'description' => $item->description,
            'category_id' => $item->category_id,
            'risk_source' => $item->risk_source,
            'basel_l1_event_type' => $item->basel_l1_event_type,
            'basel_l2_event_type' => $item->basel_l2_event_type,
            'typical_causes' => $item->typical_causes,
            'typical_consequences' => $item->typical_consequences,
            'suggested_controls' => $item->suggested_controls,
            'suggested_kris' => $item->suggested_kris,
            'change_summary' => $changeSummary !== '' ? $changeSummary : null,
            'created_by' => $actor?->id,
            'created_at' => now()->utc(),
        ]);
    }

    /**
     * @param  array<int, mixed>|null  $items
     * @return list<array{cause: string, cause_category_id: ?int}>|null
     */
    private function normaliseCauses(?array $items): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        $clean = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cause = trim((string) ($row['cause'] ?? ''));
            if ($cause === '') {
                continue;
            }
            $clean[] = [
                'cause' => $cause,
                'cause_category_id' => isset($row['cause_category_id']) && $row['cause_category_id'] !== ''
                    ? (int) $row['cause_category_id']
                    : null,
            ];
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  array<int, mixed>|null  $items
     * @return list<array{consequence: string, impact_domain: ?string}>|null
     */
    private function normaliseConsequences(?array $items): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        $clean = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $consequence = trim((string) ($row['consequence'] ?? ''));
            if ($consequence === '') {
                continue;
            }
            $domain = isset($row['impact_domain']) ? trim((string) $row['impact_domain']) : null;
            $clean[] = [
                'consequence' => $consequence,
                'impact_domain' => $domain !== '' ? $domain : null,
            ];
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  array<int, mixed>|null  $items
     * @return list<array{title: string, control_id: ?int, control_type: ?string}>|null
     */
    private function normaliseSuggestedControls(?array $items): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        $clean = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $clean[] = [
                'title' => $title,
                'control_id' => isset($row['control_id']) && $row['control_id'] !== ''
                    ? (int) $row['control_id']
                    : null,
                'control_type' => isset($row['control_type']) && $row['control_type'] !== ''
                    ? (string) $row['control_type']
                    : null,
            ];
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  array<int, mixed>|null  $items
     * @return list<array{name: string, kri_id: ?int, metric_unit: ?string}>|null
     */
    private function normaliseSuggestedKris(?array $items): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        $clean = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $unit = isset($row['metric_unit']) ? trim((string) $row['metric_unit']) : null;
            $clean[] = [
                'name' => $name,
                'kri_id' => isset($row['kri_id']) && $row['kri_id'] !== ''
                    ? (int) $row['kri_id']
                    : null,
                'metric_unit' => $unit !== '' ? $unit : null,
            ];
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  list<array{cause: string, cause_category_id: ?int}>  $typicalCauses
     */
    private function seedCausesFromLibrary(Risk $risk, array $typicalCauses, User $actor): void
    {
        foreach (array_values($typicalCauses) as $index => $causeRow) {
            $description = trim((string) ($causeRow['cause'] ?? ''));
            if ($description === '') {
                continue;
            }

            RiskCause::create([
                'organization_id' => $risk->organization_id,
                'risk_id' => $risk->id,
                'cause_category_id' => $causeRow['cause_category_id'] ?? null,
                'description' => $description,
                'source' => 'workshop',
                'is_primary' => $index === 0,
                'sort_order' => $index + 1,
                'created_by' => $actor->id,
            ]);
        }
    }

    /**
     * @param  list<array{cause: string, cause_category_id: ?int}>  $typicalCauses
     */
    private function appendMissingCausesFromLibrary(Risk $risk, array $typicalCauses, User $actor): void
    {
        if ($typicalCauses === []) {
            return;
        }

        $existing = $risk->causes()
            ->pluck('description')
            ->map(static fn ($d) => self::normaliseTitle((string) $d))
            ->flip();

        $nextOrder = (int) ($risk->causes()->max('sort_order') ?? 0);

        foreach ($typicalCauses as $causeRow) {
            $description = trim((string) ($causeRow['cause'] ?? ''));
            if ($description === '' || $existing->has(self::normaliseTitle($description))) {
                continue;
            }

            $nextOrder++;
            RiskCause::create([
                'organization_id' => $risk->organization_id,
                'risk_id' => $risk->id,
                'cause_category_id' => $causeRow['cause_category_id'] ?? null,
                'description' => $description,
                'source' => 'workshop',
                'is_primary' => false,
                'sort_order' => $nextOrder,
                'created_by' => $actor->id,
            ]);
        }
    }
}
