<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaLineRevision;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaCycleService;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;

/**
 * Steps 4 to 6 of the process flow: assess inherent risk, assess control
 * effectiveness, determine residual risk.
 */
class WorkspaceTest extends CycleTestCase
{
    /* ------------------------------------------------------------------ */
    /*  The acceptance criterion: the figures match the workbook */
    /* ------------------------------------------------------------------ */

    /**
     * "Calculated columns match the template on a 50-line assessment."
     *
     * Fifty lines scored through the real HTTP endpoint, then every calculated
     * column on every line checked against the truth table P0 wrote from the
     * workbook. This is the assertion that says the workspace and the
     * calculation engine are the same thing.
     */
    #[Test]
    public function fifty_lines_scored_through_the_endpoint_match_the_workbook(): void
    {
        $cases = json_decode(
            (string) file_get_contents(base_path('resources/js/lib/rcsa-truth-table.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        )['cases'];

        // Fifty of the hundred combinations, spread across the whole table
        // rather than the first fifty — which would all be likelihood 1 and 2.
        $selected = [];

        for ($i = 0; $i < 100; $i += 2) {
            $selected[] = $cases[$i];
        }

        $this->assertCount(50, $selected);

        foreach ($selected as $index => $case) {
            $this->publishedRisk([
                'risk_no' => 'RETAIL-R'.($index + 1),
                'potential_risk' => sprintf('Risk number %d, written long enough to be a valid statement.', $index + 1),
            ]);
        }

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $assessment = RcsaAssessment::sole();
        $lines = $assessment->lines()->orderBy('sort_order')->get();

        $this->assertCount(50, $lines);

        foreach ($lines as $index => $line) {
            $case = $selected[$index];

            $this->actingAs($this->actor)
                ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                    'inherent_likelihood' => $case['likelihood'],
                    'inherent_impact' => $case['impact'],
                    'control_effectiveness' => $case['controlEffectiveness'],
                    'version' => $line->version,
                ])
                ->assertOk();
        }

        foreach ($assessment->lines()->orderBy('sort_order')->get() as $index => $line) {
            $expected = $selected[$index]['expected'];
            $where = sprintf('line %d (L%d × I%d, %s)', $index + 1,
                $selected[$index]['likelihood'], $selected[$index]['impact'], $selected[$index]['controlEffectiveness']);

            $this->assertSame($expected['inherentScore'], $line->inherent_score, "inherent score: {$where}");
            $this->assertSame($expected['inherentLevel'], $line->inherent_level, "inherent level: {$where}");
            $this->assertSame($expected['ceModifier'], $line->ce_modifier, "CE modifier: {$where}");
            $this->assertSame((float) $expected['residualScore'], (float) $line->residual_score, "residual: {$where}");
            $this->assertSame($expected['residualLevel'], $line->residual_level, "residual level: {$where}");
            $this->assertSame($expected['riskTreatment'], $line->risk_treatment, "treatment: {$where}");
            $this->assertSame($expected['appetiteStatus'], $line->appetite_status, "appetite: {$where}");
        }

        $this->assertSame(100, $assessment->fresh()->completion_pct);
    }

    /* ------------------------------------------------------------------ */
    /*  The server computes; the client does not */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function calculated_columns_sent_by_the_client_are_ignored(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 5,
                'inherent_impact' => 5,
                'control_effectiveness' => 'Not Achieved',
                // A crafted request claiming the risk is harmless. Accepting any
                // of this would let anyone put any number into a regulatory
                // return by editing a request.
                'inherent_score' => 1,
                'residual_score' => 0,
                'residual_level' => 'very_low',
                'risk_treatment' => 'accept',
                'appetite_status' => 'Within risk appetite: Continue routine monitoring',
                'version' => $line->version,
            ])
            ->assertOk();

        $line->refresh();

