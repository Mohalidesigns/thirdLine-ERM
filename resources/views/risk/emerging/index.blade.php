@extends('layouts.app')

@section('title', 'Emerging Risk Register - GRC Risk Management')
@section('page-section', 'Risk Intelligence')
@section('page-title', 'Emerging Risk Register')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Risk Intelligence</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Emerging Risk Register</span>
@endsection

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Emerging Risk Register</h1>
            <p class="text-sm text-gray-500 mt-1">
                Risks on the horizon that are not yet in the register proper.
            </p>
        </div>
        <div class="flex gap-2">
            @if (Route::has('risk.ai.radar'))
                <a href="{{ route('risk.ai.radar') }}"
                   class="px-4 py-2 border border-gray-300 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">radar</span> View radar
                </a>
            @endif
            <a href="{{ route('risk.emerging.create') }}"
               class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">add</span> Add entry
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    <form method="GET" class="bg-white rounded-xl border border-gray-200 p-4 mb-6 grid grid-cols-1 md:grid-cols-4 gap-4">
        <div>
            <label for="status" class="block text-xs font-medium text-gray-600 mb-1">Status</label>
            <select id="status" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <option value="">All</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="horizon" class="block text-xs font-medium text-gray-600 mb-1">Horizon</label>
            <select id="horizon" name="horizon" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <option value="">All</option>
                @foreach ($horizons as $horizon)
                    <option value="{{ $horizon }}" @selected(request('horizon') === $horizon)>{{ $horizon }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="impact" class="block text-xs font-medium text-gray-600 mb-1">Potential impact</label>
            <select id="impact" name="impact" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <option value="">All</option>
                @foreach ($impacts as $impact)
                    <option value="{{ $impact }}" @selected(request('impact') === $impact)>{{ $impact }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A]">Filter</button>
            <a href="{{ route('risk.emerging.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50">Reset</a>
        </div>
    </form>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium">Reference</th>
                        <th class="text-left px-4 py-3 font-medium">Title</th>
                        <th class="text-left px-4 py-3 font-medium">Category</th>
                        <th class="text-left px-4 py-3 font-medium">Horizon</th>
                        <th class="text-center px-4 py-3 font-medium">Velocity</th>
                        <th class="text-center px-4 py-3 font-medium">Proximity</th>
                        <th class="text-left px-4 py-3 font-medium">Impact</th>
                        <th class="text-left px-4 py-3 font-medium">Status</th>
                        <th class="text-left px-4 py-3 font-medium">Reviewed</th>
                        <th class="text-right px-4 py-3 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($entries as $entry)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-semibold text-[#1A365D]">{{ $entry->reference }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('risk.emerging.edit', $entry) }}" class="text-[#1A365D] hover:underline">
                                    {{ Str::limit($entry->title, 55) }}
                                </a>
                                @if ($entry->source)
                                    <span class="block text-[10px] text-gray-500 mt-0.5">{{ Str::limit($entry->source, 45) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $entry->category?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $entry->horizon }}</td>
                            <td class="px-4 py-3 text-center">{{ $entry->velocity_score }}/5</td>
                            <td class="px-4 py-3 text-center">{{ $entry->proximity_score }}/5</td>
                            <td class="px-4 py-3">
                                <span class="badge {{ $entry->potential_impact === 'Critical' ? 'bg-red-100 text-red-700' : ($entry->potential_impact === 'High' ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-700') }}">
                                    {{ $entry->potential_impact }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ ucfirst($entry->status) }}</td>
                            <td class="px-4 py-3 {{ $entry->last_reviewed_at === null ? 'text-amber-600 font-medium' : 'text-gray-600' }}">
                                {{ $entry->last_reviewed_at?->format('d M y') ?? 'Never' }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <form method="POST" action="{{ route('risk.emerging.review', $entry) }}">
                                        @csrf
                                        <button type="submit" class="text-gray-500 hover:text-[#1A365D]" title="Mark reviewed today">
                                            <span class="material-symbols-outlined text-base">event_available</span>
                                        </button>
                                    </form>
                                    <a href="{{ route('risk.emerging.edit', $entry) }}" class="text-gray-500 hover:text-[#1A365D]" title="Edit">
                                        <span class="material-symbols-outlined text-base">edit</span>
                                    </a>
                                    <form method="POST" action="{{ route('risk.emerging.destroy', $entry) }}"
                                          onsubmit="return confirm('Remove {{ $entry->reference }} from the register?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-gray-500 hover:text-red-600" title="Delete">
                                            <span class="material-symbols-outlined text-base">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-12 text-center">
                                <span class="material-symbols-outlined text-3xl text-gray-300 mb-2 block">radar</span>
                                <p class="text-sm text-gray-500">No emerging risks recorded yet.</p>
                                <a href="{{ route('risk.emerging.create') }}" class="text-xs text-[#1A365D] hover:underline mt-2 inline-block">
                                    Add the first entry
                                </a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($entries->hasPages())
            <div class="px-4 py-3 border-t border-gray-100">{{ $entries->links() }}</div>
        @endif
    </div>
@endsection
