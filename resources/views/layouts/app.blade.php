<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'GRC Risk Management')</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Material Symbols Outlined -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Symbols+Outlined" rel="stylesheet">

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        primary: '#1A365D',
                        secondary: '#2D7D46',
                        accent: '#D4AF37',
                    }
                }
            }
        }
    </script>

    <!-- Global Styles -->
    <style>
        [x-cloak] { display: none !important; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #F7FAFC;
        }

        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #CBD5E0; border-radius: 3px; }

        /* Risk Rating Badges */
        .risk-critical { background: #FED7D7; color: #C53030; }
        .risk-high { background: #FEEBC8; color: #DD6B20; }
        .risk-medium { background: #FEFCBF; color: #B7791F; }
        .risk-low { background: #C6F6D5; color: #2F855A; }

        /* Generic Badge */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Trend Indicators */
        .trend-up { color: #C53030; }
        .trend-down { color: #2D7D46; }
        .trend-flat { color: #718096; }

        /* KPI Card */
        .kpi-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #E2E8F0;
        }

        /* Data Table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background: #F7FAFC;
            padding: 10px 12px;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            color: #4A5568;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #E2E8F0;
        }
        .data-table td {
            padding: 10px 12px;
            font-size: 0.8125rem;
            color: #2D3748;
            border-bottom: 1px solid #EDF2F7;
        }
        .data-table tr:hover { background: #F7FAFC; }

        /* Tabs */
        .tab-active { border-bottom: 2px solid #1A365D; color: #1A365D; font-weight: 600; }
        .tab-inactive { border-bottom: 2px solid transparent; color: #718096; }
    </style>

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

    {{-- Fix Chart.js infinite resize: wrap canvases in fixed-height containers --}}
    <script>
    (function() {
        document.querySelectorAll('canvas').forEach(function(canvas) {
            var h = canvas.getAttribute('height');
            if (!h) return;
            var parent = canvas.parentElement;
            if (parent.dataset.chartWrap) return;
            var wrapper = document.createElement('div');
            wrapper.dataset.chartWrap = '1';
            wrapper.style.position = 'relative';
            wrapper.style.height = h + 'px';
            wrapper.style.width = '100%';
            parent.insertBefore(wrapper, canvas);
            wrapper.appendChild(canvas);
        });
    })();
    </script>

    @stack('scripts')
</body>
</html>
