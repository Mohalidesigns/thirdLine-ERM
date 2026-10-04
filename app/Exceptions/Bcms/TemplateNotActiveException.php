<?php

namespace App\Exceptions\Bcms;

/**
 * The alert's template — or every locale sibling of it, including the
 * English fallback — has no active row to render from.
 *
 * COMPOSE-TIME AND RENDER-TIME ARE TWO DIFFERENT MOMENTS. `AlertService::
 * compose()` already refuses `template_id` of an inactive row up front, but
 * that only catches an operator picking one directly; it does nothing about
 * a template an admin deactivates AFTER an alert already exists in draft, or
 * between approval and dispatch. `TemplateRenderer::templateFor()` used to
 * paper over that with `?? $template` — falling back to the very row that
 * had just failed every active-sibling lookup — so a withdrawn template
 * kept rendering its (possibly wrong, possibly withdrawn-for-a-reason)
 * content right up until somebody re-composed the alert. This exception is
 * what `?? $template`'s removal turns that silent fallback into: a refusal,
 * caught the same way `UnresolvedTemplateVariableException` is everywhere
 * that matters (`AlertService::assertRenderable()`, `AlertDispatcher::
 * sendOne()`, `AlertController::estimate()`), via the shared
 * `AlertRenderingRefusedException` parent.
 *
 * THE MESSAGE IS DELIBERATELY NEUTRAL — "This template is not active" —
 * never "awaiting review". A row an admin deliberately withdrew was never
 * awaiting anything; saying so would be a wrong accusation on every screen
 * and every audit row this reaches.
 */
class TemplateNotActiveException extends AlertRenderingRefusedException
{
    /** Not `$code` — `\Exception::$code` already owns that name and is not readonly. */
    public function __construct(public readonly string $templateCode)
    {
        parent::__construct(sprintf('This template is not active (%s).', $templateCode));
    }

    public function failedReason(): string
    {
        return 'Template not active: '.$this->templateCode;
    }
}
