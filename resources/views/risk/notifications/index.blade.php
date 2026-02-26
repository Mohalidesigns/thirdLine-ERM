@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1>Notifications</h1>
        </div>
        <div class="col-md-4 text-end">
            <button id="markAllRead" class="btn btn-sm btn-secondary">
                Mark All as Read
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-light">
            <div class="row">
                <div class="col-md-6">
                    <form method="GET" class="form-inline" id="filterForm">
                        <div class="form-group me-3">
                            <label for="typeFilter" class="me-2">Type:</label>
                            <select id="typeFilter" name="type" class="form-select form-select-sm">
                                <option value="">All Types</option>
                                @foreach ($notificationTypes as $value => $label)
                                    <option value="{{ $value }}" {{ request('type') === $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="priorityFilter" class="me-2">Priority:</label>
                            <select id="priorityFilter" name="priority" class="form-select form-select-sm">
                                <option value="">All Priorities</option>
                                @foreach ($priorities as $priority)
                                    <option value="{{ $priority }}" {{ request('priority') === $priority ? 'selected' : '' }}>
                                        {{ ucfirst($priority) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            @if ($notifications->count() > 0)
                <div class="list-group list-group-flush">
                    @foreach ($notifications as $notification)
                        <div class="list-group-item notification-item {{ is_null($notification->read_at) ? 'unread' : '' }}">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    @if (is_null($notification->read_at))
                                        <div class="badge bg-primary rounded-circle" style="width: 12px; height: 12px;"></div>
                                    @else
                                        <div style="width: 12px; height: 12px;"></div>
                                    @endif
                                </div>

                                <div class="col-md-2">
                                    @php
                                        $metadata = json_decode($notification->metadata, true) ?? [];
                                        $priority = $metadata['priority'] ?? 'medium';
                                        $priorityColors = [
                                            'critical' => 'danger',
                                            'high' => 'warning',
                                            'medium' => 'info',
                                            'low' => 'secondary',
                                        ];
                                        $badgeColor = $priorityColors[$priority] ?? 'secondary';
                                    @endphp
                                    <span class="badge bg-{{ $badgeColor }}">
                                        {{ ucfirst($priority) }}
                                    </span>
                                </div>

                                <div class="col-md-6">
                                    <h6 class="mb-1">{{ $notification->subject }}</h6>
                                    <p class="mb-0 text-muted small">{{ $notification->body }}</p>
                                </div>

                                <div class="col-md-2 text-muted small text-end">
                                    {{ $notification->created_at->diffForHumans() }}
                                </div>

                                <div class="col-auto">
                                    @if (is_null($notification->read_at))
                                        <button class="btn btn-sm btn-outline-primary mark-read-btn" data-id="{{ $notification->id }}">
                                            Mark Read
                                        </button>
                                    @endif

                                    @php
                                        $actionUrl = $metadata['action_url'] ?? null;
                                    @endphp
                                    @if ($actionUrl)
                                        <a href="{{ $actionUrl }}" class="btn btn-sm btn-outline-secondary">
                                            View
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="card-footer">
                    {{ $notifications->render() }}
                </div>
            @else
                <div class="text-center py-5">
                    <p class="text-muted">No notifications at this time.</p>
                </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Filter form
        const typeFilter = document.getElementById('typeFilter');
        const priorityFilter = document.getElementById('priorityFilter');
        const filterForm = document.getElementById('filterForm');

        if (typeFilter) {
            typeFilter.addEventListener('change', function() {
                filterForm.submit();
            });
        }

        if (priorityFilter) {
            priorityFilter.addEventListener('change', function() {
                filterForm.submit();
            });
        }

        // Mark as read buttons
        const markReadButtons = document.querySelectorAll('.mark-read-btn');
        markReadButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const notificationId = this.dataset.id;

                fetch(`/notifications/${notificationId}/mark-read`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.closest('.notification-item').classList.remove('unread');
                        this.remove();
                        // Update unread count
                        updateUnreadCount();
                    }
                })
                .catch(error => console.error('Error:', error));
            });
        });

        // Mark all as read
        const markAllReadBtn = document.getElementById('markAllRead');
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function() {
                fetch('/notifications/mark-all-read', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(error => console.error('Error:', error));
            });
        }

        // Update unread count
        function updateUnreadCount() {
            fetch('/notifications/unread-count')
                .then(response => response.json())
                .then(data => {
                    const badge = document.querySelector('[data-notification-badge]');
                    if (badge) {
                        if (data.unread_count > 0) {
                            badge.textContent = data.unread_count;
                            badge.style.display = 'inline-block';
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                })
                .catch(error => console.error('Error:', error));
        }
    });
</script>
@endpush

<style>
    .notification-item {
        padding: 1rem !important;
        border-bottom: 1px solid #dee2e6;
        transition: background-color 0.2s;
    }

    .notification-item:hover {
        background-color: #f8f9fa;
    }

    .notification-item.unread {
        background-color: #e7f3ff;
    }

    .notification-item:last-child {
        border-bottom: none;
    }
</style>
@endsection
