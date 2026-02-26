<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Verify Two-Factor Authentication - GRC Risk Management</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Material Symbols Outlined -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Symbols+Outlined" rel="stylesheet">

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

    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
        }

        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center" style="background: linear-gradient(135deg, #1A365D 0%, #2D7D46 100%);">

    <div class="w-full max-w-md px-4">
        <!-- Logo/Branding Area -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-lg bg-white/20 backdrop-blur-sm mb-4">
                <span class="material-symbols-outlined text-white text-4xl">verified_user</span>
            </div>
            <h1 class="text-white text-3xl font-bold">GRC Platform</h1>
            <p class="text-white/70 text-sm mt-2">Two-Factor Authentication</p>
        </div>

        <!-- MFA Verification Card -->
        <div class="bg-white rounded-xl shadow-2xl overflow-hidden">
            <div class="p-8">
                <h2 class="text-2xl font-bold text-gray-900 mb-1">Enter Verification Code</h2>
                <p class="text-gray-500 text-sm mb-6">Enter the 6-digit code from your authenticator app</p>

                @if ($errors->any())
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                        @foreach ($errors->all() as $error)
                            <p class="text-red-600 text-sm">{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('mfa.verify') }}" class="space-y-5">
                    @csrf

                    <!-- Code Input -->
                    <div>
                        <label for="code" class="block text-sm font-medium text-gray-700 mb-3">
                            Verification Code
                        </label>
                        <input
                            type="text"
                            id="code"
                            name="code"
                            maxlength="6"
                            inputmode="numeric"
                            placeholder="000000"
                            class="w-full text-center text-3xl font-mono letter-spacing tracking-widest border-2 border-gray-300 rounded-lg py-3 focus:border-primary focus:ring-2 focus:ring-primary/20 transition"
                            required
                            autofocus
                        >
                        <p class="text-xs text-gray-500 mt-2 text-center">From your authenticator app</p>
                    </div>

                    <!-- Verify Button -->
                    <button
                        type="submit"
                        class="w-full bg-primary hover:bg-primary/90 text-white font-semibold py-2.5 rounded-lg transition duration-200 mt-7"
                    >
                        Verify
                    </button>
                </form>

                <!-- Additional Options -->
                <div class="mt-6 space-y-3 border-t border-gray-200 pt-6">
                    <a href="#" class="block text-center text-sm text-primary hover:text-primary/80 font-medium">
                        Use backup code instead
                    </a>
                </div>
            </div>

            <!-- Footer -->
            <div class="px-8 py-4 bg-gray-50 border-t border-gray-100">
                <p class="text-center text-sm text-gray-500">
                    GRC Platform v2.0 | Secure Authentication
                </p>
            </div>
        </div>
    </div>

    <style>
        input[inputmode="numeric"]::-webkit-outer-spin-button,
        input[inputmode="numeric"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        input[inputmode="numeric"][type=number] {
            -moz-appearance: textfield;
        }

        .letter-spacing {
            letter-spacing: 0.5em;
        }
    </style>

</body>
</html>
