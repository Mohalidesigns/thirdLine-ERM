@extends('layouts.app')

@section('title', 'Loss Event Register - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Event Register</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Loss Event Register</h1>
            <p class="text-sm text-gray-500 mt-1">Comprehensive register of all operational loss events</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('risk.export.loss-events') }}" class="flex items-center gap-2 px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-50 transition">
                <span class="material-symbols-outlined text-sm">download</span>
                Export
            </a>
            <a href="{{ url('/risk/loss-events/create') }}" class="flex items-center gap-2 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                <span class="material-symbols-outlined text-sm">add</span>
                Log New Event
            </a>
        </div>
    </div>

    {{-- Flash Messages --}}
    @if (session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">check_circle</span>
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">error</span>
            {{ session('error') }}
        </div>
    @endif

    {{-- Filters Panel --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <form method="GET" action="{{ url('/risk/loss-events') }}" id="filterForm">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D] flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">filter_list</span>
                    Filters
                </h3>
                <a href="{{ url('/risk/loss-events') }}" class="text-xs text-gray-500 hover:text-[#1A365D]">Clear All</a>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                {{-- Status --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                    <select name="status" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All Statuses</option>
                        @foreach (['Draft', 'Open', 'Under Review', 'Pending Approval', 'Closed', 'Escalated'] as $status)
                            <option value="{{ strtolower($status) }}" {{ request('status') === strtolower($status) ? 'selected' : '' }}>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Basel L1 Category --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Basel L1 Category</label>
                    <select name="basel_l1" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All Categories</option>
                        @foreach (($baselL1Categories ?? []) as $cat)
                            <option value="{{ $cat->id }}" {{ request('basel_l1') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- CBN Category --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">CBN Category</label>
                    <select name="cbn_category" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All CBN Categories</option>
                        @foreach (($cbnCategories ?? []) as $cat)
                            <option value="{{ $cat->id }}" {{ request('cbn_category') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Severity --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Severity</label>
                    <select name="severity" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All Severities</option>
                        @foreach (['Critical', 'High', 'Medium', 'Low'] as $sev)
                            <option value="{{ strtolower($sev) }}" {{ request('severity') === strtolower($sev) ? 'selected' : '' }}>{{ $sev }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Date Range --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">From Date</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}"
                           class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">To Date</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}"
                           class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                </div>
            </div>

            {{-- Regulatory Flags --}}
            <div class="flex items-center gap-4 mt-4 pt-4 border-t border-gray-100">
                <label class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                    <input type="checkbox" name="cbn_reportable" value="1" {{ request('cbn_reportable') ? 'checked' : '' }}
                           class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                    CBN Reportable
                </label>
                <label class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                    <input type="checkbox" name="nfiu_reportable" value="1" {{ request('nfiu_reportable') ? 'checked' : '' }}
                           class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                    NFIU Reportable
                </label>
                <label class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                    <input type="checkbox" name="ndic_reportable" value="1" {{ request('ndic_reportable') ? 'checked' : '' }}
                           class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                    NDIC Reportable
                </label>
                <div class="ml-auto">
                    <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A] transition">
                        Apply Filters
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- Results Summary --}}
    <div class="flex items-center justify-between mb-3">
        <span class="text-xs text-gray-500">
            Showing {{ $lossEvents->firstItem() ?? 0 }}-{{ $lossEvents->lastItem() ?? 0 }} of {{ $lossEvents->total() }} events
        </span>
        <div class="flex items-center gap-2">
            <label class="text-xs text-gray-500">Sort:</label>
            <select name="sort" onchange="this.form.submit()" form="filterForm"
                    class="text-xs border border-gray-200 rounded-lg px-2 py-1 focus:ring-1 focus:ring-[#1A365D]">
                <option value="latest" {{ request('sort') === 'latest' ? 'selected' : '' }}>Most Recent</option>
                <option value="loss_desc" {{ request('sort') === 'loss_desc' ? 'selected' : '' }}>Highest Loss</option>
                <option value="severity" {{ request('sort') === 'severity' ? 'selected' : '' }}>Severity</option>
            </select>
        </div>
    </div>

    {{-- Loss Events Table --}}
    <x-data-table id="lossEventsTable">
        <x-slot:head>
            <th>Reference</th>
            <th>Title</th>
            <th>Date of Loss</th>
            <th>Basel Category</th>
            <th>Gross Loss</th>
            <th>Severity</th>
            <th>Status</th>
            <th>CBN</th>
            <th>Days Open</th>
            <th>Actions</th>
        </x-slot:head>

        @forelse ($lossEvents as $event)
            <tr>
                <td>
                    <a href="{{ url('/risk/loss-events/' . $event->id) }}" class="text-[#1A365D] font-semibold hover:underline text-xs">
                        {{ $event->reference }}
                    </a>
                </td>
                <td class="max-w-[200px]">
                    <div class="truncate text-sm font-medium text-gray-800">{{ $event->title }}</div>
                </td>
                <td class="text-gray-500 text-xs">{{ $event->date_of_loss?->format('d M Y') ?? '-' }}</td>
                <td class="text-xs">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-blue-50 text-blue-700 text-[11px] font-medium">
                        {{ $event->baselL1Category->name ?? '-' }}
                    </span>
                </td>
                <td class="font-semibold text-sm text-gray-900">₦{{ number_format($event->gross_loss_amount ?? 0, 2) }}</td>
                <td><x-risk-badge :rating="$event->severity ?? 'low'" /></td>
                <td><x-status-badge :status="$event->status ?? 'draft'" /></td>
                <td class="text-center">
                    @if ($event->cbn_reportable)
                        <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full bg-red-50 text-red-600 text-[10px] font-bold">
                            <span class="material-symbols-outlined text-xs">flag</span> Yes
                        </span>
                    @else
                        <span class="text-xs text-gray-400">No</span>
                    @endif
                </td>
                <td class="text-xs text-gray-500">
                    @if ($event->status !== 'closed' && $event->created_at)
                        {{ $event->created_at->diffInDays(now()) }}d
                    @else
                        -
                    @endif
                </td>
                <td>
                    <div class="flex items-center gap-1">
                        <a href="{{ url('/risk/loss-events/' . $event->id) }}" class="p-1 rounded hover:bg-gray-100" title="View">
                            <span class="material-symbols-outlined text-gray-500 text-lg">visibility</span>
                        </a>
                        <a href="{{ url('/risk/loss-events/' . $event->id . '/edit') }}" class="p-1 rounded hover:bg-gray-100" title="Edit">
                            <span class="material-symbols-outlined text-gray-500 text-lg">edit</span>
                        </a>
                        <button class="p-1 rounded hover:bg-gray-100" title="More actions" onclick="toggleDropdown('actions-{{ $event->id }}')">
                            <span class="material-symbols-outlined text-gray-500 text-lg">more_vert</span>
                        </button>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="10" class="text-center py-12 text-gray-400">
                    <span class="material-symbols-outlined text-4xl mb-2 block">search_off</span>
                    <p class="text-sm font-medium">No loss events found</p>
                    <p class="text-xs mt-1">Try adjusting your filters or log a new event</p>
                    <a href="{{ url('/risk/loss-events/create') }}" class="inline-flex items-center gap-1 mt-3 px-4 py-2 bg-[#1A365D] text-white text-xs rounded-lg hover:bg-[#2D4A7A]">
                        <span class="material-symbols-outlined text-sm">add</span>
                        Log New Event
                    </a>
                </td>
            </tr>
        @endforelse
    </x-data-table>

    {{-- Pagination --}}
    @if ($lossEvents->hasPages())
        <div class="mt-4 flex justify-center">
            {{ $lossEvents->withQueryString()->links() }}
        </div>
    @endif

@endsection

@push('scripts')
<script>
function toggleDropdown(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('hidden');
}

// Close dropdowns on outside click
document.addEventListener('click', function(e) {
    document.querySelectorAll('[id^="actions-"]').forEach(function(dropdown) {
        if (!dropdown.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });
});
</script>
@endpush
