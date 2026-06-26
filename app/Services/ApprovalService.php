<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ApprovalService
{
    /**
     * Create a new approval request for a model.
     * If $reviewerId is supplied, stores it in payload and notifies the reviewer.
     */
    public function requestApproval(
        Model $entity,
        string $action,
        ?array $payload = null,
        ?int $userId = null,
        ?int $reviewerId = null
    ): ApprovalRequest {
        $userId = $userId ?? auth()->id();
        $orgId = $entity->organization_id ?? auth()->user()->organization_id;

        if ($reviewerId !== null) {
            $payload = array_merge($payload ?? [], ['reviewer_id' => $reviewerId]);
        }

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

        if ($reviewerId !== null) {
            $this->notifyReviewer($approval, $reviewerId, $entity);
        }

        return $approval;
    }

    /**
     * Get the current pending approval request for a given entity (if any).
     */
    public function latestPending(Model $entity): ?ApprovalRequest
    {
        return ApprovalRequest::where('entity_type', class_basename($entity))
            ->where('entity_id', $entity->id)
            ->where('status', 'pending')
            ->latest('requested_at')
            ->first();
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

        $this->notifyRequester($approval->fresh(), 'approved', $comments);

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

        $this->notifyRequester($approval->fresh(), 'rejected', $reason);

        return $approval->fresh();
    }

    /* ------------------------------------------------------------------ */
    /*  Notifications (in-app + mail placeholder)                          */
    /* ------------------------------------------------------------------ */

    protected function notifyReviewer(ApprovalRequest $approval, int $reviewerId, Model $entity): void
    {
        $label = class_basename($entity) . ' #' . $entity->id;
        $subject = "Review required: {$label}";
        $body = "You have been assigned as reviewer for {$label}. Please review and approve or reject.";

        NotificationService::send(
            $approval->organization_id,
            $reviewerId,
            'approval_request',
            $subject,
            $body,
            [
                'approval_request_id' => $approval->id,
                'entity_type' => $approval->entity_type,
                'entity_id'   => $approval->entity_id,
                'action'      => $approval->action,
            ]
        );

        $this->sendMailPlaceholder($reviewerId, $subject, $body);
    }

    protected function notifyRequester(ApprovalRequest $approval, string $decision, ?string $comment): void
    {
        $label = $approval->entity_type . ' #' . $approval->entity_id;
        $subject = ucfirst($decision) . ": {$label}";
        $body = "Your {$label} has been {$decision}." . ($comment ? "\n\nComment: {$comment}" : '');

        NotificationService::send(
            $approval->organization_id,
            $approval->requested_by,
            'approval_' . $decision,
            $subject,
            $body,
            ['approval_request_id' => $approval->id]
        );

        $this->sendMailPlaceholder($approval->requested_by, $subject, $body);
    }

    /**
     * Email delivery placeholder. Routed via MAIL_MAILER=log (.env) — writes
     * to storage/logs/laravel.log. Switch MAIL_MAILER to smtp + set
     * MAIL_HOST/USERNAME/PASSWORD when real delivery is ready; no code change
     * required here.
     */
    protected function sendMailPlaceholder(?int $userId, string $subject, string $body): void
    {
        if (! $userId) {
            return;
        }
        $user = User::find($userId);
        if (! $user || ! $user->email) {
            return;
        }
        try {
            \Illuminate\Support\Facades\Mail::raw($body, function ($m) use ($user, $subject) {
                $m->to($user->email, $user->name)->subject($subject);
            });
        } catch (\Throwable $e) {
            \Log::warning('ApprovalService mail placeholder failed: ' . $e->getMessage());
        }
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
