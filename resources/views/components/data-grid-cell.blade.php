{{--
    One grid cell, rendered from a Column's declared type. Everything is
    escaped; definitions never hand us HTML (Column::using returns a plain
    string). Shared by the md+ table and the mobile card list.
--}}
@props(['column', 'row', 'definition', 'ragClasses'])

@php
    $value = $definition->valueOf($row, $column);
@endphp

@if ($column->linkTo)
    <a href="{{ ($column->linkTo)($row) }}" wire:navigate class="font-medium text-[#1A365D] hover:underline">
        {{ $value ?? '—' }}
    </a>
@elseif ($column->type === 'badge')
    @php
        $map = $column->typeOptions['map'];
        $classes = $map[$value] ?? $map['*'] ?? 'bg-gray-100 text-gray-600';
    @endphp
    <span class="badge {{ $classes }}">{{ ucfirst(str_replace('_', ' ', (string) ($value ?? '—'))) }}</span>
@elseif ($column->type === 'rag')
    @php
        $level = $column->typeOptions['map'][$value] ?? 'neutral';
    @endphp
    <span class="badge {{ $ragClasses[$level] }}">{{ ucfirst(str_replace('_', ' ', (string) ($value ?? '—'))) }}</span>
@elseif ($column->type === 'date' || $column->type === 'datetime')
    <span class="text-xs text-gray-500">{{ $value?->format($column->typeOptions['format']) ?? '—' }}</span>
@elseif ($column->type === 'money')
    <span class="text-xs tabular-nums">
        {{ $value === null ? '—' : '₦'.number_format((float) $value / ($column->typeOptions['minor'] ? 100 : 1), 2) }}
    </span>
@elseif ($column->type === 'progress')
    @php $pct = max(0, min(100, (int) $value)); @endphp
    <div class="flex items-center gap-2 min-w-[90px]">
        <div class="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full {{ $pct >= 100 ? 'bg-green-500' : 'bg-[#1A365D]' }}" style="width: {{ $pct }}%"></div>
        </div>
        <span class="text-[10px] text-gray-500 tabular-nums">{{ $pct }}%</span>
    </div>
@elseif ($column->type === 'count')
    {{ $value ?? 0 }}
@else
    <span class="text-xs">{{ $value ?? '—' }}</span>
@endif
