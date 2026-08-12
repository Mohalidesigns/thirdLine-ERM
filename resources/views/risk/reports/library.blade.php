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
        @can('report.view')
            <a href="{{ route('risk.reports.board-pack.sections') }}"
               class="px-4 py-2 border border-gray-300 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">tune</span> Board pack sections
            </a>
        @endcan
    </div>

    @if (session('success'))
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    {{-- Generate --}}
    @can('report.generate')
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
    @endcan

    {{-- WP-09: the library listing is the shared grid — see
         App\Grids\Definitions\ReportsLibraryGrid. Download stays a row action
         pointing at the stored-artifact route. --}}
    <x-data-grid grid="reports_library" />
@endsection
