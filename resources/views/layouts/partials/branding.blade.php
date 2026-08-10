{{--
    Per-organization brand colours.

    The palette is declared as CSS custom properties in resources/css/app.css,
    so every Tailwind `bg-primary` / `text-primary` / `border-accent` utility
    resolves through var(--color-primary) at paint time. Re-declaring those
    variables here on :root re-themes the whole application for one tenant
    without a rebuild.

    Values come from organizations.settings->branding, e.g.

        {"branding": {"primary": "#0B3D2E", "secondary": "#C8102E", "accent": "#F2A900"}}

    Anything that is not a plain hex colour is ignored rather than printed:
    this value is tenant-controlled, and it is interpolated into a <style>
    block.
--}}
@php
    $branding = auth()->user()?->organization?->settings['branding'] ?? [];

    $palette = collect(['primary', 'secondary', 'accent'])
        ->mapWithKeys(fn (string $name) => [$name => $branding[$name] ?? null])
        ->filter(fn (?string $value) => is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value));
@endphp

@if ($palette->isNotEmpty())
    <style>
        :root {
            @foreach ($palette as $name => $value)
                --color-{{ $name }}: {{ $value }};
            @endforeach
        }
    </style>
@endif
