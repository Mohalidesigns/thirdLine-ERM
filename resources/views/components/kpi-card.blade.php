@props([
    'title',
    'value' => null,
    'change' => null,
    'changeDirection' => null,
    'icon' => null,
    'color' => 'primary',
    'subtitle' => null,
    'unavailable' => false,
    'unavailableLabel' => 'Not assessed',
])

{{--
    `unavailable` renders the tile as an explicit not-assessed state instead of
    a figure. It exists because callers previously papered over a missing
    metric with a literal — the Board report printed "Capital Adequacy 15.2%"
    in a green tile on the same screen as the narrative "No ICAAP assessment is
    on record for the current period". A tile that has nothing to show now says
    so, in neutral grey, and never borrows the success/danger colouring that
    would imply a reading was taken.
--}}

@php
    $colorMap = [
        'primary' => 'text-gray-400',
        'danger' => 'text-red-400',
        'warning' => 'text-yellow-500',
        'success' => 'text-green-500',
        'info' => 'text-blue-400',
    ];

    $valueColorMap = [
        'primary' => 'text-[#1A365D]',
        'danger' => 'text-red-600',
        'warning' => 'text-yellow-600',
        'success' => 'text-green-600',
        'info' => 'text-blue-600',
    ];

    $trendClass = match ($changeDirection) {
        'up' => 'trend-up',
        'down' => 'trend-down',
        'flat' => 'trend-flat',
        default => 'text-gray-500',
    };

    $trendIcon = match ($changeDirection) {
        'up' => 'trending_up',
        'down' => 'trending_down',
        'flat' => 'trending_flat',
        default => '',
    };

    // An unavailable tile is never coloured by severity: green on a figure
    // nobody produced reads as a passing result.
    $iconColor = $unavailable ? 'text-gray-300' : ($colorMap[$color] ?? $colorMap['primary']);
    $valueColor = $valueColorMap[$color] ?? $valueColorMap['primary'];
@endphp

<div {{ $attributes->merge(['class' => 'kpi-card']) }}>
    {{-- Header: Icon + Title --}}
    <div class="flex items-center gap-2 mb-2">
        @if ($icon)
            <span class="material-symbols-outlined text-lg {{ $iconColor }}">{{ $icon }}</span>
        @endif
        <span class="text-xs text-gray-500 font-medium">{{ $title }}</span>
    </div>

    {{-- Value --}}
    @if ($unavailable)
        <div class="text-gray-400 text-lg font-semibold italic">{{ $unavailableLabel }}</div>
    @else
        <div class="{{ $valueColor }} text-2xl font-bold">{{ $value }}</div>
    @endif

    {{-- Subtitle --}}
    @if ($subtitle)
        <div class="text-xs text-gray-500 mt-1">{{ $subtitle }}</div>
    @endif

    {{-- Change Indicator --}}
    @if ($change && ! $unavailable)
        <div class="flex items-center gap-1 mt-1 {{ $trendClass }} text-xs">
            @if ($trendIcon)
                <span class="material-symbols-outlined text-sm">{{ $trendIcon }}</span>
            @endif
            {{ $change }}
        </div>
    @endif
</div>
