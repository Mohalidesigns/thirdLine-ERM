<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign In - Atheris ERM GRC Suite</title>

    {{-- All front-end assets are served from this deployment. Tailwind, Alpine,
         Chart.js, Inter and Material Symbols were previously fetched from three
         foreign CDNs on every page load, which no on-premise data-residency
         claim survives. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50">

    <div class="min-h-screen grid lg:grid-cols-2">

        {{-- Left: brand panel --}}
        <div class="relative bg-[#0F2544] text-white p-10 lg:p-14 flex flex-col justify-between overflow-hidden">
            {{-- Subtle pattern overlay --}}
            <div class="absolute inset-0 opacity-[0.08] pointer-events-none"
                 style="background-image: radial-gradient(circle at 20% 20%, #ffffff 1px, transparent 1px), radial-gradient(circle at 80% 60%, #ffffff 1px, transparent 1px); background-size: 48px 48px, 64px 64px;"></div>

            <div class="relative">
                {{-- Logo --}}
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-lg bg-[#D4AF37] flex items-center justify-center shadow-md">
                        <span class="material-symbols-outlined text-[#1A365D]" style="font-size: 28px;">verified_user</span>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold leading-tight">Atheris ERM</h1>
                        <p class="text-xs text-white/70">GRC Suite</p>
                    </div>
                </div>
            </div>

            <div class="relative mt-16 lg:mt-0">
                <h2 class="text-3xl lg:text-4xl font-bold leading-tight">Enterprise Risk Management</h2>
                <p class="mt-4 text-sm lg:text-base text-white/80 max-w-lg leading-relaxed">
                    Enterprise-grade governance, risk, and compliance platform built for the African market. Manage risks, ensure compliance, and protect your organization.
                </p>

                <div class="mt-8 grid grid-cols-2 gap-y-3 gap-x-6 max-w-md">
                    @foreach (['COSO ERM Aligned', 'CBN ORMS Compliant', 'ISO 31000', 'Basel III Ready'] as $feature)
                        <div class="flex items-center gap-2 text-sm">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#D4AF37] flex-shrink-0"></span>
                            <span class="text-white/90">{{ $feature }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="relative mt-12 lg:mt-0">
                <p class="text-xs text-white/50">&copy; {{ date('Y') }} Atheris ERM GRC Suite. All rights reserved.</p>
            </div>
        </div>

        {{-- Right: sign-in form --}}
        <div class="flex items-center justify-center p-6 lg:p-14 bg-gray-50">
            <div class="w-full max-w-md">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 lg:p-10">
                    <h2 class="text-2xl font-bold text-gray-900">Sign In</h2>
                    <p class="text-sm text-gray-500 mt-1">Access your GRC dashboard</p>

                    @if(session('success'))
                        <div class="mt-6 p-3 bg-green-50 border border-green-200 rounded-lg flex items-center gap-2">
                            <span class="material-symbols-outlined text-green-600 text-[18px]">check_circle</span>
                            <p class="text-green-700 text-sm">{{ session('success') }}</p>
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="mt-6 p-3 bg-red-50 border border-red-200 rounded-lg flex items-center gap-2">
                            <span class="material-symbols-outlined text-red-600 text-[18px]">error</span>
                            <p class="text-red-700 text-sm">{{ session('error') }}</p>
                        </div>
                    @endif

                    @if(session('warning'))
                        <div class="mt-6 p-3 bg-yellow-50 border border-yellow-200 rounded-lg flex items-center gap-2">
                            <span class="material-symbols-outlined text-yellow-600 text-[18px]">warning</span>
                            <p class="text-yellow-700 text-sm">{{ session('warning') }}</p>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mt-6 p-3 bg-red-50 border border-red-200 rounded-lg space-y-1">
                            @foreach ($errors->all() as $error)
                                <p class="text-red-600 text-sm flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[16px]">error</span>
                                    {{ $error }}
                                </p>
                            @endforeach
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
                        @csrf

                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-800 mb-1.5">Email Address</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="{{ old('email') }}"
                                required
                                autofocus
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] transition"
                            >
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-800 mb-1.5">Password</label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                required
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] transition"
                            >
                        </div>

                        <div class="flex items-center justify-between">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="remember" class="w-4 h-4 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                                <span class="text-sm text-gray-600">Remember me</span>
                            </label>
                            <a href="{{ route('password.request') }}" class="text-sm text-[#1A365D] hover:text-[#1A365D]/80 font-semibold">Forgot password?</a>
                        </div>

                        <button
                            type="submit"
                            class="w-full bg-[#1A365D] hover:bg-[#2D4A7A] text-white font-semibold py-3 rounded-lg transition duration-200"
                        >
                            Sign In
                        </button>
                    </form>

                    {{-- Single sign-on. The organization is identified from the
                         email domain, so staff never need to know their
                         organization's sign-in URL. --}}
                    @if ($ssoAvailable ?? false)
                        <div class="mt-6">
                            <div class="relative">
                                <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                                <div class="relative flex justify-center"><span class="bg-white px-3 text-xs text-gray-400">or</span></div>
                            </div>

                            <form method="POST" action="{{ route('sso.discover') }}" class="mt-6 space-y-3">
                                @csrf
                                <label for="sso_email" class="block text-sm font-medium text-gray-800">Sign in with your organization</label>
                                <input
                                    type="email"
                                    id="sso_email"
                                    name="email"
                                    value="{{ old('email') }}"
                                    placeholder="you@yourcompany.com"
                                    required
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] transition"
                                >
                                <button
                                    type="submit"
                                    class="w-full border border-[#1A365D] text-[#1A365D] hover:bg-[#1A365D]/5 font-semibold py-2.5 rounded-lg transition duration-200"
                                >
                                    Continue with single sign-on
                                </button>
                            </form>
                        </div>
                    @endif

                    <p class="mt-6 text-center text-sm text-gray-500">
                        Don't have an account?
                        <a href="mailto:admin@yourorg.com?subject=GRC%20Suite%20account%20request" class="font-semibold text-[#1A365D] hover:underline">Create one</a>
                    </p>
                </div>
            </div>
        </div>

    </div>

    {{-- Marks the bundle as manually started (app.js calls Livewire.start()).
         Without this, livewire.esm auto-starts a SECOND time on DOMContentLoaded
         and the duplicate Alpine plugin registration throws Alpine's $persist redefinition error. --}}
    @livewireScriptConfig
</body>
</html>
