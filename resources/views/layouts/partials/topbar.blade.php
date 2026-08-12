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
        {{-- WP-08: global search — type-ahead over the object graph,
             permission-filtered server-side. --}}
        @can('search.view')
            <div class="relative" x-data="globalSearch()" @click.outside="open = false" @keydown.escape.window="open = false">
                <span class="material-symbols-outlined pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[18px] text-gray-400">search</span>
                <input type="search" x-model="term" @input.debounce.300ms="suggest" @focus="term.length >= 2 && (open = true)"
                       @keydown.enter.prevent="submit"
                       placeholder="Search risks, controls, units…"
                       class="w-56 rounded-lg border-gray-200 bg-gray-50 py-1.5 pl-8 pr-2 text-xs focus:border-[--color-primary] focus:bg-white focus:ring-[--color-primary] lg:w-72" />
                <div x-show="open && results.length > 0" x-cloak x-transition.opacity
                     class="absolute right-0 top-full z-50 mt-1 w-96 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl">
                    <ul class="max-h-96 divide-y divide-gray-50 overflow-auto">
                        <template x-for="result in results" :key="result.id">
                            <li>
                                <a :href="result.url" class="flex items-center gap-2.5 px-3 py-2 hover:bg-gray-50">
                                    <span class="material-symbols-outlined shrink-0 text-[18px] text-gray-400" x-text="result.icon || 'topic'"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-xs font-medium text-gray-800" x-text="result.name"></span>
                                        <span class="block truncate text-[10px] text-gray-400">
                                            <span x-text="result.type"></span><span x-show="result.code"> · </span><span x-text="result.code"></span>
                                        </span>
                                    </span>
                                </a>
                            </li>
                        </template>
                    </ul>
                    <a :href="'{{ route('search.index') }}?q=' + encodeURIComponent(term)"
                       class="block border-t border-gray-100 px-3 py-2 text-center text-[11px] font-medium text-[--color-primary] hover:bg-gray-50">
                        All results →
                    </a>
                </div>
                <script>
                    function globalSearch() {
                        return {
                            term: '', results: [], open: false,
                            async suggest() {
                                if (this.term.trim().length < 2) { this.results = []; this.open = false; return; }
                                try {
                                    const response = await fetch('{{ route('search.suggest') }}?q=' + encodeURIComponent(this.term), { headers: { Accept: 'application/json' } });
                                    const body = await response.json();
                                    this.results = body.results ?? [];
                                    this.open = true;
                                } catch { this.results = []; }
                            },
                            submit() { window.location = '{{ route('search.index') }}?q=' + encodeURIComponent(this.term); },
                        };
                    }
                </script>
            </div>
        @endcan

        {{-- Reporting period. Everything on the page below is "as at" this. --}}
        @include('layouts.partials.period-selector')

        {{-- Notifications --}}
        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
            <button @click="open = !open" class="p-1.5 rounded-lg hover:bg-gray-100 relative">
                <span class="material-symbols-outlined text-gray-500 text-xl">notifications</span>
                @if (($unreadCount ?? 0) > 0)
                    <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center leading-none">
                        {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                    </span>
                @endif
            </button>

            {{-- Dropdown --}}
            <div x-show="open" x-cloak x-transition
                 class="absolute right-0 top-full mt-2 w-96 max-h-[70vh] bg-white rounded-xl shadow-xl border border-gray-200 z-50 flex flex-col">
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-semibold text-gray-900">Notifications</h3>
                        @if (($unreadCount ?? 0) > 0)
                            <span class="text-[10px] font-semibold bg-red-100 text-red-600 rounded-full px-2 py-0.5">{{ $unreadCount }} unread</span>
                        @endif
                    </div>
                    @if (($unreadCount ?? 0) > 0)
                        <form method="POST" action="{{ route('notifications.read-all') }}">
                            @csrf
                            <button type="submit" class="text-xs text-[#1A365D] font-medium hover:underline">Mark all read</button>
                        </form>
                    @endif
                </div>

                <div class="flex-1 overflow-y-auto">
                    @forelse (($recent ?? []) as $n)
                        @php
                            $isUnread = is_null($n->read_at);
                            $iconMap = [
                                'approval_request'  => ['icon' => 'rate_review', 'cls' => 'bg-blue-100 text-blue-600'],
                                'approval_approved' => ['icon' => 'check_circle', 'cls' => 'bg-green-100 text-green-600'],
                                'approval_rejected' => ['icon' => 'cancel', 'cls' => 'bg-red-100 text-red-600'],
                            ];
                            $vis = $iconMap[$n->type] ?? ['icon' => 'notifications', 'cls' => 'bg-gray-100 text-gray-600'];
                            $priorityCls = match ($n->priority ?? 'normal') {
                                'high' => 'border-l-red-500',
                                'low' => 'border-l-gray-200',
                                default => 'border-l-blue-500',
                            };
                        @endphp
                        <a href="{{ route('notifications.read', $n->id) }}"
                           class="flex gap-3 px-4 py-3 border-b border-gray-50 hover:bg-gray-50 border-l-4 {{ $isUnread ? $priorityCls . ' bg-blue-50/30' : 'border-l-transparent' }}">
                            <div class="w-8 h-8 rounded-full {{ $vis['cls'] }} flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-sm">{{ $vis['icon'] }}</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-xs font-semibold text-gray-900 {{ $isUnread ? '' : 'text-gray-600' }}">{{ $n->subject }}</p>
                                    @if ($isUnread)
                                        <span class="w-2 h-2 bg-blue-500 rounded-full flex-shrink-0 mt-1"></span>
                                    @endif
                                </div>
                                <p class="text-xs text-gray-600 mt-0.5 line-clamp-2 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($n->body, 140) }}</p>
                                <p class="text-[10px] text-gray-400 mt-1">{{ \Carbon\Carbon::parse($n->created_at)->diffForHumans() }}</p>
                            </div>
                        </a>
                    @empty
                        <div class="text-center py-10 text-gray-400">
                            <span class="material-symbols-outlined text-3xl block mb-1">notifications_off</span>
                            <p class="text-xs">No notifications</p>
                        </div>
                    @endforelse
                </div>

                <div class="px-4 py-2 border-t border-gray-100 text-center">
                    <a href="{{ route('notifications.index') }}" class="text-xs text-[#1A365D] font-medium hover:underline">View all notifications</a>
                </div>
            </div>
        </div>

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
                <a href="{{ route('admin.users.show', auth()->id()) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
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
