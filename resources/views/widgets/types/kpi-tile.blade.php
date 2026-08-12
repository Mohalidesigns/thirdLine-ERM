{{-- kpi_tile — big number, delta chip, sparkline canvas (hydrated by JS). --}}
@php
    $data = $payload['data'] ?? [];
    $delta = $data['delta'] ?? null;
    $spark = collect($data['sparkline'] ?? [])->filter(fn ($p) => $p['value'] !== null);
@endphp

<div class="flex h-full flex-col justify-between">
    <div>
        <p class="text-3xl font-bold tabular-nums text-gray-900">
            @if(($data['value'] ?? null) !== null)
                {{ $data['formatted'] ?? number_format((float) $data['value'], 0) }}
                @if(!empty($data['unit']))<span class="ml-1 text-base font-medium text-gray-400">{{ $data['unit'] }}</span>@endif
            @else
                <span class="text-xl font-medium text-gray-300">No data</span>
            @endif
        </p>

        @if(is_array($delta))
            <p class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium
                      {{ ($delta['improving'] ?? false) ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">
                <span class="material-symbols-outlined text-[14px] leading-none">
                    {{ ($delta['absolute'] ?? 0) >= 0 ? 'arrow_upward' : 'arrow_downward' }}
                </span>
                {{ $delta['pct'] !== null ? number_format(abs($delta['pct']), 1).'%' : number_format(abs($delta['absolute']), 1) }}
                vs previous
            </p>
        @endif
    </div>

    @if($spark->count() >= 2)
        <div class="mt-2 h-10">
            <script type="application/json" data-sparkline-payload>@json(array_values($data['sparkline']))</script>
            <canvas data-widget-sparkline class="h-10 w-full"></canvas>
        </div>
    @endif
</div>
