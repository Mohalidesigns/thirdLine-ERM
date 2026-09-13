<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\ConsentStatus;
use App\Enums\Bcms\VerificationStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every literal written to `bcms_contacts.consent_status` or
 * `.verification_status` must be a real case of its enum.
 *
 * WHY THIS EXISTS. `Contact::casts()` maps both columns onto their enum
 * (`app/Models/Bcms/Contact.php:115-116`). Once a column is cast,
 * `HasAttributes::getEnumCaseFromValue()` calls the enum's `::from()` on
 * every read and write, and `::from()` THROWS on a value that is not a
 * declared case. `VerificationStatus` has never declared `failed` — the
 * migration's own contract comment lists `unverified|verified|bounced|invalid`
 * (`database/migrations/2026_09_09_120006_create_bcms_calltree_emns_tables.php:94`)
 * — yet four seeder writes used `'failed'` anyway, so casting the column
 * turned `db:seed` for the BCMS demo estate into a fatal `ValueError`, and any
 * row already holding `'failed'` fatals the moment it is read. `'failed'` was
 * wrong before the cast; the cast is what made it loud instead of silently
 * accepted as a plain string.
 *
 * SCOPE IS `app/` AND `database/` DELIBERATELY, NOT `tests/`. A test fixture
 * is sometimes deliberately exercising an invalid value to prove the cast
 * rejects it — this guard is about what the product itself writes and ships,
 * not about what a test constructs to provoke a failure.
 *
 * TWO SHAPES ARE MATCHED: an array assignment (`'consent_status' => 'x'`, the
 * shape of every `create()`/`update()` payload in this codebase) and a
 * query-builder filter (`where('consent_status', 'x')`). Both are how a raw
 * string reaches these two columns; an `EnumCase->value` reference is not a
 * quoted literal and does not match either pattern, so this guard does not
 * fire on the correct spelling.
 */
class ConsentAndVerificationLiteralGuardTest extends TestCase
{
    private const SEARCH_PATHS = ['app', 'database'];

    private const COLUMNS = ['consent_status', 'verification_status'];

    #[Test]
    public function every_literal_consent_and_verification_status_is_a_real_enum_case(): void
    {
        $literals = $this->literalsFound();

        // A scan that finds nothing has stopped working, not proved the
        // codebase clean — both columns are written by name in several
        // seeders and at least one service.
        $this->assertGreaterThan(
            0,
            count($literals),
            'The literal scan found nothing for consent_status/verification_status. Fix the scan '
            .'before trusting a clean result from it.'
        );

        $offenders = [];

        foreach ($literals as $entry) {
            [$file, $line, $column, $value] = $entry;

            $valid = match ($column) {
                'consent_status' => ConsentStatus::tryFrom($value) !== null,
                'verification_status' => VerificationStatus::tryFrom($value) !== null,
            };

            if (! $valid) {
                $offenders[] = sprintf('%s:%d — %s => %s', $file, $line, $column, $value);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A literal written to a cast enum column is not one of its declared cases.\n\n"
            .implode("\n", $offenders)
            ."\n\nOnce a column is cast to an enum (App\\Models\\Bcms\\Contact::casts()), Eloquent calls "
            .'the enum\'s ::from() on every read AND write — an unrecognised literal is not tolerated as '
            .'a plain string any more, it is a fatal ValueError. Use the enum case\'s ->value instead of '
            .'retyping the string.'
        );
    }

    /**
     * @return list<array{0: string, 1: int, 2: string, 3: string}> file, line, column, value
     */
    private function literalsFound(): array
    {
        $columns = implode('|', self::COLUMNS);

        // 'consent_status' => 'granted' — every create()/update() payload in
        // this codebase is written this way.
        $assignmentPattern = "/'({$columns})'\\s*=>\\s*'([a-z_]+)'/";

        // ->where('consent_status', 'granted') — the query-builder shape.
        // The lookbehind excludes an identifier ending in "where" (there is
        // none in this codebase, but a false match here would be silent).
        $wherePattern = "/(?<![A-Za-z_])where\\(\\s*'({$columns})'\\s*,\\s*'([a-z_]+)'\\s*\\)/";

        $found = [];

        foreach ($this->phpFilesUnder(self::SEARCH_PATHS) as $file) {
            $relative = $this->relativePath($file);

            foreach (file($file) as $index => $line) {
                // Line comments and docblock bodies: several files quote the
                // exact defect they fixed, and scanning raw content would
                // flag the explanation as the thing it explains.
                if (preg_match('/^\s*(\/\/|\*|#|\/\*)/', $line)) {
                    continue;
                }

                foreach ([$assignmentPattern, $wherePattern] as $pattern) {
                    if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $found[] = [$relative, $index + 1, $match[1], $match[2]];
                        }
                    }
                }
            }
        }

        return $found;
    }

    private function relativePath(string $file): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function phpFilesUnder(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = base_path($path);

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
