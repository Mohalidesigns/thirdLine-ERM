<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * WP-11 TASK 2 — move already-uploaded control-test evidence and bulk-import
 * spreadsheets off the web-served disk.
 *
 * WHAT WAS WRONG
 * --------------
 * ControlTestController::uploadEvidence() and DataImportController::upload()
 * both called `$file->store(..., 'public')`. The `public` disk is rooted at
 * `storage/app/public`, which `storage:link` symlinks to `public/storage`, so
 * every file either endpoint had ever accepted was readable over HTTP at
 * `/storage/control-test-evidence/{controlTestId}/{name}` and
 * `/storage/imports/{organizationId}/{name}` — no session, no permission, no
 * tenant check. Control-test evidence is audit evidence held on behalf of a
 * regulated bank; a bulk import is that bank's whole risk register in one file.
 *
 * Changing the disk in the controllers fixes NEW uploads only. Everything
 * written before that deploy stays exactly where it is and stays served. This
 * migration is the other half of the fix: it relocates those files to the
 * private `local` disk (`storage/app/private`, `serve => false`) and rewrites
 * the stored path column to the canonical value.
 *
 * WHY A MIGRATION AND NOT A CONSOLE COMMAND
 * -----------------------------------------
 * A console command would be re-runnable by an operator by name, which is
 * genuinely nicer — but it is a file in app/Console plus a registration, and
 * this change set deliberately keeps the whole exposure fix inside the files it
 * owns. The trade-off is acceptable because the routine below is written to be
 * a pure, idempotent function of disk state rather than of migration state: it
 * decides what to do per row by looking at both disks, so re-running it (by
 * rolling the batch back and re-migrating, or by requiring this file and
 * calling up() from tinker) is safe and does nothing on an already-clean store.
 *
 * PARTIAL FAILURE IS EXPECTED AND SURVIVED
 * ----------------------------------------
 * A production evidence store that has been running for months WILL have rows
 * whose file was deleted, restored badly, or never written. Aborting the whole
 * migration on the first of those leaves the estate half-relocated: some
 * evidence private, some still served, and no record of which. That is strictly
 * worse than a completed relocation with a reported gap, so every row is
 * handled inside its own try/catch, every anomaly is logged with the table,
 * the row id and the path, and a summary is logged at the end.
 *
 * THE MOVE IS COPY-VERIFY-DELETE, NEVER RENAME
 * --------------------------------------------
 * Bytes are streamed to the private disk, the destination size is compared with
 * the source size, and only then is the public copy removed. If the copy is
 * short or fails, the partial destination is removed and the PUBLIC file is
 * left alone — a still-exposed file is recoverable, a lost one is not. That
 * also makes an interrupted run resumable: the next run finds the file still on
 * the public disk and retries it.
 *
 * PATHS ARE PRESERVED, NOT REASSIGNED
 * -----------------------------------
 * The relative path is intentionally identical on both disks
 * (`control-test-evidence/12/ab…pdf` stays `control-test-evidence/12/ab…pdf`),
 * so in the ordinary case the stored column already holds the correct value and
 * the write-back is a no-op. Inventing a new prefix would have meant every one
 * of these rows needing a successful column update to stay readable, i.e. a
 * second way for a partial run to lose access to evidence, for no security
 * benefit — the disk, not the path, is what made the files reachable. The
 * column IS still rewritten to a canonical form, which repairs legacy values
 * carrying a leading slash or a redundant `public/` / `storage/app/public/`
 * prefix.
 */
