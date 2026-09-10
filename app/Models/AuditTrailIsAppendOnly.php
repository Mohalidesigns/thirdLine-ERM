<?php

namespace App\Models;

use RuntimeException;

/**
 * Raised when something tries to update or delete a risk_audit_trail row.
 *
 * The audit trail is the record of what happened; a code path that wants to
 * change it is a bug, not a use case.
 */
class AuditTrailIsAppendOnly extends RuntimeException {}
