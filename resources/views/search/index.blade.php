@extends('layouts.app')

@section('title', 'Search')
@section('page-section', 'Search')
@section('page-title', $term !== '' ? '“'.$term.'”' : 'Search')

@section('content')
<div class="mx-auto max-w-3xl">
    <form method="GET" action="{{ route('search.index') }}" class="mb-4">
        <div class="relative">
            <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">search</span>
            <input type="search" name="q" value="{{ $term }}" autofocus
                   placeholder="Search everything — risks, controls, issues, losses, KRIs, units…"
                   class="w-full rounded-xl border-gray-200 py-3 pl-11 pr-4 text-sm shadow-sm focus:border-[--color-primary] focus:ring-[--color-primary]" />
        </div>
    </form>

    @if($term !== '')
        <p class="mb-3 text-xs text-gray-500">{{ $results->count() }} {{ Str::plural('result', $results->count()) }}</p>

        <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            @forelse($results as $result)
                <li>
                    <a href="{{ $result['url'] }}" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50">
                        <span class="material-symbols-outlined shrink-0 rounded-lg bg-gray-100 p-2 text-[20px] leading-none text-gray-500">{{ $result['icon'] ?? 'topic' }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-800">{{ $result['name'] }}</span>
                            <span class="block truncate text-xs text-gray-400">
                                {{ $result['type'] }}@if($result['code']) · {{ $result['code'] }}@endif
                                @if($result['description']) — {{ $result['description'] }}@endif
                            </span>
                        </span>
                        <span class="material-symbols-outlined shrink-0 text-[18px] text-gray-300">chevron_right</span>
                    </a>
                </li>
            @empty
                <li class="px-4 py-10 text-center text-sm text-gray-400">
                    Nothing matched “{{ $term }}” within what you have permission to see.
                </li>
            @endforelse
        </ul>
    @endif
</div>
@endsection
