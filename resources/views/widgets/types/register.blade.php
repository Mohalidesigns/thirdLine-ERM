{{-- register — paginated table with search, inline +, per-row ⋮. Row links
     and the + target come from the definition's drilldown block. --}}
@php
    $data = $payload['data'] ?? [];
    $columns = $data['columns'] ?? [];
    $rows = $data['rows'] ?? [];
    $drill = $payload['drilldown'] ?? null;
    $page = (int) ($data['page'] ?? 1);
    $perPage = max(1, (int) ($data['per_page'] ?? 10));
    $total = (int) ($data['total'] ?? 0);
    $lastPage = (int) ceil($total / $perPage);

    $rowUrl = function ($row) use ($drill) {
        if (! is_array($drill) || empty($drill['row_route'])) return null;
        try { return route($drill['row_route'], array_merge($drill['params'] ?? [], [$drill['row_param'] ?? 'id' => $row['id']])); }
        catch (\Throwable $e) { return null; }
    };

    $createUrl = null;
    if (is_array($drill) && !empty($drill['create_route'])) {
        try { $createUrl = route($drill['create_route'], $drill['params'] ?? []); } catch (\Throwable $e) { $createUrl = null; }
    }
@endphp

<div class="flex h-full flex-col">
    <div class="mb-2 flex items-center gap-2">
        <div class="relative flex-1">
            <span class="material-symbols-outlined pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[16px] text-gray-400">search</span>
            <input type="search" value="{{ $data['search'] ?? '' }}" placeholder="Search…"
                   wire:change="search($event.target.value)"
                   class="w-full rounded-md border-gray-200 py-1 pl-7 pr-2 text-xs focus:border-primary focus:ring-primary" />
        </div>
        @if($createUrl)
            <a href="{{ $createUrl }}"
               class="inline-flex items-center gap-1 rounded-md bg-[--color-primary] px-2 py-1 text-xs font-medium text-white hover:opacity-90">
                <span class="material-symbols-outlined text-[16px] leading-none">add</span> New
            </a>
        @endif
    </div>

    <div class="min-h-0 flex-1 overflow-auto">
        <table class="min-w-full divide-y divide-gray-100 text-xs">
            <thead>
                <tr class="text-left text-[10px] uppercase tracking-wide text-gray-400">
                    @foreach($columns as $column)
                        <th class="px-2 py-1.5 font-medium">{{ $column['label'] }}</th>
                    @endforeach
                    <th class="w-8"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($rows as $row)
                    @php $url = $rowUrl($row); @endphp
                    <tr class="hover:bg-gray-50">
                        @foreach($columns as $column)
                            @php $value = $row[$column['key']] ?? null; @endphp
                            <td class="px-2 py-1.5 text-gray-700">
                                @if($loop->first && $url)
                                    <a href="{{ $url }}" class="font-medium text-[--color-primary] hover:underline">{{ $value }}</a>
                                @elseif(in_array($column['key'], ['residual_rating', 'inherent_rating', 'priority', 'event_severity', 'current_status', 'issue_status', 'status'], true) && $value !== null)
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-700">{{ $value }}</span>
                                @else
                                    {{ is_numeric($value) && !is_int($value) ? number_format((float) $value, 1) : $value }}
                                @endif
                            </td>
                        @endforeach
                        <td class="px-1 text-right">
                            @if($url)
                                <a href="{{ $url }}" class="text-gray-300 hover:text-gray-600" title="Open">
                                    <span class="material-symbols-outlined text-[16px] leading-none">more_vert</span>
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 1 }}" class="px-2 py-6 text-center text-gray-400">Nothing in scope.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($lastPage > 1)
        <div class="mt-2 flex items-center justify-between text-[11px] text-gray-500">
            <span>{{ $total }} rows</span>
            <div class="flex items-center gap-1">
                <button type="button" wire:click="goToPage({{ $page - 1 }})" @disabled($page <= 1)
                        class="rounded px-1.5 py-0.5 hover:bg-gray-100 disabled:opacity-30">‹</button>
                <span>{{ $page }} / {{ $lastPage }}</span>
                <button type="button" wire:click="goToPage({{ $page + 1 }})" @disabled($page >= $lastPage)
                        class="rounded px-1.5 py-0.5 hover:bg-gray-100 disabled:opacity-30">›</button>
            </div>
        </div>
    @endif
</div>
