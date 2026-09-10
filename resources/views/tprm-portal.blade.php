<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- The client's name, not ours. A vendor answering a questionnaire is
         doing business with the bank, not with Atheris, and a portal that
         announces its vendor in the browser tab tells every vendor of every
         client which platform that client runs. Minimal Atheris footprint is
         part 10 of the phase, and this is where it starts. --}}
    <title inertia>{{ $portalTitle ?? 'Vendor Portal' }}</title>

    {{-- Tenant branding, same allowlisted mechanism the internal app uses. --}}
    {!! \App\Support\Branding::styleTag() !!}

    {{-- ONLY THE PORTAL'S OWN ROUTES. `@routes` with no group ships the entire
         internal route table to the browser; see config/ziggy.php for why that
         is not acceptable on this surface. --}}
    @routes('tprm-portal', nonce: Illuminate\Support\Facades\Vite::cspNonce())
    @viteReactRefresh
    @vite(['resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
