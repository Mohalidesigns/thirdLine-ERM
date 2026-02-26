@props(['status', 'type' => 'default'])

@php
    $normalized = strtolower(trim($status));

    /**
     * Type-based color mappings for different contexts.
     */
    $colorMaps = [
        'default' => [
            'active' => 'bg-green-100 text-green-700',
            'open' => 'bg-blue-100 text-blue-700',
            'closed' => 'bg-gray-100 text-gray-600',
            'pending' => 'bg-yellow-100 text-yellow-700',
            'in progress' => 'bg-blue-100 text-blue-700',
            'in-progress' => 'bg-blue-100 text-blue-700',
            'overdue' => 'bg-red-100 text-red-700',
            'completed' => 'bg-green-100 text-green-700',
            'draft' => 'bg-gray-100 text-gray-500',
            'approved' => 'bg-green-100 text-green-700',
            'rejected' => 'bg-red-100 text-red-700',
            'escalated' => 'bg-red-100 text-red-700',
            'mitigated' => 'bg-green-100 text-green-700',
            'accepted' => 'bg-blue-100 text-blue-700',
            'transferred' => 'bg-purple-100 text-purple-700',
            'under review' => 'bg-yellow-100 text-yellow-700',
            'expired' => 'bg-red-100 text-red-600',
        ],
        'treatment' => [
            'not started' => 'bg-gray-100 text-gray-600',
            'in progress' => 'bg-blue-100 text-blue-700',
            'completed' => 'bg-green-100 text-green-700',
            'overdue' => 'bg-red-100 text-red-700',
            'on track' => 'bg-green-100 text-green-700',
            'at risk' => 'bg-yellow-100 text-yellow-700',
            'delayed' => 'bg-red-100 text-red-700',
        ],
        'approval' => [
            'pending' => 'bg-yellow-100 text-yellow-700',
            'approved' => 'bg-green-100 text-green-700',
            'rejected' => 'bg-red-100 text-red-700',
            'returned' => 'bg-orange-100 text-orange-700',
        ],
    ];

    $map = $colorMaps[$type] ?? $colorMaps['default'];
    $classes = $map[$normalized] ?? 'bg-gray-100 text-gray-600';
@endphp

<span {{ $attributes->merge(['class' => "badge {$classes}"]) }}>
    {{ ucwords($status) }}
</span>
