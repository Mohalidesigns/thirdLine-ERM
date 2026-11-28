<?php

namespace App\Console\Commands;

use App\Models\BusinessProcess;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Risk;
use App\Services\AuditTrailService;
use App\Services\ProcessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Non-destructive migration of legacy PROCESS-type entities into canonical
 * `business_processes` + `entity_business_process` rows (ADR 0028 Decision 3).
 *
 * Defaults to --dry-run. Never deletes or hard-merges any `entities` row.
 */
class MigrateProcessEntitiesCommand extends Command
{
    protected $signature = 'erm:migrate-process-entities
        {--org= : Limit migration to a specific organization ID}
        {--dry-run : Preview legacy PROCESS entities without writing to the database (default)}
        {--apply : Apply the non-destructive migration}';

    protected $description = 'Non-destructively migrate legacy PROCESS-type entities into business_processes and entity_business_process (ADR 0028 Decision 3).';

    public function __construct(
        private readonly ProcessService $processService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        $orgFilter = $this->option('org') !== null && $this->option('org') !== ''
            ? (int) $this->option('org')
            : null;

        // Enumeration crosses tenants, so it runs under an explicit, reasoned bypass of
        // the tenant scope ONLY. `withoutGlobalScopes()` also stripped SoftDeletes, which
        // migrated and re-linked soft-deleted entities and risks.
        $processTypeIds = TenantContext::bypass(
            static fn () => EntityType::query()
                ->where('code', 'PROCESS')
                ->when($orgFilter !== null, fn ($q) => $q->where('organization_id', $orgFilter))
                ->pluck('id')
                ->all(),
            'erm:migrate-process-entities enumerating PROCESS entity types'
        );

        if ($processTypeIds === []) {
            $this->info('No PROCESS entity types found. Nothing to migrate.');

            return self::SUCCESS;
        }

        $legacyEntities = TenantContext::bypass(
            static fn () => Entity::query()
                ->with(['parent:id,entity_code,name'])
                ->withCount(['risks', 'controls', 'keyRiskIndicators'])
                ->whereIn('entity_type_id', $processTypeIds)
                ->when($orgFilter !== null, fn ($q) => $q->where('organization_id', $orgFilter))
                ->orderBy('organization_id')
                ->orderBy('id')
                ->get(),
            'erm:migrate-process-entities enumerating legacy PROCESS entities'
        );

        if ($legacyEntities->isEmpty()) {
            $this->info('No legacy PROCESS-type entities found. Nothing to migrate.');

            return self::SUCCESS;
        }

        $rows = [];
        $pending = [];

        foreach ($legacyEntities as $entity) {
            $metadata = is_array($entity->metadata) ? $entity->metadata : [];
            $alreadyMigratedId = $metadata['migrated_to_business_process_id'] ?? null;

            $rows[] = [
                $entity->organization_id,
                $entity->id,
                $entity->entity_code,
                $entity->name,
                $entity->parent ? "{$entity->parent->entity_code} — {$entity->parent->name}" : '—',
                (int) $entity->risks_count,
                (int) $entity->controls_count,
                (int) $entity->key_risk_indicators_count,
                $alreadyMigratedId ? "Migrated (BP #{$alreadyMigratedId})" : 'Pending',
            ];

            if (! $alreadyMigratedId) {
                $pending[] = $entity;
            }
        }

        $this->table(
            ['Org', 'Entity ID', 'Code', 'Name', 'Parent Entity', 'Risks', 'Controls', 'KRIs', 'Migration State'],
            $rows
        );

        if ($pending === []) {
            $this->info('All legacy PROCESS-type entities have already been migrated.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn(sprintf(
                'DRY RUN: %d legacy PROCESS-type entit%s pending migration. Re-run with --apply to migrate non-destructively.',
                count($pending),
                count($pending) === 1 ? 'y' : 'ies'
            ));

            return self::SUCCESS;
        }

        $migratedCount = 0;
        $risksUpdated = 0;

        foreach ($pending as $legacyEntity) {
            // Each tenant's rows are written INSIDE that tenant's context, so the
            // tenant and soft-delete scopes both apply and every audit row lands
            // against the right organisation.
            TenantContext::actingAs((int) $legacyEntity->organization_id, function () use ($legacyEntity, &$migratedCount, &$risksUpdated): void {
                DB::transaction(function () use ($legacyEntity, &$migratedCount, &$risksUpdated): void {
                    $risksUpdated += $this->migrateOne($legacyEntity);
                    $migratedCount++;
                });
            });
        }

        $this->info("Migrated {$migratedCount} legacy PROCESS entity/entities into business_processes ({$risksUpdated} risk(s) re-linked). No entity rows were deleted.");

