<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class NotificationController extends Controller
{
    /**
     * Full list page — paginated.
     */
    public function index(Request $request)
    {
        $userId = auth()->id();
        $filter = $request->query('filter') === 'unread' ? 'unread' : 'all';

        $query = DB::table('notifications_log')->where('user_id', $userId);

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        }

        $notifications = $query->orderByDesc('created_at')->paginate(30)->withQueryString();

        $notifications->getCollection()->transform(function ($row) {
            $row->metadata = is_string($row->metadata) ? (json_decode($row->metadata, true) ?: []) : ($row->metadata ?? []);
            $row->is_unread = $row->read_at === null;

            return $row;
        });

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => NotificationService::getUnreadCount($userId),
            'filter' => $filter,
        ]);
    }

    /**
     * The unread count as JSON — polled by the React topbar bell.
     */
    public function unreadCount()
    {
        return response()->json([
            'unread_count' => NotificationService::getUnreadCount(auth()->id()),
        ]);
    }

    /**
     * Mark one notification read and redirect to its action URL (or the list).
     */
    public function read(int $id)
    {
        $userId = auth()->id();
        $n = DB::table('notifications_log')->where('id', $id)->where('user_id', $userId)->first();

        if (! $n) {
            return redirect()->route('notifications.index');
        }

        if (! $n->read_at) {
            NotificationService::markAsRead($id);
        }

        // Normalised again on the way out, not only on the way in: rows written
        // before the path-only rule still carry a host, and this value is
        // handed straight to redirect(). Stripping it here means a notification
        // can only ever send someone to a page on this application.
        $target = NotificationService::normaliseActionUrl($n->action_url);

        return $target
            ? redirect()->to($target)
            : redirect()->route('notifications.index');
    }

    /**
     * Mark every unread notification for the current user as read.
     */
    public function readAll()
    {
        NotificationService::markAllAsRead(auth()->id());

        return back()->with('success', 'All notifications marked as read.');
    }
}
