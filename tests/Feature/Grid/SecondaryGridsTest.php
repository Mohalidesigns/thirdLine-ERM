<?php

namespace Tests\Feature\Grid;

use App\Grids\GridRegistry;
use App\Models\AssessmentCampaign;
use App\Models\Control;
use App\Models\ControlTest;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\QuestionLibrary;
use App\Models\Questionnaire;
use App\Presenters\GridPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
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

    /** The text of one cell across every presented row. */
    private static function column(array $rows, string $key): array
    {
        return collect($rows)->map(fn ($row) => $row['cells'][$key]['text'] ?? null)->all();
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
            ->assertInertia(fn (Assert $page) => $page
                ->component('ControlTests/Index')
                ->where('total', 1)
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.test_code.text', 'CT-VISIBLE-001')
                // The title column truncates for the table; the export carries it whole.
                ->where('grid.rows.data.0.cells.title.text', fn ($text) => str_starts_with($text, 'Quarterly access recertification')));
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

        $this->get(route('risk.control-tests.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.test_code.text', 'CT-MINE-001'));
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
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Index')
                ->where('total', 1)
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.campaign_code.text', 'CAM-VISIBLE-001')
                ->where('grid.rows.data.0.cells.title.text', 'Annual RCSA cycle'));
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

        $this->get(route('risk.campaigns.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.campaign_code.text', 'CAM-MINE-001'));
    }

    /* -------------------------------------------------- questionnaires */

    #[Test]
    public function the_questionnaires_index_renders_a_seeded_row(): void
    {
        $this->makeQuestionnaire(['title' => 'Fraud risk self assessment']);

        $this->get(route('risk.questionnaires.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questionnaires/Index')
                ->where('total', 1)
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Fraud risk self assessment')
                ->where('grid.rows.data.0.cells.version.text', 'v2'));
    }

    #[Test]
    public function another_organizations_questionnaires_never_render(): void
    {
        $this->makeQuestionnaire(['title' => 'Our questionnaire']);
        $this->makeQuestionnaire([
            'organization_id' => $this->otherOrganization->id,
            'title' => 'Their questionnaire',
        ]);

        $this->get(route('risk.questionnaires.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.title.text', 'Our questionnaire'));
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
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questionnaires/Library')
                ->where('total', 1)
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.category.text', 'Credit Risk')
                ->where('grid.rows.data.0.cells.question_text.text', 'Are collateral valuations refreshed annually'));
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

        $this->get(route('risk.questionnaires.library'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 2)
                ->where('grid.rows.data', function ($rows) {
                    $rows = collect($rows)->all();
                    $questions = self::column($rows, 'question_text');
                    sort($questions);

                    return $questions === ['A shared system question', 'Our own question']
                        && ! in_array('Foreign Category', self::column($rows, 'category'), true);
                }));
    }

    #[Test]
    public function the_question_library_grid_paginates_fifty_at_a_time(): void
    {
        $this->makeLibraryQuestion();

        $this->get(route('risk.questionnaires.library'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.state.perPage', 50)
                ->where('grid.rows.meta.per_page', 50));
    }

    /* --------------------------------------------------------- imports */

    #[Test]
    public function the_imports_index_renders_a_seeded_row(): void
    {
        $this->makeImport(['file_name' => 'loss-events-january.csv']);

        $this->get(route('risk.imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Imports/Index')
                ->where('total', 1)
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.file_name.text', 'loss-events-january.csv')
                ->where('grid.rows.data.0.cells', fn ($cells) => $cells['importer.name']['text'] === 'Risk Officer'));
    }

    #[Test]
    public function another_organizations_imports_never_render(): void
    {
        $this->makeImport(['file_name' => 'our-upload.csv']);
        $this->makeImport([
            'organization_id' => $this->otherOrganization->id,
            'file_name' => 'their-upload.csv',
        ]);

        $this->get(route('risk.imports.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.file_name.text', 'our-upload.csv'));
    }

    /* ----------------------------------------------------- every knob */

    /**
     * A definition is only proven by being run. This drives every declared
     * filter option, every sortable column and every hidden column of all
     * five grids through the presenter, which is what catches an aggregate
     * alias that cannot be ordered by, an ambiguous column once a relation
     * is joined, or a ->using() closure that only ever ran for the default
     * columns.
     */
    #[Test]
    public function every_column_filter_and_sort_of_each_grid_actually_runs(): void
    {
        $this->makeControlTest();
        $this->makeCampaign();
        $this->makeQuestionnaire();
        $this->makeLibraryQuestion();
        $this->makeImport();

        $presenter = app(GridPresenter::class);

        foreach (['control_tests', 'campaigns', 'questionnaires', 'question_library', 'imports'] as $grid) {
            $definition = GridRegistry::resolve($grid);

            $present = fn (array $query) => $presenter->present($definition, Request::create('/', 'GET', $query), $this->actor);

            // Show every column, including the hidden ones.
            $every = collect($definition->columns())->pluck('key')->all();
            $presented = $present(['columns' => implode(',', $every)]);

            $this->assertSame($every, $presented['state']['columns'], "[{$grid}] every column can be shown");
            $this->assertCount(1, $presented['rows']['data']);
            $this->assertSame($every, array_keys($presented['rows']['data'][0]['cells']), "[{$grid}] every cell renders");

            foreach ($definition->columns() as $column) {
                if ($column->sortable) {
                    foreach (['asc', 'desc'] as $dir) {
                        $sorted = $present(['sort' => $column->key, 'dir' => $dir]);
                        $this->assertSame($column->key, $sorted['state']['sort'], "[{$grid}] sorts by [{$column->key}]");
                        $this->assertCount(1, $sorted['rows']['data']);
                    }
                }
            }

            foreach ($definition->filters() as $filter) {
                $options = $filter->resolveOptions();
                $this->assertNotSame([], $options, "[{$grid}] filter [{$filter->key}] has no options");

                $filtered = $present(['filters' => [$filter->key => (string) array_key_first($options)]]);
                $this->assertSame((string) array_key_first($options), $filtered['state']['filters']->{$filter->key});
                $this->assertArrayHasKey('data', $filtered['rows']);
            }

            $empty = $present(['search' => 'zzz-no-such-row']);
            $this->assertSame([], $empty['rows']['data']);
            $this->assertSame($definition->emptyMessage(), $empty['emptyMessage']);
        }
    }
}
