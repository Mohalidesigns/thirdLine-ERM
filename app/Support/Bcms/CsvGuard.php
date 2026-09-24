<?php

namespace App\Support\Bcms;

/**
 * CSV formula-injection guard (Gate 1 re-gate advisory A7).
 *
 * A cell whose text starts with `=`, `+`, `-`, `@`, a tab or a carriage
 * return is a live formula the moment the file opens in Excel, Sheets or
 * Numbers — a rationale, a reference or a name that starts with one of these
 * (typed by a user, not chosen by this system) executes as a spreadsheet
 * formula for whoever opens the export next, which on a regulatory-evidence
 * CSV is exactly the file most likely to be opened by someone the incident
 * was about. Prefixing a leading apostrophe is the standard mitigation:
 * every spreadsheet application treats a leading `'` as "the rest of this is
 * text", and CSV itself has no escape syntax of its own for it.
 */
final class CsvGuard
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::DANGEROUS_PREFIXES, true) ? "'".$value : $value;
    }

    /**
     * @param  list<mixed>  $row
     * @return list<mixed>
     */
    public static function row(array $row): array
    {
        return array_map(self::cell(...), $row);
    }
}
