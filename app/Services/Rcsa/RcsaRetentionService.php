<?php

namespace App\Services\Rcsa;

use App\Models\Organization;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaExportJob;
use App\Models\Rcsa\RcsaImportBatch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * §14 Q10 — the retention policy, and the much longer list of things it
 * refuses to delete.
 *
 * The bank was asked for a retention period for completed assessments and
 * export logs and never answered, so nothing in this module has ever expired.
 * That satisfies §13's floor — "retained for at least one audit cycle" — by
 * never reaching a ceiling: generated workbooks accumulate on disk for ever,
 * and P6's export expiry is checked on READ rather than swept, so an expired
 * export is a row that refuses to download beside a file still sitting there.
 *
 * THE DEFAULT IS THAT NOTHING IS EVER PURGED. Every period is null until a
 * tenant sets one, and `report()` is what the command runs unless it is told
 * otherwise. A retention job that deletes by default is the single most
 * destructive thing this module could ship.
 *
 * ==========================================================================
 * WHAT THIS WILL NOT DELETE, AND WHY
 * ==========================================================================
 *
 * `risk_audit_trail` — NEVER, AND THE POLICY CANNOT BE CONFIGURED TO.
 *   It is hash-chained and append-only by model AND by database trigger.
 *   Removing a link does not shorten the chain, it breaks it, and
 *   `audit:verify` would then report tampering that never happened. "Retention"
 *   is not a coherent idea for a structure whose entire value is that nothing
 *   can be taken out of it. If a regulator ever requires the trail to be aged
 *   off, that is a change to the chain design and a conversation about what the
 *   bank is then able to prove — not a period in a settings array.
 *
 * EXPORT LOG ROWS — never; the FILE goes and the row stays.
 *   §10.2 makes the export log a control: a completed RCSA is the bank's
 *   operational risk profile in one file, and the log is how they know who took
 *   it out of the building. Deleting the log destroys exactly the evidence it
 *   exists to provide. The generated workbook is a derived artefact and can be
 *   made again from the data; `file_purged_at` records that it went.
 *
 * ASSESSMENTS, LINES AND CYCLES — reported, never purged.
 *   A closed cycle is the bank's own regulatory record. `report()` lists cycles
 *   beyond the configured period so somebody can decide what to do about them;
 *   there is deliberately no code here that deletes one. Automatically
 *   destroying a completed RCSA on nobody's instruction is not a default this
 *   build gets to choose, and Q10 is the bank's question precisely because the
 *   answer belongs to them.
 */
class RcsaRetentionService
{
    /**
     * The tables this service is allowed to remove rows from.
     *
     * Nothing consults this at runtime — it is a statement for the reader and
     * for `RetentionTest`, which asserts that the row counts of every other
     * RCSA table are unchanged by a committed sweep. A list in a comment
     * drifts; a list a test reads does not.
     *
     * @var list<string>
     */
    public const PURGEABLE_TABLES = ['rcsa_import_rows'];

    public function __construct(private readonly RcsaAuditRecorder $audit) {}

    /**
     * The tenant's policy, with every period defaulting to null — keep for ever.
     *
     * @return array{export_files_days: int|null, import_files_days: int|null, closed_cycle_years: int|null}
     */
    public function policyFor(Organization|int|null $subject): array
    {
        $organization = $subject instanceof Organization ? $subject : Organization::find($subject);
        $settings = $organization?->settings;
        $policy = is_array($settings) ? ($settings['rcsa']['retention'] ?? []) : [];

        return [
            'export_files_days' => $this->period($policy, 'export_files_days'),
            'import_files_days' => $this->period($policy, 'import_files_days'),
            'closed_cycle_years' => $this->period($policy, 'closed_cycle_years'),
        ];
    }

    /**
     * What a sweep WOULD do, changing nothing.
     *
     * The command's default, and the only thing that ever runs against closed
     * cycles.
     *
     * @return array{policy: array<string, int|null>, export_files: list<array<string, mixed>>, import_files: list<array<string, mixed>>, closed_cycles: list<array<string, mixed>>, held: list<array<string, mixed>>}
     */
    public function report(Organization $organization): array
    {
        $policy = $this->policyFor($organization);

        return [
            'policy' => $policy,
            'export_files' => $this->expiredExports($organization, $policy['export_files_days'])
                ->map(fn (RcsaExportJob $job) => [
                    'id' => (int) $job->id,
                    'created_at' => $job->created_at?->toDateString(),
                    'row_count' => (int) $job->row_count,
                    'file_path' => (string) $job->file_path,
                ])->values()->all(),

            'import_files' => $this->expiredImports($organization, $policy['import_files_days'])
                ->map(fn (RcsaImportBatch $batch) => [
                    'id' => (int) $batch->id,
                    'created_at' => $batch->created_at?->toDateString(),
                    'original_name' => (string) $batch->original_name,
                    'status' => (string) $batch->status,
                ])->values()->all(),

            // REPORTED ONLY. Nothing in this service deletes one.
            'closed_cycles' => $this->agedClosedCycles($organization, $policy['closed_cycle_years'])
                ->map(fn (RcsaCycle $cycle) => [
                    'id' => (int) $cycle->id,
                    'name' => (string) $cycle->name,
                    'closed_at' => $cycle->closed_at?->toDateString(),
                ])->values()->all(),

            'held' => $this->heldCycles($organization)
                ->map(fn (RcsaCycle $cycle) => [
                    'id' => (int) $cycle->id,
                    'name' => (string) $cycle->name,
                    'reason' => (string) $cycle->legal_hold_reason,
                ])->values()->all(),
        ];
    }

