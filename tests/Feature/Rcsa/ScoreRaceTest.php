<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaLineRevision;
use App\Models\RiskAuditTrail;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaCycleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * RCSA-01: three autosaves in quick succession (likelihood, impact, control
 * effectiveness) left the answers stored and the scores NULL, and the
 * submission gate read the null band as "not above appetite".
 *
 * The cause was a read-modify-write on a stale instance; the fix is that
 * RcsaAssessmentService::apply() merges and calculates on the row as it is
 * under a write lock. These tests simulate the interleaving with stale
 * instances, because a PHPUnit process cannot run two requests at once — which
 * is exactly what a stale instance IS: a request that loaded the row before the
 * other one wrote.
 */
class ScoreRaceTest extends CycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->grant(['rcsa_assessment.submit']);
        Storage::fake('local');
    }

    /** @var array<string, array<string, mixed>> */
    private const ANSWERS = [
        'L' => ['inherent_likelihood' => 4],
        'I' => ['inherent_impact' => 4],
        'CE' => ['control_effectiveness' => 'Partially Achieved'],
    ];

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function orders(): array
    {
        return [
            'L, I, CE' => [['L', 'I', 'CE']],
            'CE, I, L' => [['CE', 'I', 'L']],
            'I, CE, L' => [['I', 'CE', 'L']],
            'CE, L, I' => [['CE', 'L', 'I']],
        ];
    }

    /**
     * @param  list<string>  $order
     */
    #[Test]
    #[DataProvider('orders')]
    public function three_single_field_saves_from_stale_instances_still_compute_every_score(array $order): void
    {
        $this->openedCycle();
        $line = RcsaAssessment::sole()->lines()->sole();
        $start = (int) $line->version;

        // Three requests, each of which loaded the row BEFORE any of the
        // others wrote: three snapshots of the same, empty, line.
        $stale = ['L' => $line->fresh(), 'I' => $line->fresh(), 'C' => $line->fresh()];
        $instances = [$line->fresh(), $line->fresh(), $line->fresh()];

        $service = app(RcsaAssessmentService::class);

        foreach ($order as $i => $key) {
            $result = $service->apply($instances[$i], self::ANSWERS[$key], $this->actor, expectedVersion: null);

            $this->assertSame('saved', $result['status']);
        }

        $stored = $line->fresh();

        $this->assertSame(4, $stored->inherent_likelihood);
        $this->assertSame(4, $stored->inherent_impact);
        $this->assertSame('Partially Achieved', $stored->control_effectiveness);
        $this->assertSame(16, $stored->inherent_score);
        $this->assertSame(8.0, (float) $stored->residual_score);
        $this->assertNotNull($stored->inherent_level);
        $this->assertNotNull($stored->residual_level);
        $this->assertNotNull($stored->risk_treatment);
        $this->assertNotNull($stored->appetite_status);
        $this->assertSame($start + 3, (int) $stored->version);
        $this->assertTrue($stored->isScored());
        $this->assertNotNull($stale['L']);
    }

    #[Test]
    public function the_instance_passed_in_is_brought_up_to_date(): void
    {
        $this->openedCycle();
        $line = RcsaAssessment::sole()->lines()->sole();

        $stale = $line->fresh();

        app(RcsaAssessmentService::class)->apply($line->fresh(), self::ANSWERS['L'], $this->actor);
        app(RcsaAssessmentService::class)->apply($stale, self::ANSWERS['I'], $this->actor);

        $this->assertSame(4, $stale->inherent_likelihood, 'The caller\'s instance sees the merged row.');
        $this->assertSame(4, $stale->inherent_impact);
        $this->assertSame(16, $stale->inherent_score);
    }

    #[Test]
    public function the_version_check_is_a_compare_and_swap_on_the_locked_row(): void
    {
        $this->openedCycle();
        $line = RcsaAssessment::sole()->lines()->sole();
        $read = (int) $line->version;

        $a = $line->fresh();
        $b = $line->fresh();
        $c = $line->fresh();

        $service = app(RcsaAssessmentService::class);

        // All three read the same version and say so.
        $first = $service->apply($a, self::ANSWERS['L'], $this->actor, expectedVersion: $read);
        $second = $service->apply($b, self::ANSWERS['I'], $this->actor, expectedVersion: $read);
        $third = $service->apply($c, self::ANSWERS['CE'], $this->actor, expectedVersion: $read);

        $this->assertSame('saved', $first['status']);
        $this->assertSame(RcsaAssessmentService::CONFLICT, $second['status']);
        $this->assertSame(RcsaAssessmentService::CONFLICT, $third['status']);

        // The conflict carries the CURRENT line, not the stale one.
        $this->assertSame(4, $second['line']->inherent_likelihood);
        $this->assertSame($read + 1, (int) $second['line']->version);

        $stored = $line->fresh();
        $this->assertSame(4, $stored->inherent_likelihood);
        $this->assertNull($stored->inherent_impact, 'A refused save writes nothing.');
        $this->assertSame($read + 1, (int) $stored->version);
    }

    #[Test]
    public function the_endpoint_answers_409_with_the_current_line_when_the_version_is_stale(): void
    {
        $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();
        $read = $line->version;

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 4,
                'version' => $read,
            ])
            ->assertOk()
            ->assertJsonPath('line.version', $read + 1);

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_impact' => 4,
                'version' => $read,
            ])
            ->assertStatus(409)
            ->assertJsonPath('line.inherent_likelihood', 4)
            ->assertJsonPath('line.version', $read + 1);

        $this->assertNull($line->fresh()->inherent_impact);

        // Sent with the version the previous response carried, the same save
        // goes through and the scores follow.
        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_impact' => 4,
                'control_effectiveness' => 'Partially Achieved',
                'version' => $read + 1,
            ])
            ->assertOk();

        $this->assertSame(16, $line->fresh()->inherent_score);
        $this->assertSame(8.0, (float) $line->fresh()->residual_score);
    }

    /* ------------------------------------------------------------------ */
    /*  The gate */
    /* ------------------------------------------------------------------ */

    /**
     * Put a line in the state the race used to leave it in, bypassing the
     * service: answers stored, every computed column NULL.
     */
    private function breakLine(RcsaAssessmentLine $line, string $control = 'Partially Achieved'): void
    {
        DB::table('rcsa_assessment_lines')->where('id', $line->id)->update([
            'inherent_likelihood' => 4,
            'inherent_impact' => 4,
            'control_effectiveness' => $control,
            'inherent_score' => null,
            'inherent_level' => null,
            'ce_modifier' => null,
            'residual_score' => null,
            'residual_level' => null,
            'risk_treatment' => null,
            'appetite_status' => null,
        ]);
    }

    #[Test]
    public function an_answered_but_unscored_line_is_listed_and_cannot_be_submitted_past(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        $this->breakLine($line);

        $this->assertTrue($line->fresh()->isScored(), 'The answers alone make isScored() true — the old hole.');

        $issues = app(RcsaAssessmentService::class)->outstanding($assessment)['issues'];

        $this->assertSame(['unscored'], array_column($issues, 'type'));
        $this->assertSame($line->id, $issues[0]['line_id']);
        $this->assertStringContainsString('not scored', $issues[0]['message']);

        // The outstanding read is a GET and heals nothing.
        $this->assertNull($line->fresh()->residual_level);

        /* --- Submit heals, then gates: above appetite with no plan ------ */

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHas('error');

        // Still `draft`: the line was broken through the table, nobody saved a
        // rating through the workspace, and a refused submit starts nothing.
        $this->assertSame(RcsaAssessment::DRAFT, $assessment->fresh()->status);

        $healed = $line->fresh();
        $this->assertSame(16, $healed->inherent_score);
        $this->assertSame(8.0, (float) $healed->residual_score);

        // THE REPAIR IS THE SYSTEM'S: the person who pressed Submit did not
        // change those scores, so neither the revisions nor the audit rows
        // name them — they carry no user and say what happened.
        $revisions = RcsaLineRevision::where('line_id', $line->id)->get();
        $this->assertEqualsCanonicalizing(['residual_score', 'risk_treatment'], $revisions->pluck('field')->all());
        $this->assertSame([null], $revisions->pluck('user_id')->unique()->values()->all());
        $this->assertSame(['Recomputed at submission — scores were missing'], $revisions->pluck('reason')->unique()->values()->all());

        $trail = RiskAuditTrail::query()
            ->where('entity_type', $line->getMorphClass())
            ->where('entity_id', $line->id)
            ->where('action_type', 'rcsa_line_change')
            ->get();
        $this->assertCount(2, $trail);
        $this->assertSame([null], $trail->pluck('changed_by')->unique()->values()->all(), 'a signed-in submitter must not be written as the author');
        $this->assertSame(['Recomputed at submission — scores were missing'], $trail->pluck('change_reason')->unique()->values()->all());

        $types = array_column(app(RcsaAssessmentService::class)->outstanding($assessment)['issues'], 'type');
        $this->assertSame(['action_plan'], $types);

        /* --- A complete plan, and it goes through ------------------------ */

        $line->actionPlans()->create([
            'organization_id' => $this->organization->id,
            'control_to_implement' => 'Introduce a four-eyes check before release.',
            'owner_id' => $this->actor->id,
            'target_date' => now()->addMonths(3)->toDateString(),
            'status' => RcsaActionPlan::OPEN,
        ]);

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertRedirect(route('rcsa.cycles.show', $cycle->id));

        $this->assertSame(RcsaAssessment::SUBMITTED, $assessment->fresh()->status);

        $stored = $line->fresh();
        $this->assertSame(16, $stored->inherent_score);
        $this->assertSame(8.0, (float) $stored->residual_score);
        $this->assertNotNull($stored->risk_treatment);
        $this->assertNotNull($stored->residual_level);
    }

    #[Test]
    public function a_line_whose_control_rating_matches_nothing_blocks_submission(): void
    {
        $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        // An import typo: answered, and nothing to compute a residual from.
        $this->breakLine($line, 'Partly Achieved');

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHas('error');

        // `draft` for the same reason as above — nobody has saved a rating.
        $this->assertSame(RcsaAssessment::DRAFT, $assessment->fresh()->status);

        $issues = app(RcsaAssessmentService::class)->outstanding($assessment)['issues'];

        $this->assertSame(['unscored'], array_column($issues, 'type'));
        $this->assertNull($line->fresh()->residual_level);
    }

    /* ------------------------------------------------------------------ */
    /*  The repair command */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_command_dry_runs_repairs_and_leaves_locked_lines_alone_unless_asked(): void
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
        [$open, $locked, $healthy] = $assessment->lines()->orderBy('id')->get()->all();

        $this->breakLine($open);
        $this->breakLine($locked);
        DB::table('rcsa_assessment_lines')->where('id', $locked->id)->update(['locked_at' => now()]);

        app(RcsaAssessmentService::class)->apply($healthy, [
            'inherent_likelihood' => 2, 'inherent_impact' => 2, 'control_effectiveness' => 'Fully Achieved',
        ], $this->actor);

        $revisionsBefore = RcsaLineRevision::count();
        $versionBefore = $open->fresh()->version;

        /* --- Dry run: counts, writes nothing ------------------------------ */

        $this->assertSame(0, Artisan::call('rcsa:recompute-scores', ['--dry-run' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('[dry run] 1 line(s) would be repaired, 1 locked line(s) skipped.', $output);
        $this->assertNull($open->fresh()->residual_level);
        $this->assertSame($revisionsBefore, RcsaLineRevision::count());
        $this->assertSame($versionBefore, $open->fresh()->version);

        /* --- A real run repairs the open line only ------------------------ */

        $this->assertSame(0, Artisan::call('rcsa:recompute-scores', ['--org' => $this->organization->id]));
        $this->assertStringContainsString('1 line(s) repaired, 1 locked line(s) skipped.', Artisan::output());

        $fixed = $open->fresh();
        $this->assertSame(16, $fixed->inherent_score);
        $this->assertSame(8.0, (float) $fixed->residual_score);
        $this->assertNotNull($fixed->risk_treatment);
        $this->assertSame($versionBefore + 1, $fixed->version);

        $revisions = RcsaLineRevision::where('line_id', $open->id)->get();
        $this->assertEqualsCanonicalizing(['residual_score', 'risk_treatment'], $revisions->pluck('field')->all());
        $this->assertNull($revisions->first()->user_id, 'There is no actor in a console.');
        $this->assertSame('Recomputed by rcsa:recompute-scores', $revisions->first()->reason);

        $this->assertNull($locked->fresh()->residual_level, 'A submitted line is skipped without the flag.');
        $this->assertSame(0, RcsaLineRevision::where('line_id', $locked->id)->count());

        /* --- Idempotent -------------------------------------------------- */

        Artisan::call('rcsa:recompute-scores');
        $this->assertStringContainsString('0 line(s) repaired, 1 locked line(s) skipped.', Artisan::output());

        /* --- With --include-locked ---------------------------------------- */

        $this->assertSame(0, Artisan::call('rcsa:recompute-scores', ['--include-locked' => true]));
        $this->assertStringContainsString('1 line(s) repaired, 0 locked line(s) skipped.', Artisan::output());

        $this->assertSame(8.0, (float) $locked->fresh()->residual_score);
        $this->assertGreaterThan(0, RcsaLineRevision::where('line_id', $locked->id)->count());
    }
}
