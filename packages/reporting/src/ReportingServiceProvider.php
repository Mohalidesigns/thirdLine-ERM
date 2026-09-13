<?php

namespace ThirdLine\Reporting;

use Illuminate\Support\ServiceProvider;
use ThirdLine\Reporting\Contracts\ResolvesDocumentBranding;

/**
 * Document rendering, auto-discovered.
 *
 * Safe to load unconditionally: it registers a renderer and changes nothing
 * until something asks for one. The one thing it does NOT do is bind
 * ResolvesDocumentBranding — a product binds its own, and one that binds
 * nothing renders unbranded rather than carrying another product's marks.
 */
class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentRenderer::class, fn ($app) => new DocumentRenderer(
            $app->bound(ResolvesDocumentBranding::class)
                ? $app->make(ResolvesDocumentBranding::class)
                : null
        ));
    }
}
