@extends('layouts.app')

@section('title', 'Report Library - GRC Risk Management')
@section('page-section', 'Reports')
@section('page-title', 'Library')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Report Library</span>
@endsection

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Report Library</h1>
            <p class="text-sm text-gray-500 mt-1">
                Every generated document, kept as it was produced. Downloading an old report returns that report —
                not a fresh run against today's data.
            </p>
        </div>
        <a href="{{ route('risk.reports.board-pack.sections') }}"
           class="px-4 py-2 border border-gray-300 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50 flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">tune</span> Board pack sections
        </a>
    </div>

    @if (session('success'))
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    {{-- Generate --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <h2 class="text-sm font-semibold text-[#1A365D] mb-4">Generate a report</h2>
        <form method="POST" action="{{ route('risk.reports.queue') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            @csrf
            <div>
                <label for="report_type" class="block text-xs font-medium text-gray-600 mb-1">Report</label>
                <select id="report_type" name="report_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" required>
                    @foreach ($types as $type)
                        <option value="{{ $type }}">{{ ucwords(str_replace('_', ' ', $type)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="format" class="block text-xs font-medium text-gray-600 mb-1">Format</label>
                <select id="format" name="format" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    <option value="pdf">PDF</option>
                    <option value="xlsx">Excel workbook (.xlsx)</option>
                    <option value="csv">CSV</option>
                </select>
                <p class="text-[10px] text-gray-500 mt-1">Board packs are always PDF.</p>
            </div>
            <div>
                <label for="as_at" class="block text-xs font-medium text-gray-600 mb-1">Position as at</label>
                <input type="date" id="as_at" name="as_at" value="{{ now()->format('Y-m-d') }}"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div class="flex items-end">
                <button type="submit"
                        class="w-full px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-semibold hover:bg-[#2D4A7A]">
                    Generate
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium">Report</th>
                        <th class="text-left px-4 py-3 font-medium">Type</th>
                        <th class="text-left px-4 py-3 font-medium">Position as at</th>
                        <th class="text-left px-4 py-3 font-medium">Version</th>
                        <th class="text-left px-4 py-3 font-medium">Format</th>
                        <th class="text-left px-4 py-3 font-medium">Generated</th>
                        <th class="text-left px-4 py-3 font-medium">By</th>
                        <th class="text-left px-4 py-3 font-medium">Status</th>
                        <th class="text-right px-4 py-3 font-medium">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($reports as $report)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-[#1A365D]">{{ $report->name }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ ucwords(str_replace('_', ' ', $report->report_type)) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $report->period_as_at?->format('d M Y') ?? $report->period ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-600">v{{ $report->version }}</td>
                            <td class="px-4 py-3 text-gray-600 uppercase">{{ $report->format ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $report->created_at?->format('d M y H:i') }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $report->generatedBy?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($report->status === 'completed')
                                    <span class="badge bg-green-100 text-green-700">Ready</span>
                                @elseif ($report->status === 'failed')
                                    <span class="badge bg-red-100 text-red-700" title="{{ $report->error_message }}">Failed</span>
                                @else
                                    <span class="badge bg-yellow-100 text-yellow-700">{{ ucfirst($report->status) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($report->hasStoredFile())
                                    <a href="{{ route('risk.reports.download', $report) }}"
                                       class="text-[#1A365D] hover:underline">Download</a>
                                @elseif ($report->isPending())
                                    <a href="{{ route('risk.reports.status', $report) }}"
                                       class="text-[#1A365D] hover:underline">View progress</a>
                                @elseif ($report->download_url)
                                    {{-- Legacy row: no stored artifact, so this re-runs the generator. --}}
                                    <a href="{{ $report->download_url }}" class="text-gray-500 hover:underline"
                                       title="No stored document — this regenerates from current data">Re-run</a>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center">
                                <span class="material-symbols-outlined text-3xl text-gray-300 mb-2 block">description</span>
                                <p class="text-sm text-gray-500">No reports have been generated yet.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($reports->hasPages())
            <div class="px-4 py-3 border-t border-gray-100">{{ $reports->links() }}</div>
        @endif
    </div>
@endsection
