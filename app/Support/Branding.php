<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Per-organization brand colours, as a <style> block for the Inertia shell.
 *
 * The palette is declared as CSS custom properties in resources/css/app.css,
 * so every Tailwind `bg-primary` / `text-primary` / `border-accent` utility
 * resolves through var(--color-primary) at paint time. Re-declaring those
 * variables on :root re-themes the whole application for one tenant without a
 * rebuild.
 *
 * Values come from organizations.settings->branding, e.g.
 *
 *     {"branding": {"primary": "#0B3D2E", "secondary": "#C8102E", "accent": "#F2A900"}}
 *
 * ANYTHING THAT IS NOT A PLAIN HEX COLOUR IS IGNORED RATHER THAN PRINTED.
 * This value is tenant-controlled and it is interpolated into a <style> block,
 * which is a script-injection sink in every browser that has ever existed. The
 * allowlist is the control; escaping is not, because a valid CSS colour that
 * escapes cleanly can still carry a payload into a stylesheet.
 *
 * WHY THIS IS A CLASS AND NOT A BLADE PARTIAL. It was
 * layouts/partials/branding.blade.php, included by both the Blade layout and
 * the Inertia shell, until migration Phase 6.8 deleted the layouts. Inlining it
 * into app.blade.php would have put an `@php` block in the one Blade file the
 * phase keeps — which acceptance criterion 1 forbids outside the PDF templates
 * and mailables, and rightly: logic in the root view is logic no test can
 * reach. Here the allowlist is directly testable.
 */
class Branding
{
    /**
     * The custom properties this tenant overrides, name => hex colour.
     *
     * @return array<string, string>
     */
    public static function palette(): array
    {
        $branding = Auth::user()?->organization?->settings['branding'] ?? [];

        if (! is_array($branding)) {
            return [];
        }

        $palette = [];

        foreach (['primary', 'secondary', 'accent'] as $name) {
            $value = $branding[$name] ?? null;

            if (is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
                $palette[$name] = $value;
            }
        }

        return $palette;
    }

    /**
     * The <style> block for the shell, or an empty string when this tenant has
     * no overrides — which is the common case, and emits nothing at all.
     */
    public static function styleTag(): string
    {
        $palette = self::palette();

        if ($palette === []) {
            return '';
        }

        $declarations = '';

        foreach ($palette as $name => $value) {
            $declarations .= "--color-{$name}: {$value};";
        }

        return '<style>:root{'.$declarations.'}</style>';
    }
}
