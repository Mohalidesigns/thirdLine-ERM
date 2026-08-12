{{-- activity_table — name, responsible, start, end, progress bar, RAG. --}}
@php
    $rows = $payload['data']['rows'] ?? [];
    $ragColor = ['red' => 'bg-red-500', 'amber' => 'bg-amber-400', 'green' => 'bg-emerald-500'];
@endphp

<div class="h-full overflow-auto">
    <table class="min-w-full divide-y divide-gray-100 text-xs">
        <thead>
            <tr class="text-left text-[10px] uppercase tracking-wide text-gray-400">
                <th class="px-2 py-1.5 font-medium">Activity</th>
                <th class="px-2 py-1.5 font-medium">Responsible</th>
                <th class="px-2 py-1.5 font-medium">Start</th>
                <th class="px-2 py-1.5 font-medium">End</th>
                <th class="px-2 py-1.5 font-medium">Progress</th>
                <th class="w-6 px-2 py-1.5"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @forelse($rows as $row)
                <tr class="hover:bg-gray-50">
                    <td class="max-w-[16rem] truncate px-2 py-1.5 font-medium text-gray-800" title="{{ $row['name'] }}">{{ $row['name'] }}</td>
                    <td class="px-2 py-1.5 text-gray-600">{{ $row['responsible'] ?? '—' }}</td>
                    <td class="whitespace-nowrap px-2 py-1.5 tabular-nums text-gray-500">{{ $row['start'] ?? '—' }}</td>
                    <td class="whitespace-nowrap px-2 py-1.5 tabular-nums text-gray-500">{{ $row['end'] ?? '—' }}</td>
                    <td class="w-32 px-2 py-1.5">
                        <div class="flex items-center gap-1.5">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full {{ $ragColor[$row['rag'] ?? ''] ?? 'bg-gray-300' }}"
                                     style="width: {{ max(0, min(100, (int) ($row['progress_pct'] ?? 0))) }}%"></div>
                            </div>
                            <span class="w-8 text-right tabular-nums text-gray-500">{{ (int) ($row['progress_pct'] ?? 0) }}%</span>
                        </div>
                    </td>
                    <td class="px-2 py-1.5">
                        <span class="inline-block h-2.5 w-2.5 rounded-full {{ $ragColor[$row['rag'] ?? ''] ?? 'bg-gray-300' }}"
                              title="{{ strtoupper($row['rag'] ?? '') }} — {{ $row['status'] ?? '' }}"></span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-2 py-6 text-center text-gray-400">No activities in scope.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
