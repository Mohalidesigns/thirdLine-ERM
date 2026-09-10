<?php

namespace App\Services;

use RuntimeException;

/**
 * A formula threshold could not be evaluated.
 *
 * Distinct from a generic RuntimeException so that the re-baselining job can
 * tell "this limit depends on a figure nobody has entered yet" — which is
 * ordinary at the start of a period — apart from a genuine fault.
 *
 * Never swallowed into a default of zero. A capital-linked limit that quietly
 * evaluates to 0 does not fail safe: it puts every measure into breach.
 */
class FormulaEvaluationException extends RuntimeException {}
