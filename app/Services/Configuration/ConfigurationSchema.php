<?php

namespace App\Services\Configuration;

/**
 * WP-05 TASK 4 — what a configuration bundle contains, and how each section is
 * identified across environments.
 *
 * THE NATURAL KEY IS THE WHOLE DESIGN. Primary keys are meaningless between
 * environments: object type 7 in staging is not object type 7 in production,
 * and an importer that matches on id will either collide or duplicate. Every
 * section therefore declares a natural key — a tuple of columns that means the
 * same thing everywhere — and both the diff and the apply are expressed in
 * terms of it.
 *
 * FOREIGN KEYS ARE EXPORTED AS NATURAL KEYS TOO. object_attributes.object_type_id
 * is exported as the type's code, and resolved back to whatever id that code
 * has in the target. Without this, importing attributes attaches them to
 * whichever type happens to hold that id in the destination — which is not an
 * error anybody notices until a form renders the wrong fields.
 *
 * `dependsOn` fixes the apply order. Attributes cannot be written before the
 * types they hang off exist, and a lifecycle cannot be assigned to a type
 * before the lifecycle row exists — which is why object_types is applied twice,
 * once without its default_lifecycle_id and once to set it. See the importer.
 */
class ConfigurationSchema
{
    /**
     * @return array<string, array{
     *     table: string,
     *     label: string,
     *     key: list<string>,
     *     columns: list<string>,
     *     json: list<string>,
     *     references: array<string, array{section: string, nullable: bool}>,
     *     dependsOn: list<string>,
     *     tenantScoped: bool,
     *     systemFlag: ?string,
     * }>
     */
    public static function sections(): array
    {
        return [
            'object_types' => [
                'table' => 'object_types',
                'label' => 'Object types',
                'key' => ['code'],
                'columns' => [
                    'code', 'name', 'plural_name', 'description', 'category', 'icon', 'color',
                    'level_hint', 'is_node_type', 'allowed_child_type_ids', 'code_prefix',
                    'backing_model', 'sort_order',
                ],
                'json' => ['allowed_child_type_ids'],
                'references' => [
                    'parent_type_id' => ['section' => 'object_types', 'nullable' => true],
                    // Resolved in a second pass: the lifecycle rows are written
                    // after the types they belong to.
                    'default_lifecycle_id' => ['section' => 'object_lifecycles', 'nullable' => true],
                ],
                'dependsOn' => [],
                'tenantScoped' => true,
                'systemFlag' => 'is_system',
            ],

            'object_attributes' => [
                'table' => 'object_attributes',
                'label' => 'Fields',
                // Unique within a type, so the type's key is part of this one.
                'key' => ['object_type_id', 'code'],
                'columns' => [
                    'code', 'maps_to_column', 'label', 'data_type', 'is_required', 'is_unique',
                    'default_value', 'validation', 'visible_when', 'enum_options', 'formula',
                    'section', 'sort_order', 'width', 'show_on_mobile', 'show_in_detail',
                    'help_text', 'is_pii',
                ],
                'json' => ['validation', 'visible_when', 'enum_options'],
                'references' => [
                    'object_type_id' => ['section' => 'object_types', 'nullable' => false],
                    'ref_object_type_id' => ['section' => 'object_types', 'nullable' => true],
                ],
                'dependsOn' => ['object_types'],
                'tenantScoped' => false,
                'systemFlag' => 'is_system',
            ],

            'object_relationship_types' => [
                'table' => 'object_relationship_types',
                'label' => 'Relationship types',
                'key' => ['code'],
                'columns' => [
                    'code', 'name', 'inverse_code', 'cardinality', 'has_weight', 'attribute_schema',
                ],
                // from_type_ids / to_type_ids hold object type IDs inside a JSON
                // array, which no per-column reference map can express. The
                // exporter and importer translate them explicitly.
                'json' => ['attribute_schema', 'from_type_ids', 'to_type_ids'],
                'references' => [],
                'dependsOn' => ['object_types'],
                'tenantScoped' => true,
                'systemFlag' => 'is_system',
            ],

            'object_lifecycles' => [
                'table' => 'object_lifecycles',
                'label' => 'Lifecycles',
                'key' => ['object_type_id', 'code'],
                'columns' => ['code', 'name', 'states'],
                'json' => ['states'],
                'references' => [
                    'object_type_id' => ['section' => 'object_types', 'nullable' => false],
                ],
                'dependsOn' => ['object_types'],
                'tenantScoped' => true,
                'systemFlag' => 'is_system',
            ],

            'scoring_profiles' => [
                'table' => 'scoring_profiles',
                'label' => 'Scoring profiles',
                'key' => ['code'],
                'columns' => [
                    'code', 'name', 'description', 'applies_to', 'likelihood_scale', 'impact_scale',
                    'impact_dimensions', 'impact_aggregation', 'dimension_weights', 'rating_bands',
                    'residual_formula', 'matrix_rows', 'matrix_cols', 'is_default', 'effective_from',
                ],
                'json' => [
                    'applies_to', 'likelihood_scale', 'impact_scale', 'impact_dimensions',
                    'dimension_weights', 'rating_bands',
                ],
                'references' => [],
                'dependsOn' => [],
                'tenantScoped' => true,
                'systemFlag' => 'is_system',
            ],

            'measures' => [
                'table' => 'measures',
                'label' => 'Measures',
                'key' => ['code'],
                'columns' => self::columnsOf('measures', exclude: [
                    'id', 'organization_id', 'created_at', 'updated_at', 'deleted_at',
                ]),
                'json' => [],
                'references' => [],
                'dependsOn' => [],
                'tenantScoped' => true,
                'systemFlag' => null,
            ],

            'measure_thresholds' => [
                'table' => 'measure_thresholds',
                'label' => 'Thresholds',
                'key' => ['measure_id', 'band_code', 'effective_from'],
                'columns' => self::columnsOf('measure_thresholds', exclude: [
                    'id', 'organization_id', 'measure_id', 'created_at', 'updated_at', 'deleted_at',
                ]),
                'json' => [],
                'references' => [
                    'measure_id' => ['section' => 'measures', 'nullable' => false],
                ],
                'dependsOn' => ['measures'],
                'tenantScoped' => true,
                'systemFlag' => null,
            ],

            'workflow_definitions' => [
                'table' => 'workflow_definitions',
                'label' => 'Workflow definitions',
                'key' => ['code'],
                'columns' => self::columnsOf('workflow_definitions', exclude: [
                    'id', 'organization_id', 'created_at', 'updated_at', 'deleted_at',
                ]),
                'json' => [],
                'references' => [],
                'dependsOn' => [],
                'tenantScoped' => true,
                'systemFlag' => null,
            ],
        ];
    }

