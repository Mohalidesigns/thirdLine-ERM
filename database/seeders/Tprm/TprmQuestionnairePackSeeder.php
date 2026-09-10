<?php

namespace Database\Seeders\Tprm;

use App\Models\Tprm\Question;
use App\Models\Tprm\QuestionControlMap;
use App\Models\Tprm\QuestionnaireSection;
use App\Models\Tprm\QuestionnaireTemplate;
use Database\Seeders\Tprm\Reference\QuestionnairePacks;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Seeds the shipped questionnaire packs — FR-ASM-04.
 *
 * The packs carry `organization_id = null`: readable by every tenant, editable
 * by none, cloned to customise. Idempotent by code and version, so re-running
 * after a pack gains a question adds the question rather than duplicating the
 * template.
 *
 * PUBLISHING IS A SECOND STEP, AFTER THE MAPPINGS EXIST. The observer refuses
 * to create a template already published, and refuses to publish one with an
 * unmapped question — so the seeder writes the template as a draft, adds its
 * questions and their control maps, and only then publishes. That ordering is
 * not a workaround for the gate; it is the gate working, and the seeder is the
 * first thing it is tested against.
 */
class TprmQuestionnairePackSeeder extends Seeder
{
    public function run(): void
    {
        // WRAPPED IN A TENANCY BYPASS, and it has to be.
        //
        // `BelongsToOrganization` stamps `organization_id` from the current
        // tenant on every create where the attribute is null — which is
        // exactly what a system pack sets it to. Without the bypass each pack
        // is silently created as the seeding tenant's own copy: every tenant
        // gets its own, the unique key on (organization_id, code, version)
        // then makes re-seeding throw, and `whereNull('organization_id')`
        // finds nothing. The reference seeder's other system libraries escape
        // this only because they are written through the query builder, which
        // has no model hooks; these go through the model because the publish
        // gate is an observer and has to run.
        TenantContext::bypass(function () {
            foreach (QuestionnairePacks::all() as $pack) {
                $this->seedPack($pack);
            }
        }, 'seeding the shipped questionnaire packs, which belong to no tenant');
    }

    /**
     * @param  array<string, mixed>  $pack
     */
    private function seedPack(array $pack): void
    {
        $completeness = QuestionnairePacks::completeness($pack);

        $template = DB::transaction(function () use ($pack, $completeness) {
            $template = QuestionnaireTemplate::query()
                ->withoutGlobalScopes()
                ->whereNull('organization_id')
                ->where('code', $pack['code'])
                ->where('version', $pack['version'])
                ->first();

            $attributes = [
                'organization_id' => null,
                'code' => $pack['code'],
                'name' => $pack['name'],
                'description' => $pack['description'],
                'version' => $pack['version'],
                'framework_tags' => $pack['framework_tags'],
                'applies_to' => $pack['applies_to'],
                'scoring_mode' => 'weighted',
                'declared_question_count' => $completeness['declared'],
                'catalogue_status' => $completeness['status'],
                'catalogue_note' => $completeness['status'] === 'complete'
                    ? null
                    : sprintf(
                        'Ships %d of the approximately %d questions TRD Appendix B specifies for this pack. The '
                        .'questions present are complete, control-mapped and usable; the pack is not yet the whole '
                        .'of what Appendix B describes, and this note is here so that is visible rather than '
                        .'assumed.',
                        $completeness['shipped'],
                        $completeness['declared']
                    ),
            ];

            if ($template === null) {
                // Created as a DRAFT. The observer refuses a template created
                // already published, because at creation it has no questions
                // and therefore no mappings.
                $template = QuestionnaireTemplate::create(
                    $attributes + ['status' => QuestionnaireTemplate::STATUS_DRAFT]
                );
            } else {
                // Reverted to draft before editing: a published template is
                // frozen, and re-seeding is an edit.
                $template->forceFill(['status' => QuestionnaireTemplate::STATUS_DRAFT])->save();
                $template->forceFill($attributes)->save();
            }

            $this->seedSections($template, $pack['sections']);

            return $template;
        });

        // Publish last, through the model so the gate actually runs.
        $template->refresh();
        $template->status = QuestionnaireTemplate::STATUS_PUBLISHED;
        $template->published_at = now();
        $template->save();
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function seedSections(QuestionnaireTemplate $template, array $sections): void
    {
        foreach ($sections as $index => $section) {
            $row = QuestionnaireSection::updateOrCreate(
                ['template_id' => $template->getKey(), 'code' => $section['code']],
                [
                    'title' => $section['title'],
                    'description' => $section['description'] ?? null,
                    'sort_order' => $index,
                    'weight' => $section['weight'] ?? 1,
                    'domain_tag' => $section['domain_tag'] ?? null,
                    'visibility_rule' => $section['visibility_rule'] ?? null,
                ]
            );

            foreach ($section['questions'] as $order => $question) {
                $this->seedQuestion($row, $question, $order);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function seedQuestion(QuestionnaireSection $section, array $question, int $order): void
    {
        $row = Question::updateOrCreate(
            ['section_id' => $section->getKey(), 'code' => $question['code']],
            [
                'text' => $question['text'],
                'help_text' => $question['help_text'] ?? null,
                'type' => $question['type'] ?? 'yes_no_na',
                'options' => $question['options'] ?? null,
                'is_required' => $question['is_required'] ?? true,
                'is_critical' => $question['is_critical'] ?? false,
                'weight' => $question['weight'] ?? 1,
                'risk_weight' => $question['risk_weight'] ?? 1,
                'evidence_required' => $question['evidence_required'] ?? false,
                'evidence_types' => $question['evidence_types'] ?? null,
                'min_assurance_level' => $question['min_assurance_level'] ?? null,
                'visibility_rule' => $question['visibility_rule'] ?? null,
                'sort_order' => $order,
            ]
        );

        foreach ($question['maps'] as [$framework, $version, $controlId]) {
            QuestionControlMap::updateOrCreate(
                [
                    'question_id' => $row->getKey(),
                    'framework' => $framework,
                    'framework_version' => $version,
                    'control_id' => $controlId,
                ],
                ['relationship' => QuestionControlMap::RELATIONSHIP_PRIMARY]
            );
        }
    }
}
