<?php

namespace ThirdLine\Reporting\Tests;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Reporting\Contracts\ResolvesDocumentBranding;
use ThirdLine\Reporting\DocumentRenderer;
use ThirdLine\Reporting\ReportingServiceProvider;

/**
 * The boundary this package exists to hold: a renderer that does not know what
 * an organisation is.
 */
class BrandingResolutionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ReportingServiceProvider::class];
    }

    #[Test]
    public function a_consumer_that_binds_no_resolver_renders_unbranded(): void
    {
        // Not an exception, and not another product's marks: a board pack
        // carrying the wrong institution's logo is worse than one carrying
        // none.
        $this->assertSame([], app(DocumentRenderer::class)->branding(new \stdClass));
    }

    #[Test]
    public function a_bound_resolver_decides_what_branding_is(): void
    {
        $this->app->bind(ResolvesDocumentBranding::class, fn () => new class implements ResolvesDocumentBranding
        {
            public function for(mixed $subject): array
            {
                return ['organization_name' => 'Test Bank PLC', 'cbn_institution_code' => '058'];
            }
        });

        $branding = $this->app->make(DocumentRenderer::class)->branding(new \stdClass);

        $this->assertSame('Test Bank PLC', $branding['organization_name']);
        // A product may return keys this package has never heard of. That is
        // the point: the templates read what they were given.
        $this->assertSame('058', $branding['cbn_institution_code']);
    }

    #[Test]
    public function a_null_subject_is_never_passed_to_the_resolver(): void
    {
        $this->app->bind(ResolvesDocumentBranding::class, fn () => new class implements ResolvesDocumentBranding
        {
            public function for(mixed $subject): array
            {
                throw new \LogicException('the resolver must not be asked about nothing');
            }
        });

        $this->assertSame([], $this->app->make(DocumentRenderer::class)->branding(null));
    }
}
