<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(): View
    {
        $userId = auth()->id();
        $notifications = DB::table('notifications_log')
            ->where('user_id', $userId)
            ->orWhere('user_id', null)
            ->orderByDesc('created_at')
            ->paginate(15);

        $notificationTypes = [
            'control_updated' => 'Control Updated',
            'kri_breach' => 'KRI Breach',
            'loss_event_created' => 'Loss Event',
            'treatment_completed' => 'Treatment Completed',
            'issue_overdue' => 'Overdue Issue',
            'assessment_approved' => 'Assessment Approved',
            'near_miss_converted' => 'Near Miss Converted',
        ];

        $priorities = ['critical', 'high', 'medium', 'low'];

        return view('risk.notifications.index', [
            'notifications' => $notifications,
            'notificationTypes' => $notificationTypes,
            'priorities' => $priorities,
        ]);
    }

    public function markAsRead(int $id): JsonResponse
    {
        NotificationService::markAsRead($id);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
        ]);
    }

    public function markAllAsRead(): JsonResponse
    {
        $userId = auth()->id();
        NotificationService::markAllAsRead($userId);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
        ]);
    }

    public function getUnreadCount(): JsonResponse
    {
        $userId = auth()->id();
        $count = NotificationService::getUnreadCount($userId);

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    public function filter(string $type = null, string $priority = null): View
    {
        $userId = auth()->id();
        $query = DB::table('notifications_log')
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhere('user_id', null);
            });

        if ($type) {
            $query->where('type', $type);
        }

        if ($priority) {
            $query->whereRaw("JSON_EXTRACT(metadata, '$.priority') = ?", [$priority]);
        }

        $notifications = $query->orderByDesc('created_at')->paginate(15);

        $notificationTypes = [
            'control_updated' => 'Control Updated',
            'kri_breach' => 'KRI Breach',
            'loss_event_created' => 'Loss Event',
            'treatment_completed' => 'Treatment Completed',
            'issue_overdue' => 'Overdue Issue',
            'assessment_approved' => 'Assessment Approved',
            'near_miss_converted' => 'Near Miss Converted',
        ];

        $priorities = ['critical', 'high', 'medium', 'low'];

        return view('risk.notifications.index', [
            'notifications' => $notifications,
            'notificationTypes' => $notificationTypes,
            'priorities' => $priorities,
            'selectedType' => $type,
            'selectedPriority' => $priority,
        ]);
    }
}
