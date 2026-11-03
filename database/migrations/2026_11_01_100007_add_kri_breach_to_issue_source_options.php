<?php

use App\Support\Metadata\FormFieldRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0026 section 8.3 row 3 - `kri_breach` joins the Issue source list.
 *
 * The list is enumerated in eight places and one of them is DATA:
 * `object_attributes.enum_options` for `Issue.issue_source` was seeded once from
 * FormFieldRegistry by 2026_08_13_120005, so editing the registry changes no
 * deployed form. This replays that one attribute's upsert, STORAGE columns
 * only (data_type, maps_to_column, enum_options, validation, is_required,
 * default_value), exactly as 2026_09_05_100000 did for EmergingRisk. A tenant's
 * own label, section, order and visibility are untouched, and a tenant's own
 * non-system attribute is never written.
 *
 * down() is a documented no-op: removing the option would strand every Issue
 * already raised with the source, and a form that cannot display its own saved
 * value is not a rollback anybody wants.
 */
return new class extends Migration
{
    private const TYPE = 'Issue';

    private const ATTRIBUTE = 'issue_source';

    public function up(): void
    {
        $type = DB::table('object_types')
            ->where('code', self::TYPE)
            ->whereNull('organization_id')
            ->first();

        if ($type === null) {
            logger()->warning('Cannot add kri_breach to the issue source options: the object type is missing.', [
                'object_type' => self::TYPE,
            ]);

            return;
        }

        $field = collect(FormFieldRegistry::definitions()[self::TYPE] ?? [])
            ->firstWhere('code', self::ATTRIBUTE);

        if ($field === null) {
            return;
        }

        $existing = DB::table('object_attributes')
            ->where('object_type_id', $type->id)
            ->where('code', self::ATTRIBUTE)
            ->where('is_system', true)
            ->first();

        if ($existing === null) {
            // 120005 has not run on this install, or the row is a tenant's own.
            // 120005 will install it from the corrected registry.
            return;
        }

        DB::table('object_attributes')->where('id', $existing->id)->update([
            'maps_to_column' => $field['maps_to_column'] ?? null,
            'data_type' => $field['data_type'],
            'is_required' => $field['is_required'] ?? false,
            'default_value' => $field['default_value'] ?? null,
            'validation' => isset($field['validation']) ? json_encode($field['validation']) : null,
            'enum_options' => isset($field['enum_options']) ? json_encode($field['enum_options']) : null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // No-op. See the class comment.
    }
};
