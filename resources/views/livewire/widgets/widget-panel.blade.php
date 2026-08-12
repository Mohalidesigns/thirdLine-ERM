{{-- WP-08 — one dashboard panel. The chrome (title, meta, ⋮ menu) is shared
     by every widget type; the body dispatches on type. Chart types render a
     JSON payload + mount for resources/js/widgets to hydrate; table-like
     types render server-side so they work without a single byte of JS. --}}
@php
    $state = $payload['state'] ?? 'error';
    $type = $payload['type'] ?? 'missing';
    $serverRendered = in_array($type, ['kpi_tile', 'heatmap', 'opportunity_heatmap', 'register', 'activity_table', 'measure_table'], true);
    $drill = $payload['drilldown'] ?? null;
    $drillUrl = null;

    if (is_array($drill) && isset($drill['route'])) {
        try {
            $drillUrl = route($drill['route'], $drill['params'] ?? []);
        } catch (\Throwable $e) {
            $drillUrl = null;
        }
    }
@endphp

<div class="widget-panel flex h-full flex-col rounded-lg border border-gray-200 bg-white shadow-sm"
     data-widget-code="{{ $payload['code'] ?? '' }}">
    <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-2.5">
        <div class="min-w-0">
            <h3 class="truncate text-sm font-semibold text-gray-800">{{ $payload['title'] ?? 'Widget' }}</h3>
            @if(!empty($payload['meta']['period']) || !empty($payload['meta']['node']))
                <p class="truncate text-xs text-gray-400">
                    {{ $payload['meta']['node'] ?? '' }}
                    @if(!empty($payload['meta']['period']) && !empty($payload['meta']['node'])) · @endif
                    {{ $payload['meta']['period'] ?? '' }}
                    @if(($payload['data']['as_of'] ?? null))
                        <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">as at {{ $payload['data']['as_of'] }}</span>
                    @endif
                </p>
            @endif
        </div>

        <div class="relative shrink-0" x-data="{ open: false }" @keydown.escape.window="open = false">
            <button type="button" @click="open = !open"
                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                    aria-label="Widget menu">
                <span class="material-symbols-outlined text-[20px] leading-none">more_vert</span>
            </button>
            <div x-cloak x-show="open" @click.outside="open = false" x-transition.opacity
                 class="absolute right-0 z-20 mt-1 w-44 rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg">
                <button type="button" wire:click="refresh" @click="open = false"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-gray-700 hover:bg-gray-50">
                    <span class="material-symbols-outlined text-[18px]">refresh</span> Refresh
                </button>
                <button type="button" wire:click="export" @click="open = false"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-gray-700 hover:bg-gray-50">
                    <span class="material-symbols-outlined text-[18px]">download</span> Export
                </button>
                @if($drillUrl)
                    <a href="{{ $drillUrl }}"
                       class="flex w-full items-center gap-2 px-3 py-1.5 text-gray-700 hover:bg-gray-50">
                        <span class="material-symbols-outlined text-[18px]">zoom_in</span> Drill down
                    </a>
                @endif
                {{-- Configure / Remove are injected by the builder around this panel. --}}
            </div>
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-auto p-3" wire:ignore.self>
        @if($state === 'forbidden')
            <div class="flex h-full flex-col items-center justify-center gap-1 py-6 text-gray-400">
                <span class="material-symbols-outlined text-3xl">lock</span>
                <p class="text-xs">You do not have permission to view this data.</p>
            </div>
        @elseif($state === 'error')
            <div class="flex h-full flex-col items-center justify-center gap-1 py-6 text-gray-400">
                <span class="material-symbols-outlined text-3xl">error_outline</span>
                <p class="text-xs">This widget could not be rendered.</p>
            </div>
        @elseif($serverRendered)
            @include('widgets.types.'.str_replace('_', '-', $type), ['payload' => $payload])
        @else
            {{-- Chart contract for resources/js/widgets — keep in sync with it. --}}
            <div class="widget-body h-full" data-widget data-widget-type="{{ $type }}" wire:key="chart-{{ $payload['widget_id'] ?? '' }}-{{ md5(json_encode($payload['data'] ?? [])) }}">
                <script type="application/json" data-widget-payload>@json($payload)</script>
                <div data-widget-mount class="h-full min-h-[180px]"></div>
            </div>
        @endif
    </div>
</div>
