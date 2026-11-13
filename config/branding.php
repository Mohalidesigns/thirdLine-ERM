<?php

/*
|--------------------------------------------------------------------------
| Product branding
|--------------------------------------------------------------------------
|
| The PRODUCT's own mark, as deployment configuration. This is deliberately
| not App\Support\Branding, which re-themes the palette per TENANT from
| organizations.settings: nothing here is tenant-controlled, and a bank that
| licenses the platform can carry its own wordmark without a rebuild.
|
| The product NAME is not repeated here. config('app.name') already holds it,
| and it is also what the <title> tag and the TOTP issuer print, so a second
| source would let the sidebar and the browser tab disagree.
|
| `logo` is the mark for light surfaces and `logo_inverted` for dark ones. The
| sidebar and both unauthenticated shells are dark, so they prefer the inverted
| mark and fall back to `logo`, then to the generic icon the shared shell
| already drew. Paths are served out of public/ and must start with a slash.
|
*/

return [

    'tagline' => env('BRANDING_TAGLINE', 'GRC Suite'),

    'logo' => env('BRANDING_LOGO'),

    'logo_inverted' => env('BRANDING_LOGO_INVERTED'),

    // The browser-tab and touch icons, emitted as <link> tags by
    // App\Support\ProductBranding into the internal root view only. The vendor
    // portal deliberately gets none of them: a tab icon naming the platform is
    // exactly the leak that view's docblock exists to prevent.
    'icons' => [
        'svg' => env('BRANDING_ICON_SVG'),
        'ico' => env('BRANDING_ICON_ICO'),
        'apple_touch' => env('BRANDING_ICON_APPLE_TOUCH'),
    ],

];
