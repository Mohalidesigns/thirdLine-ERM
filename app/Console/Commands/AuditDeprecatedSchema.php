<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports what is still living in the deprecated tables and columns, so their
 * removal is a decision made with numbers rather than an assumption.
 *
 * See docs/schema/deprecations.md.
 */
class AuditDeprecatedSchema extends Command
{
    protected $signature = 'schema:audit-deprecated';

    protected $description = 'Report rows still held in deprecated tables and columns';

    public function handle(): int
    {
        $this->auditRiskKriMapping();
        $this->auditIsNearMiss();
        $this->auditDuplicateColumns();

        return self::SUCCESS;
    }

    private function auditRiskKriMapping(): void
    {
        $this->components->info('risk_kri_mapping');

        if (! Schema::hasTable('risk_kri_mapping')) {
            $this->line('  table does not exist — already removed.');

            return;
        }

        $total = DB::table('risk_kri_mapping')->count();

        if ($total === 0) {
            $this->line('  empty — safe to drop.');

            return;
        }

        // The question that decides whether dropping it loses information:
        // is every pivot row also expressed by key_risk_indicators.risk_id?
        $unrepresented = DB::table('risk_kri_mapping as m')
            ->leftJoin('key_risk_indicators as k', function ($join) {
                $join->on('k.id', '=', 'm.kri_id')->on('k.risk_id', '=', 'm.risk_id');
            })
            ->whereNull('k.id')
            ->count();

        $this->line("  {$total} row(s).");

        if ($unrepresented === 0) {
            $this->line('  every association is also on key_risk_indicators.risk_id — safe to drop.');
        } else {
            $this->warn("  {$unrepresented} association(s) exist ONLY in the pivot.");
            $this->line('  Backfill key_risk_indicators.risk_id from these before dropping the table.');
        }
    }

    private function auditIsNearMiss(): void
    {
        $this->components->info('loss_events.is_near_miss');

        if (! Schema::hasColumn('loss_events', 'is_near_miss')) {
            $this->line('  column does not exist — already removed.');

            return;
        }

        $flagged = DB::table('loss_events')->where('is_near_miss', true)->count();

        // A flagged loss event with no corresponding near_misses row is a fact
        // recorded only by the deprecated flag.
        $orphaned = Schema::hasTable('near_misses')
            ? DB::table('loss_events as e')
                ->where('e.is_near_miss', true)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('near_misses as n')
                    ->whereColumn('n.converted_loss_event_id', 'e.id'))
                ->count()
            : $flagged;

        $this->line("  {$flagged} loss event(s) flagged as near misses.");

        if ($orphaned > 0) {
            $this->warn("  {$orphaned} of them have no near_misses row.");
            $this->line('  Create the near_misses records before dropping the column.');
        } else {
            $this->line('  all are represented in near_misses — safe to drop.');
        }
    }

    /**
     * Whether any deprecated duplicate column is still being written.
     *
     * Migration B drops these; a non-zero count here means something is still
     * writing one and the single-writer change missed a call site.
     */
    private function auditDuplicateColumns(): void
    {
        $this->components->info('Deprecated duplicate columns (Migration B)');

        $columns = [
            'loss_events' => ['event_title', 'status', 'gross_loss_amount', 'severity', 'basel_event_type'],
            'issues' => ['issue_title', 'target_resolution_date', 'escalation_level'],
            'treatment_plans' => ['treatment_title', 'treatment_type', 'estimated_cost'],
            'loss_event_rca' => ['root_cause_description', 'performed_by'],
        ];

        $written = 0;

        foreach ($columns as $table => $names) {
            foreach ($names as $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $recent = DB::table($table)
                    ->whereNotNull($column)
                    ->where('updated_at', '>=', now()->subDays(30))
                    ->count();

                if ($recent > 0) {
                    $this->warn("  {$table}.{$column}: {$recent} row(s) written in the last 30 days");
                    $written++;
                }
            }
        }

        if ($written === 0) {
            $this->line('  nothing written in the last 30 days — Migration B can drop them.');
        }
    }
}