        $this->assertSame(25, $line->inherent_score);
        $this->assertSame(18.75, (float) $line->residual_score);
        $this->assertSame('very_high', $line->residual_level);
        $this->assertSame('treat', $line->risk_treatment);
        $this->assertStringStartsWith('Above risk appetite', (string) $line->appetite_status);
    }

    #[Test]
    public function a_control_rating_is_stored_in_its_canonical_spelling(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        // The endpoint's enum rule is exact, so this is about the SERVICE: a
        // line answered through an import and a line answered on screen must
        // end up holding one spelling.
        app(RcsaAssessmentService::class)->apply(
            $line,
            ['inherent_likelihood' => 4, 'inherent_impact' => 4, 'control_effectiveness' => 'mostly achieved'],
            $this->actor,
        );

        $this->assertSame('Mostly Achieved', $line->fresh()->control_effectiveness);
    }

    #[Test]
    public function a_partially_answered_line_computes_what_it_can_and_is_not_counted_complete(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 4,
                'inherent_impact' => 4,
                'version' => $line->version,
            ])
            ->assertOk();

        $line->refresh();

        $this->assertSame(16, $line->inherent_score);
        $this->assertSame('high', $line->inherent_level);
        // No control rating means an UNKNOWN residual, not a zero — a zero
        // would band VERY LOW and report an unassessed risk as inside appetite.
        $this->assertNull($line->residual_score);
        $this->assertNull($line->residual_level);
        $this->assertFalse($line->isScored());
        $this->assertSame(0, $assessment->fresh()->completion_pct);
    }

    /* ------------------------------------------------------------------ */
    /*  The acceptance criterion: concurrent editors */
    /* ------------------------------------------------------------------ */

    /**
     * "Two concurrent users cannot silently overwrite each other."
     */
    #[Test]
    public function a_second_writer_working_from_a_stale_version_is_refused(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        $bothRead = $line->version;

        $second = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.complete', 'rcsa_cycle.view']);

        // The first assessor saves.
        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 5,
                'inherent_impact' => 5,
                'control_effectiveness' => 'Not Achieved',
                'version' => $bothRead,
            ])
            ->assertOk();

        // The second had the grid open from before, and saves the version they
        // read. Refused — loudly, with the other person's answer attached so
        // the screen can show what happened rather than only that it did.
        $response = $this->actingAs($second)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 1,
                'inherent_impact' => 1,
                'control_effectiveness' => 'Fully Achieved',
                'version' => $bothRead,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('line.inherent_likelihood', 5);
        $response->assertJsonPath('line.inherent_score', 25);

        $line->refresh();

        $this->assertSame(5, $line->inherent_likelihood, 'The first writer\'s answer stands.');
        $this->assertSame('Not Achieved', $line->control_effectiveness);
    }

    #[Test]
    public function the_version_advances_on_every_save_so_a_reader_can_tell(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        $this->assertSame(1, $line->version);

        foreach ([2, 3, 4] as $expected) {
            $this->actingAs($this->actor)
                ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line->fresh()]), [
                    'inherent_likelihood' => $expected,
                    'version' => $line->fresh()->version,
                ])
                ->assertOk();

            $this->assertSame($expected, $line->fresh()->version);
        }
    }

    #[Test]
    public function a_line_held_by_another_editor_is_refused_with_423(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        $other = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.complete', 'rcsa_cycle.view']);

        $this->actingAs($other)
            ->postJson(route('rcsa.assessments.lines.lock', [$assessment, $line]))
            ->assertOk()
            ->assertJsonPath('held', true);

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 3,
                'version' => $line->version,
            ])
            ->assertStatus(423);

        // Releasing hands it back.
        $this->actingAs($other)
            ->postJson(route('rcsa.assessments.lines.lock', [$assessment, $line]), ['release' => true])
            ->assertOk();

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 3,
                'version' => $line->fresh()->version,
            ])
            ->assertOk();
    }

    #[Test]
    public function an_expired_lock_does_not_block_anybody(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        $other = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.complete', 'rcsa_cycle.view']);

        // Someone opened this row and closed their laptop. A lock honoured for
        // ever turns that into a blocked assessment.
        $line->forceFill([
            'locked_by' => $other->id,
            'lock_expires_at' => now()->subMinute(),
        ])->save();

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 3,
                'version' => $line->version,
            ])
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  The audit trail */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_material_change_is_recorded_with_before_and_after(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        app(RcsaAssessmentService::class)->apply(
            $line,
            ['inherent_likelihood' => 4, 'inherent_impact' => 3, 'control_effectiveness' => 'Partially Achieved'],
            $this->actor,
        );

        // Rule 7 of the process flow: user, time, before, after, on columns
        // J, K and O.
        $revisions = RcsaLineRevision::where('line_id', $line->id)->get();

        $this->assertCount(3, $revisions);
        $this->assertEqualsCanonicalizing(
            ['inherent_likelihood', 'inherent_impact', 'control_effectiveness'],
            $revisions->pluck('field')->all()
        );

        $likelihood = $revisions->firstWhere('field', 'inherent_likelihood');

        $this->assertNull($likelihood->old_value);
        $this->assertSame(4, $likelihood->new_value);
        $this->assertSame($this->actor->id, $likelihood->user_id);
        $this->assertNotNull($likelihood->created_at);

        /* --- A second change records the previous value ---------------- */

        app(RcsaAssessmentService::class)->apply($line->fresh(), ['inherent_likelihood' => 2], $this->actor);

        $latest = RcsaLineRevision::where('line_id', $line->id)
            ->where('field', 'inherent_likelihood')
            ->orderByDesc('id')
            ->first();

        $this->assertSame(4, $latest->old_value);
        $this->assertSame(2, $latest->new_value);
    }

    #[Test]
    public function a_save_that_changes_nothing_writes_no_revision(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        app(RcsaAssessmentService::class)->apply($line, ['inherent_likelihood' => 3], $this->actor);
        $after = RcsaLineRevision::count();

        app(RcsaAssessmentService::class)->apply($line->fresh(), ['inherent_likelihood' => 3], $this->actor);

        // An autosaving grid fires on blur whether or not anything moved; a
        // trail full of "3 → 3" is a trail nobody reads.
        $this->assertSame($after, RcsaLineRevision::count());
    }

    /* ------------------------------------------------------------------ */
    /*  Bulk apply */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function bulk_apply_scores_many_lines_and_revisions_every_one(): void
    {
        foreach (range(1, 3) as $n) {
            $this->publishedRisk([
                'risk_no' => 'RETAIL-R'.$n,
                'potential_risk' => "Risk number {$n}, written long enough to be a valid statement.",
            ]);
        }

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $assessment = RcsaAssessment::sole();
        $lines = $assessment->lines()->get();

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.bulk-apply', $assessment), [
                'line_ids' => $lines->pluck('id')->all(),
                'control_effectiveness' => 'Fully Achieved',
                'inherent_likelihood' => 2,
                'inherent_impact' => 2,
            ])
            ->assertSessionHasNoErrors();

        foreach ($assessment->lines()->get() as $line) {
            $this->assertSame('Fully Achieved', $line->control_effectiveness);
            $this->assertSame(4, $line->inherent_score);
            $this->assertSame(0.0, (float) $line->residual_score);
        }

        // A bulk action that skipped the trail would be the easiest way to
        // change sixty ratings without a record.
        $this->assertSame(9, RcsaLineRevision::count());
        $this->assertSame(100, $assessment->fresh()->completion_pct);
    }

    #[Test]
    public function bulk_apply_cannot_reach_a_line_in_another_assessment(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $this->publishedRisk([
            'risk_no' => 'TREAS-R1',
            'business_unit_id' => $this->treasury->id,
            'process_id' => null,
            'potential_risk' => 'A risk that belongs to Treasury rather than Retail.',
        ]);

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $retail = RcsaAssessment::where('business_unit_id', $this->retail->id)->sole();
        $treasuryLine = RcsaAssessmentLine::where('business_unit_id', $this->treasury->id)->sole();

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.bulk-apply', $retail), [
                'line_ids' => [$treasuryLine->id],
                'control_effectiveness' => 'Fully Achieved',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($treasuryLine->fresh()->control_effectiveness);
    }

    /* ------------------------------------------------------------------ */
    /*  Progress and the validation panel */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function progress_counts_only_fully_answered_lines(): void
    {
        foreach (range(1, 4) as $n) {
            $this->publishedRisk([
                'risk_no' => 'RETAIL-R'.$n,
                'potential_risk' => "Risk number {$n}, written long enough to be a valid statement.",
            ]);
        }

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $assessment = RcsaAssessment::sole();
        $lines = $assessment->lines()->get();

        $service = app(RcsaAssessmentService::class);

        $service->apply($lines[0], ['inherent_likelihood' => 3, 'inherent_impact' => 3, 'control_effectiveness' => 'Fully Achieved'], $this->actor);
        $this->assertSame(25, $assessment->fresh()->completion_pct);

        // Two of three answers is not a scored line.
        $service->apply($lines[1], ['inherent_likelihood' => 3, 'inherent_impact' => 3], $this->actor);
        $this->assertSame(25, $assessment->fresh()->completion_pct);

        $service->apply($lines[1]->fresh(), ['control_effectiveness' => 'Not Achieved'], $this->actor);
        $this->assertSame(50, $assessment->fresh()->completion_pct);
    }

    #[Test]
    public function the_validation_panel_lists_what_stands_between_here_and_submission(): void
    {
        foreach (range(1, 2) as $n) {
            $this->publishedRisk([
                'risk_no' => 'RETAIL-R'.$n,
                'potential_risk' => "Risk number {$n}, written long enough to be a valid statement.",
            ]);
        }

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $assessment = RcsaAssessment::sole();
        $lines = $assessment->lines()->get();

        // One above appetite with no plan; one not assessed at all.
        app(RcsaAssessmentService::class)->apply(
            $lines[0],
            ['inherent_likelihood' => 5, 'inherent_impact' => 5, 'control_effectiveness' => 'Not Achieved'],
            $this->actor,
        );

        $outstanding = app(RcsaAssessmentService::class)->outstanding($assessment->fresh());

        $this->assertSame(1, $outstanding['scored']);
        $this->assertSame(2, $outstanding['total']);

        $types = array_column($outstanding['issues'], 'type');

        $this->assertContains('action_plan', $types);
        $this->assertContains('unscored', $types);

        // Each issue names its line, because the screen turns every one into a
        // click-to-jump link — "failures are listed with jump links, never a
        // single generic toast".
        foreach ($outstanding['issues'] as $issue) {
            $this->assertNotEmpty($issue['line_id']);
            $this->assertNotEmpty($issue['risk_no']);
            $this->assertNotEmpty($issue['message']);
        }
    }

    #[Test]
    public function material_movement_since_the_last_cycle_needs_a_rationale(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);

        $first = $this->makeCycle(['name' => 'RCSA 2025 H2', 'period_start' => '2025-07-01', 'period_end' => '2025-12-31']);
        app(RcsaCycleService::class)->open($first, $this->actor);

        $service = app(RcsaAssessmentService::class);

        $firstLine = RcsaAssessmentLine::sole();
        $service->apply($firstLine, ['inherent_likelihood' => 1, 'inherent_impact' => 1, 'control_effectiveness' => 'Fully Achieved'], $this->actor);

        $second = $this->makeCycle();
        app(RcsaCycleService::class)->open($second, $this->actor);

        $secondLine = RcsaAssessmentLine::where('id', '!=', $firstLine->id)->sole();

        // 1 → 25 is a move of 24 points and four bands.
        $service->apply($secondLine, ['inherent_likelihood' => 5, 'inherent_impact' => 5, 'control_effectiveness' => 'Not Achieved'], $this->actor);

        $secondLine = $secondLine->fresh();
        $secondLine->load('priorLine');

        $this->assertTrue($service->movedMaterially($secondLine));

        $assessment = RcsaAssessment::where('cycle_id', $second->id)->sole();
        $types = array_column($service->outstanding($assessment)['issues'], 'type');

        $this->assertContains('movement', $types);

        /* --- With a rationale, it is no longer outstanding ------------- */

        $service->apply($secondLine, ['assessment_rationale' => 'A control failure in March changed the picture entirely.'], $this->actor);

        $types = array_column($service->outstanding($assessment)['issues'], 'type');

        $this->assertNotContains('movement', $types);
    }

    /* ------------------------------------------------------------------ */
    /*  The page */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_workspace_renders_with_everything_the_page_reads(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();

        $this->actingAs($this->actor)
            ->get(route('rcsa.assessments.show', $assessment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('RcsaAssessments/Workspace')
                ->where('assessment.editable', true)
                ->has('lines', 1)
                ->has('lines.0.risk_no')
                ->has('lines.0.version')
                ->has('lines.0.existing_control')
                // The methodology travels with the page so the client mirror
                // can paint a badge without holding its own band table.
                ->has('methodology.bands', 5)
                ->has('methodology.controlEffectiveness', 4)
                ->has('methodology.likelihood', 5)
                // The guidance that turns a five-item dropdown into the
                // workbook's criteria.
                ->has('impactCriteria.5.health_safety')
                ->has('controlGuidance', 4)
                ->has('outstanding.issues')
                ->where('can.complete', true)
            );
    }

    #[Test]
    public function a_closed_cycle_makes_the_workspace_read_only(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        app(RcsaCycleService::class)->close($cycle, $this->actor);

        $this->actingAs($this->actor)
            ->get(route('rcsa.assessments.show', $assessment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assessment.editable', false)
                ->where('can.complete', false)
                ->etc()
            );

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 3,
                'version' => $line->version,
            ])
            ->assertForbidden();
    }
}
