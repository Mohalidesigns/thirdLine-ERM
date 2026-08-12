{{-- measure_table — measure, type, responsible, implemented ✓, status glyph. --}}
@php
    $rows = $payload['data']['rows'] ?? [];
    $glyphs = [
        'effective' => ['check_circle', 'text-emerald-600'],
        'passed' => ['check_circle', 'text-emerald-600'],
        'partially_effective' => ['error', 'text-amber-500'],
        'partial' => ['error', 'text-amber-500'],
        'ineffective' => ['cancel', 'text-red-600'],
        'failed' => ['cancel', 'text-red-600'],
    ];
@endphp

<div class="h-full overflow-auto">
    <table class="min-w-full divide-y divide-gray-100 text-xs">
        <thead>
            <tr class="text-left text-[10px] uppercase tracking-wide text-gray-400">
                <th class="px-2 py-1.5 font-medium">Measure</th>
                <th class="px-2 py-1.5 font-medium">Type</th>
                <th class="px-2 py-1.5 font-medium">Responsible</th>
                <th class="px-2 py-1.5 text-center font-medium">Implemented</th>
                <th class="px-2 py-1.5 text-center font-medium">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @forelse($rows as $row)
                @php [$icon, $tone] = $glyphs[strtolower((string) ($row['glyph'] ?? ''))] ?? ['radio_button_unchecked', 'text-gray-300']; @endphp
                <tr class="hover:bg-gray-50">
                    <td class="max-w-[18rem] truncate px-2 py-1.5 font-medium text-gray-800" title="{{ $row['name'] }}">{{ $row['name'] }}</td>
                    <td class="px-2 py-1.5 text-gray-600">{{ $row['type'] ?? '—' }}</td>
                    <td class="px-2 py-1.5 text-gray-600">{{ $row['responsible'] ?? '—' }}</td>
                    <td class="px-2 py-1.5 text-center">
                        @if($row['implemented'] ?? false)
                            <span class="material-symbols-outlined text-[18px] leading-none text-emerald-600">check</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-2 py-1.5 text-center">
                        <span class="material-symbols-outlined text-[18px] leading-none {{ $tone }}">{{ $icon }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-2 py-6 text-center text-gray-400">No measures in scope.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
