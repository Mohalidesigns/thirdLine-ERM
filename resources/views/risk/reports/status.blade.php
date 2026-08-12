@extends('layouts.app')

@section('title', 'Generating Report - GRC Risk Management')
@section('page-section', 'Reports')
@section('page-title', 'Generating')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.reports.library') }}" class="hover:text-[#1A365D]">Reports</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ $report->name }}</span>
@endsection

@section('content')
    <div class="max-w-2xl mx-auto">
        <div class="bg-white rounded-xl border border-gray-200 p-8" id="statusCard">
            <h1 class="text-lg font-bold text-[#1A365D] mb-1">{{ $report->name }}</h1>
            <p class="text-sm text-gray-500 mb-6">
                {{ ucwords(str_replace('_', ' ', $report->report_type)) }}
                @if ($report->period_as_at) · position as at {{ $report->period_as_at->format('d M Y') }} @endif
                @if ($report->version > 1) · version {{ $report->version }} @endif
            </p>

            <div id="pendingBlock" class="{{ $report->isPending() ? '' : 'hidden' }}">
                <div class="flex items-center gap-3 mb-4">
                    <span class="material-symbols-outlined text-[#1A365D] animate-spin">progress_activity</span>
                    <span class="text-sm text-gray-700" id="statusLabel">
                        {{ $report->status === 'queued' ? 'Queued' : 'Generating' }}
                    </span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-2 mb-2">
                    <div id="progressBar" class="h-2 rounded-full bg-[#1A365D] transition-all"
                         style="width: {{ max(3, (int) $report->progress_pct) }}%"></div>
                </div>
                <p class="text-xs text-gray-500">
                    This page updates on its own. A board pack over a large register can take a minute or two —
                    you can leave this page and pick the document up from the report library.
                </p>
            </div>

            <div id="completedBlock" class="{{ $report->hasStoredFile() ? '' : 'hidden' }}">
                <div class="flex items-center gap-3 mb-4">
                    <span class="material-symbols-outlined text-green-600">check_circle</span>
                    <span class="text-sm font-medium text-gray-800">Ready</span>
                </div>
                <p class="text-xs text-gray-500 mb-5">
                    {{ $report->file_name }}
                    @if ($report->size_for_humans) · {{ $report->size_for_humans }} @endif
                </p>
                <a id="downloadLink"
                   href="{{ $report->hasStoredFile() ? route('risk.reports.download', $report) : '#' }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#1A365D] text-white rounded-lg text-sm font-semibold hover:bg-[#2D4A7A]">
                    <span class="material-symbols-outlined text-lg">download</span> Download
                </a>
            </div>

            <div id="failedBlock" class="{{ $report->status === 'failed' ? '' : 'hidden' }}">
                <div class="flex items-center gap-3 mb-3">
                    <span class="material-symbols-outlined text-red-600">error</span>
                    <span class="text-sm font-medium text-red-700">Generation failed</span>
                </div>
                <p class="text-xs text-gray-600 bg-red-50 border border-red-200 rounded p-3" id="errorText">
                    {{ $report->error_message ?? 'No further detail was recorded.' }}
                </p>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-100">
                <a href="{{ route('risk.reports.library') }}" class="text-xs text-[#1A365D] hover:underline">
                    Back to the report library
                </a>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
window.onPageReady(function () {
    const statusUrl = @json(route('risk.reports.status-json', $report));
    let pending = @json($report->isPending());

    if (!pending) {
        return;
    }

    // Poll rather than hold the connection open: a long-running board pack
    // should not tie up a PHP worker just to report its own progress.
    const poll = setInterval(async function () {
        try {
            const response = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
            if (!response.ok) { return; }

            const data = await response.json();

            document.getElementById('progressBar').style.width = Math.max(3, data.progress_pct) + '%';
            document.getElementById('statusLabel').textContent =
                data.status === 'queued' ? 'Queued' : 'Generating';

            if (data.status === 'completed' && data.download_url) {
                clearInterval(poll);
                document.getElementById('pendingBlock').classList.add('hidden');
                document.getElementById('downloadLink').href = data.download_url;
                document.getElementById('completedBlock').classList.remove('hidden');
            }

            if (data.status === 'failed') {
                clearInterval(poll);
                document.getElementById('pendingBlock').classList.add('hidden');
                document.getElementById('errorText').textContent =
                    data.error_message || 'No further detail was recorded.';
                document.getElementById('failedBlock').classList.remove('hidden');
            }
        } catch (e) {
            // A transient network error should not stop the poll.
        }
    }, 2000);
});
</script>
@endpush
