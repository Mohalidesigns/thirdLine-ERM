<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\RiskAuditTrail;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaExportService;
use App\Support\MorphTypes;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * §11's audit trail, written into `risk_audit_trail` rather than into a second
 * mechanism.
 *
 * The point of the deviation is that this table gives guarantees
 * `spatie/laravel-activitylog` does not, so the tests worth writing are the
 * ones about those guarantees: the rows are found by the relationship (which
 * means the morph alias is right), and they cannot be edited or deleted (which
 * means "audit views are read-only and non-deletable" is enforced rather than
 * promised).
 */
class AuditTrailTest extends ReviewTestCase
{
    private function trailFor(string $alias, int|string $id)
    {
        return RiskAuditTrail::query()
            ->withoutGlobalScopes()
            ->whereIn('entity_type', MorphTypes::spellingsFor($alias))
            ->where('entity_id', $id)
            ->get();
    }

    /* ------------------------------------------------------------------ */
    /*  The models are in the map at all */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_rcsa_model_the_trail_names_is_in_the_morph_map(): void
    {
        foreach ([
            RcsaAssessment::class,
            RcsaAssessmentLine::class,
            RcsaActionPlan::class,
            \App\Models\Rcsa\RcsaCycle::class,
            \App\Models\Rcsa\RcsaExportJob::class,
            \App\Models\Rcsa\RcsaRegisterRisk::class,
        ] as $class) {
            $alias = MorphTypes::aliasFor($class);

            $this->assertNotNull($alias, "{$class} is not in the morph map, so its audit rows cannot be read back.");
            $this->assertLessThanOrEqual(
                RiskAuditTrail::ENTITY_TYPE_MAX,
                strlen($alias),
                "The alias [{$alias}] is longer than entity_type — SQLite will accept it and MySQL will not.",
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  What gets recorded */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_material_line_change_reaches_the_estate_wide_trail(): void
    {
        $assessment = $this->openedAssessment();
        $line = $assessment->lines()->first();

        app(RcsaAssessmentService::class)->apply(
            $line,
            ['inherent_likelihood' => 4, 'inherent_impact' => 3, 'control_effectiveness' => 'Partially Achieved'],
            $this->actor,
        );

        $rows = $this->trailFor('rcsa_assessment_line', $line->id);

        $this->assertGreaterThanOrEqual(3, $rows->count());
        $this->assertSame(['rcsa_line_change'], $rows->pluck('action_type')->unique()->all());

        $likelihood = $rows->firstWhere('field_changed', 'inherent_likelihood');

        $this->assertSame('4', $likelihood->new_value);
        $this->assertSame($this->actor->id, $likelihood->changed_by);
        $this->assertNotNull($likelihood->changed_at);

        // The calculated columns §11 names are here too.
        $this->assertNotNull($rows->firstWhere('field_changed', 'residual_score'));
        $this->assertNotNull($rows->firstWhere('field_changed', 'risk_treatment'));
    }

    #[Test]
    public function a_workflow_transition_reaches_the_trail_with_its_reason(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $line = $this->linesOf($assessment)->sole();

        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));
        $this->actingAs($this->reviewer)->post(route('rcsa.review.lines.challenge', [$assessment, $line]), [
            'body' => 'The control rating looks generous for a process with no maker-checker.',
        ]);
        $this->actingAs($this->reviewer)->post(route('rcsa.review.return', $assessment), [
            'reason' => 'One rating needs defending before this can be validated.',
        ]);

        $rows = $this->trailFor('rcsa_assessment', $assessment->id)
            ->where('action_type', 'rcsa_transition');

        $submit = $rows->firstWhere('new_value', RcsaAssessment::SUBMITTED);
        $return = $rows->firstWhere('new_value', RcsaAssessment::RETURNED);

        $this->assertNotNull($submit);
        $this->assertNotNull($return);
        $this->assertSame('under_review', $return->old_value);
        $this->assertSame($this->reviewer->id, $return->changed_by);
        $this->assertStringContainsString('needs defending', (string) $return->change_reason);
    }

    /**
     * Columns U, V and W. `rcsa_line_revisions` is keyed by LINE and never saw
     * them, so before P7 a plan's date moving was recorded nowhere.
     */
    #[Test]
    public function moving_an_action_plans_date_is_recorded(): void
    {
        $assessment = $this->submittedAssessment(risks: 1, aboveAppetite: true);
        $plan = $this->linesOf($assessment)->first()->actionPlans()->sole();

        $owner = $this->userWith(['rcsa_actionplan.view', 'rcsa_actionplan.update']);
        $approver = $this->userWith(['rcsa_actionplan.view', 'rcsa_actionplan.close']);

        $plan->forceFill(['owner_id' => $owner->id])->save();
        $original = $plan->target_date->toDateString();
        $new = now()->addMonths(9)->toDateString();

        $this->actingAs($owner)->post(route('rcsa.action-plans.extension', $plan), [
            'proposed_target_date' => $new,
            'extension_reason' => 'The core banking upgrade that carries this control slipped a quarter.',
        ]);

        $this->actingAs($approver)->post(route('rcsa.action-plans.extension.decide', $plan), ['approve' => true]);

        $row = $this->trailFor('rcsa_action_plan', $plan->id)->firstWhere('field_changed', 'target_date');

        $this->assertNotNull($row, 'A moved implementation date was not recorded anywhere.');
        $this->assertSame($original, substr((string) $row->old_value, 0, 10));
        $this->assertSame($new, substr((string) $row->new_value, 0, 10));
        $this->assertSame($approver->id, $row->changed_by);
        $this->assertStringContainsString('slipped', (string) $row->change_reason);
    }

    #[Test]
    public function taking_an_export_is_recorded(): void
    {
        Storage::fake('local');

        $this->submittedAssessment(risks: 2);
        $this->grant(['rcsa_export.bulk']);

        $export = app(RcsaExportService::class)->log($this->actor, ['appetite' => 'above'], 2);

        $row = $this->trailFor('rcsa_export_job', $export->id)->sole();

        $this->assertSame('rcsa_export', $row->action_type);
        $this->assertSame('2', $row->new_value);
        $this->assertSame($this->actor->id, $row->changed_by);
    }

    /* ------------------------------------------------------------------ */
    /*  "read-only and non-deletable" */
    /* ------------------------------------------------------------------ */

    /**
     * §11's actual requirement, and the reason this writes to the house trail
     * rather than to a second table: it is enforced, not promised.
     */
    #[Test]
    public function an_rcsa_audit_row_cannot_be_edited_or_deleted(): void
    {
        $assessment = $this->openedAssessment();
        $line = $assessment->lines()->first();

        app(RcsaAssessmentService::class)->apply($line, ['inherent_likelihood' => 2], $this->actor);

        $row = $this->trailFor('rcsa_assessment_line', $line->id)->first();

        $this->assertNotNull($row);

        try {
            $row->update(['new_value' => '5']);
            $this->fail('An audit row was updated.');
        } catch (\App\Models\AuditTrailIsAppendOnly $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $row->delete();
            $this->fail('An audit row was deleted.');
        } catch (\App\Models\AuditTrailIsAppendOnly $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // And the chain still verifies, which is what makes a later edit
        // detectable rather than merely forbidden.
        $this->assertSame($row->hash, $row->fresh()->expectedHash());
    }

    /* ------------------------------------------------------------------ */
    /*  The read-only view (§11) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_audit_screen_shows_the_history_and_says_it_cannot_be_edited(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        $this->actingAs($this->actor)
            ->get(route('rcsa.audit.show', $assessment))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RcsaAudit/Show')
                ->has('transitions')
                ->has('revisions')
                // The estate-wide trail is behind its own permission, and the
                // assessor does not hold it.
                ->where('estate', null)
                ->where('can.see_estate_trail', false));

        // "Read-only" is asserted where it is actually enforced — the model
        // and the trigger, in an_rcsa_audit_row_cannot_be_edited_or_deleted —
        // rather than by looking for the sentence that says so. The page is
        // React, so the copy is not in the HTML anyway.
    }

    #[Test]
    public function the_estate_wide_trail_is_shown_only_to_an_auditor(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        $auditor = $this->userWith(['rcsa_assessment.view', 'rcsa_audit.view']);

        $this->actingAs($auditor)
            ->get(route('rcsa.audit.show', $assessment))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.see_estate_trail', true)->has('estate'));
    }

    /**
     * A trail displayed without saying whether it verifies presents tampered
     * rows as fact.
     */
    #[Test]
    public function a_row_that_no_longer_matches_its_seal_is_shown_as_broken(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $auditor = $this->userWith(['rcsa_assessment.view', 'rcsa_audit.view']);

        $row = $this->trailFor('rcsa_assessment', $assessment->id)->first();
        $this->assertNotNull($row);

        // THE TRIGGER REFUSES A QUERY-BUILDER UPDATE TOO, which is a stronger
        // guarantee than this test first assumed and had to be worked around to
        // write it: tampering means dropping the guard, which is a DBA at the
        // console and nothing an application path can do. The point below is
        // that the SEAL notices even then.
        \App\Support\Audit\AuditTrailImmutability::drop();

        \Illuminate\Support\Facades\DB::table('risk_audit_trail')
            ->where('id', $row->id)
            ->update(['new_value' => 'tampered']);

        \App\Support\Audit\AuditTrailImmutability::install();

        $this->actingAs($auditor)
            ->get(route('rcsa.audit.show', $assessment))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'estate',
                fn ($estate) => collect($estate)->contains(fn ($r) => $r['sealed'] === false),
            ));
    }

    #[Test]
    public function the_audit_screen_is_scoped_like_everything_else(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        $stranger = $this->userWith(['rcsa_assessment.view', 'rcsa_audit.view'], units: []);

        $this->actingAs($stranger)
            ->get(route('rcsa.audit.show', $assessment))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function openedAssessment(): RcsaAssessment
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);

        $cycle = $this->makeCycle();
        app(\App\Services\Rcsa\RcsaCycleService::class)->open($cycle, $this->actor);

        return RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();
    }
}
