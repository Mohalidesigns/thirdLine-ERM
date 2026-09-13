<?php

use App\Support\Metadata\FormFieldRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WP-05 TASK 2 — describe the columns of five object types as attributes, so
 * their create/edit pairs can be rendered from metadata.
 *
 * A migration rather than a seeder for the reason 120005 gives: the views that
 * read these rows ship in the same release. An install that skipped the seeder
 * would render five empty forms.
 *
 * IDEMPOTENT AND NON-DESTRUCTIVE. Matched on (object_type_id, code). A field a
 * tenant has already customised — relabelled, moved to another section,
 * reordered — keeps their version of those columns; only the parts that
 * describe the underlying storage (data_type, maps_to_column, enum options,
 * validation) are refreshed. Re-running after a release that adds a field
 * installs the new one and leaves the rest alone.
 */
return new class extends Migration
{
    /**
     * Columns the tenant owns once the row exists. The migration writes them
     * on insert and never overwrites them afterwards.
     */
    private const TENANT_OWNED = ['label', 'section', 'sort_order', 'width', 'help_text', 'show_on_mobile', 'show_in_detail'];

    public function up(): void
    {
        $now = now();

        foreach (FormFieldRegistry::definitions() as $typeCode => $fields) {
            // System types, so organization_id is NULL and one row serves
            // every tenant — the same arrangement the type registry uses.
            $type = DB::table('object_types')
                ->where('code', $typeCode)
                ->whereNull('organization_id')
                ->first();

            if ($type === null) {
                // A registry that does not contain the type is a half-applied
                // WP-03, not something to paper over with a silent skip.
                logger()->warning('Cannot register form fields: object type is missing from the registry.', [
                    'object_type' => $typeCode,
                ]);

                continue;
            }

            foreach ($fields as $field) {
                $this->upsert((int) $type->id, $field, $now);
            }

            // A system field the registry no longer declares is one this
            // release withdrew — the KRI `category`, which the controller
            // validates and then discards for want of a column. Leaving the
            // row behind would keep rendering a field that cannot save.
            // Scoped to is_system so a tenant's own field is never touched.
            DB::table('object_attributes')
                ->where('object_type_id', $type->id)
                ->where('is_system', true)
                ->whereNotIn('code', array_column($fields, 'code'))
                ->delete();
        }
    }

    public function down(): void
    {
        foreach (FormFieldRegistry::definitions() as $typeCode => $fields) {
            $type = DB::table('object_types')
                ->where('code', $typeCode)
                ->whereNull('organization_id')
                ->first();

            if ($type === null) {
                continue;
            }

            // Only the rows this migration writes: is_system marks them, and a
            // tenant's own field on the same type is not this migration's to
            // remove.
            DB::table('object_attributes')
                ->where('object_type_id', $type->id)
                ->where('is_system', true)
                ->whereIn('code', array_column($fields, 'code'))
                ->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function upsert(int $objectTypeId, array $field, mixed $now): void
    {
        $row = [
            'object_type_id' => $objectTypeId,
            'code' => $field['code'],
            'maps_to_column' => $field['maps_to_column'] ?? null,
            'label' => $field['label'],
            'data_type' => $field['data_type'],
            'is_required' => $field['is_required'] ?? false,
            'is_unique' => $field['is_unique'] ?? false,
            'default_value' => $field['default_value'] ?? null,
            'validation' => isset($field['validation']) ? json_encode($field['validation']) : null,
            'visible_when' => isset($field['visible_when']) ? json_encode($field['visible_when']) : null,
            'enum_options' => isset($field['enum_options']) ? json_encode($field['enum_options']) : null,
            'section' => $field['section'] ?? 'Details',
            'sort_order' => $field['sort_order'] ?? 0,
            'width' => $field['width'] ?? 'half',
            'show_on_mobile' => $field['show_on_mobile'] ?? true,
            'show_in_detail' => $field['show_in_detail'] ?? true,
            'help_text' => $field['help_text'] ?? null,
            'is_pii' => $field['is_pii'] ?? false,
            'is_system' => true,
            'updated_at' => $now,
        ];

        $existing = DB::table('object_attributes')
            ->where('object_type_id', $objectTypeId)
            ->where('code', $field['code'])
            ->first();

        if ($existing === null) {
            DB::table('object_attributes')->insert($row + ['created_at' => $now]);

            return;
        }

        foreach (self::TENANT_OWNED as $column) {
            unset($row[$column]);
        }

        DB::table('object_attributes')->where('id', $existing->id)->update($row);
    }
};
