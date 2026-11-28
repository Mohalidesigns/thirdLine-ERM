<?php

namespace App\Services;

use App\Models\ProcessGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Domain service for Process Groups (ADR 0028 Phase 2).
 */
class ProcessGroupService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): ProcessGroup
    {
        return DB::transaction(function () use ($data, $actor): ProcessGroup {
            $orgId = (int) ($actor->organization_id ?? TenantContext::organizationId());
            $code = ! empty($data['code'])
                ? trim((string) $data['code'])
                : $this->generateNextCode($orgId);

            $group = ProcessGroup::create([
                'organization_id' => $orgId,
                'business_unit_id' => isset($data['business_unit_id']) && $data['business_unit_id'] !== ''
                    ? (int) $data['business_unit_id']
                    : null,
                'code' => $code,
                'name' => trim((string) $data['name']),
                'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                    ? trim((string) $data['description'])
                    : null,
                'apqc_code' => isset($data['apqc_code']) && trim((string) $data['apqc_code']) !== ''
                    ? trim((string) $data['apqc_code'])
                    : null,
                'owner_id' => isset($data['owner_id']) && $data['owner_id'] !== ''
                    ? (int) $data['owner_id']
                    : null,
                'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : 0,
                'status' => $data['status'] ?? ProcessGroup::STATUS_ACTIVE,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            AuditTrailService::record(
                $group,
                'process_group_created',
                'all',
                null,
                [
                    'code' => $group->code,
                    'name' => $group->name,
                    'business_unit_id' => $group->business_unit_id,
                    'apqc_code' => $group->apqc_code,
                    'status' => $group->status,
                ],
                "Created process group {$group->code} — {$group->name}."
            );

            return $group;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProcessGroup $group, array $data, User $actor): ProcessGroup
    {
        return DB::transaction(function () use ($group, $data, $actor): ProcessGroup {
            $before = [
                'code' => $group->code,
                'name' => $group->name,
                'business_unit_id' => $group->business_unit_id,
                'apqc_code' => $group->apqc_code,
                'owner_id' => $group->owner_id,
                'sort_order' => $group->sort_order,
                'status' => $group->status,
            ];

            $payload = [
                'name' => trim((string) $data['name']),
                'description' => array_key_exists('description', $data)
                    ? (trim((string) ($data['description'] ?? '')) !== '' ? trim((string) $data['description']) : null)
                    : $group->description,
                'business_unit_id' => array_key_exists('business_unit_id', $data)
                    ? ($data['business_unit_id'] !== null && $data['business_unit_id'] !== '' ? (int) $data['business_unit_id'] : null)
                    : $group->business_unit_id,
                'apqc_code' => array_key_exists('apqc_code', $data)
                    ? (trim((string) ($data['apqc_code'] ?? '')) !== '' ? trim((string) $data['apqc_code']) : null)
                    : $group->apqc_code,
                'owner_id' => array_key_exists('owner_id', $data)
                    ? ($data['owner_id'] !== null && $data['owner_id'] !== '' ? (int) $data['owner_id'] : null)
                    : $group->owner_id,
                'sort_order' => array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : $group->sort_order,
                'status' => $data['status'] ?? $group->status,
                'updated_by' => $actor->id,
            ];

            if (! empty($data['code'])) {
                $payload['code'] = trim((string) $data['code']);
            }

            $group->update($payload);

            $after = [
                'code' => $group->code,
                'name' => $group->name,
                'business_unit_id' => $group->business_unit_id,
                'apqc_code' => $group->apqc_code,
                'owner_id' => $group->owner_id,
                'sort_order' => $group->sort_order,
                'status' => $group->status,
            ];

            AuditTrailService::record(
                $group,
                'process_group_updated',
                'all',
                $before,
                $after,
                "Updated process group {$group->code} — {$group->name}."
            );

            return $group->refresh();
        });
    }

    public function deactivate(ProcessGroup $group, User $actor): ProcessGroup
    {
        return DB::transaction(function () use ($group, $actor): ProcessGroup {
            $previous = $group->status;

            $group->update([
                'status' => ProcessGroup::STATUS_INACTIVE,
                'updated_by' => $actor->id,
            ]);

            AuditTrailService::record(
                $group,
                'process_group_deactivated',
                'status',
                $previous,
                ProcessGroup::STATUS_INACTIVE,
                "Deactivated process group {$group->code} — {$group->name}."
            );

            return $group->refresh();
        });
    }

    public function reactivate(ProcessGroup $group, User $actor): ProcessGroup
    {
        return DB::transaction(function () use ($group, $actor): ProcessGroup {
            $previous = $group->status;

            $group->update([
                'status' => ProcessGroup::STATUS_ACTIVE,
                'updated_by' => $actor->id,
            ]);

            AuditTrailService::record(
                $group,
                'process_group_reactivated',
                'status',
                $previous,
                ProcessGroup::STATUS_ACTIVE,
                "Reactivated process group {$group->code} — {$group->name}."
            );

            return $group->refresh();
        });
    }

    public function generateNextCode(int $orgId): string
    {
        $existingCodes = ProcessGroup::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('organization_id', $orgId)
            ->where('code', 'LIKE', 'PG-%')
            ->pluck('code');

        $max = 0;
        foreach ($existingCodes as $code) {
            if (preg_match('/^PG-(\d+)$/', (string) $code, $matches)) {
                $num = (int) $matches[1];
                if ($num > $max) {
                    $max = $num;
                }
            }
        }

        $next = $max + 1;
        do {
            $candidate = sprintf('PG-%03d', $next);
            $exists = ProcessGroup::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->where('organization_id', $orgId)
                ->where('code', $candidate)
                ->exists();
            $next++;
        } while ($exists);

        return $candidate;
    }
}
