<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    /**
     * Full list page — paginated.
     */
    public function index(Request $request)
    {
        $userId = auth()->id();
        $query = DB::table('notifications_log')->where('user_id', $userId);

        if ($request->filled('filter') && $request->filter === 'unread') {
            $query->whereNull('read_at');
        }

        $notifications = $query->orderByDesc('created_at')->paginate(30);

        return view('notifications.index', compact('notifications'));
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

        return $n->action_url
            ? redirect()->to($n->action_url)
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
