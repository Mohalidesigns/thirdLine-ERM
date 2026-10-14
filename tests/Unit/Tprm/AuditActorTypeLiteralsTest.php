<?php

namespace Tests\Unit\Tprm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No TPRM audit writer spells `actor_type` as a string literal (ADR 0028).
 *
 * The column is hashed into a sealed chain and has no enum or check constraint
 * (a constraint would turn a bad spelling into a lost audit row), so the only
 * thing between a typo and a permanent second spelling is this scan. Every
 * writer uses `AuditLog::ACTOR_USER`, `ACTOR_PORTAL` or `ACTOR_SYSTEM`.
 */
class AuditActorTypeLiteralsTest extends TestCase
{
    #[Test]
    public function every_actor_type_assignment_uses_a_constant(): void
    {
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app';

        $scanned = 0;
        $offenders = [];

        foreach ($this->phpFiles($root) as $path) {
            $source = (string) file_get_contents($path);

            $isWriter = str_contains($source, 'use App\\Models\\Tprm\\AuditLog;')
                || str_ends_with($path, 'Tprm'.DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR.'TprmAuditable.php');

            if (! $isWriter) {
                continue;
            }

            $scanned++;

            foreach (preg_split('/\R/', $source) ?: [] as $number => $line) {
                if (! preg_match('/[\'"]actor_type[\'"]\s*=>(.*)$/', $line, $match)) {
                    continue;
                }

                if (preg_match('/[\'"]/', $match[1])) {
                    $offenders[] = str_replace($root.DIRECTORY_SEPARATOR, 'app/', $path).':'.($number + 1).'  '.trim($line);
                }
            }
        }

        $this->assertGreaterThan(0, $scanned, 'the scan found no TPRM audit writer, so it is not looking where it should');

        $this->assertSame([], $offenders, "A quoted string literal stands on the right of 'actor_type' =>.\n"
            ."ADR 0028: write AuditLog::ACTOR_USER, ACTOR_PORTAL or ACTOR_SYSTEM. The column is hashed into a\n"
            .'sealed chain, so a mistyped spelling is permanent:'."\n".implode("\n", $offenders));
    }

    #[Test]
    public function the_trait_resolver_returns_constants_not_literals(): void
    {
        $path = dirname(__DIR__, 3).'/app/Models/Tprm/Concerns/TprmAuditable.php';
        $offenders = [];

        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $number => $line) {
            $code = trim($line);

            if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '/') || str_starts_with($code, '#')) {
                continue;
            }

            if (preg_match('/[\'"](user|portal|system)[\'"]/', $line)) {
                $offenders[] = 'TprmAuditable.php:'.($number + 1).'  '.$code;
            }
        }

        $this->assertSame([], $offenders, "ADR 0028: the actor resolver must return AuditLog::ACTOR_* constants, not quoted spellings:\n".implode("\n", $offenders));
    }

    #[Test]
    public function no_portal_actor_label_is_built_from_an_email(): void
    {
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app';
        $scanned = 0;
        $offenders = [];

        foreach ($this->phpFiles($root) as $path) {
            $source = (string) file_get_contents($path);

            $isWriter = str_contains($source, 'use App\\Models\\Tprm\\AuditLog;')
                || str_ends_with($path, 'Tprm'.DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR.'TprmAuditable.php');

            if (! $isWriter) {
                continue;
            }

            $scanned++;

            foreach (preg_split('/\R/', $source) ?: [] as $number => $line) {
                $code = trim($line);

                if (str_starts_with($code, '*') || str_starts_with($code, '/') || str_starts_with($code, '#')) {
                    continue;
                }

                $clipsEmail = preg_match('/AuditText::clip\(\s*\$\w+\??->email/', $line);
                $labelFromEmail = preg_match('/[\'"]actor_label[\'"]\s*=>.*->email/', $line);

                if ($clipsEmail || $labelFromEmail) {
                    $offenders[] = str_replace($root.DIRECTORY_SEPARATOR, 'app/', $path).':'.($number + 1).'  '.$code;
                }
            }
        }

        $this->assertGreaterThan(0, $scanned);
        $this->assertSame([], $offenders, "ADR 0028: a portal row's actor_label is always NULL. actor_label is a hashed field in a sealed chain,\n"
            ."so a vendor's email written there could never be erased under an NDPA erasure request. Identify the vendor by actor_id:\n".implode("\n", $offenders));
    }

    /** @return iterable<string> */
    private function phpFiles(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
