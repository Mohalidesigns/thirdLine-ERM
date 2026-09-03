<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'Atheris ERM') }}</title>

    {{-- The Inertia root view. Every React page mounts here; the Blade layout
         (layouts/app.blade.php) keeps serving the screens that have not been
         ported yet. No font or script is fetched from a CDN: Inter and Material
         Symbols are self-hosted through resources/css/fonts.css, which app.css
         imports — a deployment inside a bank must not tell a third party who is
         using it and when. --}}
    {{-- Tenant branding overrides (--color-primary / --color-accent), the
         same partial the Blade layout includes. --}}
    @include('layouts.partials.branding')

    @routes
    @viteReactRefresh
    @vite(['resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
