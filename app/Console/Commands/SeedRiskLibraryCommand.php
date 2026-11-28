<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskLibraryItem;
use App\Services\AuditTrailService;
use App\Services\RiskLibraryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Non-destructive seeding of Risk Library items from existing unlinked risks (ADR 0028).
 *
 * Groups unlinked risks (`library_item_id IS NULL`) per organization by
 * `(category_id, normalized_title)`, previews candidate clusters, and requires
 * explicit confirmation (or `--confirm`) before creating library items and
 * linking existing rows. Never merges, overwrites, or deletes any risk row.
 */
class SeedRiskLibraryCommand extends Command
{
    protected $signature = 'erm:seed-risk-library
        {--org= : Limit seeding to a single organization ID}
        {--dry-run : Preview candidate clusters without writing anything}
        {--confirm : Create library items and link matching risks without interactive confirmation}';

    protected $description = 'Seed Risk Library items from existing unlinked risks without merging or deleting any risk rows.';

    public function handle(RiskLibraryService $service): int
    {
        $orgOption = $this->option('org');
        $dryRun = (bool) $this->option('dry-run');
        $autoConfirm = (bool) $this->option('confirm');

        $organizations = TenantContext::bypass(
            static function () use ($orgOption) {
                $q = Organization::query()->orderBy('id');
                if ($orgOption !== null && $orgOption !== '') {
                    $q->where('id', (int) $orgOption);
                }

                return $q->get(['id', 'name']);
            },
            'erm:seed-risk-library enumerating target organizations'
        );

        if ($organizations->isEmpty()) {
            $this->warn('No matching organizations found.');

            return self::SUCCESS;
        }

        foreach ($organizations as $organization) {
            TenantContext::actingAs((int) $organization->id, function () use ($organization, $service, $dryRun, $autoConfirm): void {
                $this->processOrganization($organization, $service, $dryRun, $autoConfirm);
            });
        }

        return self::SUCCESS;
    }

    private function processOrganization(
        Organization $organization,
        RiskLibraryService $service,
        bool $dryRun,
        bool $autoConfirm
    ): void {
        $unlinked = Risk::query()
            ->with(['category:id,name', 'causes:id,risk_id,cause_category_id,description'])
            ->where('organization_id', $organization->id)
            ->whereNull('library_item_id')
            ->whereNotNull('category_id')
            ->orderBy('id')
            ->get();

        if ($unlinked->isEmpty()) {
            $this->line("[Org #{$organization->id} {$organization->name}] No unlinked risks to cluster.");

            return;
        }

        $clusters = [];
        foreach ($unlinked as $risk) {
            $normTitle = RiskLibraryService::normaliseTitle((string) $risk->title);
            if ($normTitle === '') {
                continue;
            }
            $key = $risk->category_id.'|'.$normTitle;
            if (! isset($clusters[$key])) {
                $clusters[$key] = [
                    'category_id' => (int) $risk->category_id,
                    'category_name' => $risk->category?->name ?? '—',
                    'title' => trim((string) $risk->title),
                    'description' => trim((string) $risk->description),
                    'risk_source' => $risk->risk_source,
                    'basel_l1_event_type' => $risk->basel_l1_event_type,
                    'basel_l2_event_type' => $risk->basel_l2_event_type,
                    'risks' => [],
                ];
            }
            $clusters[$key]['risks'][] = $risk;
        }

        $this->info("\nOrganization #{$organization->id}: {$organization->name} — ".count($clusters).' candidate library item(s) from '.$unlinked->count().' unlinked risk(s)');

        $tableRows = [];
        foreach ($clusters as $cluster) {
            $codes = array_map(static fn (Risk $r) => $r->risk_code, array_slice($cluster['risks'], 0, 5));
            $suffix = count($cluster['risks']) > 5 ? ' (+'.(count($cluster['risks']) - 5).' more)' : '';
            $tableRows[] = [
                $cluster['category_name'],
                $cluster['title'],
                count($cluster['risks']),
                implode(', ', $codes).$suffix,
            ];
        }

        $this->table(['Category', 'Candidate Library Title', 'Matched Risks', 'Sample Risk Codes'], $tableRows);

        if ($dryRun) {
            $this->comment('Dry-run mode: no database changes were made.');

            return;
        }

        if (! $autoConfirm && ! $this->confirm(
            'Create '.count($clusters)." Risk Library item(s) and link {$unlinked->count()} existing risk(s) for {$organization->name}? (Existing risk rows will NOT be merged or deleted.)"
        )) {
            $this->comment('Skipped organization '.$organization->name.'.');

            return;
        }

        $createdItems = 0;
        $linkedRisks = 0;

        DB::transaction(function () use ($clusters, $organization, $service, &$createdItems, &$linkedRisks): void {
            foreach ($clusters as $cluster) {
                /** @var Risk $representative */
                $representative = $cluster['risks'][0];

                $typicalCauses = $representative->causes
                    ->map(static fn ($c) => [
                        'cause' => $c->description,
                        'cause_category_id' => $c->cause_category_id,
                    ])
                    ->values()
                    ->all();

                $item = RiskLibraryItem::create([
                    'organization_id' => $organization->id,
                    'code' => $service->generateNextCode((int) $organization->id),
                    'title' => $cluster['title'],
                    'description' => $cluster['description'] !== '' ? $cluster['description'] : $cluster['title'],
                    'category_id' => $cluster['category_id'],
                    'risk_source' => $cluster['risk_source'],
                    'basel_l1_event_type' => $cluster['basel_l1_event_type'],
                    'basel_l2_event_type' => $cluster['basel_l2_event_type'],
                    'typical_causes' => $typicalCauses !== [] ? $typicalCauses : null,
                    'typical_consequences' => $representative->consequences ?? null,
                    'current_version' => 1,
                    'status' => RiskLibraryItem::STATUS_ACTIVE,
                ]);

                $service->recordVersionSnapshot($item, 'Seeded from existing risk register entries.', null);

                AuditTrailService::record(
                    $item,
                    'library_item_created',
                    'all',
                    null,
                    ['code' => $item->code, 'title' => $item->title, 'version' => 1],
                    'Seeded via erm:seed-risk-library command.'
                );

                $createdItems++;

                foreach ($cluster['risks'] as $risk) {
                    $risk->update([
                        'library_item_id' => $item->id,
                        'library_version' => 1,
                        'library_synced_at' => now()->utc(),
                    ]);

                    AuditTrailService::record(
                        $risk,
                        'risk_linked_to_library',
                        'library_item_id',
                        null,
                        [
                            'library_item_id' => $item->id,
                            'library_code' => $item->code,
                            'library_version' => 1,
                        ],
                        "Linked to seeded Risk Library item {$item->code} (v1)."
                    );

                    $linkedRisks++;
                }
            }
        });

        $this->info("Created {$createdItems} Risk Library item(s) and linked {$linkedRisks} risk(s) for {$organization->name}.");
    }
}
