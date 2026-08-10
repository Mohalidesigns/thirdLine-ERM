<?php

namespace App\Services;

use App\Models\RiskAuditTrail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

class AuditTrailService
{
    /**
     * Record a change in the audit trail
     */
    public static function record(
        Model $entity,
        string $actionType,
        ?string $fieldChanged = null,
        $oldValue = null,
        $newValue = null,
        ?string $reason = null
    ): void {
        RiskAuditTrail::create([
            // The audited row's own organization is authoritative; fall back to
            // the request tenant only for entities that carry no organization.
            'organization_id' => $entity->organization_id ?? TenantContext::organizationId(),
            // The morph alias, not class_basename(). This service used to
            // write "Risk" while Risk::auditTrail() queried "risk", so the
            // relationship returned nothing for every change recorded here.
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => $entity->id,
            'action_type' => $actionType,
            'field_changed' => $fieldChanged,
            'old_value' => is_array($oldValue) ? json_encode($oldValue) : (string) $oldValue,
            'new_value' => is_array($newValue) ? json_encode($newValue) : (string) $newValue,
            // No fallback actor. A change made by a system process records NULL
            // rather than being attributed to whichever user happens to be id 1
            // — a compliance trail naming the wrong person is worse than one
            // that admits it does not know. (changed_by is widened to nullable
            // by the audit hash-chain migration.)
            'changed_by' => auth()->id(),
            'changed_at' => now(),
            'ip_address' => request()->ip(),
            'change_reason' => $reason,
        ]);
    }

    /**
     * Record all changed fields from a model update
     */
    public static function recordChanges(Model $entity, array $original, ?string $reason = null): void
    {
        $changes = $entity->getChanges();
        unset($changes['updated_at']);

        foreach ($changes as $field => $newValue) {
            $oldValue = $original[$field] ?? null;
            if ($oldValue != $newValue) {
                self::record($entity, 'update', $field, $oldValue, $newValue, $reason);
            }
        }
    }
}
