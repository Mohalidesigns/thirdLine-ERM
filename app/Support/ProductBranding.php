<?php

namespace App\Support;

/**
 * The product's own tab icons, as <link> tags for the Inertia root view.
 *
 * WHY THIS IS A CLASS AND NOT A @foreach IN app.blade.php. The same rule
 * App\Support\Branding states for the tenant palette: logic in the root view
 * is logic no test can reach, and these values are interpolated into the
 * document head. They are deployment configuration rather than tenant data,
 * but a misconfigured .env that reaches a href is still a broken or hostile
 * tag, so anything that is not a plain site-relative path is ignored rather
 * than printed. The allowlist is the control; escaping is not, for the reason
 * Branding gives — a value that passes the allowlist cannot carry a payload,
 * and one that fails it must not be printed at all.
 *
 * The vendor portal root view gets none of this on purpose. A favicon that
 * names the platform in a vendor's browser tab tells every vendor of every
 * client which platform that client runs, which is the leak that view's own
 * docblock exists to prevent.
 */
class ProductBranding
{
    /**
     * Favicon and touch-icon link tags, or an empty string when the deployment
     * configures none — the common case for a product shipped without a mark.
     */
    public static function iconTags(): string
    {
        $icons = (array) config('branding.icons', []);

        $tags = '';

        // SVG first: a browser takes the first rel="icon" whose type it
        // supports, so modern browsers get the crisp mark and the .ico is left
        // for the ones that do not.
        foreach (['svg' => 'image/svg+xml', 'ico' => 'image/x-icon'] as $key => $type) {
            $path = self::siteRelative($icons[$key] ?? null);

            if ($path !== null) {
                $tags .= '<link rel="icon" type="'.$type.'" href="'.$path.'">';
            }
        }

        $apple = self::siteRelative($icons['apple_touch'] ?? null);

        if ($apple !== null) {
            $tags .= '<link rel="apple-touch-icon" href="'.$apple.'">';
        }

        return $tags;
    }

    /**
     * A site-relative path, or null. No scheme, no protocol-relative form, no
     * quote or angle bracket: a href that can leave this origin is not a
     * favicon, it is a tracking pixel or worse.
     */
    private static function siteRelative(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^/[\w.-]+(/[\w.-]+)*$#', $value) ? $value : null;
    }
}
