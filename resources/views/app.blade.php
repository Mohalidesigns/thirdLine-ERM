<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'Atheris ERM') }}</title>

    {{-- The Inertia root view, and since migration Phase 6.8 the only page view
         in the application. No font or script is fetched from a CDN: Inter and
         Material Symbols are self-hosted through resources/css/fonts.css, which
         app.css imports — a deployment inside a bank must not tell a third
         party who is using it and when. --}}
    {{-- Tenant branding overrides (--color-primary / --color-accent). The
         allowlist that makes interpolating a tenant value into a <style> block
         safe lives in the class, where it is testable. --}}
    {!! \App\Support\Branding::styleTag() !!}

    {{-- Ziggy's route table. It is an INLINE script, and script-src is 'self'
         with no 'unsafe-inline' since Phase 6.8, so it must carry the nonce
         SetSecurityHeaders put in the header — without it the browser blocks
         the script, route() is never defined, and every page throws before it
         mounts. --}}
    @routes(nonce: Illuminate\Support\Facades\Vite::cspNonce())
    @viteReactRefresh
    @vite(['resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
