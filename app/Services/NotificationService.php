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
        array $metadata = []
    ): void {
        DB::table('notifications_log')->insert([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'channel' => 'database',
            'type' => $type,
            'subject' => $subject,
            'body' => $body,
            'status' => 'sent',
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
