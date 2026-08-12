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
         executes the callback immediately. --}}
    <script data-navigate-once>
        window.onPageReady = function (fn) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fn, { once: true });
            } else {
                fn();
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
            <span>CBN ORMS Compliant &middot; Basel III Aligned &middot; NDPA Certified</span>
        </div>
    </footer>

    @stack('scripts')

    {{-- Livewire's runtime configuration only. The Livewire + Alpine bundle
         itself is compiled into resources/js/app.js above, so exactly one
         Alpine is ever on the page — see the comment in that file. --}}
    @livewireScriptConfig
</body>
</html>
