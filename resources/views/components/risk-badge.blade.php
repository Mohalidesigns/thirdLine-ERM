@props(['rating'])

@php
    $normalized = strtolower(trim($rating));

    $classes = match ($normalized) {
        'critical' => 'risk-critical',
        'high' => 'risk-high',
        'medium' => 'risk-medium',
        'low' => 'risk-low',
        default => 'bg-gray-100 text-gray-600',
    };
@endphp

<span {{ $attributes->merge(['class' => "badge {$classes}"]) }}>
    {{ ucfirst($normalized) }}
</span>
