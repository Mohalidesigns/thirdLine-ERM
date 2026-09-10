<?php

use App\Support\Metadata\FormFieldRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration Phase 4.6 — put the emerging risk form's options back in touch with
 * its columns.
 *
 * `2026_08_13_120005_register_column_backed_form_fields` seeded the EmergingRisk
 * attributes from FormFieldRegistry, and three of them were hand-written
 * literals that had never matched the enum columns they map to:
 *
 *   horizon           near_term/medium_term/long_term   vs  0-3m/3-6m/6-12m/12m+
 *   potential_impact  low/moderate/high/severe          vs  Low/Medium/High/Critical
 *   status            monitoring/escalating/promoted/   vs  monitoring/assessing/
 *                     dismissed                             escalated/converted/closed
 *
 * `horizon` and `potential_impact` are both REQUIRED, so submitting exactly what
 * the form offered failed validation on two fields whose every option was
 * invalid: THE CREATE FORM COULD NOT SAVE AN ENTRY AT ALL, on any tenant, since
 * the form became metadata-driven. The registry now derives all three from
 * EmergingRisk::HORIZONS/IMPACTS/STATUSES so they cannot drift apart again.
 *
 * 120005 is idempotent and already applied everywhere, so it will not re-run.
 * This replays its upsert for this one type. It refreshes only the columns that
 * describe STORAGE — data_type, maps_to_column, enum_options, validation,
 * is_required, default_value — and leaves every column 120005 marks as tenant
 * owned (label, section, sort_order, width, help_text, visibility) exactly as
 * the tenant has it. A tenant's own non-system attribute is never touched.
 *
 * Down() is deliberately a no-op: the previous values could not be saved, and
 * restoring a form that cannot be submitted is not a rollback anybody wants.
 */
return new class extends Migration
{
    private const TYPE = 'EmergingRisk';

    public function up(): void
    {
        $type = DB::table('object_types')
            ->where('code', self::TYPE)
            ->whereNull('organization_id')
            ->first();

        if ($type === null) {
            logger()->warning('Cannot realign emerging risk form options: the object type is missing.', [
                'object_type' => self::TYPE,
            ]);

            return;
        }

        $fields = FormFieldRegistry::definitions()[self::TYPE] ?? [];

        foreach ($fields as $field) {
            $existing = DB::table('object_attributes')
                ->where('object_type_id', $type->id)
                ->where('code', $field['code'])
                ->where('is_system', true)
                ->first();

            if ($existing === null) {
                // 120005 has not run on this install, or the row is a tenant's
                // own. Either way it is not this migration's to write — 120005
                // will install it, from the corrected registry.
                continue;
            }

            DB::table('object_attributes')->where('id', $existing->id)->update([
                'maps_to_column' => $field['maps_to_column'] ?? null,
                'data_type' => $field['data_type'],
                'is_required' => $field['is_required'] ?? false,
                'is_unique' => $field['is_unique'] ?? false,
                'default_value' => $field['default_value'] ?? null,
                'validation' => isset($field['validation']) ? json_encode($field['validation']) : null,
                'visible_when' => isset($field['visible_when']) ? json_encode($field['visible_when']) : null,
                'enum_options' => isset($field['enum_options']) ? json_encode($field['enum_options']) : null,
                'is_pii' => $field['is_pii'] ?? false,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No-op. See the class comment.
    }
};
