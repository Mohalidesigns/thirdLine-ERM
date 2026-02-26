<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ApprovalService
{
    /**
     * Create a new approval request for a model
     */
    public function requestApproval(
        Model $entity,
        string $action,
        ?array $payload = null,
        ?int $userId = null
    ): ApprovalRequest {
        $userId = $userId ?? auth()->id();
        $orgId = $entity->organization_id ?? auth()->user()->organization_id;

        $approval = ApprovalRequest::create([
            'organization_id' => $orgId,
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->id,
            'action' => $action,
            'status' => 'pending',
            'payload' => $payload,
            'requested_by' => $userId,
            'requested_at' => now(),
        ]);

        return $approval;
    }

    /**
     * Approve an approval request and optionally apply changes
     */
    public function approve(
        ApprovalRequest $approval,
        int $reviewerId,
        ?string $comments = null
    ): ApprovalRequest {
        DB::transaction(function () use ($approval, $reviewerId, $comments) {
            $approval->update([
                'status' => 'approved',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'comments' => $comments,
            ]);

            // If payload exists, apply changes to the entity
            if ($approval->payload) {
                $entityClass = 'App\\Models\\' . $approval->entity_type;
                if (class_exists($entityClass)) {
                    $entity = $entityClass::findOrFail($approval->entity_id);
                    $entity->update($approval->payload);
                }
            }

            // Audit trail
            AuditTrailService::record(
                $approval,
                'approval_granted',
                null,
                $approval->payload,
                null,
                "Approval for {$approval->entity_type} ID {$approval->entity_id} - {$approval->action}"
            );
        });

        return $approval->fresh();
    }

    /**
     * Reject an approval request with reason
     */
    public function reject(
        ApprovalRequest $approval,
        int $reviewerId,
        string $reason
    ): ApprovalRequest {
        $approval->update([
            'status' => 'rejected',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        // Audit trail
        AuditTrailService::record(
            $approval,
            'approval_rejected',
            null,
            null,
            null,
            "Approval for {$approval->entity_type} ID {$approval->entity_id} rejected: {$reason}"
        );

        return $approval->fresh();
    }

    /**
     * Get pending approvals for an organization
     */
    public function getPendingApprovals(int $orgId, ?string $entityType = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = ApprovalRequest::where('organization_id', $orgId)
            ->where('status', 'pending')
            ->with(['requestedBy', 'organization']);

        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        return $query->orderByDesc('requested_at')->get();
    }

    /**
     * Get pending approvals for a specific user to review
     */
    public function getMyPendingApprovals(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        $user = User::findOrFail($userId);

        return ApprovalRequest::where('organization_id', $user->organization_id)
            ->where('status', 'pending')
            ->with(['requestedBy', 'organization'])
            ->orderByDesc('requested_at')
            ->get();
    }

    /**
     * Get approval history for an organization
     */
    public function getHistory(int $orgId, ?string $entityType = null, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        $query = ApprovalRequest::where('organization_id', $orgId)
            ->whereIn('status', ['approved', 'rejected'])
            ->with(['requestedBy', 'reviewedBy', 'organization']);

        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        return $query->orderByDesc('reviewed_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get approval statistics for dashboard
     */
    public function getStatistics(int $orgId): array
    {
        $pending = ApprovalRequest::where('organization_id', $orgId)
            ->where('status', 'pending')
            ->count();

        $approved = ApprovalRequest::where('organization_id', $orgId)
            ->where('status', 'approved')
            ->count();

        $rejected = ApprovalRequest::where('organization_id', $orgId)
            ->where('status', 'rejected')
            ->count();

        return [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'total' => $pending + $approved + $rejected,
        ];
    }
}
