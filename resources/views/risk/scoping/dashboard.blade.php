@extends('layouts.app')

@section('title', 'Entity & Scope Management - GRC Risk Management')
@section('page-section', 'Scoping')
@section('page-title', 'Entity Dashboard')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Scoping</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Entity Dashboard</span>
@endsection

@section('content')
    {{-- KPI Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Total Entities" :value="$totalEntities" icon="account_tree" color="primary" subtitle="Across all levels" />
        <x-kpi-card title="Active Risk Owners" :value="$activeOwners" icon="people" color="success" subtitle="Assigned to entities" />
        <x-kpi-card title="Exceeding Appetite" :value="$exceedingAppetite" icon="trending_up" color="danger" subtitle="Entities above tolerance" />
        <x-kpi-card title="Pending Assessments" :value="$pendingAssessments" icon="assignment" color="warning" subtitle="Risks without assessment" />
    </div>

    {{-- Organizational Hierarchy Tree --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-[#1A365D]">Organizational Hierarchy Tree</h2>
            <div class="flex gap-2">
                <a href="{{ route('risk.scoping.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">add_circle</span> New Entity
                </a>
            </div>
        </div>

        <div class="space-y-1 font-mono text-sm" x-data="{ expanded: {} }">
            @foreach ($hierarchyTree as $rootEntity)
                @include('risk.scoping._tree-node', ['entity' => $rootEntity, 'depth' => 0])
            @endforeach

            @if ($hierarchyTree->isEmpty())
                <div class="text-center py-12">
                    <span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">account_tree</span>
                    <p class="text-sm text-gray-500">No entities found. Create your first entity to build the hierarchy.</p>
                    <a href="{{ route('risk.scoping.create') }}" class="mt-2 inline-block text-sm text-[#1A365D] font-medium hover:underline">Create Entity</a>
                </div>
            @endif
        </div>
    </div>

    {{-- Entity Risk Heatmap --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-bold text-[#1A365D] mb-4">Entity Risk Heatmap</h2>
        <x-data-table id="entityHeatmapTable">
            <x-slot name="head">
                <th>Entity Name</th>
                <th>Type</th>
                <th>Total Risks</th>
                <th>Critical</th>
                <th>High</th>
                <th>Risk Score</th>
                <th>Status</th>
            </x-slot>

            @forelse ($entityHeatmap->take(10) as $ent)
                <tr class="hover:bg-blue-50/50">
                    <td class="font-medium text-[#1A365D]">
                        <a href="{{ route('risk.scoping.show', $ent) }}" class="hover:underline">{{ $ent->name }}</a>
                    </td>
                    <td>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                            {{ $ent->entityType->name ?? '—' }}
                        </span>
                    </td>
                    <td class="font-semibold">{{ $ent->risk_total }}</td>
                    <td>
                        @if ($ent->critical_count > 0)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">{{ $ent->critical_count }}</span>
                        @else
                            <span class="text-gray-400">0</span>
                        @endif
                    </td>
                    <td>
                        @if ($ent->high_count > 0)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-orange-100 text-orange-700">{{ $ent->high_count }}</span>
                        @else
                            <span class="text-gray-400">0</span>
                        @endif
                    </td>
                    <td>
                        @php
                            $scoreColor = $ent->risk_score >= 4 ? 'text-red-600' : ($ent->risk_score >= 3 ? 'text-orange-600' : ($ent->risk_score >= 2 ? 'text-yellow-600' : 'text-green-600'));
                        @endphp
                        <span class="{{ $scoreColor }} font-bold">{{ $ent->risk_score }}/5</span>
                    </td>
                    <td>
                        <x-status-badge :status="$ent->status ?? 'active'" />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center py-8">
                        <p class="text-sm text-gray-500">No entity risk data available yet.</p>
                    </td>
                </tr>
            @endforelse
        </x-data-table>
    </div>

    {{-- Bottom Section: Type Distribution + Recent Activity --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Entity Type Distribution Chart --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-[#1A365D] mb-4">Entity Type Distribution</h3>
            <div style="height: 280px;">
                <canvas id="entityTypeChart"></canvas>
            </div>
        </div>

        {{-- Recent Entity Activity --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-[#1A365D] mb-4">Recent Entity Activity</h3>
            <div class="space-y-3">
                @forelse ($recentActivity as $activity)
                    <div class="flex items-start gap-3 pb-3 {{ !$loop->last ? 'border-b border-gray-200' : '' }}">
                        <span class="material-symbols-outlined text-[18px] text-[#1A365D] mt-0.5">edit</span>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-gray-800">
                                <a href="{{ route('risk.scoping.show', $activity) }}" class="hover:underline">{{ $activity->name }}</a>
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ $activity->entityType->name ?? 'Entity' }} &mdash; Updated {{ $activity->updated_at->diffForHumans() }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 text-center py-4">No recent activity.</p>
                @endforelse
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('entityTypeChart');
            if (ctx) {
                new Chart(ctx.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: @json($typeDistribution['labels']),
                        datasets: [{
                            data: @json($typeDistribution['data']),
                            backgroundColor: ['#1A365D', '#2D7D46', '#D4AF37', '#DD6B20', '#3182CE', '#48BB78', '#ED8936', '#CBD5E0', '#9F7AEA'],
                            borderColor: '#FFFFFF',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { font: { size: 12 }, usePointStyle: true, padding: 15 } }
                        }
                    }
                });
            }
        });
    </script>
    @endpush
@endsection
