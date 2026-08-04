@extends('layouts.app')

@section('title', 'Near Misses - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Near Misses</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Near Miss Register</h1>
            <p class="text-sm text-gray-500 mt-1">Track near-miss events that could have resulted in operational losses</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('risk.loss-events.create-near-miss') }}" class="flex items-center gap-2 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                <span class="material-symbols-outlined text-sm">add</span>
                Log Near Miss
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

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Total Near Misses" :value="$totalNearMisses ?? 0" icon="warning" color="warning" />
        <x-kpi-card title="Open" :value="$openNearMisses ?? 0" icon="pending" color="info" />
        <x-kpi-card title="Under Review" :value="$underReviewNearMisses ?? 0" icon="rate_review" color="primary" />
        <x-kpi-card title="Potential Loss Avoided" :value="'₦' . number_format($potentialLossAvoided ?? 0, 2)" icon="savings" color="success" />
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-6">
        <form method="GET" action="{{ url('/risk/loss-events/near-misses') }}">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                    <select name="status" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All Statuses</option>
                        @foreach (['Open', 'Under Review', 'Closed', 'Converted'] as $status)
                            <option value="{{ strtolower($status) }}" {{ request('status') === strtolower($status) ? 'selected' : '' }}>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Severity</label>
                    <select name="severity" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All Severities</option>
                        @foreach (['Critical', 'High', 'Medium', 'Low'] as $sev)
                            <option value="{{ strtolower($sev) }}" {{ request('severity') === strtolower($sev) ? 'selected' : '' }}>{{ $sev }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Business Unit</label>
                    <select name="business_unit" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All Units</option>
                        @foreach (($businessUnits ?? []) as $unit)
                            <option value="{{ $unit->id }}" {{ request('business_unit') == $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A] transition w-full">
                        Apply Filters
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- Near Misses Table --}}
    <x-data-table id="nearMissesTable">
        <x-slot:head>
            <th>Reference</th>
            <th>Title</th>
            <th>Date</th>
            <th>Business Unit</th>
            <th>Potential Loss</th>
            <th>Severity</th>
            <th>Status</th>
            <th>Actions</th>
        </x-slot:head>

        @forelse (($nearMissEvents ?? []) as $event)
            <tr>
                <td>
                    <a href="{{ url('/risk/loss-events/' . $event->id) }}" class="text-[#1A365D] font-semibold hover:underline text-xs">
                        {{ $event->reference }}
                    </a>
                </td>
                <td class="max-w-[200px]">
                    <div class="truncate text-sm font-medium text-gray-800">{{ $event->title }}</div>
                </td>
                <td class="text-gray-500 text-xs">{{ $event->date_occurred?->format('d M Y') ?? '-' }}</td>
                <td class="text-xs text-gray-600">{{ $event->businessUnit->name ?? '-' }}</td>
                <td class="font-semibold text-sm text-gray-900">₦{{ number_format($event->potential_loss ?? 0, 2) }}</td>
                <td><x-risk-badge :rating="$event->severity ?? 'low'" /></td>
                <td><x-status-badge :status="$event->status ?? 'open'" /></td>
                <td>
                    <div class="flex items-center gap-1">
                        @if ($event->converted_loss_event_id)
                            <a href="{{ route('risk.loss-events.show', $event->converted_loss_event_id) }}" class="p-1 rounded hover:bg-gray-100" title="View Converted Loss Event">
                                <span class="material-symbols-outlined text-gray-500 text-lg">visibility</span>
                            </a>
                        @endif
                        @if ($event->status !== 'converted')
                            <form method="POST" action="{{ route('risk.loss-events.convert-near-miss', $event) }}" class="inline"
                                  onsubmit="return confirm('Convert this near miss to a loss event?')">
                                @csrf
                                <button type="submit" class="p-1 rounded hover:bg-yellow-50" title="Convert to Loss Event">
                                    <span class="material-symbols-outlined text-[#D4AF37] text-lg">swap_horiz</span>
                                </button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="text-center py-12 text-gray-400">
                    <span class="material-symbols-outlined text-4xl mb-2 block">check_circle</span>
                    <p class="text-sm font-medium">No near misses recorded</p>
                    <p class="text-xs mt-1">Near-miss events help identify potential risks before they cause losses</p>
                </td>
            </tr>
        @endforelse
    </x-data-table>

    {{-- Pagination --}}
    @if (($nearMissEvents ?? collect()) instanceof \Illuminate\Pagination\LengthAwarePaginator && $nearMissEvents->hasPages())
        <div class="mt-4 flex justify-center">
            {{ $nearMissEvents->withQueryString()->links() }}
        </div>
    @endif
@endsection
