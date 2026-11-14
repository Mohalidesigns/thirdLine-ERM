<?php

namespace App\Services\Sm;

use RuntimeException;

/**
 * A lifecycle rule refused the request, and the message is what the user sees.
 *
 * Standard §3: lifecycle rules ("only an in-review record can be approved") stay in
 * the controller and answer a wrong status with a FLASH MESSAGE rather than a 403.
 * §A1.8 names the precedent — `Risk\LossEventController::updateStatus()`, where "an
 * illegal target [is] answered with `back()->with('error', …)`".
 *
 * The rule lives in `ScenarioLifecycle` so it is testable without HTTP; the sentence
 * travels on the exception so the controller cannot drift from it and a test can
 * assert the wording a user actually reads. It is NOT an authorization failure: the
 * user is allowed to try, the machine says no and says why (spec 01 §0.2).
 */
class ScenarioRefused extends RuntimeException
{
    /** The sentence to flash. */
    public function flash(): string
    {
        return $this->getMessage();
    }
}
