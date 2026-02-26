<header class="fixed top-0 left-[260px] right-0 h-14 bg-white border-b border-gray-200 z-40 flex items-center justify-between px-6">
    {{-- Left: Breadcrumb Path --}}
    <div class="flex items-center gap-2 text-sm">
        <span class="text-gray-400 text-xs">Risk Management</span>
        @hasSection('page-section')
            <span class="text-gray-300">/</span>
            <span class="text-gray-400 text-xs">@yield('page-section')</span>
        @endif
        @hasSection('page-title')
            <span class="text-gray-300">/</span>
            <span class="text-[#1A365D] font-semibold text-xs">@yield('page-title')</span>
        @endif
    </div>

    {{-- Right: Actions & User --}}
    <div class="flex items-center gap-3">
        {{-- Notifications --}}
        <button class="p-1.5 rounded-lg hover:bg-gray-100 relative">
            <span class="material-symbols-outlined text-gray-500 text-xl">notifications</span>
            <span class="absolute top-1 right-1 w-1.5 h-1.5 bg-red-500 rounded-full"></span>
        </button>

        {{-- Settings --}}
        <button class="p-1.5 rounded-lg hover:bg-gray-100">
            <span class="material-symbols-outlined text-gray-500 text-xl">settings</span>
        </button>

        {{-- Divider --}}
        <div class="h-6 w-px bg-gray-200"></div>

        {{-- User Avatar & Info --}}
        @php
            $currentUser = auth()->user();
            $userName = $currentUser->name ?? 'Admin User';
            $userRole = $currentUser->job_title ?? 'Risk Administrator';
            $initials = collect(explode(' ', $userName))->map(fn($w) => strtoupper(substr($w, 0, 1)))->take(2)->implode('');
        @endphp
        <div class="flex items-center gap-2 cursor-pointer group relative">
            <div class="w-8 h-8 bg-[#1A365D] rounded-full flex items-center justify-center text-white text-[11px] font-bold">
                {{ $initials }}
            </div>
            <div class="hidden md:block">
                <div class="text-[12px] font-semibold text-gray-700 leading-tight">{{ $userName }}</div>
                <div class="text-[10px] text-gray-400">{{ $userRole }}</div>
            </div>
            <span class="material-symbols-outlined text-gray-400 text-[16px]">expand_more</span>

            {{-- User Dropdown --}}
            <div class="hidden group-hover:block absolute right-0 top-full mt-1 w-48 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-50">
                <a href="#" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    <span class="material-symbols-outlined text-[18px] text-gray-400">person</span>
                    Profile
                </a>
                <a href="{{ route('mfa.setup') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    <span class="material-symbols-outlined text-[18px] text-gray-400">verified_user</span>
                    2FA Setup
                </a>
                <div class="h-px bg-gray-100 my-1"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 w-full text-left">
                        <span class="material-symbols-outlined text-[18px]">logout</span>
                        Sign Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
