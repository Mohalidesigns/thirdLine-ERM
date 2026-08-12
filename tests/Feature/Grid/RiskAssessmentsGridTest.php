<?php

namespace Tests\Feature\Grid;

use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-09 — the assessments register on the shared data grid
 * (App\Grids\Definitions\RiskAssessmentsGrid).
 */
class RiskAssessmentsGridTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('assessment.view');
        $this->actor->givePermissionTo('assessment.view');

        $this->actingAs($this->actor);
    }

    private function makeAssessment(Risk $risk, array $attributes = []): RiskAssessment
    {
        return RiskAssessment::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'assessment_type' => 'periodic',
            'assessment_date' => now()->subDay()->toDateString(),
            'assessor_id' => $this->actor->id,
            'likelihood_score' => 3,
            'impact_score' => 4,
            'overall_score' => 12,
            'overall_rating' => 'High',
            'status' => 'draft',
        ], $attributes));
    }

    #[Test]
    public function the_index_page_renders_a_seeded_assessment(): void
    {
        $assessment = $this->makeAssessment($this->makeRisk());

        $this->get(route('risk.assessments.index'))
            ->assertOk()
            ->assertSee('Risk Assessments')
            ->assertSee('ASS-'.str_pad((string) $assessment->id, 4, '0', STR_PAD_LEFT));
    }

    #[Test]
    public function grid_search_narrows_server_side(): void
    {
        // Assessor names distinguish rows: risk codes also appear in the
        // risk_id filter dropdown, so they cannot carry a DontSee.
        $otherAssessor = User::create([
            'name' => 'Beatrice Analyst',
            'email' => 'beatrice@example.test',
            'password' => bcrypt('secret-password'),
            'organization_id' => $this->organization->id,
        ]);

        $this->makeAssessment($this->makeRisk(), ['status' => 'draft']);
        $this->makeAssessment($this->makeRisk(), [
            'status' => 'approved',
            'assessor_id' => $otherAssessor->id,
        ]);

        Livewire::test('data-grid', ['grid' => 'assessments'])
            ->assertSee('Risk Officer')
            ->assertSee('Beatrice Analyst')
            ->set('search', 'approved')
            ->assertSee('Beatrice Analyst')
            ->assertDontSee('Risk Officer');
    }

    #[Test]
    public function another_organizations_assessments_never_render(): void
    {
        $mine = $this->makeAssessment($this->makeRisk());

        $otherOrg = Organization::create(['name' => 'Other Bank', 'slug' => 'other-bank']);
        $foreignRisk = Risk::create([
            'organization_id' => $otherOrg->id,
            'risk_code' => 'RK-FOREIGN',
            'title' => 'Their risk',
            'description' => 'Foreign fixture risk',
            'category_id' => $this->category->id,
            'status' => 'active',
            'created_by' => $this->actor->id,
        ]);
        $theirs = RiskAssessment::create([
            'organization_id' => $otherOrg->id,
            'risk_id' => $foreignRisk->id,
            'assessment_type' => 'periodic',
            'assessment_date' => now()->toDateString(),
            'assessor_id' => $this->actor->id,
            'overall_score' => 20,
            'overall_rating' => 'Critical',
            'status' => 'draft',
        ]);

        Livewire::test('data-grid', ['grid' => 'assessments'])
            ->assertSee('ASS-'.str_pad((string) $mine->id, 4, '0', STR_PAD_LEFT))
            ->assertDontSee('RK-FOREIGN')
            ->assertDontSee('ASS-'.str_pad((string) $theirs->id, 4, '0', STR_PAD_LEFT));
    }
}
