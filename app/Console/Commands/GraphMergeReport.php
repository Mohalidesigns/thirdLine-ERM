<?php

namespace App\Console\Commands;

use App\Models\ObjectMergeCandidate;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Print the org-model unification review queue.
 *
 * WP-03 TASK 4 requires a merge report for human review and forbids silent
 * merges. object_merge_candidates is that report; this is how somebody reads it
 * without a database client.
 *
 * `--pending` is the one that matters: those are the pairs the unifier refused
 * to decide. Until they are settled, entities.entity_id and
 * risks.business_unit_id cannot be dropped, because they are still the fallback
 * for anything the graph got wrong.
 */
class GraphMergeReport extends Command
{
    protected $signature = 'graph:merge-report
                            {--org= : restrict to one organization}
                            {--pending : only the pairs awaiting a decision}
                            {--automatic : only what the unifier decided on its own}';

    protected $description = 'Show the organisation-model merge review queue produced by the object graph unification.';

    public function handle(): int
    {
        $organizationId = $this->option('org') === null ? null : (int) $this->option('org');

        return TenantContext::actingAs($organizationId, function () use ($organizationId) {
            $query = ObjectMergeCandidate::query()
                ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
                ->when($this->option('pending'), fn ($q) => $q->pending())
                ->when($this->option('automatic'), fn ($q) => $q->automatic())
                ->orderBy('organization_id')
                ->orderByDesc('similarity');

            $rows = $query->get();

            if ($rows->isEmpty()) {
                $this->info('Nothing in the merge queue.');

                return self::SUCCESS;
            }

            $this->table(
                ['Org', 'Decision', 'Basis', 'Similarity', 'Left', 'Right', 'Object'],
                $rows->map(fn (ObjectMergeCandidate $row) => [
                    $row->organization_id,
                    $row->decision,
                    $row->match_basis,
                    // A ratio computed from the two names, not a confidence
                    // score: it ranks the queue and decides nothing.
                    number_format((float) $row->similarity, 2),
                    "{$row->left_source_type}#{$row->left_source_id} {$row->left_name}",
                    "{$row->right_source_type}#{$row->right_source_id} {$row->right_name}",
                    $row->resolved_object_id ?? '—',
                ])->all()
            );

            $summary = $rows->groupBy('decision')->map->count();

            foreach ($summary as $decision => $count) {
                $this->line("  {$decision}: {$count}");
            }

            if (($summary['pending'] ?? 0) > 0) {
                $this->warn(
                    ($summary['pending'] ?? 0).' pairing(s) still need a human decision. '
                    .'The legacy entity_id / business_unit_id columns stay until they are settled.'
                );
            }

            return self::SUCCESS;
        });
    }
}
