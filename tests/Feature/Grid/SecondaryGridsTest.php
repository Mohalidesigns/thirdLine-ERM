<?php

namespace Tests\Feature\Grid;

use App\Models\AssessmentCampaign;
use App\Models\Control;
use App\Models\ControlTest;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\QuestionLibrary;
use App\Models\Questionnaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-09 — the five secondary registers on the shared data grid:
 * ControlTestsGrid, CampaignsGrid, QuestionnairesGrid, QuestionLibraryGrid
 * and DataImportsGrid.
 *
 * Each grid gets the same two assertions, which together prove the definition
 * is actually exercised rather than merely written: the index route renders a
 * seeded row end-to-end (so every column closure, relation and route() call
 * runs), and a row belonging to another Organization never appears.
 */
class SecondaryGridsTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private Organization $otherOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        $permissions = [
            'control_test.view', 'control_test.create', 'control_test.edit',
            'campaign.view', 'campaign.create',
            'questionnaire.view', 'questionnaire.create', 'questionnaire.edit',
            'import.view', 'import.create',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->actor->givePermissionTo($permissions);
        $this->actingAs($this->actor);

        $this->otherOrganization = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------- fixtures */

    private function makeControlTest(array $attributes = []): ControlTest
    {
        static $sequence = 0;
        $sequence++;

        $organizationId = $attributes['organization_id'] ?? $this->organization->id;

        $control = $attributes['control_id'] ?? Control::create([
            'organization_id' => $organizationId,
            'control_code' => sprintf('CTL-CT-%03d', $sequence),
            'name' => "Control for test {$sequence}",
            'status' => 'active',
            'created_by' => $this->actor->id,
        ])->id;

        return ControlTest::create(array_merge([
            'organization_id' => $organizationId,
            'control_id' => $control,
            'test_code' => sprintf('CT-GRID-%03d', $sequence),
            'title' => "Control test {$sequence}",
            'test_type' => 'operating_effectiveness',
            'tester_id' => $this->actor->id,
            'scheduled_date' => now()->subDays($sequence)->toDateString(),
            'status' => 'scheduled',
            'result' => 'not_tested',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function makeCampaign(array $attributes = []): AssessmentCampaign
    {
        static $sequence = 0;
        $sequence++;

        return AssessmentCampaign::create(array_merge([
            'organization_id' => $this->organization->id,
            'campaign_code' => sprintf('CAM-GRID-%03d', $sequence),
            'title' => "Campaign {$sequence}",
            'campaign_type' => 'rcsa',
            'status' => 'draft',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'completion_pct' => 40,
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function makeQuestionnaire(array $attributes = []): Questionnaire
    {
        static $sequence = 0;
        $sequence++;

        return Questionnaire::create(array_merge([
            'organization_id' => $this->organization->id,
            'title' => "Questionnaire {$sequence}",
            'questionnaire_type' => 'rcsa',
            'scoring_method' => 'average',
            'status' => 'draft',
            'version' => 2,
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function makeLibraryQuestion(array $attributes = []): QuestionLibrary
    {
        static $sequence = 0;
        $sequence++;

        return QuestionLibrary::create(array_merge([
            'organization_id' => $this->organization->id,
            'category' => 'Operational Risk',
            'question_text' => "Library question {$sequence}",
            'question_type' => 'likert',
            'usage_count' => 3,
        ], $attributes));
    }

    private function makeImport(array $attributes = []): DataImport
    {
        static $sequence = 0;
        $sequence++;

        return DataImport::create(array_merge([
            'organization_id' => $this->organization->id,
            'import_type' => 'risks',
            'file_name' => "register-upload-{$sequence}.csv",
            'file_path' => "imports/1/register-upload-{$sequence}.csv",
            'total_rows' => 120,
            'success_count' => 118,
            'error_count' => 2,
            'skipped_count' => 0,
            'status' => 'completed',
            'imported_by' => $this->actor->id,
        ], $attributes));
    }

    /* --------------------------------------------------- control tests */

    #[Test]
    public function the_control_tests_index_renders_a_seeded_row(): void
    {
        $this->makeControlTest([
            'test_code' => 'CT-VISIBLE-001',
            'title' => 'Quarterly access recertification walkthrough',
        ]);

        $this->get(route('risk.control-tests.index'))
            ->assertOk()
            ->assertSee('All Control Tests')
            ->assertSee('CT-VISIBLE-001')
            ->assertSee('Quarterly access recertification');
    }

    #[Test]
    public function another_organizations_control_tests_never_render(): void
    {
        $this->makeControlTest(['test_code' => 'CT-MINE-001']);
        $this->makeControlTest([
            'organization_id' => $this->otherOrganization->id,
            'test_code' => 'CT-FOREIGN-001',
            'title' => 'Their control test',
        ]);

        Livewire::test('data-grid', ['grid' => 'control_tests'])
            ->assertSee('CT-MINE-001')
            ->assertDontSee('CT-FOREIGN-001')
            ->assertDontSee('Their control test');
    }

    /* ------------------------------------------------------- campaigns */

    #[Test]
    public function the_campaigns_index_renders_a_seeded_row(): void
    {
        $this->makeCampaign([
            'campaign_code' => 'CAM-VISIBLE-001',
            'title' => 'Annual RCSA cycle',
        ]);

        $this->get(route('risk.campaigns.index'))
            ->assertOk()
            ->assertSee('All Assessment Campaigns')
            ->assertSee('CAM-VISIBLE-001')
            ->assertSee('Annual RCSA cycle');
    }

    #[Test]
    public function another_organizations_campaigns_never_render(): void
    {
        $this->makeCampaign(['campaign_code' => 'CAM-MINE-001']);
        $this->makeCampaign([
            'organization_id' => $this->otherOrganization->id,
            'campaign_code' => 'CAM-FOREIGN-001',
            'title' => 'Their campaign',
        ]);

        Livewire::test('data-grid', ['grid' => 'campaigns'])
            ->assertSee('CAM-MINE-001')
            ->assertDontSee('CAM-FOREIGN-001')
            ->assertDontSee('Their campaign');
    }

    /* -------------------------------------------------- questionnaires */

    #[Test]
    public function the_questionnaires_index_renders_a_seeded_row(): void
    {
        $this->makeQuestionnaire(['title' => 'Fraud risk self assessment']);

        $this->get(route('risk.questionnaires.index'))
            ->assertOk()
            ->assertSee('Questionnaire Library')
            ->assertSee('Fraud risk self assessment')
            ->assertSee('v2');
    }

    #[Test]
    public function another_organizations_questionnaires_never_render(): void
    {
        $this->makeQuestionnaire(['title' => 'Our questionnaire']);
        $this->makeQuestionnaire([
            'organization_id' => $this->otherOrganization->id,
            'title' => 'Their questionnaire',
        ]);

        Livewire::test('data-grid', ['grid' => 'questionnaires'])
            ->assertSee('Our questionnaire')
            ->assertDontSee('Their questionnaire');
    }

    /* ------------------------------------------------- question library */

    #[Test]
    public function the_question_library_index_renders_a_seeded_row(): void
    {
        $this->makeLibraryQuestion([
            'category' => 'Credit Risk',
            'question_text' => 'Are collateral valuations refreshed annually',
        ]);

        $this->get(route('risk.questionnaires.library'))
            ->assertOk()
            ->assertSee('Question Library')
            ->assertSee('Credit Risk')
            ->assertSee('Are collateral valuations refreshed annually');
    }

    #[Test]
    public function the_question_library_grid_shows_shared_questions_but_not_another_tenants(): void
    {
        $this->makeLibraryQuestion(['question_text' => 'Our own question']);
        $this->makeLibraryQuestion([
            'organization_id' => null,
            'is_global' => true,
            'question_text' => 'A shared system question',
        ]);
        $this->makeLibraryQuestion([
            'organization_id' => $this->otherOrganization->id,
            'category' => 'Foreign Category',
            'question_text' => 'Their private question',
        ]);

        Livewire::test('data-grid', ['grid' => 'question_library'])
            ->assertSee('Our own question')
            ->assertSee('A shared system question')
            ->assertDontSee('Their private question')
            ->assertDontSee('Foreign Category');
    }

    #[Test]
    public function the_question_library_grid_paginates_fifty_at_a_time(): void
    {
        $this->makeLibraryQuestion();

        Livewire::test('data-grid', ['grid' => 'question_library'])
            ->assertSet('perPage', 50);
    }

    /* --------------------------------------------------------- imports */

    #[Test]
    public function the_imports_index_renders_a_seeded_row(): void
    {
        $this->makeImport(['file_name' => 'loss-events-january.csv']);

        $this->get(route('risk.imports.index'))
            ->assertOk()
            ->assertSee('Data Import History')
            ->assertSee('loss-events-january.csv')
            ->assertSee('Risk Officer');
    }

    #[Test]
    public function another_organizations_imports_never_render(): void
    {
        $this->makeImport(['file_name' => 'our-upload.csv']);
        $this->makeImport([
            'organization_id' => $this->otherOrganization->id,
            'file_name' => 'their-upload.csv',
        ]);

        Livewire::test('data-grid', ['grid' => 'imports'])
            ->assertSee('our-upload.csv')
            ->assertDontSee('their-upload.csv');
    }

    /* ----------------------------------------------------- every knob */

    /**
     * A definition is only proven by being run. This drives every declared
     * filter option, every sortable column and every hidden column of all
     * five grids, which is what catches an aggregate alias that cannot be
     * ordered by, an ambiguous column once a relation is joined, or a
     * ->using() closure that only ever ran for the default columns.
     */
    #[Test]
    public function every_column_filter_and_sort_of_each_grid_actually_runs(): void
    {
        $this->makeControlTest();
        $this->makeCampaign();
        $this->makeQuestionnaire();
        $this->makeLibraryQuestion();
        $this->makeImport();

        foreach (['control_tests', 'campaigns', 'questionnaires', 'question_library', 'imports'] as $grid) {
            $definition = \App\Grids\GridRegistry::resolve($grid);

            $component = Livewire::test('data-grid', ['grid' => $grid]);

            // Show every column, including the hidden ones.
            foreach ($definition->columns() as $column) {
                if (! $column->visibleByDefault) {
                    $component->call('toggleColumn', $column->key);
                }
            }

            foreach ($definition->columns() as $column) {
                if ($column->sortable) {
                    $component->call('sortBy', $column->key)->assertOk();
                }
            }

            foreach ($definition->filters() as $filter) {
                $options = $filter->resolveOptions();
                $this->assertNotSame([], $options, "[{$grid}] filter [{$filter->key}] has no options");

                $component->set("filters.{$filter->key}", (string) array_key_first($options))
                    ->assertOk();
                $component->set("filters.{$filter->key}", '');
            }

            $component->set('search', 'zzz-no-such-row')->assertSee($definition->emptyMessage());
        }
    }
}
