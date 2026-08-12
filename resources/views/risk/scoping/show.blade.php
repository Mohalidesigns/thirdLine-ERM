@extends('layouts.app')

@section('title', $entity->name . ' - Entity Detail - GRC Risk Management')
@section('page-section', 'Scoping')
@section('page-title', 'Entity Detail')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.scoping.dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Scoping</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.scoping.index') }}" class="text-gray-500 hover:text-[#1A365D]">Entity Register</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ $entity->entity_code }}</span>
@endsection

@section('content')
    {{-- Flash Messages --}}
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

    {{-- Entity Header --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <div class="flex items-start justify-between mb-4">
            <div>
                <div class="flex items-center gap-3 mb-2">
                    <h1 class="text-2xl font-bold text-[#1A365D]">{{ $entity->name }}</h1>
                    <x-status-badge :status="$entity->status ?? 'active'" />
                </div>
                <p class="text-sm text-gray-500">
                    Code: <strong>{{ $entity->entity_code }}</strong> |
                    Type: <strong>{{ $entity->entityType->name ?? '—' }} (L{{ $entity->level }})</strong>
                    @if ($entity->parent)
                        | Parent: <a href="{{ route('risk.scoping.show', $entity->parent) }}" class="text-[#1A365D] font-semibold hover:underline">{{ $entity->parent->name }}</a>
                    @endif
                </p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('risk.scoping.edit', $entity) }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">edit</span> Edit
                </a>
                <a href="{{ route('risk.scoping.dashboard') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">account_tree</span> Hierarchy
                </a>
            </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 pt-4 border-t border-gray-200">
            <div>
                <p class="text-xs text-gray-500 mb-1">Owner</p>
                <p class="font-semibold text-gray-800">{{ $entity->owner->name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-1">Delegate</p>
                <p class="font-semibold text-gray-800">{{ $entity->delegateOwner->name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-1">Created</p>
                <p class="font-semibold text-gray-800">{{ $entity->created_at->format('M d, Y') }}</p>
                <p class="text-xs text-gray-500">{{ $entity->created_at->diffForHumans() }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-1">Last Updated</p>
                <p class="font-semibold text-gray-800">{{ $entity->updated_at->format('M d, Y') }}</p>
                <p class="text-xs text-gray-500">{{ $entity->updated_at->diffForHumans() }}</p>
            </div>
        </div>
    </div>

    {{-- Tab Navigation --}}
    <div x-data="{ activeTab: 'overview' }">
        <div class="flex gap-1 mb-6 border-b border-gray-200 bg-white rounded-t-xl px-6">
            @foreach (['overview' => 'Overview', 'risks' => 'Risks', 'kris' => 'KRIs', 'issues' => 'Issues', 'sub_entities' => 'Sub-Entities'] as $tabKey => $tabLabel)
                <button @click="activeTab = '{{ $tabKey }}'"
                        :class="activeTab === '{{ $tabKey }}' ? 'border-[#1A365D] text-[#1A365D]' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-4 py-3 border-b-2 font-medium text-sm transition-colors">
                    {{ $tabLabel }}
                </button>
            @endforeach
        </div>

        {{-- Overview Tab --}}
        <div x-show="activeTab === 'overview'" class="space-y-6">
            {{-- Entity Summary --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-bold text-[#1A365D] mb-4">Entity Summary</h2>
                @if ($entity->description)
                    <p class="text-sm text-gray-600 leading-relaxed mb-4">{{ $entity->description }}</p>
                @endif
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div>
                        <p class="text-xs text-gray-500 mb-1">Regulatory Scope</p>
                        <p class="text-sm font-medium text-gray-800">{{ $entity->regulatory_frameworks_list }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 mb-1">Risk Appetite</p>
                        <p class="text-sm font-medium text-gray-800">{{ ucfirst($entity->risk_appetite_level ?? 'Not set') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 mb-1">Sub-Entities</p>
                        <p class="text-sm font-medium text-gray-800">{{ $subEntities->count() }}</p>
                    </div>
                </div>
            </div>

            {{-- Risk Posture KPIs --}}
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <x-kpi-card title="Total Risks" :value="$riskStats->total ?? 0" icon="assessment" color="primary" />
                <x-kpi-card title="Critical" :value="$riskStats->critical ?? 0" icon="error" color="danger" />
                <x-kpi-card title="High" :value="$riskStats->high ?? 0" icon="warning" color="warning" />
                <x-kpi-card title="Medium" :value="$riskStats->medium ?? 0" icon="info" color="info" />
                <x-kpi-card title="Low" :value="$riskStats->low ?? 0" icon="check_circle" color="success" />
            </div>

            {{-- Risk Distribution Chart --}}
            @if (!empty($categoryDistribution['labels']))
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="text-lg font-bold text-[#1A365D] mb-4">Risk Distribution by Category</h3>
                    <div style="height: 280px;">
                        <canvas id="riskDistributionChart"></canvas>
                    </div>
                </div>
            @endif

            {{-- Sub-Entity Risk Heatmap --}}
            @if ($subEntityHeatmap->isNotEmpty())
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-lg font-bold text-[#1A365D] mb-4">Sub-Entity Risk Heatmap</h2>
                    <x-data-table id="subEntityHeatmap">
                        <x-slot name="head">
                            <th>Sub-Entity</th>
                            <th>Type</th>
                            <th>Total Risks</th>
                            <th>Critical</th>
                            <th>High</th>
                            <th>Risk Score</th>
                            <th>Status</th>
                        </x-slot>
                        @foreach ($subEntityHeatmap as $sub)
                            <tr class="hover:bg-blue-50/50">
                                <td class="font-medium text-[#1A365D]">
                                    <a href="{{ route('risk.scoping.show', $sub) }}" class="hover:underline">{{ $sub->name }}</a>
                                </td>
                                <td>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                                        {{ $sub->entityType->name ?? '—' }}
                                    </span>
                                </td>
                                <td class="font-semibold">{{ $sub->risk_total }}</td>
                                <td>
                                    @if ($sub->critical_count > 0)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">{{ $sub->critical_count }}</span>
                                    @else
                                        <span class="text-gray-400">0</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($sub->high_count > 0)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-orange-100 text-orange-700">{{ $sub->high_count }}</span>
                                    @else
                                        <span class="text-gray-400">0</span>
                                    @endif
                                </td>
                                <td>
                                    @php $sc = $sub->risk_score; $scColor = $sc >= 4 ? 'text-red-600' : ($sc >= 3 ? 'text-orange-600' : 'text-green-600'); @endphp
                                    <span class="{{ $scColor }} font-bold">{{ $sc }}/5</span>
                                </td>
                                <td><x-status-badge :status="$sub->status ?? 'active'" /></td>
                            </tr>
                        @endforeach
                    </x-data-table>
                </div>
            @endif
        </div>

        {{-- Risks Tab --}}
        <div x-show="activeTab === 'risks'" x-cloak class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-bold text-[#1A365D] mb-4">Risks Assigned to {{ $entity->name }}</h2>
                <x-data-table id="entityRisksTable">
                    <x-slot name="head">
                        <th>Code</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Rating</th>
                        <th>Owner</th>
                    </x-slot>
                    @forelse ($risks as $risk)
                        <tr class="hover:bg-blue-50/50">
                            <td class="font-semibold text-[#1A365D]">
                                <a href="{{ route('risk.register.show', $risk) }}" class="hover:underline">{{ $risk->risk_code }}</a>
                            </td>
                            <td class="text-sm">{{ Str::limit($risk->title, 50) }}</td>
                            <td class="text-xs">{{ $risk->category->name ?? '—' }}</td>
                            <td><x-risk-badge :rating="$risk->inherent_rating ?? 'Medium'" /></td>
                            <td class="text-xs text-gray-600">{{ $risk->riskOwner->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8">
                                <p class="text-sm text-gray-500">No risks assigned to this entity yet.</p>
                            </td>
                        </tr>
                    @endforelse
                </x-data-table>
            </div>
        </div>

        {{-- KRIs Tab --}}
        <div x-show="activeTab === 'kris'" x-cloak class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-bold text-[#1A365D] mb-4">Key Risk Indicators</h2>
                <x-data-table id="entityKrisTable">
                    <x-slot name="head">
                        <th>Code</th>
                        <th>Name</th>
                        <th>Current Value</th>
                        <th>Status</th>
                        <th>Trend</th>
                    </x-slot>
                    @forelse ($kris as $kri)
                        <tr class="hover:bg-blue-50/50">
                            <td class="font-semibold text-[#1A365D]">{{ $kri->kri_code }}</td>
                            <td class="text-sm">{{ $kri->name ?? $kri->kri_name }}</td>
                            <td class="font-semibold">{{ $kri->current_value ?? '—' }}</td>
                            <td><x-status-badge :status="$kri->current_status ?? 'green'" /></td>
                            <td class="text-sm">{{ ucfirst($kri->trend_direction ?? '—') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8">
                                <p class="text-sm text-gray-500">No KRIs assigned to this entity.</p>
                            </td>
                        </tr>
                    @endforelse
                </x-data-table>
            </div>
        </div>

        {{-- Issues Tab --}}
        <div x-show="activeTab === 'issues'" x-cloak class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h2 class="text-lg font-bold text-[#1A365D] mb-4">Issues & Findings</h2>
                <x-data-table id="entityIssuesTable">
                    <x-slot name="head">
                        <th>Code</th>
                        <th>Title</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <th>Owner</th>
                    </x-slot>
                    @forelse ($issues as $issue)
                        <tr class="hover:bg-blue-50/50">
                            <td class="font-semibold text-[#1A365D]">
                                <a href="{{ route('risk.issues.show', $issue) }}" class="hover:underline">{{ $issue->issue_reference }}</a>
                            </td>
                            <td class="text-sm">{{ Str::limit($issue->title, 50) }}</td>
                            <td><x-risk-badge :rating="ucfirst($issue->severity ?? 'medium')" /></td>
                            <td><x-status-badge :status="$issue->status ?? 'open'" /></td>
                            <td class="text-xs text-gray-600">{{ $issue->responsibleOwner->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8">
                                <p class="text-sm text-gray-500">No issues assigned to this entity.</p>
                            </td>
                        </tr>
                    @endforelse
                </x-data-table>
            </div>
        </div>

        {{-- Sub-Entities Tab --}}
        <div x-show="activeTab === 'sub_entities'" x-cloak class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-bold text-[#1A365D]">Sub-Entities</h2>
                </div>
                <x-data-table id="subEntitiesTable">
                    <x-slot name="head">
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Risks</th>
                        <th>Issues</th>
                        <th>Status</th>
                    </x-slot>
                    @forelse ($subEntities as $sub)
                        <tr class="hover:bg-blue-50/50">
                            <td class="font-semibold text-[#1A365D]">
                                <a href="{{ route('risk.scoping.show', $sub) }}" class="hover:underline">{{ $sub->entity_code }}</a>
                            </td>
                            <td class="font-medium">{{ $sub->name }}</td>
                            <td>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                                    {{ $sub->entityType->name ?? '—' }}
                                </span>
                            </td>
                            <td class="font-semibold">{{ $sub->risks_count }}</td>
                            <td class="font-semibold">{{ $sub->issues_count }}</td>
                            <td><x-status-badge :status="$sub->status ?? 'active'" /></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8">
                                <p class="text-sm text-gray-500">No sub-entities under this entity.</p>
                            </td>
                        </tr>
                    @endforelse
                </x-data-table>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        window.onPageReady(function() {
            @if (!empty($categoryDistribution['labels']))
                const ctx = document.getElementById('riskDistributionChart');
                if (ctx) {
                    new Chart(ctx.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: @json($categoryDistribution['labels']),
                            datasets: [{
                                data: @json($categoryDistribution['data']),
                                backgroundColor: ['#1A365D', '#2D7D46', '#DD6B20', '#3182CE', '#D4AF37', '#ED8936', '#48BB78', '#9F7AEA'],
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
            @endif
        });
    </script>
    @endpush
@endsection
