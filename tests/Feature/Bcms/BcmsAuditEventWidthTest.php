<?php

namespace Tests\Feature\Bcms;

use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every BCMS audit event name must fit `bcms_audit_logs.event`.
 *
 * This exists because it did not, and nothing said so. The column was 20
 * characters; twelve of the twenty-two event names `recordAudit()` writes are
 * longer. On MariaDB the insert raises `1406 Data too long`;
 * `BcmsAuditable::writeBcmsAuditRow()` catches Throwable by design so that
 * auditing can never fail a business write, logs, and returns. The audit row
 * was silently absent. SQLite enforces no VARCHAR length, so the suite stayed
 * green while the product lost audit rows on the only database it ships to.
 *
 * A wider column fixes today. This test is what stops tomorrow: the day someone
 * adds `call_tree.something_rather_descriptive` and it does not fit, this fails
 * loudly instead of the audit trail going quiet.
 *
 * It reads the names out of the source rather than restating them, so it cannot
 * drift from what the code actually writes.
 */
class BcmsAuditEventWidthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Events written by `BcmsAuditable`'s Eloquent hooks rather than by an
     * explicit `recordAudit()` call, so they appear in no scan.
     *
     * @var list<string>
     */
    private const HOOK_EVENTS = ['created', 'updated', 'deleted', 'restored'];

    #[Test]
    public function every_audit_event_name_fits_the_column(): void
    {
        $width = $this->eventColumnWidth();
        $events = $this->auditEventNames();

        // A scan that finds nothing would pass this test while proving nothing.
        $this->assertGreaterThan(
            15,
            count($events),
            'The recordAudit() scan found almost nothing, so it has stopped working '
            .'rather than the codebase having shrunk. Fix the scan.'
        );

        $tooLong = array_filter($events, fn (string $e) => strlen($e) > $width);

        $this->assertSame([], array_values($tooLong), sprintf(
            'These audit event names do not fit bcms_audit_logs.event (%d chars), so their rows are '.
            "DISCARDED IN SILENCE — writeBcmsAuditRow() catches the error by design:\n  %s",
            $width,
            implode("\n  ", array_map(fn ($e) => sprintf('%s (%d)', $e, strlen($e)), $tooLong))
        ));
    }

    #[Test]
    public function an_oversized_event_name_is_rejected_for_being_oversized(): void
    {
        // Proves the guard above is guarding something real: that the database
        // rejects an overlong value rather than truncating it quietly.
        //
        // THE ASSERTION IS ON THE SQLSTATE, NOT ON QueryException. An earlier
        // version of this test asserted only `expectException(QueryException)`
        // against a row whose organization_id did not exist, and gate 2 showed
        // by control experiment that the identical insert with a VALID short
        // event also throws QueryException — SQLSTATE[23000] 1452, a foreign
        // key violation. Both are QueryException, so the test could not tell
        // "the width was enforced" from "the fixture was wrong", and on a
        // server in the non-strict mode this test claims to exclude it would
        // have passed while the value truncated silently.
        //
        // 22001 is "string data, right truncated". Nothing else raises it here.
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only a strict-mode server enforces VARCHAR length.');
        }

        $organization = Organization::create([
            'name' => 'Width Probe Bank', 'short_name' => 'WPB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        $width = $this->eventColumnWidth();

        try {
            DB::table('bcms_audit_logs')->insert([
                'organization_id' => $organization->id,
                'auditable_type' => 'Test',
                'auditable_id' => 1,
                'event' => str_repeat('x', $width + 1),
                'created_at' => now(),
            ]);

            $this->fail(sprintf(
                'A %d-character event was accepted into a %d-character column. The server is not enforcing '.
                'VARCHAR length, so every audit row longer than the column is being truncated in silence.',
                $width + 1,
                $width
            ));
        } catch (QueryException $e) {
            $this->assertSame(
                '22001',
                $e->getCode(),
                'The insert failed, but not for being too long — SQLSTATE '.$e->getCode().': '.$e->getMessage().
                "\nThis test only proves anything if the width is what rejected the row."
            );
        }

        // And the control: the same row with a value that fits must insert
        // cleanly. Without this, a fixture that could never insert for any
        // reason would still satisfy the assertion above.
        DB::table('bcms_audit_logs')->insert([
            'organization_id' => $organization->id,
            'auditable_type' => 'Test',
            'auditable_id' => 1,
            'event' => str_repeat('x', $width),
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('bcms_audit_logs', [
            'organization_id' => $organization->id,
            'event' => str_repeat('x', $width),
        ]);
    }

    private function eventColumnWidth(): int
    {
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // SQLite reports no length. Fall back to the width the migration
            // declares, so the name check still runs everywhere.
            return 60;
        }

        $row = $connection->selectOne(
            'SELECT character_maximum_length AS len FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$connection->getDatabaseName(), 'bcms_audit_logs', 'event']
        );

        return (int) ($row->len ?? 0);
    }

    /**
     * Every event name the codebase can pass to `recordAudit()`.
     *
     * Takes the first argument of each call — up to the comma at depth 1, or
     * the closing paren — and pulls every single-quoted literal out of it. That
     * span, rather than a simpler `recordAudit\('([^']+)'` match, is what
     * catches a ternary: AlertTemplateController picks between
     * 'template.activated' and 'template.deactivated' inline, and a naive
     * pattern silently misses both.
     *
     * @return list<string>
     */
    private function auditEventNames(): array
    {
        $names = self::HOOK_EVENTS;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach ($this->firstArguments($source) as $argument) {
                preg_match_all("/'([^']*)'/", $argument, $matches);
                foreach ($matches[1] as $literal) {
                    if ($literal !== '') {
                        $names[] = $literal;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string> the raw text of each recordAudit() first argument
     */
    private function firstArguments(string $source): array
    {
        $out = [];
        $offset = 0;

        while (($pos = strpos($source, 'recordAudit(', $offset)) !== false) {
            $i = $pos + strlen('recordAudit(');
            $depth = 1;
            $start = $i;

            for (; $i < strlen($source); $i++) {
                $c = $source[$i];

                if ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    if (--$depth === 0) {
                        break;
                    }
                } elseif ($c === ',' && $depth === 1) {
                    break;
                }
            }

            $out[] = substr($source, $start, $i - $start);
            $offset = $pos + 1;
        }

        return $out;
    }
}
