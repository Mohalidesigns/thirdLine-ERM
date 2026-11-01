<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A lifecycle refusal in the departmental KRI cycle (ADR 0026 section 10):
 * submitting a submitted return, reopening when a period is closed, a
 * structural impossibility the validators could not have seen.
 *
 * Controllers catch it and answer with a flash message, never a 403
 * (development standard section 3). It always rolls the enclosing
 * transaction back, because it is thrown from inside one.
 */
class KriCycleException extends RuntimeException {}
