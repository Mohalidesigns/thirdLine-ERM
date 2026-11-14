<?php

namespace Tests\Feature;

use App\Support\ProductBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The product's tab icons, and the allowlist that keeps a configured value
 * from reaching a href. The tenant palette gets the same treatment in
 * AssetResidencyTest against App\Support\Branding; these are deployment
 * configuration rather than tenant data, but they interpolate into the same
 * document head and the failure mode is the same.
 */
class ProductBrandingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function configured_site_relative_icons_are_emitted_as_link_tags(): void
    {
        config()->set('branding.icons', [
            'svg' => '/Vantage-logo/Vantage-favicon.svg',
            'ico' => '/Vantage-logo/Vantage-favicon.ico',
            'apple_touch' => '/Vantage-logo/Vantage-apple-touch-icon-180.png',
        ]);

        $tags = ProductBranding::iconTags();

        $this->assertStringContainsString('<link rel="icon" type="image/svg+xml" href="/Vantage-logo/Vantage-favicon.svg">', $tags);
        $this->assertStringContainsString('<link rel="icon" type="image/x-icon" href="/Vantage-logo/Vantage-favicon.ico">', $tags);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/Vantage-logo/Vantage-apple-touch-icon-180.png">', $tags);

        // The SVG first: a browser takes the first rel="icon" whose type it
        // supports, so the .ico is left for the ones that do not.
        $this->assertLessThan(strpos($tags, 'image/x-icon'), strpos($tags, 'image/svg+xml'));
    }

    #[Test]
    public function nothing_is_emitted_when_nothing_is_configured(): void
    {
        config()->set('branding.icons', []);

        $this->assertSame('', ProductBranding::iconTags());
    }

    #[Test]
    public function a_value_that_is_not_a_site_relative_path_is_dropped(): void
    {
        // These reach a href in the document head, so a scheme, a
        // protocol-relative form and a quote that closes the attribute must
        // all be refused rather than printed.
        config()->set('branding.icons', [
            'svg' => 'javascript:alert(document.domain)',
            'ico' => '//elsewhere.test/x.ico',
            'apple_touch' => '/ok.png" onload="alert(1)',
        ]);

        $this->assertSame('', ProductBranding::iconTags());
    }

    #[Test]
    public function the_root_view_carries_the_tags(): void
    {
        config()->set('branding.icons', ['svg' => '/Vantage-logo/Vantage-favicon.svg']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('<link rel="icon" type="image/svg+xml" href="/Vantage-logo/Vantage-favicon.svg">', false);
    }

    #[Test]
    public function the_vendor_portal_root_view_stays_out_of_it(): void
    {
        // A tab icon naming the platform in a vendor's browser tells every
        // vendor of every client which platform that client runs. The portal
        // view's own docblock states the rule; this holds it in step.
        $portal = (string) file_get_contents(resource_path('views/tprm-portal.blade.php'));

        $this->assertStringNotContainsString('ProductBranding', $portal);
    }
}
