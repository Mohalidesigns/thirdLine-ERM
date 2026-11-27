<?php

namespace App\Services;

use App\Models\BusinessProcess;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Domain service for Business Processes & Sub-Processes (ADR 0028 Phase 2).
 *
 * Enforces the RCSA v2 two-level invariant inside `business_processes`:
 * - Top-level Process: `parent_id IS NULL`.
 * - Sub-Process: `parent_id` MUST reference a top-level Process (`parent_id IS NULL`)
 *   in the same organization. Never 3-deep, never cyclic.
 * - Never mutates `bcms_processes` (BCMS overlay per ADR 0001).
 */
class ProcessService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): BusinessProcess
    {
        return DB::transaction(function () use ($data, $actor): BusinessProcess {
            $orgId = (int) ($actor->organization_id ?? TenantContext::organizationId());
            $level = (string) ($data['level'] ?? (! empty($data['parent_id']) ? 'sub_process' : 'process'));
            $parentId = $level === 'sub_process'
                ? (isset($data['parent_id']) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null)
                : null;

            $parent = null;
            if ($level === 'sub_process') {
                if ($parentId === null) {
                    throw new InvalidArgumentException('A sub-process must belong to a top-level parent process.');
                }
                $parent = $this->resolveValidParent($parentId, $orgId);
            }

            $processGroupId = isset($data['process_group_id']) && $data['process_group_id'] !== ''
                ? (int) $data['process_group_id']
                : ($parent?->process_group_id);

            $businessUnitId = isset($data['business_unit_id']) && $data['business_unit_id'] !== ''
                ? (int) $data['business_unit_id']
                : ($parent?->business_unit_id);

            $code = ! empty($data['code'])
                ? trim((string) $data['code'])
                : $this->generateNextCode($orgId, $parentId !== null ? 'SUB' : 'PRC');

            $process = BusinessProcess::create([
                'organization_id' => $orgId,
                'business_unit_id' => $businessUnitId,
                'process_group_id' => $processGroupId,
                'parent_id' => $parentId,
                'code' => $code,
                'apqc_code' => isset($data['apqc_code']) && trim((string) $data['apqc_code']) !== ''
                    ? trim((string) $data['apqc_code'])
                    : null,
                'name' => trim((string) $data['name']),
                'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                    ? trim((string) $data['description'])
                    : null,
                'owner_id' => isset($data['owner_id']) && $data['owner_id'] !== ''
                    ? (int) $data['owner_id']
                    : null,
                'criticality' => isset($data['criticality']) && $data['criticality'] !== ''
                    ? (string) $data['criticality']
                    : null,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ]);

            AuditTrailService::record(
                $process,
                'process_created',
                'all',
                null,
                [
                    'code' => $process->code,
                    'name' => $process->name,
                    'parent_id' => $process->parent_id,
                    'process_group_id' => $process->process_group_id,
                    'business_unit_id' => $process->business_unit_id,
                    'criticality' => $process->criticality,
                ],
                sprintf(
                    'Created %s %s — %s.',
                    $process->parent_id ? 'sub-process' : 'business process',
                    $process->code,
                    $process->name
                )
            );

            if (! empty($data['entity_id'])) {
                $entity = Entity::query()
                    ->where('organization_id', $orgId)
                    ->find((int) $data['entity_id']);

                if ($entity !== null) {
                    $this->attachToEntity($entity, $process, $actor, isPrimary: true);
                }
            }

            return $process;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BusinessProcess $process, array $data, User $actor): BusinessProcess
    {
        return DB::transaction(function () use ($process, $data): BusinessProcess {
            $orgId = (int) $process->organization_id;

            $level = (string) ($data['level'] ?? (
                array_key_exists('parent_id', $data)
                    ? (! empty($data['parent_id']) ? 'sub_process' : 'process')
                    : ($process->parent_id !== null ? 'sub_process' : 'process')
            ));

            // An update that does not mention `parent_id` keeps the current parent:
            // a partial edit (name, owner, criticality) of a sub-process must not
            // read as "move this sub-process to no parent" and fail.
            $targetParentId = $level === 'sub_process'
                ? (array_key_exists('parent_id', $data)
                    ? ($data['parent_id'] !== null && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null)
                    : ($process->parent_id !== null ? (int) $process->parent_id : null))
                : null;

            if ($level === 'sub_process') {
                if ($targetParentId === null) {
                    throw new InvalidArgumentException('A sub-process must belong to a top-level parent process.');
                }
                if ($targetParentId === (int) $process->id) {
                    throw new InvalidArgumentException('A process cannot be its own parent.');
                }
                if ($process->subProcesses()->exists()) {
                    throw new InvalidArgumentException('Cannot convert a process to a sub-process while child sub-processes exist.');
                }
                $this->resolveValidParent($targetParentId, $orgId);
            }

            $before = [
                'code' => $process->code,
                'name' => $process->name,
                'parent_id' => $process->parent_id,
                'process_group_id' => $process->process_group_id,
                'business_unit_id' => $process->business_unit_id,
                'owner_id' => $process->owner_id,
                'criticality' => $process->criticality,
                'is_active' => $process->is_active,
            ];

            $payload = [
                'parent_id' => $targetParentId,
                'name' => trim((string) $data['name']),
                'process_group_id' => array_key_exists('process_group_id', $data)
                    ? ($data['process_group_id'] !== null && $data['process_group_id'] !== '' ? (int) $data['process_group_id'] : null)
                    : $process->process_group_id,
                'business_unit_id' => array_key_exists('business_unit_id', $data)
                    ? ($data['business_unit_id'] !== null && $data['business_unit_id'] !== '' ? (int) $data['business_unit_id'] : null)
                    : $process->business_unit_id,
                'apqc_code' => array_key_exists('apqc_code', $data)
                    ? (trim((string) ($data['apqc_code'] ?? '')) !== '' ? trim((string) $data['apqc_code']) : null)
                    : $process->apqc_code,
                'description' => array_key_exists('description', $data)
                    ? (trim((string) ($data['description'] ?? '')) !== '' ? trim((string) $data['description']) : null)
                    : $process->description,
                'owner_id' => array_key_exists('owner_id', $data)
                    ? ($data['owner_id'] !== null && $data['owner_id'] !== '' ? (int) $data['owner_id'] : null)
                    : $process->owner_id,
                'criticality' => array_key_exists('criticality', $data)
                    ? ($data['criticality'] !== null && $data['criticality'] !== '' ? (string) $data['criticality'] : null)
                    : $process->criticality,
                'is_active' => array_key_exists('is_active', $data)
                    ? (bool) $data['is_active']
                    : $process->is_active,
            ];

            if (! empty($data['code'])) {
                $payload['code'] = trim((string) $data['code']);
            }

            $process->update($payload);

            $after = [
                'code' => $process->code,
                'name' => $process->name,
                'parent_id' => $process->parent_id,
                'process_group_id' => $process->process_group_id,
                'business_unit_id' => $process->business_unit_id,
                'owner_id' => $process->owner_id,
                'criticality' => $process->criticality,
                'is_active' => $process->is_active,
            ];

            AuditTrailService::record(
                $process,
                'process_updated',
                'all',
                $before,
                $after,
                "Updated business process {$process->code} — {$process->name}."
            );

            return $process->refresh();
        });
    }

    public function deactivate(BusinessProcess $process, User $actor): BusinessProcess
    {
        return DB::transaction(function () use ($process): BusinessProcess {
            $process->update(['is_active' => false]);

            AuditTrailService::record(
                $process,
                'process_deactivated',
                'is_active',
                'true',
                'false',
                "Deactivated business process {$process->code} — {$process->name}."
            );

            return $process->refresh();
        });
    }

    public function reactivate(BusinessProcess $process, User $actor): BusinessProcess
    {
        return DB::transaction(function () use ($process): BusinessProcess {
            $process->update(['is_active' => true]);

            AuditTrailService::record(
                $process,
                'process_reactivated',
                'is_active',
                'false',
                'true',
                "Reactivated business process {$process->code} — {$process->name}."
            );

            return $process->refresh();
        });
    }

    public function attachToEntity(Entity $entity, BusinessProcess $process, User $actor, bool $isPrimary = false): void
    {
        if ((int) $entity->organization_id !== (int) $process->organization_id) {
            throw new InvalidArgumentException('Entity and business process must belong to the same organization.');
        }

        DB::transaction(function () use ($entity, $process, $actor, $isPrimary): void {
            $orgId = (int) $process->organization_id;

            $exists = DB::table('entity_business_process')
                ->where('organization_id', $orgId)
                ->where('entity_id', $entity->id)
                ->where('business_process_id', $process->id)
                ->exists();

            if ($exists) {
                return;
            }

            $now = now()->utc();

            DB::table('entity_business_process')->insert([
                'organization_id' => $orgId,
                'entity_id' => $entity->id,
                'business_process_id' => $process->id,
                'is_primary' => $isPrimary,
                'attached_by' => $actor->id,
                'attached_at' => $now,
                'created_at' => $now,
            ]);

            AuditTrailService::record(
                $process,
                'process_attached_to_entity',
                'entity_id',
                null,
                [
                    'entity_id' => $entity->id,
                    'entity_code' => $entity->entity_code,
                    'entity_name' => $entity->name,
                    'is_primary' => $isPrimary,
                ],
                "Attached process {$process->code} to entity {$entity->entity_code} — {$entity->name}."
            );
        });
    }

    public function detachFromEntity(Entity $entity, BusinessProcess $process, User $actor): void
    {
        if ((int) $entity->organization_id !== (int) $process->organization_id) {
            throw new InvalidArgumentException('Entity and business process must belong to the same organization.');
        }

        DB::transaction(function () use ($entity, $process): void {
            $deleted = DB::table('entity_business_process')
                ->where('organization_id', (int) $process->organization_id)
                ->where('entity_id', $entity->id)
                ->where('business_process_id', $process->id)
                ->delete();

            if ($deleted > 0) {
                AuditTrailService::record(
                    $process,
                    'process_detached_from_entity',
                    'entity_id',
                    [
                        'entity_id' => $entity->id,
                        'entity_code' => $entity->entity_code,
                        'entity_name' => $entity->name,
                    ],
                    null,
                    "Detached process {$process->code} from entity {$entity->entity_code} — {$entity->name}."
                );
            }
        });
    }

    public function generateNextCode(int $orgId, string $prefix = 'PRC'): string
    {
        $existingCodes = BusinessProcess::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('organization_id', $orgId)
            ->where('code', 'LIKE', $prefix.'-%')
            ->pluck('code');

        $max = 0;
        $pattern = '/^'.preg_quote($prefix, '/').'-(\d+)$/';
        foreach ($existingCodes as $code) {
            if (preg_match($pattern, (string) $code, $matches)) {
                $num = (int) $matches[1];
                if ($num > $max) {
                    $max = $num;
                }
            }
        }

        $next = $max + 1;
        do {
            $candidate = sprintf('%s-%04d', $prefix, $next);
            $exists = BusinessProcess::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->where('organization_id', $orgId)
                ->where('code', $candidate)
                ->exists();
            $next++;
        } while ($exists);

        return $candidate;
    }

    private function resolveValidParent(int $parentId, int $orgId): BusinessProcess
    {
        $parent = BusinessProcess::query()
            ->where('organization_id', $orgId)
            ->whereKey($parentId)
            ->first();

        if ($parent === null) {
            throw new InvalidArgumentException('Selected parent process does not exist in this organization.');
        }

        if ($parent->parent_id !== null) {
            throw new InvalidArgumentException('A sub-process cannot be nested under another sub-process (maximum 2 levels).');
        }

        return $parent;
    }
}
