<?php

namespace App\Support\Graph;

use Illuminate\Support\Facades\DB;

/**
 * Writes the system type registry into the database, idempotently.
 *
 * Deliberately query-builder only. A migration that goes through Eloquent binds
 * itself to whatever shape the models happen to have on the day it is replayed,
 * which is how a migration that passed in CI fails eighteen months later on a
 * customer's restore. This class is called by the seeding migration and by
 * ObjectGraphSeeder, and both get the same result.
 *
 * System rows are identified by (organization_id IS NULL, code). That pair
 * cannot be a unique index in MySQL — NULLs compare distinct — so the lookup is
 * explicit rather than an upsert.
 */
class ObjectRegistryInstaller
{
    public function install(): void
    {
        $typeIds = $this->installTypes();
        $this->installTypeGraphReferences($typeIds);
        $this->installRelationshipTypes($typeIds);
        $this->installLifecycles($typeIds);
    }

    /**
     * @return array<string, int> type code => id
     */
    private function installTypes(): array
    {
        $now = now();
        $ids = [];
        $sortOrder = 0;

        foreach (ObjectTypeRegistry::types() as $type) {
            $sortOrder += 10;

            $row = [
                'name' => $type['name'],
                'plural_name' => $type['plural_name'],
                'category' => $type['category'],
                'icon' => $type['icon'],
                'color' => $type['color'],
                'level_hint' => $type['level_hint'] ?? null,
                'is_system' => true,
                'is_node_type' => $type['is_node_type'],
                'code_prefix' => $type['code_prefix'],
                'sort_order' => $sortOrder,
                'updated_at' => $now,
            ];

            $existingId = DB::table('object_types')
                ->whereNull('organization_id')
                ->where('code', $type['code'])
                ->value('id');

            if ($existingId) {
                DB::table('object_types')->where('id', $existingId)->update($row);
                $ids[$type['code']] = (int) $existingId;

                continue;
            }

            $ids[$type['code']] = (int) DB::table('object_types')->insertGetId($row + [
                'organization_id' => null,
                'code' => $type['code'],
                'created_at' => $now,
            ]);
        }

        return $ids;
    }

    /**
     * Second pass: the references a type makes to other types can only be
     * resolved once every type has an id.
     *
     * @param  array<string, int>  $typeIds
     */
    private function installTypeGraphReferences(array $typeIds): void
    {
        foreach (ObjectTypeRegistry::types() as $type) {
            $childCodes = $type['allowed_child_type_codes'] ?? [];

            $childIds = array_values(array_filter(array_map(
                fn (string $code) => $typeIds[$code] ?? null,
                $childCodes
            )));

            DB::table('object_types')->where('id', $typeIds[$type['code']])->update([
                'parent_type_id' => isset($type['parent_type_code']) ? ($typeIds[$type['parent_type_code']] ?? null) : null,
                'allowed_child_type_ids' => $childIds === [] ? null : json_encode($childIds),
            ]);
        }
    }

    /**
     * @param  array<string, int>  $typeIds
     */
    private function installRelationshipTypes(array $typeIds): void
    {
        $now = now();

        foreach (ObjectTypeRegistry::relationshipTypes() as $relationship) {
            $resolve = fn (array $codes) => array_values(array_filter(array_map(
                fn (string $code) => $typeIds[$code] ?? null,
                $codes
            )));

            $fromIds = $resolve($relationship['from_type_codes']);
            $toIds = $resolve($relationship['to_type_codes']);

            $row = [
                'name' => $relationship['name'],
                'inverse_code' => $relationship['inverse_code'],
                // NULL means "any type". An empty array would mean "no type is
                // allowed", which would make the edge unusable.
                'from_type_ids' => $fromIds === [] ? null : json_encode($fromIds),
                'to_type_ids' => $toIds === [] ? null : json_encode($toIds),
                'cardinality' => $relationship['cardinality'],
                'has_weight' => $relationship['has_weight'],
                'attribute_schema' => isset($relationship['attribute_schema'])
                    ? json_encode($relationship['attribute_schema'])
                    : null,
                'is_system' => true,
                'updated_at' => $now,
            ];

            $existingId = DB::table('object_relationship_types')
                ->whereNull('organization_id')
                ->where('code', $relationship['code'])
                ->value('id');

            if ($existingId) {
                DB::table('object_relationship_types')->where('id', $existingId)->update($row);

                continue;
            }

            DB::table('object_relationship_types')->insert($row + [
                'organization_id' => null,
                'code' => $relationship['code'],
                'created_at' => $now,
            ]);
        }
    }

    /**
     * @param  array<string, int>  $typeIds
     */
    private function installLifecycles(array $typeIds): void
    {
        $now = now();

        foreach (ObjectTypeRegistry::lifecycles() as $lifecycle) {
            $typeId = $typeIds[$lifecycle['object_type_code']] ?? null;

            if ($typeId === null) {
                continue;
            }

            $row = [
                'name' => $lifecycle['name'],
                'states' => json_encode($lifecycle['states']),
                'is_system' => true,
                'updated_at' => $now,
            ];

            $existingId = DB::table('object_lifecycles')
                ->whereNull('organization_id')
                ->where('object_type_id', $typeId)
                ->where('code', $lifecycle['code'])
                ->value('id');

            if ($existingId) {
                DB::table('object_lifecycles')->where('id', $existingId)->update($row);
                $lifecycleId = (int) $existingId;
            } else {
                $lifecycleId = (int) DB::table('object_lifecycles')->insertGetId($row + [
                    'organization_id' => null,
                    'object_type_id' => $typeId,
                    'code' => $lifecycle['code'],
                    'created_at' => $now,
                ]);
            }

            DB::table('object_types')->where('id', $typeId)->update([
                'default_lifecycle_id' => $lifecycleId,
            ]);
        }
    }
}
