<?php

namespace App\Services\Rcsa;

use RuntimeException;

/**
 * Thrown to roll back a dry run once it has done the work.
 *
 * A DRY RUN THAT COUNTS WHAT "WOULD" HAPPEN IS A SECOND IMPLEMENTATION, and two
 * implementations of a migration disagree the first time either changes — which
 * is precisely when somebody is relying on the dry run to decide whether to
 * commit. So the real migration runs, reports its real numbers, and this
 * unwinds the transaction.
 */
class DryRunComplete extends RuntimeException {}
