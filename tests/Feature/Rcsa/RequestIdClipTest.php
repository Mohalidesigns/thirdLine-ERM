<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentTransition;
use App\Models\Rcsa\RcsaExportJob;
use App\Models\Rcsa\RcsaLineRevision;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaExportService;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * `X-Request-Id` is client-supplied and is written to two `string(64)` columns,
 * `rcsa_line_revisions.request_id` and `rcsa_assessment_transitions.request_id`.
 * Unclipped, a long header fails the insert on strict MySQL (the user's save or
 * submit 500s) or is silently cut on a lax one.
 */
class RequestIdClipTest extends CycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->grant(['rcsa_assessment.submit', 'rcsa_export.bulk']);
        Storage::fake('local');
    }

    #[Test]
    public function an_oversized_request_id_does_not_break_a_line_save_or_a_submit(): void
    {
        $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $header = ['X-Request-Id' => str_repeat('r', 200)];

        $line = $assessment->lines()->orderBy('sort_order')->firstOrFail();

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 1,
                'inherent_impact' => 1,
                'control_effectiveness' => 'Fully Achieved',
                'version' => $line->version,
            ], $header)
            ->assertOk();

        $revision = RcsaLineRevision::query()->withoutGlobalScopes()->where('line_id', $line->id)->latest('id')->firstOrFail();
        $this->assertSame(64, mb_strlen((string) $revision->request_id));

        // Score the rest so the gate lets the submit through.
        $service = app(RcsaAssessmentService::class);
        foreach ($assessment->lines()->get() as $each) {
            $service->apply($each, [
                'inherent_likelihood' => 1, 'inherent_impact' => 1, 'control_effectiveness' => 'Fully Achieved',
            ], $this->actor);
        }

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment), [], $header)
            ->assertSessionHasNoErrors();

        $this->assertSame(RcsaAssessment::SUBMITTED, $assessment->fresh()->status);

        $transition = RcsaAssessmentTransition::query()->withoutGlobalScopes()
            ->where('assessment_id', $assessment->id)->latest('id')->firstOrFail();
        $this->assertSame(RcsaAssessment::SUBMITTED, $transition->to_status);
        $this->assertNotNull($transition->request_id);
        $this->assertLessThanOrEqual(64, mb_strlen($transition->request_id));
    }

    #[Test]
    public function an_export_logged_without_a_request_stores_a_null_user_agent_and_ip(): void
    {
        $export = app(RcsaExportService::class)->log($this->actor, [], 0);

        $row = RcsaExportJob::query()->withoutGlobalScopes()->findOrFail($export->getKey());

        $this->assertNull($row->user_agent);
        $this->assertNull($row->ip_address);
    }
}
