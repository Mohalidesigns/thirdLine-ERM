@extends('layouts.app')

@section('title', 'Connectors')
@section('page-section', 'Administration')
@section('page-title', 'Connectors')

@section('content')
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">Connectors</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Scheduled reads from the systems that already hold your numbers, written into the measure engine.
            A connector's first run is a dry run: it reports what it would have written before it writes anything.
        </p>
    </div>

    @foreach (['success' => 'green', 'error' => 'red'] as $key => $tone)
        @if (session($key))
            <div class="mb-4 rounded-lg border border-{{ $tone }}-200 bg-{{ $tone }}-50 px-4 py-3 text-sm text-{{ $tone }}-800">
                {{ session($key) }}
            </div>
        @endif
    @endforeach

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-3 lg:col-span-2">
            @forelse ($connectors as $connector)
                <a href="{{ route('admin.connectors.show', $connector) }}"
                   class="block rounded-xl border border-gray-200 bg-white p-4 hover:border-[#1A365D]">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-gray-900">
                                {{ $connector->name }}
                                <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">{{ $connector->type }}</span>
                            </h3>
                            <p class="text-[11px] text-gray-500">
                                {{ $connector->healthLabel() }}
                                @if ($connector->schedule) · {{ $connector->schedule }} @endif
                                · {{ $connector->runs_count }} run(s)
                            </p>
                            @if ($connector->last_error)
                                <p class="mt-1 text-[11px] text-red-600">{{ Str::limit($connector->last_error, 140) }}</p>
                            @endif
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium
                            {{ $connector->is_active ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-700' }}">
                            {{ $connector->is_active ? 'Active' : 'Disabled' }}
                        </span>
                    </div>
                </a>
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                    No connectors yet.
                </div>
            @endforelse
        </div>

        @can('connector.manage')
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">New connector</h3>
                <form method="POST" action="{{ route('admin.connectors.store') }}" class="mt-3 space-y-3">
                    @csrf
                    <label class="block">
                        <span class="text-[11px] font-medium text-gray-500">Kind</span>
                        <select name="type" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            @foreach ($drivers as $type => $driver)
                                <option value="{{ $type }}">{{ $driver['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-[11px] font-medium text-gray-500">Name</span>
                        <input type="text" name="name" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-[11px] font-medium text-gray-500">Schedule</span>
                        <select name="schedule" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            <option value="">Manual only</option>
                            @foreach ($schedules as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="w-full rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">
                        Create
                    </button>
                    <p class="text-[10px] text-gray-400">
                        You configure its source and field mapping on the next screen.
                    </p>
                </form>
            </div>
        @endcan
    </div>
@endsection
