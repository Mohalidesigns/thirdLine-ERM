<?php

namespace App\Exceptions\Bcms;

use RuntimeException;

/**
 * `TemplateRenderer::render()` refuses to hand back a message — for any
 * reason. Two concrete reasons exist today (`UnresolvedTemplateVariableException`,
 * `TemplateNotActiveException`); this is the shared type every caller that
 * needs to react to "render() would not commit to a rendering" catches,
 * rather than listing both by name and risking a third reason added later
 * being caught nowhere.
 *
 * `failedReason()` IS THE FIXED, STABLE STRING a caller writes onto a record
 * meant to be read later — `NotificationDelivery::failed_reason`, which
 * `EvidenceExport` prints verbatim. It is deliberately shorter and more
 * stable than `getMessage()`, which is a full sentence tuned for an operator
 * reading it once on a screen; `failedReason()` is tuned for being grepped
 * out of a CSV months later.
 */
abstract class AlertRenderingRefusedException extends RuntimeException
{
    abstract public function failedReason(): string;
}
