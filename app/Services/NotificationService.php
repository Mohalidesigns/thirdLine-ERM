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
        $actionUrl = static::normaliseActionUrl($actionUrl ?: static::resolveActionUrl($metadata));

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
     * Notify a list of people, on a queue.
     *
     * WP-07. One notification is a single insert and belongs inline; a fan-out
     * to every holder of a role does not — in a bank with two hundred risk
     * managers that is two hundred inserts and two hundred mail attempts inside
     * the request that was somebody pressing Approve.
     *
     * A list of one is sent inline: queueing a single insert costs more than
     * doing it.
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $metadata
     */
    public static function sendMany(
        int $organizationId,
        array $userIds,
        string $type,
        string $subject,
        string $body,
        array $metadata = [],
        ?string $actionUrl = null,
        string $priority = 'medium',
        string $category = 'workflow'
    ): void {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === []) {
            return;
        }

        if (count($userIds) === 1) {
            static::send($organizationId, $userIds[0], $type, $subject, $body, $metadata, $actionUrl, $priority, $category);

            return;
        }

        \App\Jobs\FanOutNotificationsJob::dispatch(
            $organizationId, $userIds, $type, $subject, $body, $metadata, $actionUrl, $priority, $category,
        );
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
            // Relative. See normaliseActionUrl() for why a stored notification
            // must never carry a host.
            return route($map[$type], $id, false);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Reduce an action URL to a root-relative path before it is stored.
     *
     * A notification row outlives the host that created it. `route()` and
     * `url()` return absolute URLs built from the current request — or, with no
     * request, from `APP_URL` — so a notification raised by a queue worker, a
     * scheduled command or a seeder was stamped with whatever `APP_URL`
     * happened to say, and one raised under `artisan serve` was stamped with
     * that port. Both were then served to a browser on a different origin, and
     * clicking the bell landed on a 404 from whatever else was listening there.
     * The rows in a live database showed exactly that split:
     * `http://localhost/risk/my-tasks` alongside
     * `http://127.0.0.1:8000/risk/my-tasks`.
     *
     * Storing the path alone makes the link resolve against whatever host the
     * user is actually on — dev port, staging, production — with no
     * configuration to keep in step. It also means an action URL can never
     * redirect a signed-in user off-site: `NotificationController::read()`
     * hands this value straight to `redirect()`, and an absolute URL there is
     * an open redirect waiting for a call site that takes user input.
     *
     * Query strings and fragments are kept; scheme, host and port are dropped.
     */
    public static function normaliseActionUrl(?string $actionUrl): ?string
    {
        $actionUrl = trim((string) $actionUrl);

        if ($actionUrl === '') {
            return null;
        }

        // Protocol-relative ("//evil.test/x") is host-bearing despite starting
        // with a slash, so it is parsed rather than passed through.
        if (str_starts_with($actionUrl, '/') && ! str_starts_with($actionUrl, '//')) {
            return $actionUrl;
        }

        $parts = parse_url($actionUrl);

        if ($parts === false) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if ($path === '') {
            return null;
        }

        return '/'.ltrim($path, '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
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
