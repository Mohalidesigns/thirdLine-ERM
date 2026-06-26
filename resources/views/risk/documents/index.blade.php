@extends('layouts.app')

@section('title', 'Document Repository - GRC Risk Management')
@section('page-section', 'Documents')
@section('page-title', 'Repository')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Document Repository</span>
@endsection

@php
    $formatBytes = function ($bytes) {
        $bytes = (int) $bytes;
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return number_format($bytes / 1048576, 1)  . ' MB';
        if ($bytes >= 1024)       return number_format($bytes / 1024, 1)     . ' KB';
        return $bytes . ' B';
    };
    $iconForMime = function ($mime) {
        if (! $mime) return 'draft';
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'movie';
        if (str_starts_with($mime, 'audio/')) return 'audiotrack';
        if (str_contains($mime, 'pdf')) return 'picture_as_pdf';
        if (str_contains($mime, 'word') || str_contains($mime, 'msword') || str_contains($mime, 'officedocument.word')) return 'article';
        if (str_contains($mime, 'sheet') || str_contains($mime, 'excel') || str_contains($mime, 'csv')) return 'table_view';
        if (str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint')) return 'slideshow';
        if (str_contains($mime, 'zip') || str_contains($mime, 'compressed')) return 'folder_zip';
        return 'draft';
    };
@endphp

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-[#1A365D]">Document Repository</h1>
    <p class="text-sm text-gray-500 mt-1">Every file uploaded across Loss Events, Control Tests, and Issues — in one place.</p>
</div>

{{-- KPI row --}}
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <x-kpi-card icon="folder_open" title="Total Documents" :value="$summary['total']" color="primary" />
    <x-kpi-card icon="database" title="Storage" :value="$formatBytes($summary['total_size'])" color="info" />
    <x-kpi-card icon="report_problem" title="Loss Event" :value="$summary['by_source']['loss_event'] ?? 0" color="warning" />
    <x-kpi-card icon="verified" title="Control Test" :value="$summary['by_source']['control_test'] ?? 0" color="success" />
    <x-kpi-card icon="gavel" title="Regulatory Flagged" :value="$summary['regulatory']" color="danger" />
</div>

{{-- Filters --}}
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
    <form method="GET" action="{{ route('risk.documents.index') }}" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
        <div class="md:col-span-2">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Search file name</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="e.g. invoice, report…" data-live-search
                   class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Source</label>
            <select name="source" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                <option value="">All</option>
                @foreach ($sources as $s)
                    <option value="{{ $s['key'] }}" {{ ($filters['source'] ?? null) === $s['key'] ? 'selected' : '' }}>{{ $s['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Document Type</label>
            <select name="document_type" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                <option value="">All</option>
                @foreach ($docTypes as $dt)
                    <option value="{{ $dt }}" {{ ($filters['document_type'] ?? null) === $dt ? 'selected' : '' }}>{{ ucwords(str_replace('_', ' ', $dt)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">From</label>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">To</label>
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
        </div>
        <div class="md:col-span-6 flex items-center justify-between">
            <label class="flex items-center gap-2 text-xs text-gray-700">
                <input type="checkbox" name="regulatory" value="1" {{ ($filters['regulatory'] ?? false) ? 'checked' : '' }} class="rounded border-gray-300">
                Regulatory / compliance files only
            </label>
            <div class="flex gap-2">
                <a href="{{ route('risk.documents.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Clear</a>
                <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Apply filters
                </button>
            </div>
        </div>
    </form>
</div>

{{-- Document type chips --}}
@if ($summary['by_doc_type']->count())
    <div class="flex items-center gap-2 flex-wrap mb-4">
        <span class="text-xs text-gray-500 font-semibold">By document type:</span>
        @foreach ($summary['by_doc_type'] as $type => $count)
            <a href="{{ route('risk.documents.index', array_merge($filters, ['document_type' => $type])) }}"
               class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-[11px] font-medium border {{ ($filters['document_type'] ?? null) === $type ? 'bg-[#1A365D] text-white border-[#1A365D]' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50' }}">
                {{ ucwords(str_replace('_', ' ', $type)) }}
                <span class="opacity-70">{{ $count }}</span>
            </a>
        @endforeach
    </div>
@endif

{{-- Folder view: grouped by source (Loss Event / Control Test / Issue) --}}
@php $totalFiles = $folders->sum('count'); @endphp

@if ($totalFiles === 0)
    <div class="bg-white rounded-xl border border-gray-200 py-12 text-center">
        <span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">folder_off</span>
        <p class="text-sm text-gray-500">No documents found{{ array_filter($filters) ? ' for the current filters' : '' }}.</p>
    </div>
@else
    <div class="space-y-4">
        @foreach ($folders as $folder)
            @if ($folder->count === 0)
                @continue
            @endif
            <div x-data="{ open: @js($loop->first) }" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                {{-- Folder header --}}
                <button type="button" @click="open = !open"
                        class="w-full flex items-center gap-3 px-5 py-4 hover:bg-gray-50 transition-colors text-left">
                    <span class="w-10 h-10 rounded-lg bg-[#1A365D]/5 border border-[#1A365D]/10 flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-[#1A365D]" x-text="open ? 'folder_open' : 'folder'">folder</span>
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-semibold text-[#1A365D]">{{ $folder->label }}</span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 text-[11px] font-medium">
                                <span class="material-symbols-outlined text-[13px]">{{ $folder->icon }}</span>
                                {{ $folder->count }} {{ \Illuminate\Support\Str::plural('file', $folder->count) }}
                            </span>
                            <span class="text-[11px] text-gray-500">{{ $formatBytes($folder->total_size) }}</span>
                        </div>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 transition-transform"
                          :class="open ? 'rotate-180' : ''">expand_more</span>
                </button>

                {{-- Folder contents --}}
                <div x-show="open" x-cloak class="border-t border-gray-100 overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Linked To</th>
                                <th>Document Type</th>
                                <th>Size</th>
                                <th>Uploaded By</th>
                                <th>Uploaded</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($folder->files as $doc)
                                <tr class="hover:bg-gray-50">
                                    <td class="min-w-[260px]">
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-gray-400">{{ $iconForMime($doc->file_type) }}</span>
                                            <div class="min-w-0">
                                                <div class="text-sm font-medium text-gray-900 truncate">{{ $doc->file_name }}</div>
                                                @if ($doc->description)
                                                    <div class="text-[11px] text-gray-500 truncate max-w-[260px]">{{ \Illuminate\Support\Str::limit($doc->description, 60) }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        @if ($doc->parent_link)
                                            <a href="{{ $doc->parent_link }}" class="text-sm text-[#1A365D] font-medium hover:underline">{{ $doc->parent_label }}</a>
                                        @else
                                            <span class="text-sm text-gray-400">{{ $doc->parent_label }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($doc->document_type)
                                            <span class="inline-block px-2 py-0.5 rounded bg-gray-100 text-gray-700 text-[11px]">{{ ucwords(str_replace('_', ' ', $doc->document_type)) }}</span>
                                        @else
                                            <span class="text-xs text-gray-400">—</span>
                                        @endif
                                        @if ($doc->is_regulatory)
                                            <span class="ml-1 inline-block px-2 py-0.5 rounded bg-yellow-100 text-yellow-700 text-[11px] font-semibold">Regulatory</span>
                                        @endif
                                    </td>
                                    <td class="text-xs text-gray-600">{{ $formatBytes($doc->size_bytes) }}</td>
                                    <td class="text-xs text-gray-600">{{ $doc->uploaded_by }}</td>
                                    <td class="text-xs text-gray-500">
                                        <div>{{ $doc->uploaded_at?->format('d M Y') }}</div>
                                        <div class="text-[10px] text-gray-400">{{ $doc->uploaded_at?->format('H:i') }}</div>
                                    </td>
                                    <td class="text-right">
                                        @if ($doc->download_link)
                                            <a href="{{ $doc->download_link }}" class="inline-flex items-center gap-1 px-2 py-1 rounded hover:bg-gray-100 text-[#1A365D] text-xs font-medium" title="Download">
                                                <span class="material-symbols-outlined text-sm">download</span>
                                            </a>
                                        @endif
                                        @if ($doc->parent_link)
                                            <a href="{{ $doc->parent_link }}" class="inline-flex items-center gap-1 px-2 py-1 rounded hover:bg-gray-100 text-gray-500 text-xs" title="View parent">
                                                <span class="material-symbols-outlined text-sm">open_in_new</span>
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
