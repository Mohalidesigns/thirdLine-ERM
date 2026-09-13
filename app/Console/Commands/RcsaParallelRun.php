<?php

namespace App\Console\Commands;

use App\Models\Rcsa\RcsaAssessment;
use App\Services\Rcsa\RcsaParallelRunComparer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * §13 step 5's comparison, once the pilot unit has assessed the quarter twice.
 *
 * THE RUN ITSELF IS PEOPLE, NOT A COMMAND. What this does is the part that
 * cannot be done reliably by eye: two hundred rows across two modules, where
 * the differences that matter look exactly like the ones that do not.
 *
 * ONLY ONE KIND OF DIFFERENCE BLOCKS A SIGN-OFF — identical inputs reaching
 * different scores. Everything else is an assessor answering differently from
 * whoever last edited the register, which is what a self-assessment is for and
 * would make a red verdict meaningless if it counted.
 */
class RcsaParallelRun extends Command
{
    protected $signature = 'rcsa:parallel-run
        {--assessment= : The v2 assessment to compare against the register.}
        {--organization= : Needed only when the assessment is ambiguous across tenants.}
        {--full : List every row, not only the ones that differ.}';

    protected $description = 'Compare a v2 assessment against the legacy risk register, line by line (§13 step 5)';

    public function handle(RcsaParallelRunComparer $comparer): int
    {
        $id = (int) $this->option('assessment');

        if ($id === 0) {
            $this->error('Name the v2 assessment with --assessment.');

            return self::FAILURE;
        }

        $assessment = RcsaAssessment::query()->withoutGlobalScopes()->find($id);

        if ($assessment === null) {
            $this->error("No assessment {$id}.");

            return self::FAILURE;
        }

        TenantContext::set((int) $assessment->organization_id);

        $report = $comparer->compare($assessment);

        $this->newLine();
        $this->info(sprintf('%s — %s', $report['business_unit'] ?? 'Unknown unit', $report['cycle'] ?? 'no cycle'));
        $this->line(str_repeat('─', 70));

        $s = $report['summary'];

        $this->table(['', 'Lines'], [
            ['Compared', $s['lines']],
            ['Identical', $s['match']],
            ['Assessor judgement differs', $s['judgement']],
            ['ENGINE DISAGREEMENT', $s['engine']],
            ['No register baseline', $s['no_baseline']],
        ]);

        $rows = collect($report['rows'])
            ->when(! $this->option('full'), fn ($c) => $c->where('classification', '!=', RcsaParallelRunComparer::MATCH));

        if ($rows->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Risk', 'v2 L×I', 'legacy L×I', 'v2 residual', 'legacy residual', 'Reading'],
                $rows->map(fn (array $r) => [
                    $r['risk_no'],
                    $this->pair($r['v2']['likelihood'] ?? null, $r['v2']['impact'] ?? null),
                    $r['legacy'] === null ? '—' : $this->pair($r['legacy']['likelihood'], $r['legacy']['impact']),
                    $this->level($r['v2']['residual_score'] ?? null, $r['v2']['residual_level'] ?? null),
                    $r['legacy'] === null ? '—' : $this->level($r['legacy']['residual_score'], $r['legacy']['residual_level']),
                    $r['classification'] === RcsaParallelRunComparer::ENGINE ? '*** ENGINE ***' : $r['classification'],
                ])->all(),
            );
        }

        $this->newLine();

        if ($report['verdict']['signable']) {
            $this->info('  ✓ '.$report['verdict']['note']);
        } else {
            $this->error('  ✗ '.$report['verdict']['note']);

            foreach ($report['verdict']['blockers'] as $blocker) {
                $this->error('    - '.$blocker);
            }
        }

        $path = sprintf('rcsa/parallel-run/%d/assessment-%d-%s.json',
            $assessment->organization_id, $assessment->id, now()->format('Ymd-His'));

        Storage::disk('local')->put($path, (string) json_encode($report, JSON_PRETTY_PRINT));

        $this->line("  Written to storage/app/private/{$path}");

        TenantContext::clear();

        return $report['verdict']['signable'] ? self::SUCCESS : self::FAILURE;
    }

    private function pair(?int $likelihood, ?int $impact): string
    {
        return $likelihood === null || $impact === null ? '—' : "{$likelihood}×{$impact}";
    }

    private function level(?float $score, ?string $level): string
    {
        if ($score === null && blank($level)) {
            return '—';
        }

        return trim(sprintf('%s %s', $score === null ? '' : rtrim(rtrim(number_format($score, 2), '0'), '.'),
            blank($level) ? '' : '('.str_replace('_', ' ', $level).')'));
    }
}
