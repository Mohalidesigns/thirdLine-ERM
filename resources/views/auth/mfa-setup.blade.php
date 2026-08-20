@extends('layouts.app')

@section('title', 'Two-Factor Authentication Setup')

@section('page-section', 'Account Settings')
@section('page-title', 'Two-Factor Authentication')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <!-- Instructions -->
        <div class="space-y-6">
            <div class="bg-white rounded-lg shadow border border-gray-100 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">info</span>
                    Setup Instructions
                </h3>

                <ol class="space-y-4 text-sm text-gray-700">
                    <li class="flex gap-3">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-primary text-white flex items-center justify-center text-xs font-bold">1</span>
                        <span>Download an authenticator app like Google Authenticator, Microsoft Authenticator, or Authy</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-primary text-white flex items-center justify-center text-xs font-bold">2</span>
                        <span>Scan the QR code below with your authenticator app</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-primary text-white flex items-center justify-center text-xs font-bold">3</span>
                        <span>Enter the 6-digit code from your app below to verify</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-primary text-white flex items-center justify-center text-xs font-bold">4</span>
                        <span>Save your backup codes in a safe place</span>
                    </li>
                </ol>

                <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <p class="text-yellow-800 text-xs font-semibold">
                        <span class="material-symbols-outlined inline text-[16px]">warning</span>
                        Important: Keep your backup codes safe. You'll need them if you lose access to your authenticator.
                    </p>
                </div>
            </div>

            <!-- Manual Entry -->
            <div class="bg-white rounded-lg shadow border border-gray-100 p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Manual Entry (if QR doesn't work)</h3>
                <p class="text-xs text-gray-500 mb-2">Enter this key in your authenticator app:</p>
                <div class="bg-gray-50 rounded p-3 font-mono text-sm text-gray-700 break-all border border-gray-200">
                    {{ $secret }}
                </div>
            </div>
        </div>

        <!-- QR Code & Verification -->
        <div class="space-y-6">
            {{--
                THE QR CODE IS NOT RENDERED, AND MUST NOT BE UNTIL IT IS
                GENERATED IN-PROCESS.

                PREVIOUS BEHAVIOUR: this card rendered an <img> whose src was
                the $qrCodeUrl passed in by the controller — the
                https://api.qrserver.com/v1/create-qr-code/ URL built by
                AuthController::generateQrCodeUrl(). The query string carries the
                whole otpauth:// URI — the TOTP SHARED SECRET, the issuer and the
                enrolling user's EMAIL ADDRESS. Every render therefore handed a
                live authentication credential and a staff email to a third-party
                service outside the deployment, in the user's browser, before
                enrolment had even been confirmed. On a platform sold to Nigerian
                banks that is an unreviewed cross-border disclosure of an
                authentication credential, not a rendering convenience.

                The screen as a whole is unreachable today — mfa/setup is behind
                `feature:mfa_totp`, which defaults off (see config/features.php)
                — but the disclosure is removed here as well so that flipping the
                flag on cannot silently reintroduce it. $qrCodeUrl is still
                computed by the controller and passed to this view; it is simply
                not emitted. Nothing has been deleted.

                THE ENROLMENT PATH THIS LEAVES is the manual-entry key in the
                left-hand column, which is the same secret and works with every
                authenticator app. When the MFA rebuild lands, replace this block
                with a locally generated SVG or data-URI PNG — no request to any
                external host, and no secret in any URL.

                This is one of three defects behind the mfa_totp flag; the other
                two (sign-in cannot complete, and the four-byte time counter in
                verifyTotpCode()) are documented in config/features.php along
                with everything that must be true before the flag is turned on.
            --}}
            <!-- QR Code Card -->
            <div class="bg-white rounded-lg shadow border border-gray-100 p-8 flex flex-col items-center">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Scan QR Code</h3>
                <div class="w-48 h-48 mb-4 rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 flex flex-col items-center justify-center text-center px-4">
                    <span class="material-symbols-outlined text-gray-400 text-[32px]">qr_code_2</span>
                    <p class="text-xs text-gray-500 mt-2">QR code unavailable</p>
                </div>
                <p class="text-xs text-gray-500 text-center">
                    Use the manual entry key on the left. A scannable code will be
                    generated by this server once the authenticator rebuild lands —
                    it will never be produced by an external service, because the
                    request would carry your secret key off this platform.
                </p>
            </div>

            <!-- Verification Form -->
            <div class="bg-white rounded-lg shadow border border-gray-100 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Verify Setup</h3>

                @if ($errors->any())
                    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                        @foreach ($errors->all() as $error)
                            <p class="text-red-600 text-sm">{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('mfa.enable') }}" class="space-y-4">
                    @csrf

                    <input type="hidden" name="secret" value="{{ $secret }}">

                    <div>
                        <label for="code" class="block text-sm font-medium text-gray-700 mb-2">
                            Enter 6-Digit Code
                        </label>
                        <input
                            type="text"
                            id="code"
                            name="code"
                            maxlength="6"
                            inputmode="numeric"
                            placeholder="000000"
                            class="w-full text-center text-2xl font-mono letter-spacing tracking-widest border-2 border-gray-300 rounded-lg py-3 focus:border-primary focus:ring-2 focus:ring-primary/20 transition"
                            required
                            autofocus
                        >
                        <p class="text-xs text-gray-500 mt-2">Get this from your authenticator app</p>
                    </div>

                    <button
                        type="submit"
                        class="w-full bg-primary hover:bg-primary/90 text-white font-semibold py-2.5 rounded-lg transition duration-200"
                    >
                        Verify & Enable
                    </button>
                </form>

                <div class="mt-4">
                    <a href="{{ route('risk.dashboard') }}" class="text-sm text-gray-500 hover:text-gray-700">
                        Skip for now
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
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
@endpush
