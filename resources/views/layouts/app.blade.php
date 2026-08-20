<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'GRC Risk Management')</title>

    {{-- All front-end assets are served from this deployment. Tailwind, Alpine,
         Chart.js, Inter and Material Symbols were previously fetched from three
         foreign CDNs on every page load, which no on-premise data-residency
         claim survives. The brand theme and every global style that used to sit
         inline below now live in resources/css/app.css. --}}
    {{-- SPA-navigation-safe replacement for DOMContentLoaded, defined as a
         classic head script because inline page scripts execute during HTML
         parsing — before Vite's deferred module bundle runs. On the first
         full page load it defers to DOMContentLoaded; after a wire:navigate
         visit (document already 'complete' when body scripts re-run) it
         executes the callback immediately.

         It sizes every chart canvas FIRST, because that has to happen before
         Chart.js is constructed — see wrapSizedCanvases below. --}}
    <script data-navigate-once>
        /*
         * Chart.js grows a canvas without bound when its parent's height is
         * decided by the canvas itself, so every sized canvas needs a
         * fixed-height, position:relative box around it.
         *
         * That box has to exist BEFORE the chart is constructed, for two
         * reasons:
         *   1. Chart.js binds its ResizeObserver to whatever the parent is at
         *      construction time. Re-parenting the canvas afterwards leaves
         *      the chart measuring a box it no longer lives in.
         *   2. Chart.js rewrites the canvas `height` ATTRIBUTE to
         *      cssHeight x devicePixelRatio. Reading that attribute after a
         *      chart exists therefore yields a box twice too tall on a retina
         *      screen — which is what used to push the doughnut on the
         *      Command Centre out over the KPI cards above it.
         *
         * The authored height is cached in data-chart-height on first sight,
         * so later runs (Livewire morphs, wire:navigate visits) stay correct
         * no matter what Chart.js has done to the attribute by then.
         */
        window.wrapSizedCanvases = function () {
            document.querySelectorAll('canvas').forEach(function (canvas) {
                var parent = canvas.parentElement;
                if (!parent || parent.dataset.chartWrap) return;

                /* Only ever size a canvas no chart has claimed yet. This runs
                   again after every Livewire morph and wire:navigate visit, and
                   by then the widget engine has built its own boxes
                   (.widget-chart / .widget-sparkline, which flex with their
                   GridStack panel) — nesting a fixed-height div inside one of
                   those would freeze the panel at its first size. */
                if (window.Chart && window.Chart.getChart(canvas)) return;
                if (parent.classList.contains('widget-chart') ||
                    parent.classList.contains('widget-sparkline')) return;

                /* The view often supplies the sized box itself; reuse it
                   rather than nesting a second one inside it. */
                if (parent.style.height) {
                    if (!parent.style.position) parent.style.position = 'relative';
                    parent.dataset.chartWrap = '1';
                    return;
                }

                var height = canvas.dataset.chartHeight;
                if (height === undefined) {
                    /* style.height wins: if a chart already rendered here the
                       attribute is in device pixels and lies. */
                    height = parseFloat(canvas.style.height) || canvas.getAttribute('height') || '';
                    if (height === '') return;
                    canvas.dataset.chartHeight = height;
                }

                var wrapper = document.createElement('div');
                wrapper.dataset.chartWrap = '1';
                wrapper.style.position = 'relative';
                wrapper.style.height = height + 'px';
                wrapper.style.width = '100%';
                parent.insertBefore(wrapper, canvas);
                wrapper.appendChild(canvas);
            });
        };

        window.onPageReady = function (fn) {
            var run = function () {
                window.wrapSizedCanvases();
                fn();
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', run, { once: true });
            } else {
                run();
            }
        };
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @include('layouts.partials.branding')

    {{-- Livewire's stylesheet, emitted explicitly because asset auto-injection
         is off (see AppServiceProvider). It is what hides a wire:loading
         element until a request is in flight; without it every component shows
         its idle and its loading label at the same time. --}}
    @livewireStyles

    @stack('styles')
</head>
<body class="bg-[#F7FAFC] min-h-screen">

    {{-- Sidebar --}}
    @include('layouts.partials.sidebar')

    {{-- Top Bar --}}
    @include('layouts.partials.topbar')

    {{-- Main Content --}}
    <main class="ml-[260px] mt-14 min-h-[calc(100vh-4rem)]">
        {{-- Breadcrumb Bar --}}
        <div class="px-6 py-3 bg-white border-b border-gray-100">
            <div class="flex items-center gap-2 text-xs text-gray-500">
                @yield('breadcrumbs')
            </div>
        </div>

        {{-- Flash Messages --}}
        @if(session('success'))
            <div class="mx-6 mt-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" x-transition>
                <span class="material-symbols-outlined text-green-600 text-xl">check_circle</span>
                <p class="text-green-800 text-sm font-medium flex-1">{{ session('success') }}</p>
                <button @click="show = false" class="text-green-400 hover:text-green-600">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </div>
        @endif

        @if(session('error'))
            <div class="mx-6 mt-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3" x-data="{ show: true }" x-show="show" x-transition>
                <span class="material-symbols-outlined text-red-600 text-xl">error</span>
                <p class="text-red-800 text-sm font-medium flex-1">{{ session('error') }}</p>
                <button @click="show = false" class="text-red-400 hover:text-red-600">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </div>
        @endif

        @if(session('warning'))
            <div class="mx-6 mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg flex items-center gap-3" x-data="{ show: true }" x-show="show" x-transition>
                <span class="material-symbols-outlined text-yellow-600 text-xl">warning</span>
                <p class="text-yellow-800 text-sm font-medium flex-1">{{ session('warning') }}</p>
                <button @click="show = false" class="text-yellow-400 hover:text-yellow-600">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </div>
        @endif

        {{-- Page Content --}}
        <div class="p-6">
            @yield('content')
        </div>
    </main>

    {{-- Footer --}}
    <footer class="ml-[260px] bg-white border-t border-gray-200 py-3 px-6">
        <div class="flex items-center justify-between text-[11px] text-gray-400">
            <span>GRC Risk Management Platform v2.0 &copy; {{ date('Y') }}</span>
            {{-- Design intent, not accreditation. This read "CBN ORMS Compliant
                 · Basel III Aligned · NDPA Certified". Nothing in this
                 repository issues, records or evidences any of those, and
                 "NDPA Certified" in particular names a certification that has
                 no issuer — the question a bank's DPO asks first. Same three
                 segments, same styling. --}}
            <span>Designed for CBN ORMS reporting &middot; Basel III-aligned taxonomy &middot; Built for NDPA obligations</span>
        </div>
    </footer>

    @stack('scripts')

    {{-- Livewire's runtime configuration only. The Livewire + Alpine bundle
         itself is compiled into resources/js/app.js above, so exactly one
         Alpine is ever on the page — see the comment in that file. --}}
    @livewireScriptConfig
</body>
</html>
