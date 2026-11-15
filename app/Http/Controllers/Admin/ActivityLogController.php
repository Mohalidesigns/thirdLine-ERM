<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    /**
     * Admin paginated view of the application activity audit log.
     */
    public function index(Request $request): Response
    {
        $query = ActivityLog::query()
            ->with(['user:id,name,email']);

        if ($event = $request->input('event')) {
            $query->where('event', $event);
        }

        if ($userId = $request->integer('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($modelType = $request->input('auditable_type')) {
            $query->where('auditable_type', $modelType);
        }

        if ($from = $request->input('from')) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('occurred_at', '<=', $to.' 23:59:59');
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%")
                    ->orWhere('user_email', 'like', "%{$search}%")
                    ->orWhere('subject_label', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%");
            });
        }

        $logs = $query->orderByDesc('occurred_at')
            ->paginate(30)
            ->withQueryString()
            ->through(fn ($l) => [
                'id' => $l->id,
                'event' => $l->event,
                'event_label' => $this->eventLabel($l->event),
                'user' => $l->user
                    ? ['id' => $l->user->id, 'name' => $l->user->name, 'email' => $l->user->email]
                    : ($l->user_name ? ['name' => $l->user_name, 'email' => $l->user_email] : null),
                'subject_type' => $l->auditable_type ? class_basename($l->auditable_type) : null,
                'subject_label' => $l->subject_label,
                'description' => $l->description,
                'route_name' => $l->route_name,
                'url' => $l->url,
                'method' => $l->method,
                'ip_address' => $l->ip_address,
                'user_agent' => $l->user_agent,
                'properties' => $l->properties,
                'occurred_at' => $l->occurred_at?->toISOString() ?? $l->occurred_at,
            ]);

        return Inertia::render('Admin/ActivityLog/Index', [
            'logs' => $logs,
            'filters' => $request->only(['event', 'user_id', 'auditable_type', 'from', 'to', 'search']),
            'options' => $this->filterOptions(),
        ]);
    }

    /**
     * Return distinct events + top users + model types for filter dropdowns.
     *
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'events' => ActivityLog::query()
                ->select('event')
                ->distinct()
                ->orderBy('event')
                ->pluck('event')
                ->map(fn ($e) => ['value' => $e, 'label' => $this->eventLabel($e)])
                ->values(),
            'users' => User::query()
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]),
            'model_types' => ActivityLog::query()
                ->select('auditable_type')
                ->whereNotNull('auditable_type')
                ->distinct()
                ->pluck('auditable_type')
                ->map(fn ($t) => ['value' => $t, 'label' => class_basename($t)])
                ->values(),
        ];
    }

    private function eventLabel(?string $event): string
    {
        return match ($event) {
            'login' => 'Login',
            'logout' => 'Logout',
            'login_failed' => 'Failed Login',
            'password_reset' => 'Password Reset',
            'access_denied' => 'Access Denied',
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            null => 'Event',
            default => ucwords(str_replace(['_', '.'], ' ', $event)),
        };
    }
}