return new class extends Migration
{
    /**
     * table => [column, expected path prefix]
     *
     * The prefix is a safety rail. Only files under it are relocated, so a row
     * whose column points somewhere unexpected — anything sharing the public
     * disk with these, such as the organisation logos DocumentRenderer reads
     * from `app/public` — is reported rather than silently made unreachable.
     *
     * @var list<array{table: string, column: string, prefix: string}>
     */
    private const TARGETS = [
        ['table' => 'control_test_evidence', 'column' => 'file_path', 'prefix' => 'control-test-evidence/'],
        ['table' => 'data_imports', 'column' => 'file_path', 'prefix' => 'imports/'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $target) {
            $this->relocate($target['table'], $target['column'], $target['prefix']);
        }
    }

    /**
     * Deliberately a no-op.
     *
     * Reversing this migration means copying audit evidence and complete risk
     * registers back into `storage/app/public`, which is symlinked to
     * `public/storage` — i.e. re-publishing them to the internet. There is no
     * circumstance in which rolling a schema batch back should do that, and a
     * `down()` that "helpfully" undoes a security fix is a footgun aimed at
     * whoever is having a bad enough day to be running `migrate:rollback`.
     *
     * Nothing needs it, either: this migration changes no schema, and the
     * previous application code can still READ the private disk — the
     * controllers and DataImportProcessor were given a public-disk fallback for
     * exactly the deploy window this covers. If a genuine restore is ever
     * required it is a deliberate, supervised operation, not a rollback.
     */
    public function down(): void
    {
        // Intentionally empty. See the docblock above.
    }

    private function relocate(string $table, string $column, string $prefix): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            Log::info('WP-11 relocation: skipping, table or column absent.', compact('table', 'column'));

            return;
        }

        $public = Storage::disk('public');
        $private = Storage::disk(\App\Services\FileUploadService::DISK);

        $stats = ['moved' => 0, 'already_private' => 0, 'missing' => 0, 'skipped' => 0, 'failed' => 0, 'repathed' => 0];

        DB::table($table)
            ->select(['id', $column])
            ->orderBy('id')
            ->chunkById(250, function ($rows) use ($table, $column, $prefix, $public, $private, &$stats) {
                foreach ($rows as $row) {
                    try {
                        $this->relocateRow($row, $table, $column, $prefix, $public, $private, $stats);
                    } catch (\Throwable $e) {
                        // One unreadable file must not strand the rest of the
                        // estate on the public disk.
                        $stats['failed']++;
                        Log::error('WP-11 relocation: row failed, continuing.', [
                            'table' => $table,
                            'id' => $row->id,
                            'path' => $row->{$column},
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info('WP-11 relocation complete for '.$table.'.', $stats);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function relocateRow(
        object $row,
        string $table,
        string $column,
        string $prefix,
        \Illuminate\Contracts\Filesystem\Filesystem $public,
        \Illuminate\Contracts\Filesystem\Filesystem $private,
        array &$stats,
    ): void {
        $stored = (string) ($row->{$column} ?? '');

        if (trim($stored) === '') {
            $stats['skipped']++;

            return;
        }

        $canonical = $this->canonicalise($stored);

        if ($canonical === null) {
            // A traversal segment or a stream wrapper in a stored path is not
            // something to "fix up" — it is either corruption or an attempt,
            // and it gets reported, not followed.
            $stats['skipped']++;
            Log::warning('WP-11 relocation: refusing an unsafe stored path.', [
                'table' => $table, 'id' => $row->id, 'path' => $stored,
            ]);

            return;
        }

        if (! str_starts_with($canonical, $prefix)) {
            $stats['skipped']++;
            Log::warning('WP-11 relocation: stored path is outside the expected prefix, left in place.', [
                'table' => $table, 'id' => $row->id, 'path' => $canonical, 'expected_prefix' => $prefix,
            ]);

            return;
        }

        $onPrivate = $private->exists($canonical);
        $onPublic = $public->exists($canonical);

        if ($onPrivate) {
            // A previous run (or a previous, interrupted run) already copied
            // this file. Remove the public leftover only when it is provably
            // the same file; if the two differ, keep BOTH and shout, because
            // deleting the one that might be the real evidence is unrecoverable.
            if ($onPublic) {
                if ((int) $private->size($canonical) === (int) $public->size($canonical)) {
                    $public->delete($canonical);
                } else {
                    Log::warning('WP-11 relocation: public and private copies differ in size, public copy KEPT for manual review.', [
                        'table' => $table,
                        'id' => $row->id,
                        'path' => $canonical,
                        'public_bytes' => $public->size($canonical),
                        'private_bytes' => $private->size($canonical),
                    ]);
                }
            }

            $stats['already_private']++;
            $this->writeBackPath($table, $column, $row, $stored, $canonical, $stats);

            return;
        }

        if (! $onPublic) {
            // The row references a file that is on neither disk. Nothing to
            // move, nothing lost by this migration — but it is a real gap in an
            // evidence store and somebody needs to know.
            $stats['missing']++;
            Log::warning('WP-11 relocation: file referenced by the database is missing from disk.', [
                'table' => $table, 'id' => $row->id, 'path' => $canonical,
            ]);

            $this->writeBackPath($table, $column, $row, $stored, $canonical, $stats);

            return;
        }

        $sourceBytes = (int) $public->size($canonical);
        $stream = $public->readStream($canonical);

        if ($stream === null || $stream === false) {
            $stats['failed']++;
            Log::error('WP-11 relocation: could not open the public copy for reading.', [
                'table' => $table, 'id' => $row->id, 'path' => $canonical,
            ]);

            return;
        }

        try {
            $written = $private->writeStream($canonical, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false || ! $private->exists($canonical) || (int) $private->size($canonical) !== $sourceBytes) {
            // Short or failed copy. Bin the partial destination and leave the
            // public original untouched so the next run can retry it.
            $private->delete($canonical);
            $stats['failed']++;
            Log::error('WP-11 relocation: copy to the private disk did not verify, public copy left in place for retry.', [
                'table' => $table, 'id' => $row->id, 'path' => $canonical, 'expected_bytes' => $sourceBytes,
            ]);

            return;
        }

        $public->delete($canonical);
        $stats['moved']++;

        $this->writeBackPath($table, $column, $row, $stored, $canonical, $stats);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function writeBackPath(string $table, string $column, object $row, string $stored, string $canonical, array &$stats): void
    {
        if ($stored === $canonical) {
            return;
        }

        DB::table($table)->where('id', $row->id)->update([$column => $canonical]);
        $stats['repathed']++;

        Log::info('WP-11 relocation: stored path normalised.', [
            'table' => $table, 'id' => $row->id, 'from' => $stored, 'to' => $canonical,
        ]);
    }

    /**
     * Reduce a stored path to the disk-relative form both disks use, or return
     * null if it is not safe to act on.
     */
    private function canonicalise(string $path): ?string
    {
        if (str_contains($path, "\0")) {
            return null;
        }

        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $path) === 1) {
            return null;
        }

        $normalised = str_replace('\\', '/', trim($path));

        // Legacy shapes seen in the wild: an absolute storage path, a
        // 'public/' prefix left over from a `public_path()` concatenation, or a
        // leading slash from a URL fragment.
        foreach (['storage/app/public/', 'public/storage/', 'storage/', 'public/'] as $legacyPrefix) {
            $normalised = preg_replace('#^/*'.preg_quote($legacyPrefix, '#').'#', '', $normalised, 1) ?? $normalised;
        }

        $segments = [];

        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }
};
