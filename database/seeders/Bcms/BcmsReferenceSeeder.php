<?php

namespace Database\Seeders\Bcms;

use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\BlackoutPeriod;
use App\Models\Bcms\ClauseRef;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\ReadinessTemplate;
use App\Models\Bcms\ReadinessTemplateTask;
use App\Models\Bcms\Scenario;
use App\Models\Bcms\Setting;
use App\Models\Bcms\TrainingCurriculum;
use App\Models\Organization;
use App\Services\Bcms\BcmsSettings;
use Database\Seeders\Bcms\Reference\AlertTemplates;
use Database\Seeders\Bcms\Reference\BlackoutCalendar;
use Database\Seeders\Bcms\Reference\ClauseRefs;
use Database\Seeders\Bcms\Reference\ExerciseTypes;
use Database\Seeders\Bcms\Reference\ReadinessTemplates;
use Database\Seeders\Bcms\Reference\Scenarios;
use Database\Seeders\Bcms\Reference\TrainingCurricula;
use Illuminate\Database\Seeder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Everything BCMS needs before a user can do anything.
 *
 * TWO HALVES, DIFFERENT IN KIND — the split TPRM settled and BCMS follows.
 *
 * THE SYSTEM HALF carries `organization_id = null`: the clause reference table,
 * the exercise-type catalogue, the readiness templates, the blackout calendar,
 * the alert templates, the scenario library and the training curricula. Shipped
 * with the product, readable by every tenant through
 * `$tenantIncludesGlobal`, editable by none. Idempotent by natural key, so
 * re-running after a catalogue is extended adds the new rows without
 * duplicating or resetting the old ones.
 *
 * THE TENANT HALF is one row: `bcms_settings`, created with the defaults from
 * `config/bcms.php`. Everything else a tenant owns is theirs to create.
 *
 * WHY THIS SEEDER WRITES NO EXERCISES, NO PROCESSES AND NO CONTACTS. A client
 * who found an invented fire drill already on their calendar, or somebody
 * else's phone number in their emergency roster, would be right to stop
 * trusting everything else the seeder put there. Demonstration data lives in
 * `BcmsDemoSeeder`, beside `DemoDataSeeder`, for exactly that reason.
 *
 * SYSTEM ROWS ARE WRITTEN WITH TENANCY BYPASSED. `BelongsToOrganization`
 * stamps `organization_id` from `TenantContext` on create, so a system row
 * written inside a tenant loop would silently become that tenant's private
 * copy — the trap that cost the TPRM build two sessions. `TenantContext::clear()`
 * before the system half is not tidiness.
 */
class BcmsReferenceSeeder extends Seeder
{
    public function run(): void
    {
        TenantContext::clear();

        $this->seedClauseRefs();
        $this->seedExerciseTypesAndReadiness();
        $this->seedBlackoutCalendar();
        $this->seedAlertTemplates();
        $this->seedScenarios();
        $this->seedTrainingCurricula();

        foreach (Organization::query()->get() as $organization) {
            $this->seedSettingsFor($organization);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  System libraries */
    /* ------------------------------------------------------------------ */

    private function seedClauseRefs(): void
    {
        foreach (ClauseRefs::all() as $index => $row) {
            ClauseRef::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'standard' => $row['standard'],
                    'clause' => $row['clause'],
                    'title' => $row['title'],
                    'requirement' => $row['requirement'],
                    'citation' => $row['citation'],
                    'is_mandatory_record' => $row['mandatory'],
                    'export_packs' => $row['packs'],
                    'sort_order' => $index,
                ]
            );
        }
    }

