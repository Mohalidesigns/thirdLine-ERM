@extends('layouts.app')

@section('title', 'Reporting Calendar')
@section('page-section', 'Governance')
@section('page-title', 'Reporting Calendar')

@section('breadcrumbs')
    <a href="{{ route('risk.dashboard') }}" class="hover:text-[#1A365D]">Dashboard</a>
    <span>/</span>
    <span class="text-gray-700">Reporting Calendar</span>
@endsection

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-[#1A365D]">Reporting Calendar</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ $calendar->name }} &middot; fiscal year starts in
                {{ \Carbon\Carbon::create(null, $calendar->fiscal_year_start_month, 1)->format('F') }}.
                Closing a period locks every value recorded in it and in the periods beneath it.
            </p>
        </div>

        <form method="GET" action="{{ route('risk.periods.index') }}">
            <select name="type" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700"
                    onchange="this.form.submit()">
                @foreach (['month' => 'Months', 'quarter' => 'Quarters', 'half' => 'Halves', 'year' => 'Years'] as $value => $label)
                    <option value="{{ $value }}" {{ $type === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <x-data-table>
        <x-slot name="head">
            <th>Period</th>
            <th>Code</th>
            <th>Starts</th>
            <th>Ends</th>
            <th>Values recorded</th>
            <th>Status</th>
            <th>Actions</th>
        </x-slot>

        @forelse ($periods as $period)
            <tr class="hover:bg-blue-50/50">
                <td class="font-medium text-[#1A365D]">
                    <a href="{{ route('risk.periods.select', ['period' => $period->id, 'redirect' => '/risk/periods']) }}"
                       class="hover:underline">{{ $period->name }}</a>
                    @if (($selectedPeriod?->id ?? null) === $period->id)
                        <span class="badge bg-blue-100 text-blue-700 ml-1">selected</span>
                    @endif
                </td>
                <td class="text-xs text-gray-500 font-mono">{{ $period->code }}</td>
                <td class="text-xs">{{ $period->start_date?->format('d M Y') }}</td>
                <td class="text-xs">{{ $period->end_date?->format('d M Y') }}</td>
                <td class="text-xs">{{ number_format($valueCounts[$period->id] ?? 0) }}</td>
                <td>
                    @if ($period->is_closed)
                        <span class="badge bg-gray-200 text-gray-700">Closed</span>
                        <div class="text-[10px] text-gray-400 mt-0.5">
                            {{ $period->closed_at?->format('d M Y') }}
                            @if ($period->closedBy) by {{ $period->closedBy->name }} @endif
                        </div>
                    @else
                        <span class="badge bg-green-100 text-green-700">Open</span>
                    @endif
                </td>
                <td>
                    @if (! $period->is_closed)
                        @can('period.close')
                            <form method="POST" action="{{ route('risk.periods.close', $period) }}"
                                  onsubmit="return confirm('Close {{ $period->name }}? Every value recorded in it will be locked.');">
                                @csrf
                                <button type="submit" class="text-xs text-[#1A365D] font-medium hover:underline">Close</button>
                            </form>
                        @endcan
                    @else
                        @can('period.reopen')
                            {{-- A reason is mandatory: reopening can move a number
                                 a board pack has already been built on. --}}
                            <form method="POST" action="{{ route('risk.periods.reopen', $period) }}"
                                  x-data="{ reason: '' }"
                                  @submit="if (reason.trim().length < 10) { $event.preventDefault(); alert('Give a reason of at least 10 characters.'); }">
                                @csrf
                                <input type="text" name="reason" x-model="reason" required minlength="10" maxlength="1000"
                                       placeholder="Reason for reopening"
                                       class="border border-gray-300 rounded px-2 py-1 text-xs w-44 mb-1">
                                <button type="submit" class="text-xs text-red-600 font-medium hover:underline">Reopen</button>
                            </form>
                        @endcan
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="text-center py-12 text-sm text-gray-500">
                    No periods of this granularity have been generated.
                </td>
            </tr>
        @endforelse
    </x-data-table>

    <div class="mt-4">{{ $periods->links() }}</div>
@endsection
