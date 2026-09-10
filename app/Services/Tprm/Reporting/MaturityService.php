<?php

namespace App\Services\Tprm\Reporting;

use App\Models\Tprm\MaturityAssessment;
use App\Models\Tprm\MaturityScore;
use App\Support\Tprm\MaturityModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Programme maturity — FR-RPT-06.
 *
 * OPENING AN ASSESSMENT SEEDS EVERY CATEGORY UNSCORED, and that is the point.
 * A blank form invites a programme to score the six categories it is proud of;
 * a form with eighteen rows, four of them still reading "Not assessed" at
 * sign-off, is a statement about the assessment as well as the programme.
 *
 * THE NIST SUBCATEGORIES ARE READ FROM THE SHIPPED FRAMEWORK LIBRARY, not
 * restated here. `tp_framework_controls` already carries GV.SC-01 to GV.SC-10
 * with their published titles; a second copy in this service would drift the
 * first time NIST revises one, and the maturity page would then disagree with
 * the control mapping page about what GV.SC-09 says.
 *
 * APPROVAL FREEZES THE SCORES. A maturity trend whose earlier points move is
 * not a trend, and the same argument that froze the board pack applies here
 * with more force: this one is explicitly a time series.
 */
class MaturityService
{
    /** The framework whose supplier-relevant subcategories are the CSF lens. */
    private const CSF_CODE = 'csf20';

    /** Only the governance-of-supply-chain category is a maturity lens. */
    private const CSF_PREFIX = 'GV.SC-';

