{{-- heatmap / opportunity_heatmap — CSS grid matrix, count badge per cell,
     click-through to the filtered register. Colours arrive in the payload
     from the scoring profile; nothing here assumes 5×5 or a palette. --}}
@php
    $data = $payload['data'] ?? [];
    $rows = (int) ($data['rows'] ?? 0);
    $cols = (int) ($data['cols'] ?? 0);
    $axis = $data['axis'] ?? ['likelihood' => [], 'impact' => []];
    $drill = $payload['drilldown'] ?? null;
    $impactValues = array_keys($axis['impact'] ?? []);
@endphp

@if($rows === 0 || $cols === 0)
    <p class="py-6 text-center text-xs text-gray-400">No scoring profile available.</p>
@else
    <div class="flex h-full flex-col">
        <div class="grid flex-1 gap-1"
             style="grid-template-columns: auto repeat({{ $cols }}, minmax(0, 1fr));">
            @foreach(collect($data['cells'] ?? [])->chunk($cols) as $rowCells)
                @php $first = $rowCells->first(); @endphp
                <div class="flex items-center justify-end pr-2 text-right text-[10px] leading-tight text-gray-500">
                    {{ $axis['likelihood'][$first['likelihood']] ?? $first['likelihood'] }}
                </div>
                @foreach($rowCells as $cell)
                    @php
                        $url = null;
                        if (is_array($drill) && isset($drill['route'])) {
                            try {
                                // WP-09: the register is a Livewire data grid;
                                // its filter state lives under filters[...].
                                $url = route($drill['route'], array_merge(
                                    $drill['params'] ?? [],
                                    ($cell['filters'] ?? []) !== [] ? ['filters' => $cell['filters']] : []
                                ));
                            } catch (\Throwable $e) { $url = null; }
                        }
                    @endphp
                    <a @if($url) href="{{ $url }}" @endif
                       class="group relative flex min-h-9 items-center justify-center rounded {{ $url ? 'cursor-pointer hover:ring-2 hover:ring-gray-900/30' : 'cursor-default' }}"
                       style="background-color: {{ $cell['color'] ?? '#e5e7eb' }}{{ $cell['count'] === 0 ? '55' : '' }};"
                       title="L{{ $cell['likelihood'] }} × C{{ $cell['impact'] }} — {{ $cell['count'] }}">
                        <span class="rounded-full px-1.5 text-xs font-bold tabular-nums
                                     {{ $cell['count'] > 0 ? 'bg-white/85 text-gray-900 shadow-sm' : 'text-gray-500/60' }}">
                            {{ $cell['count'] }}
                        </span>
                    </a>
                @endforeach
            @endforeach

            <div></div>
            @foreach($impactValues as $value)
                <div class="pt-1 text-center text-[10px] leading-tight text-gray-500">
                    {{ $axis['impact'][$value] ?? $value }}
                </div>
            @endforeach
        </div>

        <div class="mt-2 flex items-center justify-between text-[10px] text-gray-400">
            <span>{{ ($data['polarity'] ?? '') === 'opportunity' ? 'Likelihood ↑ · Benefit →' : 'Likelihood ↑ · Consequence →' }}</span>
            <span>{{ $data['total'] ?? 0 }} {{ ($data['polarity'] ?? '') === 'opportunity' ? 'opportunities' : 'risks' }}</span>
        </div>
    </div>
@endif
