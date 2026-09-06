<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

class ReferenceCodeService
{
    /**
     * How many times to re-attempt an allocation that lost a race.
     *
     * Two things can lose one: two requests both creating the counter row for
     * the first time, and two requests deadlocking or timing out on the row
     * lock. Neither consumes a number — the transaction rolls back — so
     * re-attempting is safe and is the normal way to handle both.
     */
    private const MAX_ATTEMPTS = 8;

    /**
     * Base backoff between attempts, in microseconds. Multiplied by the
     * attempt number so a heavily contended counter backs off rather than
     * spinning against the same lock.
     */
    private const RETRY_BACKOFF_MICROSECONDS = 2000;

    /**
     * Generate the next reference code for a given prefix and table.
     * Format: PREFIX-YEAR-NNNN (e.g. RK-2026-0001)
     *
     * Numbering is per organization. Before tenancy this query had no
     * organization filter, so every tenant drew from one global sequence:
     * organization B creating a risk advanced organization A's counter, and
     * the gaps in A's own numbering leaked how much activity other tenants
     * had. Codes are unique per (organization_id, code).
     *
     * The number now comes from the reference_sequences counter, allocated
     * inside a transaction that holds the row with SELECT ... FOR UPDATE.
     * The previous implementation read MAX(code) and added one, which both
     * raced (two concurrent creates produced the same code) and broke past
     * 9999, because it ordered a string column lexicographically —
     * 'LE-2026-9999' sorts above 'LE-2026-10000', so the counter walked
     * backwards and began re-issuing codes.
     */
    public static function generate(
        string $table,
        string $column,
        string $prefix,
        int $digits = 4,
        ?int $organizationId = null
    ): string {
        $organizationId ??= TenantContext::organizationId();

        $year = now()->year;
        $number = self::nextValue($organizationId, "{$table}.{$column}", $prefix, $year, $digits, $table, $column);

        return sprintf("%s-%d-%0{$digits}d", $prefix, $year, $number);
    }

    /**
     * Claim the next number for a sequence.
     */
    private static function nextValue(
        int $organizationId,
        string $sequenceKey,
        string $prefix,
        int $year,
        int $digits,
        string $table,
        string $column
    ): int {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use (
                    $organizationId, $sequenceKey, $prefix, $year, $digits, $table, $column
                ) {
                    $row = DB::table('reference_sequences')
                        ->where('organization_id', $organizationId)
                        ->where('sequence_key', $sequenceKey)
                        ->where('prefix', $prefix)
                        ->where('period', $year)
                        // The lock is the whole point: every other transaction
                        // wanting this counter waits here rather than reading
                        // the same value and issuing a duplicate code.
                        ->lockForUpdate()
                        ->first();

                    if ($row !== null) {
                        DB::table('reference_sequences')
                            ->where('id', $row->id)
                            ->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

                        return (int) $row->next_value;
                    }

                    // First code for this sequence. Start above whatever the
                    // legacy scheme already issued, so the switch-over cannot
                    // re-use a code that is already printed on a filing.
                    $start = self::highestExistingNumber($table, $column, $prefix, $year, $organizationId) + 1;

                    DB::table('reference_sequences')->insert([
                        'organization_id' => $organizationId,
                        'sequence_key' => $sequenceKey,
                        'prefix' => $prefix,
                        'period' => $year,
                        'next_value' => $start + 1,
                        'padding' => $digits,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return $start;
                }, 3);
            } catch (QueryException $e) {
                // Either another request created the counter row between our
                // SELECT and our INSERT (their row is the one that exists now,
                // so go round again and take a number from it), or we lost the
                // race for the row lock. The transaction rolled back, so no
                // number was consumed either way.
                if ($attempt === self::MAX_ATTEMPTS || ! self::isRetryable($e)) {
                    throw $e;
                }

                usleep(self::RETRY_BACKOFF_MICROSECONDS * $attempt);
            }
        }

        // Unreachable: the loop either returns or rethrows.
        throw new \RuntimeException("Could not allocate a reference number for [{$sequenceKey}].");
    }

    /**
     * The highest number already issued under this prefix and year.
     *
     * Compared numerically in PHP rather than ordered in SQL, because the
     * codes are stored as strings and a lexicographic MAX() is exactly the bug
     * this class is replacing.
     */
    private static function highestExistingNumber(
        string $table,
        string $column,
        string $prefix,
        int $year,
        int $organizationId
    ): int {
        $codes = DB::table($table)
            ->where('organization_id', $organizationId)
            ->where($column, 'like', "{$prefix}-{$year}-%")
            ->pluck($column);

        $highest = 0;

        foreach ($codes as $code) {
            $parts = explode('-', (string) $code);
            $suffix = end($parts);

            if (ctype_digit($suffix)) {
                $highest = max($highest, (int) $suffix);
            }
        }

        return $highest;
    }

    /**
     * Whether losing this particular race is worth another attempt.
     *
     * Covers the unique violation on first insert (23000 / 23505) and the
     * three ways a database says "you lost the lock": InnoDB deadlock (40001),
     * InnoDB lock wait timeout (1205) and SQLite's whole-database lock, which
     * it raises when two transactions that both hold a read lock try to
     * upgrade to a write.
     */
    private static function isRetryable(QueryException $e): bool
    {
        if (in_array((string) $e->getCode(), ['23000', '23505', '40001'], true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        foreach (['database is locked', 'database table is locked', 'deadlock found', 'lock wait timeout'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