    /**
     * Open a period, or return the draft that already exists for it.
     */
    public function open(string $periodLabel, CarbonImmutable $asAt, int $userId): MaturityAssessment
    {
        return DB::transaction(function () use ($periodLabel, $asAt, $userId) {
            $existing = MaturityAssessment::query()
                ->where('period_label', $periodLabel)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! $existing->isEditable()) {
                    throw new RuntimeException(
                        "The {$periodLabel} maturity assessment has been approved and its scores are frozen. "
                        .'Open a new period rather than reopening an approved one — the trend is measured '
                        .'against these.'
                    );
                }

                return $existing;
            }

            // `framework_version` is NOT NULL and guarded, so it is forced
            // onto the instance BEFORE the first save rather than after. The
            // column is not nullable on purpose: a score with no rubric
            // version behind it is the thing the column exists to prevent.
            $assessment = (new MaturityAssessment)->fill([
                'organization_id' => TenantContext::organizationId(),
                'period_label' => $periodLabel,
                'as_at' => $asAt->toDateString(),
                'created_by' => $userId,
            ])->forceFill([
                'framework_version' => MaturityModel::VERSION,
                'assessed_by' => $userId,
                'assessed_at' => now(),
            ]);

            $assessment->save();

            $this->seedCategories($assessment, $userId);

            return $assessment->refresh();
        });
    }

    /**
     * Record a category's score.
     *
     * @param  array<string, mixed>  $data
     */
    public function score(MaturityScore $score, array $data, int $userId): MaturityScore
    {
        $assessment = $score->assessment;

        if ($assessment !== null && ! $assessment->isEditable()) {
            throw new RuntimeException(
                "The {$assessment->period_label} assessment is approved and its scores are frozen."
            );
        }

        foreach (['current_level', 'target_level'] as $field) {
            $value = $data[$field] ?? null;

            if (! MaturityModel::isValidLevel($value === null ? null : (int) $value)) {
                throw new InvalidArgumentException(
                    "A maturity level is 0 to 5, or unset. Received [{$value}] for {$field}."
                );
            }
        }

        $score->fill([
            // An explicit null clears a score. A category somebody realises
            // they scored on the wrong evidence should be returnable to "not
            // assessed" rather than stuck at a number they no longer stand by.
            'current_level' => $this->levelOrNull($data['current_level'] ?? null),
            'target_level' => $this->levelOrNull($data['target_level'] ?? null),
            'evidence' => $data['evidence'] ?? null,
            'gap_actions' => $data['gap_actions'] ?? null,
            'owner_id' => $data['owner_id'] ?? null,
            'target_date' => $data['target_date'] ?? null,
            'updated_by' => $userId,
        ])->save();

        return $score->refresh();
    }

    public function approve(MaturityAssessment $assessment, int $userId): MaturityAssessment
    {
        if ($assessment->isApproved()) {
            throw new RuntimeException("The {$assessment->period_label} assessment is already approved.");
        }

        if ($assessment->scores()->whereNotNull('current_level')->doesntExist()) {
            throw new RuntimeException(
                'Approve an assessment that has been made: no category carries a current level. An assessment '
                .'of nothing is not a baseline for a trend.'
            );
        }

        $assessment->forceFill([
            'status' => MaturityAssessment::STATUS_APPROVED,
            'approved_by' => $userId,
            'approved_at' => now(),
            'updated_by' => $userId,
        ])->save();

        return $assessment->refresh();
    }

    /**
     * The gap plan — every category short of its own target, worst first.
     *
     * A CATEGORY WITH NO TARGET IS NOT IN THE PLAN AND IS COUNTED SEPARATELY.
     * A gap measured against a target nobody set is not a gap, and quietly
     * assuming level 5 would produce a plan the programme never agreed to.
     *
     * @return array{items: list<array<string, mixed>>, no_target: int, unscored: int}
     */
    public function gapPlan(MaturityAssessment $assessment): array
    {
        $scores = $assessment->scores()->with('owner:id,name')->get();

        $items = $scores
            ->filter(fn (MaturityScore $score) => $score->gap() !== null && $score->gap() > 0)
            ->sortByDesc(fn (MaturityScore $score) => $score->gap())
            ->map(fn (MaturityScore $score) => [
                'framework' => $score->framework,
                'category_code' => $score->category_code,
                'category_name' => $score->category_name,
                'current_level' => $score->current_level,
                'current_label' => $score->currentLabel(),
                'target_level' => $score->target_level,
                'target_label' => $score->targetLabel(),
                'gap' => $score->gap(),
                'actions' => $score->gap_actions,
                'owner' => $score->owner?->name,
                'target_date' => $score->target_date?->toDateString(),
                // A gap with no action recorded is the gap plan's own gap.
                'has_plan' => filled($score->gap_actions),
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'no_target' => $scores->filter(
                fn (MaturityScore $s) => $s->current_level !== null && $s->target_level === null
            )->count(),
            'unscored' => $scores->whereNull('current_level')->count(),
        ];
    }

    /**
     * The trend across approved assessments, per category.
     *
     * @return array<string, mixed>
     */
    public function trend(int $limit = 8): array
    {
        $assessments = MaturityAssessment::query()
            ->where('status', MaturityAssessment::STATUS_APPROVED)
            ->orderByDesc('as_at')
            ->limit($limit)
            ->with('scores')
            ->get()
            ->reverse()
            ->values();

        $periods = $assessments->map(fn (MaturityAssessment $a) => [
            'period_label' => $a->period_label,
            'as_at' => $a->as_at->toDateString(),
            'framework_version' => $a->framework_version,
        ])->all();

        $categories = [];

        foreach ($assessments as $assessment) {
            foreach ($assessment->scores as $score) {
                $key = $score->framework.':'.$score->category_code;

                $categories[$key] ??= [
                    'framework' => $score->framework,
                    'category_code' => $score->category_code,
                    'category_name' => $score->category_name,
                    'levels' => [],
                ];

                // Nulls are preserved rather than dropped: a category that
                // went unassessed in one period must show a break in its line,
                // not a straight segment across the gap.
                $categories[$key]['levels'][$assessment->period_label] = $score->current_level;
            }
        }

        // Every category carries a reading, or an explicit null, for every
        // period on the chart.
        foreach ($categories as $key => $category) {
            $series = [];
            foreach ($periods as $period) {
                $series[] = $category['levels'][$period['period_label']] ?? null;
            }
            $categories[$key]['levels'] = $series;
        }

        return [
            'periods' => $periods,
            'categories' => array_values($categories),
            // The two rubrics are not averaged together. VRMMM levels describe
            // programme maturity and CSF subcategories describe outcomes, and
            // one number over both would mean nothing.
            'means' => $this->meansPerFramework($assessments),
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  Collection<int, MaturityAssessment>  $assessments
     * @return array<string, list<array<string, mixed>>>
     */
    private function meansPerFramework(Collection $assessments): array
    {
        $means = [];

        foreach ([MaturityModel::FRAMEWORK_VRMMM, MaturityModel::FRAMEWORK_NIST_CSF] as $framework) {
            $means[$framework] = $assessments->map(function (MaturityAssessment $assessment) use ($framework) {
                $scored = $assessment->scores
                    ->where('framework', $framework)
                    ->whereNotNull('current_level');

                return [
                    'period_label' => $assessment->period_label,
                    // Null, not zero, where nothing in this framework was
                    // scored in that period.
                    'mean' => $scored->isEmpty()
                        ? null
                        : round($scored->avg(fn (MaturityScore $s) => $s->current_level), 2),
                    'scored' => $scored->count(),
                ];
            })->values()->all();
        }

        return $means;
    }

    private function seedCategories(MaturityAssessment $assessment, int $userId): void
    {
        $rows = [];

        foreach (MaturityModel::vrmmmCategories() as $category) {
            $rows[] = [
                'framework' => MaturityModel::FRAMEWORK_VRMMM,
                'category_code' => $category['code'],
                'category_name' => $category['name'],
            ];
        }

        foreach ($this->csfSubcategories() as $code => $title) {
            $rows[] = [
                'framework' => MaturityModel::FRAMEWORK_NIST_CSF,
                'category_code' => $code,
                'category_name' => $title,
            ];
        }

        foreach ($rows as $row) {
            MaturityScore::create($row + [
                'organization_id' => $assessment->organization_id,
                'assessment_id' => $assessment->getKey(),
                'updated_by' => $userId,
            ]);
        }
    }

    /**
     * GV.SC-01 to GV.SC-10, from the shipped framework library.
     *
     * The query builder is used deliberately: `tp_frameworks` is system-owned
     * with a null `organization_id`, and the tenancy global scope excludes
     * null — the trap documented against `QuestionnaireTemplate`. There is no
     * model here to carry the scope in the first place.
     *
     * @return array<string, string>
     */
    private function csfSubcategories(): array
    {
        $frameworkId = DB::table('tp_frameworks')
            ->where('code', self::CSF_CODE)
            ->orderByDesc('id')
            ->value('id');

        if ($frameworkId === null) {
            // The reference seeder has not run. Seeding invented subcategory
            // text here would put a second, wrong copy of NIST's wording in
            // the product; an empty CSF lens with eight VRMMM categories is
            // visibly incomplete, which is the better failure.
            return [];
        }

        return DB::table('tp_framework_controls')
            ->where('framework_id', $frameworkId)
            ->where('control_id', 'like', self::CSF_PREFIX.'%')
            ->orderBy('control_id')
            ->pluck('title', 'control_id')
            ->all();
    }

    private function levelOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
