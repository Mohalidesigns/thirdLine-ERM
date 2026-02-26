@extends('layouts.app')

@section('title', 'Entity Register - GRC Risk Management')
@section('page-section', 'Scoping')
@section('page-title', 'Entity Register')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.scoping.dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Scoping</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Entity Register</span>
@endsection

@section('content')
    {{-- Success / Error Messages --}}
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm text-red-700">{{ session('error') }}</span>
        </div>
    @endif

    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Entity Register</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $totalCount }} total entities across the organization</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.scoping.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">add_circle</span> New Entity
            </a>
            <a href="{{ route('risk.scoping.dashboard') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">dashboard</span> Dashboard
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('risk.scoping.index') }}" id="filterForm">
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
            <div class="flex flex-wrap gap-3 items-center">
                {{-- Search --}}
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                        <input type="text" name="search" value="{{ request('search') }}"
                               placeholder="Search entity name or code..."
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                </div>

                {{-- Type Filter --}}
                <select name="entity_type_id" onchange="document.getElementById('filterForm').submit()"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    <option value="">All Types</option>
                    @foreach ($entityTypes as $type)
                        <option value="{{ $type->id }}" {{ request('entity_type_id') == $type->id ? 'selected' : '' }}>
                            {{ $type->name }}
                        </option>
                    @endforeach
                </select>

                {{-- Parent Filter --}}
                <select name="parent_id" onchange="document.getElementById('filterForm').submit()"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    <option value="">All Parents</option>
                    @foreach ($parentEntities as $pe)
                        <option value="{{ $pe->id }}" {{ request('parent_id') == $pe->id ? 'selected' : '' }}>
                            {{ $pe->name }}
                        </option>
                    @endforeach
                </select>

                {{-- Status Filter --}}
                <select name="status" onchange="document.getElementById('filterForm').submit()"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    <option value="">All Status</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    <option value="archived" {{ request('status') === 'archived' ? 'selected' : '' }}>Archived</option>
                </select>

                {{-- Clear --}}
                @if (request()->hasAny(['search', 'entity_type_id', 'parent_id', 'status']))
                    <a href="{{ route('risk.scoping.index') }}" class="text-sm text-gray-500 hover:text-[#1A365D] flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">close</span> Clear
                    </a>
                @endif
            </div>

            {{-- Quick Filter Pills --}}
            <div class="flex gap-2 flex-wrap mt-3">
                <a href="{{ route('risk.scoping.index') }}"
                   class="px-3 py-1 rounded-full text-xs font-semibold {{ !request('entity_type_id') ? 'bg-[#1A365D] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    All ({{ $totalCount }})
                </a>
                @foreach ($entityTypes as $type)
                    @php $count = $typeCounts[$type->id] ?? 0; @endphp
                    @if ($count > 0)
                        <a href="{{ route('risk.scoping.index', ['entity_type_id' => $type->id]) }}"
                           class="px-3 py-1 rounded-full text-xs font-semibold {{ request('entity_type_id') == $type->id ? 'bg-[#1A365D] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                            {{ $type->name }} ({{ $count }})
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
    </form>

    {{-- Entity Table --}}
    <x-data-table id="entityRegisterTable">
        <x-slot name="head">
            <th>Code</th>
            <th>Entity Name</th>
            <th>Type</th>
            <th>Parent Entity</th>
            <th>Level</th>
            <th>Risks</th>
            <th>KRIs</th>
            <th>Issues</th>
            <th>Owner</th>
            <th>Status</th>
            <th>Actions</th>
        </x-slot>

        @forelse ($entities as $entity)
            <tr class="hover:bg-blue-50/50">
                <td class="font-semibold text-[#1A365D]">
                    <a href="{{ route('risk.scoping.show', $entity) }}" class="hover:underline">{{ $entity->entity_code }}</a>
                </td>
                <td class="font-medium text-gray-800">{{ $entity->name }}</td>
                <td>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                        {{ $entity->entityType->name ?? '—' }}
                    </span>
                </td>
                <td class="text-xs text-gray-600">
                    @if ($entity->parent)
                        <a href="{{ route('risk.scoping.show', $entity->parent) }}" class="hover:underline">{{ $entity->parent->name }}</a>
                    @else
                        <span class="text-gray-400">&mdash;</span>
                    @endif
                </td>
                <td>
                    <span class="text-xs font-semibold text-gray-600">L{{ $entity->level }}</span>
                </td>
                <td class="font-semibold">{{ $entity->risks_count }}</td>
                <td class="font-semibold">{{ $entity->key_risk_indicators_count }}</td>
                <td class="font-semibold">{{ $entity->issues_count }}</td>
                <td class="text-xs text-gray-600">{{ $entity->owner->name ?? '—' }}</td>
                <td>
                    <x-status-badge :status="$entity->status ?? 'active'" />
                </td>
                <td>
                    <div class="flex items-center gap-1">
                        <a href="{{ route('risk.scoping.show', $entity) }}" class="p-1 hover:bg-gray-100 rounded" title="View">
                            <span class="material-symbols-outlined text-gray-400 text-lg">visibility</span>
                        </a>
                        <a href="{{ route('risk.scoping.edit', $entity) }}" class="p-1 hover:bg-gray-100 rounded" title="Edit">
                            <span class="material-symbols-outlined text-gray-400 text-lg">edit</span>
                        </a>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="11" class="text-center py-12">
                    <span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">account_tree</span>
                    <p class="text-sm text-gray-500">No entities found.</p>
                    <a href="{{ route('risk.scoping.create') }}" class="mt-2 inline-block text-sm text-[#1A365D] font-medium hover:underline">Create First Entity</a>
                </td>
            </tr>
        @endforelse
    </x-data-table>

    {{-- Pagination --}}
    @if ($entities->hasPages())
        <div class="mt-4 flex items-center justify-between">
            <span class="text-xs text-gray-500">Showing {{ $entities->firstItem() }}-{{ $entities->lastItem() }} of {{ $entities->total() }}</span>
            <div>{{ $entities->appends(request()->query())->links('vendor.pagination.tailwind') }}</div>
        </div>
    @endif
@endsection
