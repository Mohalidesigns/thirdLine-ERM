@extends('layouts.app')

@section('title', 'Configuration Builder')
@section('page-section', 'Administration')
@section('page-title', 'Configuration Builder')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Administration</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Configuration Builder</span>
@endsection

@section('content')
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">Configuration Builder</h2>
        <p class="text-sm text-gray-500 mt-1">
            Define what kinds of thing this organisation governs, what they record, how they connect,
            what states they move through and how they are scored — without a code change or a release.
        </p>
    </div>

    @php
        $cards = [
            [
                'route' => 'admin.builder.object-types',
                'permission' => 'admin.metadata',
                'icon' => 'category',
                'title' => 'Object types',
                'count' => $typeCount,
                'noun' => 'types',
                'blurb' => 'The kinds of thing that exist: risks, controls, third parties, projects. Add your own, or inherit from an existing one.',
            ],
            [
                'route' => 'admin.builder.object-types',
                'permission' => 'admin.metadata',
                'icon' => 'view_list',
                'title' => 'Fields',
                'count' => $attributeCount,
                'noun' => 'fields configured',
                'blurb' => 'What each type records. Every data type, validation rules, conditional visibility and calculated fields.',
            ],
            [
                'route' => 'admin.builder.relationship-types',
                'permission' => 'admin.metadata',
                'icon' => 'account_tree',
                'title' => 'Relationship types',
                'count' => $relationshipTypeCount,
                'noun' => 'edge types',
                'blurb' => 'How things connect, and with what weight — which is what makes roll-up across the graph possible.',
            ],
            [
                'route' => 'admin.builder.lifecycles',
                'permission' => 'admin.metadata',
                'icon' => 'linear_scale',
                'title' => 'Lifecycles',
                'count' => $lifecycleCount,
                'noun' => 'state machines',
                'blurb' => 'The states a record moves through, the transitions between them, and the permission each transition needs.',
            ],
            [
                'route' => 'admin.builder.scoring-profiles',
                'permission' => 'admin.scoring',
                'icon' => 'grid_on',
                'title' => 'Scoring profiles',
                'count' => $scoringProfileCount,
                'noun' => 'profiles',
                'blurb' => 'What a score means: matrix size, scale definitions, impact dimensions, rating bands and the residual formula.',
            ],
            [
                'route' => 'admin.configuration',
                'permission' => 'admin.configuration',
                'icon' => 'inventory_2',
                'title' => 'Configuration bundles',
                'count' => null,
                'noun' => '',
                'blurb' => 'Export this configuration, diff it against another environment, and import it with a dry run first.',
            ],
        ];
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach ($cards as $card)
            @can($card['permission'])
                <a href="{{ route($card['route']) }}"
                   class="block bg-white rounded-xl border border-gray-200 p-5 hover:border-[#1A365D] hover:shadow-sm transition">
                    <div class="flex items-start justify-between">
                        <span class="material-symbols-outlined text-[#1A365D] text-2xl">{{ $card['icon'] }}</span>
                        @if ($card['count'] !== null)
                            <span class="text-2xl font-bold text-gray-900">{{ $card['count'] }}</span>
                        @endif
                    </div>
                    <h3 class="mt-3 text-sm font-semibold text-gray-900">{{ $card['title'] }}</h3>
                    @if ($card['noun'])
                        <p class="text-[11px] uppercase tracking-wide text-gray-400">{{ $card['noun'] }}</p>
                    @endif
                    <p class="mt-2 text-xs text-gray-500 leading-relaxed">{{ $card['blurb'] }}</p>
                </a>
            @endcan
        @endforeach
    </div>

    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5">
        <div class="flex gap-3">
            <span class="material-symbols-outlined text-amber-600">warning</span>
            <div class="text-xs text-amber-900 leading-relaxed">
                <p class="font-semibold">Changes here affect every record in this organisation.</p>
                <p class="mt-1">
                    Resizing a scoring matrix re-rates the whole register. Changing a field's data type can lose
                    what is already stored in it. Deleting a relationship type archives the relationships that use it.
                    Each screen tells you what it is about to affect before it does it — read that, and export a
                    configuration bundle first if the change is large.
                </p>
            </div>
        </div>
    </div>
@endsection
