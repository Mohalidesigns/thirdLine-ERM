<?php

namespace App\Support\Sso;

use RuntimeException;

/**
 * A single sign-on attempt that was refused for a reason the user may safely
 * be shown — a disallowed domain, a deactivated account, a missing mapping.
 *
 * Anything that would leak whether a given account exists, or details of the
 * IdP exchange, is logged instead and surfaces as a generic failure.
 */
class SsoAuthenticationException extends RuntimeException {}
