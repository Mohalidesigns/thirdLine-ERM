<?php
namespace App\Services;

use App\Models\RiskAuditTrail;
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
            'organization_id' => $entity->organization_id ?? (auth()->user()->organization_id ?? 1),
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->id,
            'action_type' => $actionType,
            'field_changed' => $fieldChanged,
            'old_value' => is_array($oldValue) ? json_encode($oldValue) : (string) $oldValue,
            'new_value' => is_array($newValue) ? json_encode($newValue) : (string) $newValue,
            'changed_by' => auth()->id() ?? 1,
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