    /**
     * Sections the work package names that have no table yet — dashboards,
     * widgets, report templates and content pack bindings.
     *
     * They are declared rather than omitted so the bundle format carries the
     * empty section from the first release. A consumer that has to distinguish
     * "this environment has no dashboards" from "this bundle predates
     * dashboards" otherwise has no way to tell, and every format that solves
     * that problem later does it by breaking compatibility once.
     *
     * @return list<string>
     */
    public static function reservedSections(): array
    {
        return ['dashboards', 'widgets', 'report_templates', 'content_pack_bindings'];
    }

    /**
     * Columns of a table, minus the ones that never travel between
     * environments.
     *
     * Read from the live schema rather than listed by hand so that a column
     * added to measures in a later work package is exported without anybody
     * remembering to come back here. The cost is that a column added without
     * thought is exported without thought, which is why the identity and
     * tenancy columns are excluded explicitly rather than by convention.
     *
     * @param  list<string>  $exclude
     * @return list<string>
     */
    private static function columnsOf(string $table, array $exclude): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return [];
        }

        return array_values(array_diff(
            \Illuminate\Support\Facades\Schema::getColumnListing($table),
            $exclude
        ));
    }

    /**
     * Sections in the order they must be applied, parents before children.
     *
     * @return list<string>
     */
    public static function applyOrder(): array
    {
        $sections = self::sections();
        $ordered = [];
        $visiting = [];

        $visit = function (string $name) use (&$visit, &$ordered, &$visiting, $sections) {
            if (in_array($name, $ordered, true) || isset($visiting[$name])) {
                return;
            }

            $visiting[$name] = true;

            foreach ($sections[$name]['dependsOn'] ?? [] as $dependency) {
                $visit($dependency);
            }

            unset($visiting[$name]);
            $ordered[] = $name;
        };

        foreach (array_keys($sections) as $name) {
            $visit($name);
        }

        return $ordered;
    }

    /**
     * A stable string identifying one row within its section.
     *
     * @param  array<string, mixed>  $row
     */
    public static function naturalKey(string $section, array $row): string
    {
        $parts = [];

        foreach (self::sections()[$section]['key'] as $column) {
            $parts[] = (string) ($row[$column] ?? $row['_refs'][$column] ?? '');
        }

        return implode('::', $parts);
    }
}
