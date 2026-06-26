@extends('layouts.app')

@section('title', 'Notifications')
@section('page-section', 'Inbox')
@section('page-title', 'Notifications')

@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Home</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">Notifications</span>
@endsection

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Notifications</h1>
            <p class="text-sm text-gray-500 mt-1">Approvals, decisions, and system alerts directed to you.</p>
        </div>
        @if ($notifications->whereNull('read_at')->count() > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button type="submit" class="px-4 py-2 border border-gray-300 text-sm rounded-lg hover:bg-gray-50 flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">done_all</span> Mark all read
                </button>
            </form>
        @endif
    </div>

    {{-- Filter tabs --}}
    <div class="flex items-center gap-1 border-b border-gray-200 mb-4">
        <a href="{{ route('notifications.index') }}"
           class="px-4 py-2 text-sm font-medium border-b-2 {{ request('filter') !== 'unread' ? 'border-[#1A365D] text-[#1A365D]' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
            All
        </a>
        <a href="{{ route('notifications.index', ['filter' => 'unread']) }}"
           class="px-4 py-2 text-sm font-medium border-b-2 {{ request('filter') === 'unread' ? 'border-[#1A365D] text-[#1A365D]' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
            Unread
        </a>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
        @forelse ($notifications as $n)
            @php
                $isUnread = is_null($n->read_at);
                $iconMap = [
                    'approval_request'  => ['icon' => 'rate_review', 'cls' => 'bg-blue-100 text-blue-600'],
                    'approval_approved' => ['icon' => 'check_circle', 'cls' => 'bg-green-100 text-green-600'],
                    'approval_rejected' => ['icon' => 'cancel', 'cls' => 'bg-red-100 text-red-600'],
                ];
                $vis = $iconMap[$n->type] ?? ['icon' => 'notifications', 'cls' => 'bg-gray-100 text-gray-600'];
                $priorityCls = match ($n->priority ?? 'normal') {
                    'high' => 'border-l-red-500',
                    'low' => 'border-l-gray-200',
                    default => 'border-l-blue-500',
                };
                $meta = is_string($n->metadata) ? json_decode($n->metadata, true) : ($n->metadata ?? []);
            @endphp
            <a href="{{ route('notifications.read', $n->id) }}"
               class="flex gap-4 p-4 hover:bg-gray-50 border-l-4 {{ $isUnread ? $priorityCls . ' bg-blue-50/30' : 'border-l-transparent' }}">
                <div class="w-10 h-10 rounded-full {{ $vis['cls'] }} flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-outlined text-lg">{{ $vis['icon'] }}</span>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm font-semibold text-gray-900">{{ $n->subject }}</p>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            @if ($n->priority === 'high')
                                <span class="text-[10px] font-semibold bg-red-100 text-red-700 rounded-full px-2 py-0.5">Priority</span>
                            @endif
                            @if ($isUnread)
                                <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
                            @endif
                        </div>
                    </div>
                    <p class="text-sm text-gray-600 mt-1 whitespace-pre-line">{{ $n->body }}</p>
                    <div class="flex items-center gap-3 mt-2 text-xs text-gray-400">
                        <span>{{ \Carbon\Carbon::parse($n->created_at)->format('M d, Y H:i') }}</span>
                        <span>&middot; {{ \Carbon\Carbon::parse($n->created_at)->diffForHumans() }}</span>
                        @if (! empty($meta['entity_type']))
                            <span>&middot; {{ $meta['entity_type'] }} #{{ $meta['entity_id'] ?? '?' }}</span>
                        @endif
                    </div>
                </div>
            </a>
        @empty
            <div class="text-center py-16 text-gray-400">
                <span class="material-symbols-outlined text-4xl block mb-2">notifications_off</span>
                <p class="text-sm">No notifications yet</p>
            </div>
        @endforelse
    </div>

    @if ($notifications->hasPages())
        <div class="mt-4">{{ $notifications->links() }}</div>
    @endif
</div>
@endsection
