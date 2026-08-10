<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Reset Password - GRC Risk Management</title>

    {{-- All front-end assets are served from this deployment. Tailwind, Alpine,
         Chart.js, Inter and Material Symbols were previously fetched from three
         foreign CDNs on every page load, which no on-premise data-residency
         claim survives. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center" style="background: linear-gradient(135deg, #1A365D 0%, #2D7D46 100%);">

    <div class="w-full max-w-md px-4">
        <!-- Logo/Branding Area -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-lg bg-white/20 backdrop-blur-sm mb-4">
                <span class="material-symbols-outlined text-white text-4xl">password</span>
            </div>
            <h1 class="text-white text-3xl font-bold">GRC Platform</h1>
            <p class="text-white/70 text-sm mt-2">Enterprise Risk & Compliance Management</p>
        </div>

        <!-- Reset Card -->
        <div class="bg-white rounded-xl shadow-2xl overflow-hidden">
            <div class="p-8">
                <h2 class="text-2xl font-bold text-gray-900 mb-1">Set New Password</h2>
                <p class="text-gray-500 text-sm mb-6">Enter your email and new password to reset your account</p>

                @if ($errors->any())
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                        @foreach ($errors->all() as $error)
                            <p class="text-red-600 text-sm">{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
                    @csrf

                    <input type="hidden" name="token" value="{{ $token }}">

                    <!-- Email Field -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                        <div class="relative">
                            <span class="absolute left-3 top-3.5 material-symbols-outlined text-gray-400 text-[20px]">mail</span>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="{{ old('email') }}"
                                required
                                autofocus
                                class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="you@example.com"
                            >
                        </div>
                    </div>

                    <!-- Password Requirements Info -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                        <p class="text-blue-900 text-xs font-semibold mb-2">Password Requirements:</p>
                        <ul class="text-blue-800 text-xs space-y-1">
                            <li>• At least 12 characters</li>
                            <li>• One uppercase letter (A-Z)</li>
                            <li>• One lowercase letter (a-z)</li>
                            <li>• One number (0-9)</li>
                            <li>• One special character (!@#$%^&*)</li>
                        </ul>
                    </div>

                    <!-- New Password Field -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                        <div class="relative">
                            <span class="absolute left-3 top-3.5 material-symbols-outlined text-gray-400 text-[20px]">lock</span>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                required
                                class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="••••••••"
                            >
                        </div>
                    </div>

                    <!-- Confirm Password Field -->
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                        <div class="relative">
                            <span class="absolute left-3 top-3.5 material-symbols-outlined text-gray-400 text-[20px]">lock_outline</span>
                            <input
                                type="password"
                                id="password_confirmation"
                                name="password_confirmation"
                                required
                                class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="••••••••"
                            >
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        class="w-full bg-primary hover:bg-primary/90 text-white font-semibold py-2.5 rounded-lg transition duration-200 mt-7"
                    >
                        Reset Password
                    </button>
                </form>

                <!-- Back to Login -->
                <div class="mt-6 text-center">
                    <a href="{{ route('login') }}" class="text-sm text-primary hover:text-primary/80 font-medium">
                        Back to Login
                    </a>
                </div>
            </div>

            <!-- Footer -->
            <div class="px-8 py-4 bg-gray-50 border-t border-gray-100">
                <p class="text-center text-sm text-gray-500">
                    GRC Platform v2.0 | CBN ORMS Compliant | Basel III Aligned
                </p>
            </div>
        </div>
    </div>

</body>
</html>
