<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\RiskAuditTrail;
use Illuminate\Console\Command;

/**
 * Walks the risk_audit_trail hash chain and reports breaks.
 *
 * Two failure modes are distinguished, because they mean different things to
 * whoever is investigating:
 *
 *   CONTENT  the row's stored hash does not match its own contents — the row
 *            was edited in place.
 *   LINK     the row's previous_hash does not match the hash of the row before
 *            it — a row was removed or inserted out of band.
 */
class VerifyAuditTrail extends Command
{
    protected $signature = 'audit:verify
                            {--organization= : Verify a single organization id}
                            {--json : Emit a machine-readable report}';

    protected $description = 'Verify the integrity of the risk_audit_trail hash chain';

    public function handle(): int
    {
        $organizationIds = $this->option('organization')
            ? [(int) $this->option('organization')]
            : RiskAuditTrail::query()->withoutGlobalScopes()
                ->select('organization_id')->distinct()->orderBy('organization_id')
                ->pluck('organization_id')->all();

        if ($organizationIds === []) {
            $this->components->info('No audit trail rows to verify.');

            return self::SUCCESS;
        }

        $report = [];
        $totalRows = 0;
        $totalBreaks = 0;

        foreach ($organizationIds as $organizationId) {
            $result = $this->verifyOrganization((int) $organizationId);

            $report[] = $result;
            $totalRows += $result['rows'];
            $totalBreaks += count($result['breaks']);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'verified_at' => now()->toIso8601String(),
                'rows' => $totalRows,
                'breaks' => $totalBreaks,
                'organizations' => $report,
            ], JSON_PRETTY_PRINT));

            return $totalBreaks === 0 ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report as $result) {
            $name = $result['organization_name'] ?? "organization {$result['organization_id']}";

            if ($result['breaks'] === []) {
                $this->components->info("{$name}: chain intact across {$result['rows']} rows.");

                continue;
            }

            $this->components->error("{$name}: ".count($result['breaks'])." break(s) across {$result['rows']} rows.");

            $this->table(
                ['Row id', 'Type', 'Recorded at', 'Detail'],
                array_map(fn (array $b) => [
                    $b['id'],
                    $b['type'],
                    $b['changed_at'] ?? '—',
                    $b['detail'],
                ], $result['breaks'])
            );
        }

        if ($totalBreaks === 0) {
            $this->newLine();
            $this->components->info("Audit chain verified: {$totalRows} rows, no breaks.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->error("Audit chain BROKEN: {$totalBreaks} break(s) across {$totalRows} rows.");

        return self::FAILURE;
    }

    /**
     * @return array{organization_id: int, organization_name: ?string, rows: int, breaks: list<array<string, mixed>>}
     */
    private function verifyOrganization(int $organizationId): array
    {
        $breaks = [];
        $rows = 0;
        $previousHash = null;

        RiskAuditTrail::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->chunk(500, function ($chunk) use (&$breaks, &$rows, &$previousHash) {
                foreach ($chunk as $row) {
                    $rows++;

                    if ($row->previous_hash !== $previousHash) {
                        $breaks[] = [
                            'id' => $row->id,
                            'type' => 'LINK',
                            'changed_at' => optional($row->changed_at)->toDateTimeString(),
                            'detail' => 'previous_hash does not match the preceding row; a row was removed or inserted',
                        ];
                    }

                    $expected = $row->expectedHash();

                    if (! hash_equals((string) $row->hash, $expected)) {
                        $breaks[] = [
                            'id' => $row->id,
                            'type' => 'CONTENT',
                            'changed_at' => optional($row->changed_at)->toDateTimeString(),
                            'detail' => 'stored hash does not match the row contents; the row was edited',
                        ];
                    }

                    // Continue from what is stored, not from what was expected,
                    // so one broken row does not cascade into a false LINK
                    // break on every row after it.
                    $previousHash = $row->hash;
                }
            });

        return [
            'organization_id' => $organizationId,
            'organization_name' => Organization::withTrashed()->find($organizationId)?->name,
            'rows' => $rows,
            'breaks' => $breaks,
        ];
    }
}