        return self::SUCCESS;
    }

    /**
     * Migrate one legacy PROCESS entity. Runs inside its tenant's context and a
     * transaction. Returns the number of risks re-linked.
     *
     * Every write is audited the way the interactive path audits it: the process
     * creation (`process_created`, as ProcessService does), the attach to the parent
     * entity (`process_attached_to_entity`), and ONE ROW PER RE-LINKED RISK with the
     * before and after `entity_id` / `process_id` — a bulk UPDATE would move a risk
     * between entities with no trace of where it had been.
     */
    private function migrateOne(Entity $legacyEntity): int
    {
        $orgId = (int) $legacyEntity->organization_id;

        $businessProcess = BusinessProcess::query()
            ->whereNull('parent_id')
            ->where('name', $legacyEntity->name)
            ->first();

        if ($businessProcess === null) {
            $businessProcess = BusinessProcess::create([
                'organization_id' => $orgId,
                'parent_id' => null,
                'code' => $this->processService->generateNextCode($orgId, 'PRC'),
                'name' => $legacyEntity->name,
                'description' => $legacyEntity->description,
                'owner_id' => $legacyEntity->owner_id,
                'is_active' => ($legacyEntity->status ?? 'active') === 'active',
            ]);

            AuditTrailService::record(
                $businessProcess,
                'process_created',
                'all',
                null,
                [
                    'code' => $businessProcess->code,
                    'name' => $businessProcess->name,
                    'parent_id' => null,
                    'migrated_from_entity_id' => $legacyEntity->id,
                ],
                "Created business process {$businessProcess->code} — {$businessProcess->name} from legacy PROCESS entity {$legacyEntity->entity_code}."
            );
        }

        $parent = $legacyEntity->parent_id !== null
            ? Entity::query()->whereKey($legacyEntity->parent_id)->first()
            : null;

        if ($parent !== null) {
            $pivotExists = DB::table('entity_business_process')
                ->where('organization_id', $orgId)
                ->where('entity_id', $parent->id)
                ->where('business_process_id', $businessProcess->id)
                ->exists();

            if (! $pivotExists) {
                $now = now()->utc();
                DB::table('entity_business_process')->insert([
                    'organization_id' => $orgId,
                    'entity_id' => $parent->id,
                    'business_process_id' => $businessProcess->id,
                    'is_primary' => true,
                    'attached_by' => null,
                    'attached_at' => $now,
                    'created_at' => $now,
                ]);

                AuditTrailService::record(
                    $businessProcess,
                    'process_attached_to_entity',
                    'entity_id',
                    null,
                    [
                        'entity_id' => $parent->id,
                        'entity_code' => $parent->entity_code,
                        'entity_name' => $parent->name,
                        'is_primary' => true,
                    ],
                    "Attached process {$businessProcess->code} to entity {$parent->entity_code} — {$parent->name} (legacy PROCESS entity migration)."
                );
            }
        }

        $relinked = 0;

        Risk::query()
            ->where('entity_id', $legacyEntity->id)
            ->chunkById(200, function ($risks) use ($businessProcess, $parent, $legacyEntity, &$relinked): void {
                foreach ($risks as $risk) {
                    $before = ['entity_id' => $risk->entity_id, 'process_id' => $risk->process_id];
                    $after = [
                        'entity_id' => $parent?->id ?? $risk->entity_id,
                        'process_id' => $businessProcess->id,
                    ];

                    $risk->forceFill($after)->saveQuietly();

                    AuditTrailService::record(
                        $risk,
                        'risk_relinked_to_process',
                        'entity_id,process_id',
                        $before,
                        $after,
                        "Re-linked from legacy PROCESS entity {$legacyEntity->entity_code} to business process {$businessProcess->code}."
                    );

                    $relinked++;
                }
            });

        $statusBefore = $legacyEntity->status;
        $metadata = is_array($legacyEntity->metadata) ? $legacyEntity->metadata : [];
        $metadata['migrated_to_business_process_id'] = $businessProcess->id;
        $metadata['migrated_at'] = now()->utc()->toIso8601String();

        $legacyEntity->update([
            'status' => 'inactive',
            'metadata' => $metadata,
        ]);

        AuditTrailService::record(
            $legacyEntity,
            'process_entity_migrated',
            'status,metadata',
            ['status' => $statusBefore],
            [
                'status' => 'inactive',
                'business_process_id' => $businessProcess->id,
                'business_process_code' => $businessProcess->code,
                'parent_entity_id' => $legacyEntity->parent_id,
                'risks_relinked' => $relinked,
            ],
            "Non-destructively migrated legacy PROCESS entity {$legacyEntity->entity_code} to business process {$businessProcess->code}."
        );

        return $relinked;
    }
}
