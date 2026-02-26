@php
    $hasChildren = $entity->descendants && $entity->descendants->count() > 0;
    $indent = $depth * 24;
    $levelColors = [
        0 => ['bg' => 'bg-[#1A365D]', 'text' => 'text-white', 'icon' => 'domain', 'iconColor' => 'text-[#1A365D]'],
        1 => ['bg' => 'bg-[#2D7D46]', 'text' => 'text-white', 'icon' => 'account_balance', 'iconColor' => 'text-[#2D7D46]'],
        2 => ['bg' => 'bg-[#D4AF37]', 'text' => 'text-[#1A365D]', 'icon' => 'store', 'iconColor' => 'text-[#D4AF37]'],
        3 => ['bg' => 'bg-green-500', 'text' => 'text-white', 'icon' => 'location_on', 'iconColor' => 'text-green-500'],
        4 => ['bg' => 'bg-gray-200', 'text' => 'text-gray-700', 'icon' => 'location_city', 'iconColor' => 'text-gray-500'],
    ];
    $color = $levelColors[$entity->level] ?? $levelColors[4];
@endphp

<div style="margin-left: {{ $indent }}px;">
    <a href="{{ route('risk.scoping.show', $entity) }}"
       class="flex items-center gap-2 py-1.5 px-2 rounded hover:bg-blue-50/50 transition-colors group">
        @if ($hasChildren)
            <span class="{{ $color['iconColor'] }} text-sm">&#9660;</span>
        @else
            <span class="text-gray-300 text-sm">&bull;</span>
        @endif
        <span class="material-symbols-outlined text-[16px] {{ $color['iconColor'] }}">{{ $entity->entityType->icon ?? $color['icon'] }}</span>
        <span class="font-semibold text-gray-800 group-hover:text-[#1A365D] {{ $depth === 0 ? 'text-[#1A365D]' : '' }}">{{ $entity->name }}</span>
        <span class="text-[11px] {{ $color['bg'] }} {{ $color['text'] }} px-1.5 py-0.5 rounded">L{{ $entity->level }} {{ $entity->entityType->name ?? '' }}</span>
    </a>

    @if ($hasChildren)
        @foreach ($entity->descendants as $child)
            @include('risk.scoping._tree-node', ['entity' => $child, 'depth' => $depth + 1])
        @endforeach
    @endif
</div>
