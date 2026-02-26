@props([
    'title',
    'value',
    'change' => null,
    'changeDirection' => null,
    'icon' => null,
    'color' => 'primary',
    'subtitle' => null,
])

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

    $iconColor = $colorMap[$color] ?? $colorMap['primary'];
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
    <div class="{{ $valueColor }} text-2xl font-bold">{{ $value }}</div>

    {{-- Subtitle --}}
    @if ($subtitle)
        <div class="text-xs text-gray-500 mt-1">{{ $subtitle }}</div>
    @endif

    {{-- Change Indicator --}}
    @if ($change)
        <div class="flex items-center gap-1 mt-1 {{ $trendClass }} text-xs">
            @if ($trendIcon)
                <span class="material-symbols-outlined text-sm">{{ $trendIcon }}</span>
            @endif
            {{ $change }}
        </div>
    @endif
</div>
