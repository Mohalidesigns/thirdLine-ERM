<?php

namespace ThirdLine\Reporting\Contracts;

/**
 * How a product describes whose document this is.
 *
 * DocumentRenderer::branding() used to build this itself, and in doing so it
 * named five columns on `App\Models\Organization` — including
 * `cbn_institution_code`, which is a Central Bank of Nigeria registration
 * number. That is the risk product's idea of an institution, not a shared one:
 * an audit product in another jurisdiction has no such column and should not
 * acquire the concept by depending on a PDF renderer.
 *
 * So the renderer no longer knows what an organisation is. It asks whatever
 * the application bound to this contract, and renders the array it gets back.
 * A consumer that binds nothing gets no branding rather than a wrong one.
 *
 * The returned keys are the renderer's vocabulary, and every one is optional:
 * organization_name, short_name, address, logo (a data URI), primary_colour,
 * accent_colour. A product may add its own — the PDF templates read what they
 * were given — which is how cbn_institution_code stays available to the risk
 * product's board pack without existing here.
 */
interface ResolvesDocumentBranding
{
    /**
     * @param  mixed  $subject  whatever the product identifies a document's
     *                          owner by — an Organization model here, and not
     *                          necessarily a model elsewhere.
     * @return array<string, mixed>
     */
    public function for(mixed $subject): array;
}