    /**
     * Do it: remove the artefacts the policy has aged out.
     *
     * Only files and staged import rows. See the class comment for the list of
     * things this deliberately leaves alone.
     *
     * @return array{export_files: int, import_files: int, import_rows: int}
     */
    public function purge(Organization $organization, ?User $actor = null): array
    {
        $policy = $this->policyFor($organization);

        $exports = 0;

        foreach ($this->expiredExports($organization, $policy['export_files_days']) as $job) {
            $this->deleteFile($job->file_path);

            // THE ROW SURVIVES ITS FILE. `status` becomes `expired` so the
            // screen stops offering a download, and `file_purged_at` says the
            // artefact was cleaned up rather than never written — a null
            // file_path with no explanation reads as a failed export.
            $job->forceFill([
                'file_purged_at' => now(),
                'status' => RcsaExportJob::EXPIRED,
            ])->save();

            $exports++;
        }

        $batches = 0;
        $rows = 0;

        foreach ($this->expiredImports($organization, $policy['import_files_days']) as $batch) {
            $this->deleteFile($batch->file_path);

            // The staged rows are the only records this service deletes. They
            // are a scratch area — the parsed contents of a workbook that has
            // already been published or discarded — and the batch record that
            // says what happened stays.
            $rows += $batch->rows()->count();
            $batch->rows()->delete();

            $batch->forceFill(['file_purged_at' => now()])->save();

            $batches++;
        }

        if ($exports > 0 || $batches > 0) {
            $this->audit->retention($organization, $exports, $batches, $rows, $actor);
        }

        return ['export_files' => $exports, 'import_files' => $batches, 'import_rows' => $rows];
    }

    /* ------------------------------------------------------------------ */
    /*  Selection */
    /* ------------------------------------------------------------------ */

    /**
     * Export files older than the policy, excluding anything under legal hold.
     *
     * @return \Illuminate\Support\Collection<int, RcsaExportJob>
     */
    private function expiredExports(Organization $organization, ?int $days)
    {
        if ($days === null) {
            return collect();
        }

        $held = $this->heldCycleIds($organization);

        return RcsaExportJob::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereNotNull('file_path')
            ->whereNull('file_purged_at')
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->get()
            // An export whose filters name a held cycle is evidence about that
            // cycle. Done in PHP because `filters` is JSON and the predicate
            // has to work identically on SQLite and MySQL — P4's cross-driver
            // rule. The set is small: it is only ever rows already past the
            // retention period.
            ->reject(fn (RcsaExportJob $job) => $held->contains(
                (int) (is_array($job->filters) ? ($job->filters['cycle'] ?? 0) : 0)
            ));
    }

    /**
     * Import workbooks for batches that are finished with.
     *
     * `published` and `discarded` only. A batch still queued, parsing or
     * validated is work in progress, and deleting the file underneath it would
     * break a job that has not run yet.
     *
     * @return \Illuminate\Support\Collection<int, RcsaImportBatch>
     */
    private function expiredImports(Organization $organization, ?int $days)
    {
        if ($days === null) {
            return collect();
        }

        return RcsaImportBatch::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('status', [RcsaImportBatch::PUBLISHED, RcsaImportBatch::DISCARDED])
            ->whereNull('file_purged_at')
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->get();
    }

    /**
     * Closed cycles past the retention period. REPORTED, NEVER PURGED.
     *
     * @return \Illuminate\Support\Collection<int, RcsaCycle>
     */
    private function agedClosedCycles(Organization $organization, ?int $years)
    {
        if ($years === null) {
            return collect();
        }

        return RcsaCycle::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', RcsaCycle::CLOSED)
            ->whereNull('legal_hold_at')
            ->whereNotNull('closed_at')
            ->where('closed_at', '<', Carbon::now()->subYears($years))
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, RcsaCycle> */
    private function heldCycles(Organization $organization)
    {
        return RcsaCycle::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereNotNull('legal_hold_at')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private function heldCycleIds(Organization $organization)
    {
        return $this->heldCycles($organization)->map(fn (RcsaCycle $c) => (int) $c->id);
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * A period of null, 0 or nonsense means "keep for ever".
     *
     * ZERO IS NOT "PURGE EVERYTHING". A tenant who types 0 into a retention
     * field has almost certainly not decided to delete every export the moment
     * it is written, and reading it that way is not a defensible way to find
     * out. It falls back to the safe reading, which the settings screen should
     * refuse to save in the first place.
     *
     * @param  array<string, mixed>  $policy
     */
    private function period(array $policy, string $key): ?int
    {
        $value = $policy[$key] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $days = (int) $value;

        return $days > 0 ? $days : null;
    }

    private function deleteFile(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
