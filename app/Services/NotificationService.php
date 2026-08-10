<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class NotificationService
{
    public static function send(
        int $organizationId,
        ?int $userId,
        string $type,
        string $subject,
        string $body,
        array $metadata = [],
        ?string $actionUrl = null,
        string $priority = 'medium',
        string $category = 'workflow'
    ): void {
        // Derive a deep-link from entity metadata when no explicit URL given.
        $actionUrl = $actionUrl ?: static::resolveActionUrl($metadata);

        DB::table('notifications_log')->insert([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'channel' => 'database',
            'type' => $type,
            'subject' => $subject,
            'body' => $body,
            'status' => 'sent',
            'notification_category' => $category,
            'action_url' => $actionUrl,
            'priority' => $priority,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Map entity_type/entity_id in metadata to a deep-link route so every
     * call site produces consistent clickable notifications.
     */
    public static function resolveActionUrl(array $metadata): ?string
    {
        $type = $metadata['entity_type'] ?? null;
        $id = $metadata['entity_id'] ?? null;
        if (! $type || ! $id) {
            return null;
        }
        $map = [
            'ControlTest' => 'risk.control-tests.show',
            'TreatmentPlan' => 'risk.treatments.show',
            'RiskAssessment' => 'risk.assessments.show',
            'LossEvent' => 'risk.loss-events.show',
            'Risk' => 'risk.register.show',
        ];
        if (! isset($map[$type])) {
            return null;
        }
        try {
            return route($map[$type], $id);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function getUnread(int $userId, int $limit = 10): \Illuminate\Support\Collection
    {
        return DB::table('notifications_log')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public static function getUnreadCount(int $userId): int
    {
        return DB::table('notifications_log')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    public static function markAsRead(int $notificationId): void
    {
        DB::table('notifications_log')
            ->where('id', $notificationId)
            ->update(['read_at' => now()]);
    }

    public static function markAllAsRead(int $userId): void
    {
        DB::table('notifications_log')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