    private function seedExerciseTypesAndReadiness(): void
    {
        // Readiness templates first: an exercise type points at one.
        $templateIds = [];

        foreach (ReadinessTemplates::all() as $template) {
            /** @var ReadinessTemplate $model */
            $model = ReadinessTemplate::query()->updateOrCreate(
                ['organization_id' => null, 'code' => $template['code']],
                [
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'is_system_default' => true,
                    'is_active' => true,
                ]
            );

            $templateIds[$template['code']] = $model->id;

            foreach ($template['tasks'] as $order => $task) {
                ReadinessTemplateTask::query()->updateOrCreate(
                    ['template_id' => $model->id, 'title' => $task['title']],
                    [
                        'organization_id' => null,
                        'due_offset_days' => $task['offset'],
                        'is_blocking' => $task['blocking'],
                        'requires_evidence' => $task['evidence'],
                        'default_owner_role' => $task['role'] ?? null,
                        'sort_order' => $order,
                    ]
                );
            }
        }

        foreach (ExerciseTypes::all() as $type) {
            // A type with no checklist of its own falls back to the generic
            // one. A type with NO checklist at all would generate an occurrence
            // with an empty readiness ladder, which reads as "nothing to do"
            // rather than as "nobody configured this".
            $templateCode = isset($templateIds[$type['code']]) ? $type['code'] : 'GENERIC';

            ExerciseType::query()->updateOrCreate(
                ['organization_id' => null, 'code' => $type['code']],
                [
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'ladder_level' => $type['ladder_level'],
                    'default_duration_minutes' => $type['duration'],
                    'default_frequency_per_year' => $type['frequency'],
                    'default_lead_time_days' => $type['lead'],
                    'readiness_template_id' => $templateIds[$templateCode] ?? null,
                    'objectives_template' => $type['objectives'],
                    'cadence_clause_ref' => isset($type['clause']) ? $type['clause']->value : null,
                    'is_system_default' => true,
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedBlackoutCalendar(): void
    {
        foreach (BlackoutCalendar::all() as $period) {
            BlackoutPeriod::query()->updateOrCreate(
                ['organization_id' => null, 'name' => $period['name']],
                [
                    'category' => $period['category'],
                    'is_hard_block' => $period['hard'],
                    // Month-day windows are stored in `recurrence` rather than
                    // as dates: "15 December to 5 January" is every year, and a
                    // concrete pair of dates would expire.
                    'recurrence' => $this->recurrenceFor($period),
                    'is_system_default' => true,
                    'is_active' => true,
                ]
            );
        }
    }

    /** @param array<string, mixed> $period @return array<string, mixed> */
    private function recurrenceFor(array $period): array
    {
        $recurrence = $period['recurrence'] ?? [];

        if (isset($period['starts'], $period['ends'])) {
            $recurrence = [
                'rule' => 'annual_window',
                'starts' => $period['starts'],
                'ends' => $period['ends'],
                // A window whose end month-day is before its start crosses the
                // year boundary. Stated here so the generator does not have to
                // infer it.
                'crosses_year' => $period['ends'] < $period['starts'],
            ];
        }

        return $recurrence + ['note' => $period['note'] ?? null];
    }

    private function seedAlertTemplates(): void
    {
        foreach (AlertTemplates::all() as $template) {
            AlertTemplate::query()->updateOrCreate(
                ['organization_id' => null, 'code' => $template['code'], 'locale' => $template['locale']],
                [
                    'name' => $template['name'],
                    'category' => $template['category'],
                    'severity' => $template['severity'],
                    'subject' => $template['subject'],
                    'body' => $template['body'],
                    'channel_renderings' => array_filter([
                        'sms' => $template['sms'] ?? null,
                        'voice' => $template['voice'] ?? null,
                        'ussd' => $template['sms'] ?? null,
                    ]),
                    'variables' => $template['variables'],
                    'requires_dual_approval' => $template['dual_approval'],
                    'is_life_safety' => $template['life_safety'],
                    'is_system_default' => true,
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedScenarios(): void
    {
        foreach (Scenarios::all() as $scenario) {
            Scenario::query()->updateOrCreate(
                ['organization_id' => null, 'code' => $scenario['code']],
                [
                    'name' => $scenario['name'],
                    'category' => $scenario['category'],
                    'summary' => $scenario['summary'],
                    'narrative' => $scenario['narrative'],
                    'suggested_objectives' => $scenario['objectives'],
                    'ladder_level_min' => $scenario['ladder_min'],
                    'regulatory_drivers' => $scenario['drivers'],
                    'is_system_default' => true,
                    'ai_generated' => false,
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedTrainingCurricula(): void
    {
        foreach (TrainingCurricula::all() as $curriculum) {
            TrainingCurriculum::query()->updateOrCreate(
                ['organization_id' => null, 'code' => $curriculum['code']],
                [
                    'name' => $curriculum['name'],
                    'description' => $curriculum['description'],
                    'target_roles' => $curriculum['roles'],
                    'modules' => $curriculum['modules'],
                    'frequency_months' => $curriculum['frequency_months'],
                    'is_mandatory' => $curriculum['mandatory'],
                    'requires_assessment' => $curriculum['assess'],
                    'pass_mark' => $curriculum['pass_mark'] ?? null,
                    'iso_clause_ref' => $curriculum['clause']->value,
                    'is_system_default' => true,
                    'is_active' => true,
                ]
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Tenant settings */
    /* ------------------------------------------------------------------ */

    private function seedSettingsFor(Organization $organization): void
    {
        if (Setting::query()->where('organization_id', $organization->id)->exists()) {
            return;
        }

        app(BcmsSettings::class)->update([], $organization->id);
    }
}
