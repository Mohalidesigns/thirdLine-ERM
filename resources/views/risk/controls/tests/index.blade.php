@extends('layouts.app')
@section('title', 'Control Tests')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <a href="{{ route('risk.control-tests.dashboard') }}" class="hover:text-primary">Control Testing</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">All Tests</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">All Control Tests</h1>
        <a href="{{ route('risk.control-tests.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90">
            <span class="material-symbols-outlined text-lg">add_circle</span> Schedule Test
        </a>
    </div>

    {{-- Filters --}}
    <form method="GET" class="flex items-center gap-4 bg-white p-4 rounded-xl border border-gray-200">
        <select name="status" class="text-sm border border-gray-200 rounded-lg px-3 py-2">
            <option value="">All Statuses</option>
            @foreach(['scheduled','in_progress','pending_review','completed','cancelled'] as $s)
                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
            @endforeach
        </select>
        <select name="result" class="text-sm border border-gray-200 rounded-lg px-3 py-2">
            <option value="">All Results</option>
            @foreach(['effective','partially_effective','ineffective','not_tested'] as $r)
                <option value="{{ $r }}" {{ request('result') === $r ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $r)) }}</option>
            @endforeach
        </select>
        <button type="submit" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">Filter</button>
    </form>

    {{-- Table --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Test Code</th>
                    <th>Title</th>
                    <th>Control</th>
                    <th>Type</th>
                    <th>Tester</th>
                    <th>Scheduled</th>
                    <th>Status</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tests as $test)
                <tr class="cursor-pointer hover:bg-gray-50" onclick="window.location='{{ route('risk.control-tests.show', $test) }}'">
                    <td class="font-mono text-xs">{{ $test->test_code }}</td>
                    <td class="font-medium">{{ Str::limit($test->title, 40) }}</td>
                    <td>{{ $test->control?->name ?? '—' }}</td>
                    <td><span class="badge bg-blue-50 text-blue-700">{{ ucfirst(str_replace('_', ' ', $test->test_type)) }}</span></td>
                    <td>{{ $test->tester?->name ?? '—' }}</td>
                    <td class="{{ $test->scheduled_date->isPast() && $test->status === 'scheduled' ? 'text-red-600 font-semibold' : '' }}">{{ $test->scheduled_date->format('M d, Y') }}</td>
                    <td>
                        @php $statusColors = ['scheduled'=>'gray','in_progress'=>'yellow','pending_review'=>'blue','completed'=>'green','cancelled'=>'red']; @endphp
                        <span class="badge bg-{{ $statusColors[$test->status] ?? 'gray' }}-100 text-{{ $statusColors[$test->status] ?? 'gray' }}-700">{{ ucfirst(str_replace('_', ' ', $test->status)) }}</span>
                    </td>
                    <td>
                        @if($test->result === 'effective')<span class="badge bg-green-100 text-green-700">Effective</span>
                        @elseif($test->result === 'partially_effective')<span class="badge bg-yellow-100 text-yellow-700">Partial</span>
                        @elseif($test->result === 'ineffective')<span class="badge bg-red-100 text-red-700">Ineffective</span>
                        @else <span class="text-gray-400">—</span>@endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center py-8 text-gray-400">No control tests found</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $tests->links() }}</div>
</div>
@endsection
