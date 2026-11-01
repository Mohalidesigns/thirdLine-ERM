<?php

namespace App\Enums;

/**
 * The stored lifecycle of a KRI return (ADR 0026 section 4).
 *
 * draft -> submitted (Submit); submitted -> reopened (Reopen); reopened ->
 * submitted (Submit again). Only `submitted` counts as submitted. The badge a
 * screen shows (overdue, in_progress, not_started) is DERIVED, never stored.
 */
enum KriReturnStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Reopened = 'reopened';
}
