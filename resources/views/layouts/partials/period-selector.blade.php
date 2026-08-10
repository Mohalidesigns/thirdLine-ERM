{{--
    The reporting-period selector.

    Every dashboard, register and score on this platform is an "as at" view;
    this is the control that says as at WHEN. It lives in the top bar rather
    than on each screen because the period has to survive navigation — following
    a link from the dashboard into the register must not silently jump the user
    back to today.

    Rendered only when a period is bound. An organisation whose calendar could
    not be provisioned sees the chrome it saw before WP-04 rather than a broken
    control.
--}}
@if (!empty($selectedPeriod))
    @php
        // The current page, so the selector returns the user where they were
        // instead of to a redirect target. Path and query only — PeriodController
        // refuses anything that is not a local path.
        $currentPath = '/' . ltrim(request()->getRequestUri(), '/');
        $periodLink = fn (array $params) => route('risk.periods.select', $params + ['redirect' => $currentPath]);
    @endphp

    <div class="relative flex items-center gap-1 pl-3 pr-1 py-1 rounded-lg border border-gray-200 bg-gray-50"
         x-data="{ open: false }" @click.outside="open = false">

        <a href="{{ $periodLink(['direction' => 'previous']) }}"
           class="p-1 rounded hover:bg-white text-gray-500 hover:text-[#1A365D]"
           title="Previous {{ $selectedPeriod->type }}"
           aria-label="Previous period">
            <span class="material-symbols-outlined text-[18px] leading-none">chevron_left</span>
        </a>

        <button type="button" @click="open = !open"
                class="px-2 py-0.5 text-xs font-semibold text-[#1A365D] hover:bg-white rounded min-w-[104px]"
                aria-haspopup="true" :aria-expanded="open">
            {{ $selectedPeriod->name }}
            @if ($selectedPeriod->is_closed)
                <span class="material-symbols-outlined text-[13px] text-gray-400 align-middle" title="Closed — values locked">lock</span>
            @endif
        </button>

        <a href="{{ $periodLink(['direction' => 'next']) }}"
           class="p-1 rounded hover:bg-white text-gray-500 hover:text-[#1A365D]"
           title="Next {{ $selectedPeriod->type }}"
           aria-label="Next period">
            <span class="material-symbols-outlined text-[18px] leading-none">chevron_right</span>
        </a>

        {{-- Granularity + jump-to. --}}
        <div x-show="open" x-cloak x-transition
             class="absolute right-0 top-full mt-2 w-64 bg-white rounded-xl shadow-xl border border-gray-200 z-50 p-3">
            <p class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold mb-2">Granularity</p>
            <div class="grid grid-cols-4 gap-1 mb-3">
                @foreach (['month' => 'Month', 'quarter' => 'Qtr', 'half' => 'Half', 'year' => 'Year'] as $type => $label)
                    <a href="{{ $periodLink(['type' => $type]) }}"
                       class="text-center text-[11px] py-1 rounded border
                              {{ $selectedPeriod->type === $type
                                 ? 'bg-[#1A365D] text-white border-[#1A365D]'
                                 : 'border-gray-200 text-gray-600 hover:bg-gray-50' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <a href="{{ $periodLink(['direction' => 'current']) }}"
               class="block text-center text-[11px] py-1.5 rounded border border-gray-200 text-gray-600 hover:bg-gray-50 mb-2">
                Jump to today
            </a>

            @can('period.view')
                <a href="{{ route('risk.periods.index') }}"
                   class="block text-center text-[11px] py-1.5 rounded bg-gray-50 text-[#1A365D] font-medium hover:bg-gray-100">
                    Manage calendar &amp; close periods
                </a>
            @endcan

            <p class="text-[10px] text-gray-400 mt-2 leading-snug">
                {{ $selectedPeriod->start_date?->format('d M Y') }} &ndash;
                {{ $selectedPeriod->end_date?->format('d M Y') }}
                @if ($selectedPeriod->is_closed)
                    &middot; closed {{ $selectedPeriod->closed_at?->format('d M Y') }}
                @endif
            </p>
        </div>
    </div>
@endif
