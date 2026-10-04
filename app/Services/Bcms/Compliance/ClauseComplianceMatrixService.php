<?php

namespace App\Services\Bcms\Compliance;

use App\Enums\Bcms\DependencyType;
use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\LadderLevel;
use App\Models\Bcms\Aar;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\ClauseRef;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\DrTest;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Incident;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\Objective;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanAttestation;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\ProgrammeObligation;
use App\Models\Bcms\ProgrammeScopeItem;
use App\Models\Bcms\RaciAssignment;
use App\Models\Bcms\Strategy;
use App\Models\Bcms\TrainingCurriculum;
use App\Models\Bcms\TrainingRecord;
use App\Models\KeyRiskIndicator;
use App\Services\Bcms\Exercises\LadderAdvisor;
use App\Services\Bcms\MaturityService;
use App\Services\Bcms\ResilienceKriPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The clause-by-clause evidence matrix — `docs/bcms/screens/
 * compliance-evidence-matrix.md`, phase-11-spec §3.2, ADR 0021 §1/§2.
 *
 * FOUR STATES, NOT THREE. green (the sufficiency rule is met), amber (partial
 * or externally referenced — 9.2 lives here whenever anything at all is on
 * file), red (an honest, named gap), grey (`bcms_programme_obligations.applies
 * = false`, with its rationale). 9.2 programme/results NEVER render grey —
 * they apply to every certified BCMS regardless of what the obligation
 * register says (ADR 0021 §1).
 *
 * EVERY ARTEFACT STRING NAMES A NOUN. Development standard §5: a figure with
 * no named source is cut, not shown behind a dash. Every branch below returns
 * a sentence a reader can act on, never a bare percentage.
 *
 * THIS SERVICE COMPUTES SUFFICIENCY; IT DOES NOT RECOMPUTE MATURITY OR THE
 * KRI READINGS. Maturity comes read-only from `MaturityService`'s stored
 * assessment; the seventeen resilience KRIs come read-only from
 * `ResilienceKriPublisher::status()`. `evidence-pack-export.md` calls this
 * same service so the matrix and the exported pack cannot disagree about
 * whether a given clause is green.
 *
 * REPRODUCIBILITY (phase-11-spec §6 criterion 2): `build()` is read-only over
 * stored rows and must return byte-identical `sections`/`summary` content on
 * two calls with no write between them, over the same data. EVERY "latest
 * row" AND "order by a single column" QUERY IN THIS CLASS CARRIES A PRIMARY-
 * KEY TIEBREAKER (`->orderByDesc('id')` chained after `->latest($col)`, or
 * the equivalent on `existsCheck()`'s generic `->latest()`) — an ORDER BY on
 * a non-unique column alone (`completed_at`, `dispatched_at`, `approved_at`,
 * ...) leaves the database free to return either side of a tie, and two
 * fixture rows landing in the same second is not a hypothetical in a seeded
 * test. Follow this convention for any new sufficiency check added here.
 */
class ClauseComplianceMatrixService
{
    /**
     * Memoised per `build()` call — B10 (gate 1 code review #1): several
     * `IsoClauseRef` cases share one sufficiency method (`plansSufficiency()`
     * for three), and `crosswalk()` calls `sufficiency($answering)` again for
     * every CBN/DORA row an ISO ref answers, which for a widely-answered ref
     * like `iso22301.8.4.5` meant the SAME query ran a third and fourth time
     * in one `build()`. Reset at the top of `build()`; never shared across
     * two different `$asOf` values, which the key includes.
     *
     * @var array<string, array{state: string, artefact: string, last_evidenced: ?string}>
     */
    private array $sufficiencyCache = [];

    /** @var array<string, mixed> */
    private array $methodCache = [];

    /**
     * The upper bound every "in the period"/"as of"/"inside its frequency"
     * read measures against — see `build()`.
     */
    private Carbon $periodEnd;

    /**
     * The lower bound for the small set of rules that are a TRUE window
     * (spec §3.2 rows 7, 13, 17 — "a campaign/occurrence/review IN THE
     * PERIOD"), or null when none was given (the live screen — those three
     * rules fall back to the start of `$periodEnd`'s own calendar year, R3's
     * carry-forward of the previous cycle's "current year" behaviour). Never
     * applied to an existence/current-state check — see `existsCheck()`'s
     * own docblock for why an upper bound only is the correct read there.
     */
    private ?Carbon $periodStart;

    public function __construct(
        private readonly ResilienceKriPublisher $kris,
        private readonly MaturityService $maturity,
        private readonly LadderAdvisor $ladder,
        // R4 (gate 1 code review #2): `assignedUsers()` is the SAME live
        // role-holder resolution the training-compliance screen uses —
        // called, never re-derived. A different engineer's file this
        // cycle; only called, never edited.
        private readonly \App\Services\Bcms\Training\TrainingComplianceService $training,
    ) {}

    /**
     * R3 (gate 1 code review #2): `$asOf` alone was not enough — `$from` was
     * threaded nowhere, `aarSufficiency()`/`communicationSufficiency()`/
     * `reviewSufficiency()` had an upper bound (calendar year of `$to`) but
     * NO lower bound (so a fact from three years into that "year" — a leap
     * from `whereYear()` — still counted), and every `existsCheck()`-driven
     * row was entirely unbounded, so a Finding/Plan/BIA approved AFTER a
     * past pack's own `$to` still counted toward it, which is what made the
     * pack fail to reproduce for its stated period.
     *
     * @param  ?Carbon  $periodEnd  the moment every bounded read's UPPER
     *                              bound is measured against — defaults to
     *                              `now()` for the live Compliance & Evidence
     *                              screen and every caller that does not
     *                              pass one.
     * @param  ?Carbon  $periodStart  the LOWER bound for the three true
     *                                "in the period" rules only — null for
     *                                the live screen (falls back to the
     *                                start of `$periodEnd`'s calendar year).
     *                                `EvidencePackService` passes the pack's
     *                                own `$from 00:00`/`$to 23:59:59.999`
     *                                (end of day, UTC): a pack printing
     *                                "period: 2024-01-01 to 2024-12-31" on
     *                                its cover must not silently answer
     *                                every row against TODAY, and a fact
     *                                dated AFTER `$periodEnd` must never
     *                                count toward a past pack.
     * @return array<string, mixed>
     */
    public function build(?Carbon $periodEnd = null, ?Carbon $periodStart = null): array
    {
        $this->periodEnd = $periodEnd ?? now();
        $this->periodStart = $periodStart;
        $this->sufficiencyCache = [];
        $this->methodCache = [];

        $programme = $this->programmeAsOf();

        if ($programme === null) {
            return ['empty_programme' => true];
        }

        $refs = ClauseRef::query()->orderBy('standard')->orderBy('sort_order')->get();
        $obligations = ProgrammeObligation::query()
            ->where('programme_id', $programme->getKey())
            ->get()
            ->keyBy('clause_ref');

        // A4 (gate 1 code review #1): "the register is seeded" must check the
        // programme actually shown above, not any programme any tenant has
        // ever loaded an obligation for. `$obligations` (already scoped to
        // THIS programme) is exactly that check, and it is already loaded.
        $registerSeeded = $obligations->isNotEmpty();

        $rows = [];

        foreach ($refs as $meta) {
            $ref = IsoClauseRef::tryFrom($meta->code);

            if ($ref === null) {
                continue;
            }

            $rows[] = $this->rowFor($ref, $meta, $obligations->get($meta->code), $registerSeeded);
        }

        $sections = collect($rows)->groupBy('standard')->map(fn (Collection $group) => $group->values()->all());

        $mandatory = collect($rows)->filter(fn (array $r) => $r['mandatory']);

        return [
            'empty_programme' => false,
            'programme' => ['id' => $programme->getKey(), 'year' => $programme->year, 'status' => $programme->status],
            // Code review #3, A3: a frontend engineer will change
            // Matrix.jsx:113's own copy to say WHICH approved programme
            // governs and that a newer draft is not used until approved —
            // this is the data half only, never null-by-omission: `null`
            // means "no such draft exists", not "not computed".
            'newer_draft_programme' => $this->newerDraftProgramme($programme),
            'register_seeded' => $registerSeeded,
            // QA ruling on the build() programme picker: `bcms_programme_
            // obligations` carries only generic `created_at`/`updated_at`,
            // no dated applicability history — WHICH obligations govern
            // `$programme` (picked as of `periodEnd` above) is bound, but
            // WHAT each one currently says (`applies`, its rationale) is
            // not reconstructable for a past period. One pack-level flag,
            // not per-row (every grey/amber-not-seeded gate in `sections`
            // already reads the SAME `$obligations` collection).
            'obligations_current_state' => true,
            'sections' => $sections->all(),
            'summary' => [
                'green' => collect($rows)->where('state', 'green')->count(),
                'amber' => collect($rows)->where('state', 'amber')->count(),
                'red' => collect($rows)->where('state', 'red')->count(),
                'grey' => collect($rows)->where('state', 'grey')->count(),
                'mandatory_gap_count' => $mandatory->whereIn('state', ['red', 'amber'])->count(),
            ],
            'kris' => $this->kris->status(),
            'maturity' => $this->maturitySnapshot(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function maturitySnapshot(): ?array
    {
        $latest = $this->maturity->latest();

        if ($latest === null) {
            return null;
        }

        return [
            'overall_score' => $latest->overall_score === null ? null : (float) $latest->overall_score,
            'assessed_at' => $latest->assessed_at?->toIso8601String(),
            'method_version' => $latest->method_version,
        ];
    }

    /** @return array<string, mixed> */
    private function rowFor(IsoClauseRef $ref, ClauseRef $meta, ?ProgrammeObligation $obligation, bool $registerSeeded): array
    {
        $base = [
            'code' => $ref->value,
            'standard' => $meta->standard,
            'clause' => $meta->clause,
            'title' => $meta->title,
            'mandatory' => $ref->isMandatoryRecord(),
        ];

        $is92 = in_array($ref, [IsoClauseRef::Iso22301_9_2_programme, IsoClauseRef::Iso22301_9_2_results], true);

        if (! $is92 && $obligation !== null && ! $obligation->applies) {
            return $base + [
                'state' => 'grey',
                'artefact' => 'Not applicable — '.($obligation->applicability_note ?? 'the institution has recorded this clause as not applicable.'),
                'last_evidenced' => null,
            ];
        }

        if (! $is92 && ! $registerSeeded) {
            return $base + [
                'state' => 'amber',
                'artefact' => 'Applicability not yet determined — load the obligations register in Programme governance.',
                'last_evidenced' => null,
            ];
        }

        return $base + $this->sufficiency($ref);
    }

    /** @return array{state: string, artefact: string, last_evidenced: ?string} */
    private function sufficiency(IsoClauseRef $ref): array
    {
        return $this->sufficiencyCache[$ref->value] ??= $this->computeSufficiency($ref);
    }

    /**
     * A second memo, keyed by METHOD rather than by ref (B10, A5 — gate 1
     * code review #1). `$sufficiencyCache` above stops the SAME ref being
     * computed twice (crosswalk re-answering an already-seen ISO ref), but
     * several `IsoClauseRef` cases share ONE sufficiency method — three
     * `plansSufficiency()` cases, two each for `communicationSufficiency()`,
     * `reviewSufficiency()` and `nonconformitySufficiency()` — and each of
     * those is a DIFFERENT ref, so the ref-keyed cache alone still ran the
     * method fresh for every case in its group. This is what
     * `plansSufficiency()`'s three `Plan::where(...)->get()` calls, and
     * `nonconformitySufficiency()` running twice per `build()`, both were.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $compute
     * @return TResult
     */
    private function memo(string $key, callable $compute): mixed
    {
        if (! array_key_exists($key, $this->methodCache)) {
            $this->methodCache[$key] = $compute();
        }

        return $this->methodCache[$key];
    }

    /**
     * QA re-gate #10's own design ruling, implemented verbatim:
     * `programmeAsOf()` means "the programme IN FORCE as of `periodEnd`".
     *
     *   1. PRIMARY — the most recently APPROVED programme as of
     *      `periodEnd`: `approved_at <= periodEnd`, ordered `approved_at`
     *      desc, `id` desc. STATUS IS IRRELEVANT here — a programme only
     *      moves FORWARD (`draft` → `approved` → `active`) and
     *      `approved_at` is stamped once, on approval, and never cleared,
     *      so `approved` AND `active` both count. This is what fixes both
     *      re-gate #10 defects at once: `orderByDesc('year')` alone let a
     *      LATER-YEAR DRAFT created before `periodEnd` beat an
     *      EARLIER-YEAR APPROVED programme (year 2027 draft vs. year 2025
     *      approved, both existing by a 2025-12-31 `periodEnd` — the draft
     *      won purely on `year`); and a programme approved WITHIN the
     *      period that has since moved on to `active` must still count,
     *      never read as "not approved" for its own past pack merely
     *      because `status` has since moved past `approved` (this is
     *      `status` being CURRENT state, the exact family of bug `$to`-vs-
     *      `now()` fixes elsewhere in this file address).
     *   2. FALLBACK, only when NOTHING qualifies under (1) — the latest
     *      programme that EXISTED as of `periodEnd`: `created_at <=
     *      periodEnd`, ordered `year` desc, `id` desc. The pack then
     *      honestly shows that no approved programme governed the period
     *      (every downstream approval check below still reads `approved_at
     *      === null` and renders red/not-approved) — this is what still
     *      gives `build()`'s own `registerSeeded`/`$obligations` gate
     *      SOMETHING to show for a tenant with only ever a draft.
     *
     * EVERY "is it approved" check that reads a `Programme` fetched through
     * this method — `scopeSufficiency()`, and `operationalPlanningSufficiency()`
     * (8.1, a SEPARATE `existsCheck()`-driven query over the same model,
     * caught by the same grep for Programme `status = 'approved'` string
     * comparisons this ruling asked for) — now reads `approved_at !== null
     * && approved_at <= periodEnd`, never `status`. `policySufficiency()`
     * does not check the PROGRAMME's own approval at all (only the linked
     * POLICY PLAN's, a `Plan`, unaffected by this ruling — `Plan.status`
     * carries the SAME as-of flaw in principle, `draft|review|approved|
     * archived`, a Plan superseded after being the governing one for a
     * past period would misread — reported here, per the ruling's own
     * instruction, rather than fixed: out of this pass's scope).
     *
     * CONSISTENCY, PAST AND LIVE: the live screen (`periodEnd = now()`)
     * follows the identical two-tier rule — an approved-as-of-now
     * programme is preferred over a newer, still-unapproved draft, even
     * though the draft's `created_at` also satisfies `<= now()`. A
     * newly-drafted programme for a later year does NOT pre-empt the
     * live matrix the moment someone starts drafting it; it must be
     * APPROVED first, the same governance gate a past pack is judged
     * against. `Phase11ReportingGateTest.php`'s own
     * `a_draft_programme_for_a_later_year_created_today_...` test's live-
     * pack expectation was corrected to match (previously asserted the
     * draft won live — QA re-gate #10 overturned the "live screen accepts
     * an unapproved draft over the governing approved programme" behaviour
     * that assumption depended on).
     */
    private function programmeAsOf(): ?Programme
    {
        return $this->memo('programme_as_of', function () {
            $approved = Programme::query()
                ->where('approved_at', '<=', $this->periodEnd)
                ->orderByDesc('approved_at')->orderByDesc('id')->first();

            return $approved ?? Programme::query()
                ->where('created_at', '<=', $this->periodEnd)
                ->orderByDesc('year')->orderByDesc('id')->first();
        });
    }

    /**
     * Code review #3, A3: the LATEST programme created after `$governing`
     * (the one `programmeAsOf()` picked) that is NOT approved as of
     * `periodEnd` — a reader looking at the matrix under an approved
     * programme should be told a newer one is being drafted, and that it
     * is not yet in force. `null` when none exists. Ordered `created_at`
     * desc, `id` desc — the newest DRAFT, not the highest `year` label
     * (`year` is not a validity window in this table, per
     * `programmeAsOf()`'s own docblock).
     *
     * @return array{id: int, year: int}|null
     */
    private function newerDraftProgramme(Programme $governing): ?array
    {
        $draft = Programme::query()
            ->whereKeyNot($governing->getKey())
            ->where('created_at', '>', $governing->created_at)
            ->where(fn ($q) => $q->whereNull('approved_at')->orWhere('approved_at', '>', $this->periodEnd))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first();

        return $draft === null ? null : ['id' => $draft->getKey(), 'year' => $draft->year];
    }

    /** @return array{state: string, artefact: string, last_evidenced: ?string} */
    private function computeSufficiency(IsoClauseRef $ref): array
    {
        return match ($ref) {
            IsoClauseRef::Iso22301_4_1, IsoClauseRef::Iso22301_4_2, IsoClauseRef::Iso22301_4_3 => $this->memo('scope', fn () => $this->scopeSufficiency()),
            IsoClauseRef::Iso22301_5_2 => $this->policySufficiency(),
            IsoClauseRef::Iso22301_5_3 => $this->raciSufficiency(),
            IsoClauseRef::Iso22301_6_2 => $this->objectivesSufficiency(),
            IsoClauseRef::Iso22301_7_2 => $this->competenceSufficiency(),
            IsoClauseRef::Iso22301_7_3 => $this->awarenessSufficiency(),
            IsoClauseRef::Iso22301_7_4, IsoClauseRef::Iso22301_8_4_3 => $this->memo('communication', fn () => $this->communicationSufficiency()),
            IsoClauseRef::Iso22301_7_5 => $this->documentedInformationSufficiency(),
            IsoClauseRef::Iso22301_8_1 => $this->operationalPlanningSufficiency(),
            IsoClauseRef::Iso22301_8_2_2 => $this->biaSufficiency(),
            IsoClauseRef::Iso22301_8_2_3 => $this->riskAssessmentSufficiency(),
            IsoClauseRef::Iso22301_8_3 => $this->strategySufficiency(),
            IsoClauseRef::Iso22301_8_4_1, IsoClauseRef::Iso22301_8_4_2, IsoClauseRef::Iso22301_8_4_4 => $this->memo('plans', fn () => $this->plansSufficiency()),
            IsoClauseRef::Iso22301_8_4_5 => $this->recoverySufficiency(),
            IsoClauseRef::Iso22301_8_5_programme => $this->exerciseProgrammeSufficiency(),
            IsoClauseRef::Iso22301_8_5_exercise => $this->exerciseSufficiency(),
            IsoClauseRef::Iso22301_8_5_report => $this->aarSufficiency(),
            IsoClauseRef::Iso22301_8_6 => $this->evaluationSufficiency(),
            IsoClauseRef::Iso22301_9_1 => $this->kriSufficiency(),
            IsoClauseRef::Iso22301_9_2_programme => $this->auditProgrammeSufficiency(),
            IsoClauseRef::Iso22301_9_2_results => $this->auditResultsSufficiency(),
            IsoClauseRef::Iso22301_9_3_inputs, IsoClauseRef::Iso22301_9_3_results => $this->memo('review', fn () => $this->reviewSufficiency()),
            IsoClauseRef::Iso22301_10_1_nonconformity, IsoClauseRef::Iso22301_10_1_corrective => $this->memo('nonconformity', fn () => $this->nonconformitySufficiency()),
            IsoClauseRef::Iso22301_10_2 => $this->continualImprovementSufficiency(),
            IsoClauseRef::Iso22318_supply_chain => $this->supplyChainSufficiency(),
            IsoClauseRef::Iso22317_bia_method => $this->existsCheck(BiaAssessment::query()->where('status', 'approved'), 'An approved business impact assessment'),
            // B17 (gate 1 code review #1): ADR 0020 §4 requires `is_exercise
            // = false` "in every aggregate". Neither of these two carried it,
            // so a tabletop that declares a PRACTICE incident turned
            // `iso22320.incident_response` green — and, through the §3.4
            // crosswalk, turned the CBN regulatory row `cbn.rcf.incident`
            // green too, on a drill.
            IsoClauseRef::Iso22320_incident => $this->existsCheck(Incident::query()->where('is_exercise', false), 'An incident record demonstrating command and control'),
            // QA re-gate #11 item 2: `is_selected` is a LIVE pointer —
            // `StrategyService::select()` flips a sibling's `is_selected` to
            // false the moment a new one is chosen, with no trace of when
            // THIS one stopped being selected. `approval_status = 'approved'`
            // survives that (never touched by `select()`); `existsCheck()`'s
            // own `approved_at <= periodEnd` bound does the rest.
            IsoClauseRef::Iso22331_strategy => $this->existsCheck(Strategy::query()->where('approval_status', 'approved'), 'A selected continuity strategy with its rationale'),
            IsoClauseRef::Iso22361_crisis => $this->existsCheck(Incident::query()->where('is_exercise', false)->whereNotNull('declared_at'), 'A declared incident demonstrating crisis activation'),
            IsoClauseRef::Iso22398_exercise_design => $this->existsCheck(ExerciseOccurrence::query(), 'An exercise occurrence with a defined scenario'),
            // Code review #3, D2: `status = 'completed'` is CURRENT state —
            // the same `actual_end` bound as `aarSufficiency()`'s own fix,
            // explicit here rather than relying on `existsCheck()`'s own
            // generic per-model column (which stays `scheduled_date` for
            // `ExerciseOccurrence` — see `DATE_COLUMNS`'s own docblock —
            // because the SIBLING row immediately above needs a merely
            // SCHEDULED occurrence to count, not a completed one).
            IsoClauseRef::Iso22398_ladder => $this->existsCheck(ExerciseOccurrence::query()->whereNotNull('actual_end')->where('actual_end', '<=', $this->periodEnd), 'A completed exercise on the programme'),
            // B16 part 1 (gate 1 code review #1, confirmed by compliance
            // ruling "PIRs and clause 8.6"): a PIR (`incident_id` set,
            // `occurrence_id` null, ADR 0020) is not an exercise evaluation.
            // `occurrence_id` not null scopes this to exercise AARs only —
            // exactly the same rule `aarSufficiency()` already applies for
            // 8.5.report (§13 defect 9).
            IsoClauseRef::Iso22398_evaluation => $this->existsCheck(Aar::query()->whereNotNull('occurrence_id')->where('status', 'final'), 'A finalised after-action report', self::AAR_CURRENT_STATE_REASON),
            default => $this->crosswalk($ref),
        };
    }

    /* -------------------------------------------------------------- */
    /*  Individual sufficiency rules — phase-11-spec §3.2 */
    /* -------------------------------------------------------------- */

    /**
     * R3, gate 1 code review #2's own follow-up: the programme picked here
     * is now the one most recently approved AS OF `$this->periodEnd`, not
     * simply "the latest by year/id" — a programme approved AFTER a past
     * pack's own `$to` must not evidence that pack.
     */
    private function scopeSufficiency(): array
    {
        // `programmeAsOf()` — the SAME programme `build()` and
        // `policySufficiency()` agree on (QA ruling, see that method's own
        // docblock). NEVER `status` — QA re-gate #10: `status` is CURRENT
        // state and only moves FORWARD (draft → approved → active), so a
        // programme approved WITHIN the period that has since moved to
        // `active` must still count here. `approved_at !== null &&
        // approved_at <= periodEnd` is the one true "was it approved as of
        // this pack's own period" answer.
        $programme = $this->programmeAsOf();

        if ($programme === null || $programme->approved_at === null || $programme->approved_at->gt($this->periodEnd)) {
            return ['state' => 'red', 'artefact' => 'No approved programme scope statement on file.', 'last_evidenced' => null];
        }

        // Code review #3, D2: no `created_at <= periodEnd` bound — an
        // exclusion ROW added today (even without a rationale) could not
        // have made a past pack amber; it did not exist yet.
        $missingRationale = ProgrammeScopeItem::query()
            ->where('programme_id', $programme->getKey())
            ->where('in_scope', false)
            ->whereNull('rationale')
            ->where('created_at', '<=', $this->periodEnd)
            ->count();

        // Code review #3, A5: `rationale` is live content with no dated
        // history — the amber/green branches below, which read WHETHER
        // one is currently recorded, are current_state; the row's own
        // EXISTENCE is already bound above (D2), and the programme-approval
        // red branch is not content-dependent at all.
        if ($missingRationale > 0) {
            return ['state' => 'amber', 'artefact' => $missingRationale.' excluded item(s) have no recorded rationale.', 'last_evidenced' => $programme->approved_at->toDateString(), 'current_state' => true, 'current_state_reason' => self::SCOPE_RATIONALE_CURRENT_STATE_REASON];
        }

        return ['state' => 'green', 'artefact' => 'Programme scope approved, with a rationale recorded for every exclusion.', 'last_evidenced' => $programme->approved_at->toDateString(), 'current_state' => true, 'current_state_reason' => self::SCOPE_RATIONALE_CURRENT_STATE_REASON];
    }

    /**
     * R3, gate 1 code review #2's own follow-up: the policy plan is now
     * treated as on file only if approved ON OR BEFORE `$this->periodEnd`
     * (a policy approved after a past pack's own `$to` cannot evidence it),
     * and "inside its review cycle" is judged against `$periodEnd`, not
     * `isFuture()` against today.
     */
    private function policySufficiency(): array
    {
        // `programmeAsOf()` — the SAME programme `build()` and
        // `scopeSufficiency()` agree on (QA ruling, see that method's own
        // docblock). The programme's OWN approval status is irrelevant
        // here (an unapproved programme's `policy_plan_id` can still point
        // at an approved policy) — only the linked POLICY PLAN's own
        // approval, checked below, matters.
        //
        // QA re-gate #11 item 1: NEVER `status` — `PlanService::supersede()`
        // (:184) archives the OLD approved version without touching its own
        // `approved_at`, so a policy plan superseded AFTER `periodEnd` must
        // still read as approved for a past pack. The plan counts if its
        // OWN `approved_at <= periodEnd`, whatever its CURRENT status.
        $programme = $this->programmeAsOf();
        $policy = $programme?->policy_plan_id !== null ? Plan::query()->find($programme->policy_plan_id) : null;

        if ($policy === null || $policy->approved_at === null || $policy->approved_at->gt($this->periodEnd)) {
            return ['state' => 'red', 'artefact' => 'No approved business continuity policy is on file.', 'last_evidenced' => null];
        }

        $insideCycle = $policy->next_review_date === null || $policy->next_review_date->gt($this->periodEnd);
        $approvedByAuthor = $policy->approver_id !== null && $policy->approver_id === $policy->owner_id;

        if (! $insideCycle || $approvedByAuthor) {
            return ['state' => 'amber', 'artefact' => 'The policy is approved but '.($approvedByAuthor ? 'by its own author' : 'past its review cycle').'.', 'last_evidenced' => $policy->approved_at->toDateString()];
        }

        return ['state' => 'green', 'artefact' => 'Business continuity policy approved and inside its review cycle.', 'last_evidenced' => $policy->approved_at->toDateString()];
    }

    /**
     * R3 follow-up (gate 1 code review #2): `bcms_processes` carries no
     * history of which processes were Tier 1/critical on a past date — only
     * their CURRENT `criticality_tier`/`is_critical_service`/`status`, so
     * the denominator here can never be reconstructed for a past period.
     * `current_state: true` marks the row for exactly that reason, on every
     * branch. The RACI assignment itself (`RaciAssignment`, timestamps
     * only — no dedicated "assigned at") IS bound, on its `created_at`, as
     * far as the schema allows, so a "who is accountable" write made after
     * a past pack's own `$to` at least cannot move it.
     */
    private function raciSufficiency(): array
    {
        $criticalProcessIds = Process::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('criticality_tier', 1)->orWhere('is_critical_service', true))
            ->pluck('id');

        if ($criticalProcessIds->isEmpty()) {
            return ['state' => 'red', 'artefact' => 'No process is marked Tier 1 or critical, so RACI coverage cannot be evidenced.', 'last_evidenced' => null, 'current_state' => true];
        }

        $withAccountable = RaciAssignment::query()
            ->where('assignable_type', 'bcms_process')
            ->whereIn('assignable_id', $criticalProcessIds)
            ->where('raci_role', 'A')
            ->where('created_at', '<=', $this->periodEnd)
            ->distinct()
            ->count('assignable_id');

        $missing = $criticalProcessIds->count() - $withAccountable;

        if ($missing > 0) {
            return ['state' => 'red', 'artefact' => $missing.' of '.$criticalProcessIds->count().' critical process(es) have nobody accountable.', 'last_evidenced' => null, 'current_state' => true];
        }

        return ['state' => 'green', 'artefact' => 'Every critical process has an accountable owner recorded.', 'last_evidenced' => null, 'current_state' => true];
    }

    /**
     * R3 follow-up (gate 1 code review #2): `bcms_objectives` carries no
     * dedicated approval/effective date — bound on `created_at` (the
     * `DATE_COLUMN_PRIORITY` fallback `existsCheck()` itself would reach
     * for), so an objective written AFTER a past pack's own `$to` cannot
     * evidence it.
     */
    private function objectivesSufficiency(): array
    {
        $total = Objective::query()->where('created_at', '<=', $this->periodEnd)->count();

        if ($total === 0) {
            return ['state' => 'red', 'artefact' => 'No business continuity objective is on file.', 'last_evidenced' => null];
        }

        $unmeasurable = Objective::query()->where('created_at', '<=', $this->periodEnd)
            ->where(fn ($q) => $q->whereNull('target_value')->orWhereNull('target_date'))->count();

        // Code review #3, A5: `target_value`/`target_date` are live content
        // with no dated history — the amber/green branches below, which
        // read THAT content, are current_state; the red branch above
        // (mere existence, bound on `created_at`) is not.
        if ($unmeasurable > 0) {
            return ['state' => 'amber', 'artefact' => $unmeasurable.' of '.$total.' objective(s) have no measurable target or date.', 'last_evidenced' => null, 'current_state' => true, 'current_state_reason' => self::OBJECTIVE_CURRENT_STATE_REASON];
        }

        return ['state' => 'green', 'artefact' => 'Every objective carries a measurable target and a date.', 'last_evidenced' => null, 'current_state' => true, 'current_state_reason' => self::OBJECTIVE_CURRENT_STATE_REASON];
    }

    /**
     * B3 (gate 1 code review #1) / R4 (gate 1 code review #2, spec §3.2 row
     * 5: "Every ROLE in a mandatory curriculum holds a current, assessed
     * record"). Two defects fixed in R4, over the B3 pass:
     *
     *   1. **Coverage, not existence.** The B3 version went green on ONE
     *      current, passed record however many people the curriculum is
     *      actually assigned to — row 5 is a coverage rule, not an
     *      existence check. Measured here against
     *      `TrainingComplianceService::assignedUsers()` — the SAME live
     *      role-holder resolution the training-compliance screen itself
     *      uses (called, never re-derived; that class is a different
     *      engineer's file in this cycle).
     *   2. **Failed vs unassessed, named separately.** `competency_assessed`
     *      now means "assessed AND passed" (the write side's own fix,
     *      `TrainingComplianceService::recordOutcome()`/`assess()`) — a
     *      FAILED assessment carries a non-null `score` with
     *      `competency_assessed = false`, exactly the same as a record that
     *      was never assessed at all. This read tells the two apart by
     *      `score !== null`, and the artefact sentence never blends a
     *      failure into "attended but not yet assessed".
     *
     * Currency is judged AS OF `$this->periodEnd` (R3), not `isPast()`
     * against today — a past-period pack must not report a record as
     * "current" or "expired" based on when the pack happens to be opened.
     *
     * A5: one `TrainingRecord` query PER CURRICULUM (not per assigned
     * person) — `assignedUsers()` itself cannot be joined into SQL (it is a
     * live role resolution across `users`/`roles`, not a stored list), so
     * this is as far as the read can be batched.
     *
     * QA re-gate #9 — two defects:
     *
     *   1. The "latest per assigned user" `TrainingRecord` selection had NO
     *      upper bound on `completed_at` — only the CURRENCY half
     *      (`next_due_date` vs `periodEnd`, R3) was bound. A record entered
     *      TODAY for a user who had none in a past period was still picked
     *      for the past pack, flipping this mandatory clause from red to
     *      green. Fixed with `completed_at <= periodEnd`, upper bound only
     *      (row 5 is validity AS OF `periodEnd`, not activity IN the
     *      period — a record completed before `periodStart` that is still
     *      valid at `periodEnd` must still count, unlike row 6's true
     *      window), null-safe the same way `awarenessSufficiency()`'s
     *      neighbours (`scopeSufficiency()`, `nonconformitySufficiency()`)
     *      already are.
     *   2. The DENOMINATOR — `TrainingComplianceService::assignedUsers()`
     *      (`app/Services/Bcms/Training/TrainingComplianceService.php:44-60`)
     *      — resolves LIVE role membership (`User::where('is_active', true)
     *      ->whereHas('roles', ...)`) against the curriculum's CURRENT
     *      `target_roles`. Neither `is_active` nor a role assignment nor
     *      `target_roles` carries any queryable history here, so who
     *      counts as "assigned" is irreducibly current state — the SAME
     *      shape as `raciSufficiency()`/`biaSufficiency()`/
     *      `strategySufficiency()`/`evaluationSufficiency()`'s own Process
     *      catalogue denominator. `current_state: true` on every branch.
     */
    private function competenceSufficiency(): array
    {
        $curricula = TrainingCurriculum::query()
            ->where('requires_assessment', true)
            ->where('is_mandatory', true)
            ->get();

        if ($curricula->isEmpty()) {
            return ['state' => 'red', 'artefact' => 'No mandatory, assessed curriculum is configured.', 'last_evidenced' => null, 'current_state' => true];
        }

        $totalAssigned = 0;
        $current = 0;
        $failed = 0;
        $unassessed = 0;
        $expired = 0;
        $latestPass = null;

        foreach ($curricula as $curriculum) {
            $assigned = $this->training->assignedUsers($curriculum);

            if ($assigned->isEmpty()) {
                continue;
            }

            $totalAssigned += $assigned->count();

            $latestPerUser = TrainingRecord::query()
                ->where('curriculum_id', $curriculum->getKey())
                ->whereIn('user_id', $assigned->pluck('id'))
                ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '<=', $this->periodEnd))
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->get()
                ->groupBy('user_id')
                ->map(fn (Collection $records) => $records->first());

            foreach ($assigned as $user) {
                /** @var ?TrainingRecord $record */
                $record = $latestPerUser->get($user->getKey());

                if ($record === null || $record->score === null) {
                    $unassessed++;

                    continue;
                }

                $isCurrent = $record->next_due_date === null || ! $record->next_due_date->lt($this->periodEnd);

                if (! $record->competency_assessed) {
                    $failed++;
                } elseif (! $isCurrent) {
                    $expired++;
                } else {
                    $current++;

                    if ($record->completed_at !== null && ($latestPass === null || $record->completed_at->gt($latestPass))) {
                        $latestPass = $record->completed_at;
                    }
                }
            }
        }

        if ($totalAssigned === 0) {
            return ['state' => 'red', 'artefact' => 'No one is currently assigned to a mandatory, assessed curriculum.', 'last_evidenced' => null, 'current_state' => true];
        }

        $gaps = [];
        if ($failed > 0) {
            $gaps[] = $failed.' failed assessment(s)';
        }
        if ($unassessed > 0) {
            $gaps[] = $unassessed.' not yet assessed';
        }
        if ($expired > 0) {
            $gaps[] = $expired.' expired, needing re-assessment';
        }
        $gapSentence = $gaps === [] ? '' : ' ('.implode('; ', $gaps).')';

        if ($current === 0) {
            return ['state' => 'red', 'artefact' => '0 of '.$totalAssigned.' assigned person(s) hold a current, passed competency record'.$gapSentence.'.', 'last_evidenced' => null, 'current_state' => true];
        }

        if ($current < $totalAssigned) {
            return ['state' => 'amber', 'artefact' => $current.' of '.$totalAssigned.' assigned person(s) hold a current, passed competency record'.$gapSentence.'.', 'last_evidenced' => $latestPass?->toDateString(), 'current_state' => true];
        }

        return ['state' => 'green', 'artefact' => 'All '.$totalAssigned.' assigned person(s) hold a current, passed competency record.', 'last_evidenced' => $latestPass?->toDateString(), 'current_state' => true];
    }

    /**
     * QA re-gate #8: unbounded and unlabelled. Spec §3.2 row 6's own
     * sufficiency rule reads "A campaign IN THE PERIOD with reach
     * reported" — the SAME true-window framing row 7
     * (`communicationSufficiency()`, immediately above) already uses, so
     * both pulls here (attendance AND the awareness alert) are bound
     * two-sided to `[periodStart(), periodEnd]`, not `<= periodEnd` alone —
     * an attendance record or a campaign dispatched before the period
     * started is no more "in the period" than one dispatched after it
     * ended.
     */
    private function awarenessSufficiency(): array
    {
        $attendance = \App\Models\Bcms\TrainingRecord::query()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$this->periodStart(), $this->periodEnd])
            ->count();

        $awarenessAlert = \App\Models\Bcms\Alert::query()
            ->whereHas('template', fn ($q) => $q->where('category', 'awareness'))
            ->whereNotNull('dispatched_at')
            ->whereBetween('dispatched_at', [$this->periodStart(), $this->periodEnd])
            ->latest('dispatched_at')
            ->orderByDesc('id')
            ->first();

        if ($attendance === 0 && $awarenessAlert === null) {
            return ['state' => 'red', 'artefact' => 'No awareness attendance record and no awareness campaign has been sent.', 'last_evidenced' => null];
        }

        if ($awarenessAlert !== null) {
            $acknowledged = \App\Models\Bcms\AlertRecipient::query()
                ->where('alert_id', $awarenessAlert->getKey())
                ->whereNotNull('acknowledged_at')
                ->exists();

            if (! $acknowledged) {
                return ['state' => 'amber', 'artefact' => 'An awareness campaign reached '.((int) $awarenessAlert->recipient_count).' recipient(s); no engagement (acknowledgement) data is on file.', 'last_evidenced' => $awarenessAlert->dispatched_at?->toDateString()];
            }

            return ['state' => 'green', 'artefact' => 'An awareness campaign was sent and engagement was recorded.', 'last_evidenced' => $awarenessAlert->dispatched_at?->toDateString()];
        }

        return ['state' => 'amber', 'artefact' => $attendance.' attendance record(s) on file; no awareness campaign reach/engagement evidence.', 'last_evidenced' => null];
    }

    /**
     * The lower bound for the three TRUE "in the period" rules (spec §3.2
     * rows 7, 13, 17) — `$this->periodStart` when `build()` was given one
     * (an evidence pack's own `$from`), else the start of `$this->periodEnd`'s
     * own calendar year (the live screen's carried-forward "current year"
     * behaviour).
     */
    private function periodStart(): Carbon
    {
        return $this->periodStart ?? $this->periodEnd->copy()->startOfYear();
    }

    /**
     * B2/R3 (gate 1 code review #1/#2): spec §3.2 row 7 asks for "a
     * completed cascade or alert IN THE PERIOD" — a true window, now bounded
     * on BOTH sides (`whereYear($to)` alone had no lower bound, so a row
     * from any point in that calendar year counted, and the year itself was
     * always `$to`'s, ignoring `$from` entirely).
     */
    private function communicationSufficiency(): array
    {
        $delivery = \App\Models\Bcms\NotificationDelivery::query()
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$this->periodStart(), $this->periodEnd])
            ->latest('delivered_at')->orderByDesc('id')->first();

        if ($delivery === null) {
            return ['state' => 'red', 'artefact' => 'No alert or cascade has recorded per-recipient delivery evidence in the period.', 'last_evidenced' => null];
        }

        return ['state' => 'green', 'artefact' => 'Per-recipient delivery evidence is on file from a completed alert or cascade in the period.', 'last_evidenced' => $delivery->delivered_at?->toDateString()];
    }

    /**
     * QA re-gate #11 item 1: `where('status', 'approved')` carried the same
     * as-of-`periodEnd` flaw `scopeSufficiency()`'s own Programme read did
     * — `PlanService::supersede()` archives a plan's OLD version without
     * touching its `approved_at`. `whereNotNull('approved_at')` (status
     * irrelevant) plus `existsCheck()`'s own `approved_at <= periodEnd`
     * bound is the correct as-of-the-period answer.
     */
    private function documentedInformationSufficiency(): array
    {
        return $this->existsCheck(Plan::query()->whereNotNull('approved_at'), 'An approved, version-controlled plan');
    }

    /**
     * QA re-gate #10: `where('status', 'approved')` carried the same
     * as-of-`periodEnd` flaw `scopeSufficiency()`'s own read did — a
     * programme approved within the period but since moved to `active`
     * would have been excluded. `whereNotNull('approved_at')` (status
     * irrelevant) plus `existsCheck()`'s own `approved_at <= periodEnd`
     * bound is the same two-part answer `programmeAsOf()`'s primary tier
     * gives.
     */
    private function operationalPlanningSufficiency(): array
    {
        return $this->existsCheck(Programme::query()->whereNotNull('approved_at'), 'An approved BCMS programme');
    }

    /**
     * R3 follow-up (gate 1 code review #2): the denominator (which
     * processes are "active" right now) has no history and cannot be
     * reconstructed for a past period — `current_state: true` on every
     * branch. `BiaAssessment` itself IS bound to `approved_at <= periodEnd`,
     * so an assessment approved after a past pack's own `$to` cannot
     * evidence it.
     */
    private function biaSufficiency(): array
    {
        $active = Process::query()->where('status', 'active')->pluck('id');

        if ($active->isEmpty()) {
            return ['state' => 'red', 'artefact' => 'No active process is on the catalogue to assess.', 'last_evidenced' => null, 'current_state' => true];
        }

        $withApprovedBia = BiaAssessment::query()
            ->whereIn('process_id', $active)
            ->where('status', 'approved')
            ->where('approved_at', '<=', $this->periodEnd)
            ->distinct()
            ->count('process_id');

        $missing = $active->count() - $withApprovedBia;
        $latest = BiaAssessment::query()->where('status', 'approved')
            ->where('approved_at', '<=', $this->periodEnd)
            ->latest('approved_at')->orderByDesc('id')->first();

        if ($missing > 0) {
            return ['state' => 'red', 'artefact' => $missing.' of '.$active->count().' process(es) have no approved BIA.', 'last_evidenced' => $latest?->approved_at?->toDateString(), 'current_state' => true];
        }

        return ['state' => 'green', 'artefact' => 'Every in-scope process has an approved BIA.', 'last_evidenced' => $latest?->approved_at?->toDateString(), 'current_state' => true];
    }

    private function riskAssessmentSufficiency(): array
    {
        return $this->existsCheck(BiaAssessment::query()->where('status', 'approved'), 'A completed risk assessment recorded against an approved BIA');
    }

    /**
     * R3 follow-up (gate 1 code review #2): the denominator (which
     * processes are Tier 1/critical right now) has no history and cannot
     * be reconstructed for a past period — `current_state: true` on every
     * branch.
     *
     * QA re-gate #11 item 2: NEVER `is_selected` — `StrategyService::select()`
     * (:106-107) flips a SIBLING strategy's `is_selected` to `false` the
     * instant a different one is chosen for the same process, with no trace
     * of when this one stopped being selected. `approval_status =
     * 'approved' AND approved_at <= periodEnd` survives that entirely
     * (neither column is touched by `select()`), grouped by `process_id`
     * exactly as `$withSelected` already was.
     */
    private function strategySufficiency(): array
    {
        $critical = Process::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('criticality_tier', 1)->orWhere('is_critical_service', true))
            ->pluck('id');

        if ($critical->isEmpty()) {
            return ['state' => 'red', 'artefact' => 'No process is marked Tier 1 or critical to hold a strategy against.', 'last_evidenced' => null, 'current_state' => true];
        }

        $withSelected = Strategy::query()
            ->whereIn('process_id', $critical)
            ->where('approval_status', 'approved')
            ->where('approved_at', '<=', $this->periodEnd)
            ->whereNotNull('selection_rationale')
            ->distinct()
            ->count('process_id');

        $missing = $critical->count() - $withSelected;

        if ($missing > 0) {
            return ['state' => 'red', 'artefact' => $missing.' of '.$critical->count().' critical process(es) have no selected, justified strategy.', 'last_evidenced' => null, 'current_state' => true];
        }

        return ['state' => 'green', 'artefact' => 'Every critical process has a selected strategy with its rationale.', 'last_evidenced' => null, 'current_state' => true];
    }

    /**
     * R3 follow-up (gate 1 code review #2): approved plans are now bound to
     * `approved_at <= periodEnd`, and the reader acknowledgement to
     * `attested_at <= periodEnd` — a plan approved, or a read acknowledged,
     * after a past pack's own `$to` cannot evidence it.
     *
     * QA re-gate #11 item 1: NEVER `status` — `PlanService::supersede()`
     * archives the OLD approved version's `status` without touching its
     * `approved_at`, so it must still count for a period it governed.
     * DEDUPED BY LINEAGE, so a plan family with TWO versions both approved
     * by `periodEnd` (v1, then v2 superseding it, both approved before this
     * pack's own `$to`) is not double-counted: the family key is the ROOT
     * of the `supersedes_plan_id` chain (each version points at the one it
     * replaces; `bcms_plans` has no separate "family id" column). Per
     * family, the LATEST version with `approved_at <= periodEnd` is kept —
     * `approved_at` desc, `id` desc, the same tiebreak convention every
     * other "latest of" read in this file uses — so the family's content is
     * the version that was actually current as of the period, not
     * necessarily the newest version that exists today.
     */
    private function plansSufficiency(): array
    {
        // Code review #3, A7: only `id` (the family-reduce tiebreak and
        // `$approved->pluck('id')` below) and `approved_at` (the family
        // reduce and `last_evidenced`) are ever read here — `get()` with no
        // column list also loads `content`, the frozen plan body JSON,
        // which this sufficiency check never touches.
        $eligible = Plan::query()
            ->whereNotNull('approved_at')
            ->where('approved_at', '<=', $this->periodEnd)
            ->get(['id', 'approved_at']);

        if ($eligible->isEmpty()) {
            return ['state' => 'red', 'artefact' => 'No approved continuity plan is on file.', 'last_evidenced' => null];
        }

        $approved = $this->latestPerPlanFamily($eligible);

        $acknowledged = PlanAttestation::query()
            ->whereIn('plan_id', $approved->pluck('id'))
            ->where('attestation_type', 'read')
            ->where('attested_at', '<=', $this->periodEnd)
            ->exists();

        if (! $acknowledged) {
            return ['state' => 'amber', 'artefact' => $approved->count().' approved plan(s) on file; none has a recorded reader acknowledgement.', 'last_evidenced' => $approved->max('approved_at')?->toDateString()];
        }

        return ['state' => 'green', 'artefact' => $approved->count().' approved plan(s), acknowledged by their readers.', 'last_evidenced' => $approved->max('approved_at')?->toDateString()];
    }

    /**
     * The family root of `$plan`'s own `supersedes_plan_id` chain, walked
     * via `$lineage` (every plan's own `id => supersedes_plan_id`, loaded
     * once so a multi-version family costs no extra queries per hop).
     * `$seen` guards a cyclic chain — never expected, but a loop over
     * corrupt data must terminate, not hang.
     *
     * @param  \Illuminate\Support\Collection<int, ?int>  $lineage
     */
    private function planFamilyRoot(int $planId, Collection $lineage): int
    {
        $seen = [];

        while (($parent = $lineage->get($planId)) !== null && ! isset($seen[$planId])) {
            $seen[$planId] = true;
            $planId = $parent;
        }

        return $planId;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Plan>  $eligible  every
     *                                                               plan already known
     *                                                               to be approved as
     *                                                               of `periodEnd`
     * @return \Illuminate\Support\Collection<int, Plan>
     */
    private function latestPerPlanFamily(Collection $eligible): Collection
    {
        $lineage = Plan::query()->pluck('supersedes_plan_id', 'id');

        return $eligible
            ->groupBy(fn (Plan $p) => $this->planFamilyRoot($p->getKey(), $lineage))
            ->map(function (Collection $versions) {
                return $versions->reduce(function (?Plan $best, Plan $candidate) {
                    if ($best === null) {
                        return $candidate;
                    }

                    if ($candidate->approved_at->gt($best->approved_at)) {
                        return $candidate;
                    }

                    if ($candidate->approved_at->eq($best->approved_at) && $candidate->getKey() > $best->getKey()) {
                        return $candidate;
                    }

                    return $best;
                });
            })
            ->values();
    }

    /**
     * B2 (gate 1 code review #1): spec §3.2 row 11 asks for "a failback
     * tested IN THE CYCLE — not only a failover", and the previous version
     * found the latest failback of ANY age and called it "tested in the
     * current cycle" regardless. No per-check DR cadence is read at this
     * check's granularity (it is not scoped to one `DrSystem`, whose own
     * cadence is `next_test_due`), so "the cycle" is the twelve months
     * ending `$this->periodEnd` — the same annual cadence this file's other
     * yearly programmes (`Programme`, `ExerciseProgramme`) already assume.
     */
    private function recoverySufficiency(): array
    {
        $cycleStart = $this->periodEnd->copy()->subMonths(12);

        $failback = DrTest::query()->where('test_type', 'failback')
            ->where('test_date', '>=', $cycleStart)
            ->where('test_date', '<=', $this->periodEnd)
            ->latest('test_date')->orderByDesc('id')->first();

        if ($failback === null) {
            $anyDrTest = DrTest::query()->where('test_date', '>=', $cycleStart)->where('test_date', '<=', $this->periodEnd)->exists();

            return $anyDrTest
                ? ['state' => 'amber', 'artefact' => 'DR tests are on file in the last twelve months, but none is a failback — only a failover has been tested.', 'last_evidenced' => null]
                : ['state' => 'red', 'artefact' => 'No DR recovery test of any kind is on file in the last twelve months.', 'last_evidenced' => null];
        }

        return ['state' => 'green', 'artefact' => 'A failback has been tested in the last twelve months.', 'last_evidenced' => $failback->test_date?->toDateString()];
    }

    /**
     * R3 (gate 1 code review #2): used `now()->year` — a past-period pack
     * would still be scored against the CURRENT year's programme. The
     * programme year that matters is `$this->periodEnd`'s own.
     */
    private function exerciseProgrammeSufficiency(): array
    {
        $year = $this->periodEnd->year;

        // No unique constraint on (organization_id, year) — two approved
        // programmes for the same year is possible (one superseding the
        // other mid-year), so "the current programme" means the one most
        // recently approved AS OF $periodEnd, tiebroken on id for a
        // same-instant approval.
        $programme = ExerciseProgramme::query()->where('year', $year)->where('status', 'approved')
            ->where('approved_at', '<=', $this->periodEnd)
            ->orderByDesc('approved_at')->orderByDesc('id')->first();

        if ($programme === null || (int) $programme->total_planned === 0) {
            return ['state' => 'red', 'artefact' => 'No approved exercise programme with any planned exercises exists for '.$year.'.', 'last_evidenced' => null];
        }

        return ['state' => 'green', 'artefact' => 'Exercise programme for '.$year.' approved with '.$programme->total_planned.' exercise(s) planned.', 'last_evidenced' => $programme->approved_at?->toDateString()];
    }

    private function exerciseSufficiency(): array
    {
        return $this->existsCheck(ExerciseOccurrence::query(), 'A scheduled exercise occurrence with a defined scenario');
    }

    /**
     * B2/R3 (gate 1 code review #1/#2): spec §3.2 row 13 asks for "every
     * completed occurrence IN THE PERIOD" — a true window, now bounded on
     * BOTH sides (was `whereYear($to)`, no lower bound, `$from` ignored
     * entirely).
     *
     * Code review #3, D2: `status = 'completed'` is CURRENT state, not a
     * dated fact — `OccurrenceExecutionService::complete()` (:80-84)
     * stamps `actual_end` in the SAME write that sets `status`, so
     * `actual_end` is the real, bounded, "completed AS OF" answer:
     * `whereNotNull('actual_end')->where('actual_end', '<=', periodEnd)`.
     * The `scheduled_date` window is unchanged. With this, the red "no
     * occurrence completed" branch below needs no `current_state` label —
     * it is a genuine, reconstructable fact for the period.
     */
    private function aarSufficiency(): array
    {
        $completed = ExerciseOccurrence::query()
            ->whereNotNull('actual_end')->where('actual_end', '<=', $this->periodEnd)
            ->whereBetween('scheduled_date', [$this->periodStart(), $this->periodEnd])
            ->pluck('id');

        if ($completed->isEmpty()) {
            return ['state' => 'red', 'artefact' => 'No exercise occurrence has completed in the period.', 'last_evidenced' => null];
        }

        // QA re-gate #9 (same pattern as competenceSufficiency()): occurrence
        // MEMBERSHIP was bound to the period, but the AAR's own `approved_at`
        // was not — an occurrence completed inside a past pack's period
        // could still have its AAR finalised TODAY, wrongly counting toward
        // that past pack. Bound here too, null-safe.
        $finalised = Aar::query()->whereIn('occurrence_id', $completed)->where('status', 'final')
            ->where(fn ($q) => $q->whereNull('approved_at')->orWhere('approved_at', '<=', $this->periodEnd))
            ->count();
        $missing = $completed->count() - $finalised;
        // Scoped to the same completed-occurrence set as $finalised above —
        // a finalised PIR (`incident_id` set, `occurrence_id` null) must
        // never put its own date beside this clause's red/green cell.
        $latest = Aar::query()->whereIn('occurrence_id', $completed)->where('status', 'final')
            ->where(fn ($q) => $q->whereNull('approved_at')->orWhere('approved_at', '<=', $this->periodEnd))
            ->orderByDesc('approved_at')->orderByDesc('id')->first();

        // QA re-gate #11 item 3: both branches below read `bcms_aars.status
        // = 'final'`, which `AarService::reopen()` can clear with no trace
        // — `current_state: true` on both, unlike the "no occurrence
        // completed" red branch above, which never reads Aar at all.
        if ($missing > 0) {
            return ['state' => 'red', 'artefact' => $missing.' of '.$completed->count().' completed occurrence(s) in the period have no finalised AAR.', 'last_evidenced' => $latest?->approved_at?->toDateString(), 'current_state' => true, 'current_state_reason' => self::AAR_CURRENT_STATE_REASON];
        }

        return ['state' => 'green', 'artefact' => 'All '.$completed->count().' completed occurrence(s) in the period have a finalised AAR.', 'last_evidenced' => $latest?->approved_at?->toDateString(), 'current_state' => true, 'current_state_reason' => self::AAR_CURRENT_STATE_REASON];
    }

    /**
     * ISO 22301 8.6 — the compliance ruling in `phase-11-notes.md`
     * ("Compliance ruling — PIRs and clause 8.6"), implemented verbatim:
     *
     * "8.6 is red while there is no active Tier-1 process, or while
     * `LadderAdvisor` would raise `tier1_never_drilled` or `tier1_stale` for
     * any active Tier-1 process (read through `coverageMatrix()`, using the
     * same test as `tierOneOnlyWalkedThrough()`); otherwise amber while any
     * incident with `status = closed` and `is_exercise = false` has no
     * `bcms_aars` row with that `incident_id` and `status = final`;
     * otherwise green. A PIR can hold 8.6 at amber but can never lift it, and
     * `last_evidenced` is never a PIR's date."
     *
     * "Tier 1" is `criticality_tier = 1` AND `status = active` — the
     * ruling's own point 3: not `is_critical_service` too, because
     * `LadderAdvisor` does not use it either, and two definitions of "Tier 1"
     * in one module is how the advisor and the matrix come to disagree.
     */
    /**
     * R3 follow-up (gate 1 code review #2): the opening RED check's own
     * denominator (which processes are active Tier 1 right now) has no
     * history and cannot be reconstructed for a past period —
     * `current_state: true` on every branch, on top of the compliance
     * ruling's own logic (unchanged). `tier1LadderGap()` already measures
     * staleness against `$this->periodEnd`. The closed-incident/PIR reads
     * below ARE bound, as far as the schema allows (`closed_at`/
     * `approved_at <= periodEnd`, null-safe) — a real incident closed, or a
     * PIR approved, after a past pack's own `$to` cannot evidence it.
     */
    private function evaluationSufficiency(): array
    {
        $tier1 = Process::query()->where('status', 'active')->where('criticality_tier', 1)->orderBy('code')->get();

        if ($tier1->isEmpty()) {
            return ['state' => 'red', 'artefact' => 'No active Tier-1 process is on the catalogue to evaluate against the exercise ladder.', 'last_evidenced' => null, 'current_state' => true];
        }

        $matrix = $this->ladder->coverageMatrix($tier1);

        foreach ($matrix as $row) {
            $rule = $this->tier1LadderGap($row);

            if ($rule !== null) {
                return ['state' => 'red', 'artefact' => $row['name'].' ('.$row['code'].') '.$rule.'.', 'last_evidenced' => null, 'current_state' => true];
            }
        }

        // Coverage holds. Amber while any REAL (non-exercise) closed
        // incident has no finalised PIR against it — a PIR can hold this at
        // amber but never lift it to green, and never supplies
        // `last_evidenced` (ruling point 5: that comes from the exercise arm
        // only, below).
        $closedRealIncidentIds = Incident::query()
            ->where('status', 'closed')->where('is_exercise', false)
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '<=', $this->periodEnd))
            ->pluck('id');

        $withFinalPir = Aar::query()
            ->whereIn('incident_id', $closedRealIncidentIds)
            ->whereNotNull('incident_id')
            ->where('status', 'final')
            ->where(fn ($q) => $q->whereNull('approved_at')->orWhere('approved_at', '<=', $this->periodEnd))
            ->pluck('incident_id');

        $missingPir = $closedRealIncidentIds->diff($withFinalPir)->values();

        $lastEvidenced = collect($matrix)
            ->flatMap(fn (array $row) => collect($row['levels'])
                ->filter(fn (array $level, string $code) => LadderLevel::from($code)->isAtOrAbove(LadderLevel::Drill) && $level['successful'] > 0)
                ->pluck('last_at'))
            ->filter()
            ->max();

        // QA re-gate #11 item 3: this arm — amber and green alike — reads
        // `bcms_aars.status = 'final'` for the PIR check; the red branches
        // above are already `current_state: true` for the Process/Tier-1
        // catalogue reason and keep that generic label, but these two carry
        // the AAR-specific reason too, since a reopened PIR is what would
        // actually move them.
        if ($missingPir->isNotEmpty()) {
            $named = Incident::query()->whereIn('id', $missingPir)->orderBy('reference')->pluck('reference')->implode(', ');

            return ['state' => 'amber', 'artefact' => $missingPir->count().' of '.$closedRealIncidentIds->count().' closed incident(s) have no finalised post-incident review: '.$named.'.', 'last_evidenced' => null, 'current_state' => true, 'current_state_reason' => self::AAR_CURRENT_STATE_REASON];
        }

        return ['state' => 'green', 'artefact' => 'Every active Tier-1 process has been proven above a walkthrough, and every closed incident has a finalised post-incident review.', 'last_evidenced' => $lastEvidenced, 'current_state' => true, 'current_state_reason' => self::AAR_CURRENT_STATE_REASON];
    }

    /**
     * Mirrors `LadderAdvisor::tierOneOnlyWalkedThrough()`'s two rules
     * (`tier1_never_drilled`/`tier1_stale`) over the SAME `coverageMatrix()`
     * row this method is built from. The private method itself needs a
     * hypothetical scheduled exercise's own level to evaluate, which this
     * read has none of, so the rule is restated here against the row shape
     * rather than called directly. `LadderAdvisor::TIER1_STALE_MONTHS` is
     * the one constant, reused rather than a second `18` written here.
     *
     * @param  array<string, mixed>  $row  one `coverageMatrix()` row
     */
    private function tier1LadderGap(array $row): ?string
    {
        $highest = $row['highest_proven'] === null ? null : LadderLevel::from($row['highest_proven']);

        if ($highest === null || ! $highest->isAtOrAbove(LadderLevel::Drill)) {
            return 'has never been exercised above a walkthrough';
        }

        $lastAtDrillOrAbove = collect($row['levels'])
            ->filter(fn (array $level, string $code) => LadderLevel::from($code)->isAtOrAbove(LadderLevel::Drill) && $level['successful'] > 0)
            ->pluck('last_at')
            ->filter()
            ->max();

        if ($lastAtDrillOrAbove !== null
            && Carbon::parse($lastAtDrillOrAbove)->lt($this->periodEnd->copy()->subMonths(LadderAdvisor::TIER1_STALE_MONTHS))) {
            return 'has not been exercised above a walkthrough since '.Carbon::parse($lastAtDrillOrAbove)->format('F Y');
        }

        return null;
    }

    /**
     * B2 (gate 1 code review #1): spec §3.2 row 15 asks for "each KRI [with]
     * a measurement INSIDE ITS OWN FREQUENCY" — the previous version checked
     * only "has ever been measured at all", never the KRI's own
     * `measurement_frequency`. The frequency-to-days mapping matches
     * `App\Services\MyResponsibilitiesService::kriDueDate()`'s own mapping
     * exactly — restated here (that class is a different phase's file) so
     * "quarterly" is not defined twice with two different answers.
     *
     * R3 (gate 1 code review #2): `last_measurement_at` now also carries an
     * UPPER bound against `$this->periodEnd` — without it, a reading dated
     * AFTER a past pack's own `$to` (impossible in real life, but exactly
     * what "insert one row after `$to`" reproducibility test writes) was
     * further from the lower cutoff than `$periodEnd` itself and so read as
     * "within frequency", wrongly moving a pack for a period the reading
     * postdates.
     */
    private function kriSufficiency(): array
    {
        $status = $this->kris->status();
        $linked = collect($status)->where('linked', true);

        if ($linked->isEmpty()) {
            return ['state' => 'red', 'artefact' => 'No resilience KRI has been adopted into the KRI register yet.', 'last_evidenced' => null];
        }

        $kris = KeyRiskIndicator::query()
            ->whereIn('id', $linked->pluck('kri_id'))
            ->get(['id', 'measurement_frequency', 'last_measurement_at'])
            ->keyBy('id');

        $withinFrequency = $linked->filter(function (array $k) use ($kris) {
            $kri = $kris->get($k['kri_id']);

            if ($kri === null || $kri->last_measurement_at === null) {
                return false;
            }

            if ($kri->last_measurement_at->gt($this->periodEnd)) {
                return false;
            }

            return ! $kri->last_measurement_at->lt($this->periodEnd->copy()->subDays($this->kriFrequencyDays($kri->measurement_frequency)));
        });

        if ($withinFrequency->count() < $linked->count()) {
            return ['state' => 'amber', 'artefact' => $withinFrequency->count().' of '.$linked->count().' adopted resilience KRI(s) have a measurement inside their own frequency.', 'last_evidenced' => null];
        }

        return ['state' => 'green', 'artefact' => 'Every adopted resilience KRI has a measurement inside its own frequency.', 'last_evidenced' => null];
    }

    /** frequency string -> days a measurement stays current for. */
    private function kriFrequencyDays(?string $frequency): int
    {
        return match (strtolower((string) $frequency)) {
            'daily' => 1,
            'weekly' => 7,
            'biweekly', 'fortnightly' => 14,
            'monthly' => 30,
            'quarterly' => 91,
            'semi_annual', 'semiannual' => 182,
            'annual', 'annually', 'yearly' => 365,
            default => 30,
        };
    }

    /**
     * 9.2 — never grey, regardless of the obligation register (ADR 0021 §1).
     * State is derived from the latest approved management review's
     * `internal_audit` block, and — for the results row — linked audit
     * findings.
     */
    private function auditProgrammeSufficiency(): array
    {
        $review = $this->latestReviewWithAudit();

        if ($review === null) {
            return [
                'state' => 'red',
                'artefact' => 'No internal audit programme is held in this system; ISO 22301 9.2 is evidenced from the internal audit function\'s own records.',
                'last_evidenced' => null,
            ];
        }

        return [
            'state' => 'amber',
            'artefact' => 'Internal audit referenced in the '.$review->title.', '.$review->held_on?->toDateString().' — see the review.',
            'last_evidenced' => $review->held_on?->toDateString(),
        ];
    }

    private function auditResultsSufficiency(): array
    {
        $review = $this->latestReviewWithAudit();

        $linkedFindings = Finding::query()
            ->where('source', FindingSource::Audit->value)
            ->whereNotNull('erm_issue_id')
            ->where(fn ($q) => $q->whereNull('raised_at')->orWhere('raised_at', '<=', $this->periodEnd))
            ->count();

        if ($linkedFindings > 0) {
            return [
                'state' => 'amber',
                'artefact' => $linkedFindings.' audit finding(s) recorded and linked to an enterprise issue; no audit report is held in this system.',
                'last_evidenced' => null,
            ];
        }

        if ($review !== null) {
            return [
                'state' => 'amber',
                'artefact' => 'Internal audit referenced in the '.$review->title.', '.$review->held_on?->toDateString().' — see the review.',
                'last_evidenced' => $review->held_on?->toDateString(),
            ];
        }

        return [
            'state' => 'red',
            'artefact' => 'No internal audit programme is held in this system; ISO 22301 9.2 is evidenced from the internal audit function\'s own records.',
            'last_evidenced' => null,
        ];
    }

    /**
     * B10/A5 (gate 1 code review #1): called from both
     * `auditProgrammeSufficiency()` (9.2.programme) and
     * `auditResultsSufficiency()` (9.2.results) — memoised so a `build()`
     * runs this read once, not twice.
     */
    private function latestReviewWithAudit(): ?ManagementReview
    {
        return $this->memo('review_with_audit', fn () => $this->computeLatestReviewWithAudit());
    }

    /**
     * R3 follow-up (gate 1 code review #2): bound to `held_on <= periodEnd`
     * — a review held after a past pack's own `$to` cannot evidence it.
     */
    private function computeLatestReviewWithAudit(): ?ManagementReview
    {
        return ManagementReview::query()
            ->where('status', 'approved')
            ->whereNotNull('inputs')
            ->where('held_on', '<=', $this->periodEnd)
            // A defined base order before the in-PHP filter/sort below —
            // `->get()` with no ORDER BY leaves row order to the database,
            // and `sortByDesc()`'s PHP-stable sort only preserves THAT order
            // on a tie, so an undefined base order is still an undefined
            // final order on two reviews `held_on` the same date.
            ->orderBy('id')
            ->get()
            ->filter(fn (ManagementReview $r) => ! empty($r->inputs['internal_audit']['report_reference'] ?? null)
                || ! empty($r->inputs['internal_audit']['conclusion'] ?? null))
            ->sortByDesc(fn (ManagementReview $r) => $r->held_on)
            ->first();
    }

    /**
     * B2 (gate 1 code review #1): spec §3.2 row 17 asks for "a review held IN
     * THE PERIOD" — scoped to `$this->periodEnd`'s own calendar year.
     */
    /**
     * B2/R3 (gate 1 code review #1/#2): spec §3.2 row 17 asks for "a review
     * held IN THE PERIOD" — a true window, now bounded on BOTH sides.
     */
    private function reviewSufficiency(): array
    {
        $review = ManagementReview::query()->where('status', 'approved')
            ->whereBetween('held_on', [$this->periodStart(), $this->periodEnd])
            ->latest('held_on')->orderByDesc('id')->first();

        if ($review === null) {
            return ['state' => 'red', 'artefact' => 'No management review has been approved in the period.', 'last_evidenced' => null];
        }

        if ($review->inputs_captured_at === null || $review->approved_at === null || $review->inputs_captured_at->gt($review->approved_at)) {
            return ['state' => 'amber', 'artefact' => 'A management review exists but its inputs were not captured before approval.', 'last_evidenced' => $review->held_on?->toDateString()];
        }

        return ['state' => 'green', 'artefact' => 'A management review was held, its inputs captured before approval, and it was approved.', 'last_evidenced' => $review->approved_at->toDateString()];
    }

    /**
     * B10 (gate 1 code review #1): `$f->correctiveActions()->count()` inside
     * a `filter()` ran one query PER nonconformity — and, before `sufficiency()`
     * memoised its own result, this whole method ran TWICE per `build()`
     * (two `IsoClauseRef` cases share it), so a tenant with fifty
     * nonconformities issued a hundred count queries for one matrix render.
     * `withCount()` batches it into the one query that already loads the
     * rows.
     */
    /**
     * R3 follow-up (gate 1 code review #2): the nonconformity set is bound
     * to `raised_at <= periodEnd`, its corrective-action count to
     * `created_at <= periodEnd` (`bcms_corrective_actions` has no dedicated
     * "raised at"), and the closed-but-unverified check to
     * `completed_at <= periodEnd` — a nonconformity raised, an action
     * opened, or an action closed after a past pack's own `$to` cannot
     * evidence it.
     */
    private function nonconformitySufficiency(): array
    {
        $nonconformities = Finding::query()
            ->where('classification', FindingClassification::Nonconformity->value)
            ->where(fn ($q) => $q->whereNull('raised_at')->orWhere('raised_at', '<=', $this->periodEnd))
            ->withCount(['correctiveActions' => fn ($q) => $q->where('created_at', '<=', $this->periodEnd)])
            ->get();

        if ($nonconformities->isEmpty()) {
            return ['state' => 'amber', 'artefact' => 'No nonconformity has been raised — either the programme has none, or has not looked hard enough to find one.', 'last_evidenced' => null];
        }

        $withoutAction = $nonconformities->filter(fn (Finding $f) => $f->corrective_actions_count === 0)->count();

        if ($withoutAction > 0) {
            return ['state' => 'red', 'artefact' => $withoutAction.' of '.$nonconformities->count().' nonconformity(ies) have no corrective action.', 'last_evidenced' => null];
        }

        $closedButUnverified = CorrectiveAction::query()
            ->whereIn('finding_id', $nonconformities->pluck('id'))
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $this->periodEnd)
            ->where(fn ($q) => $q->whereNull('verified_by')->orWhereColumn('verified_by', 'owner_id'))
            ->count();

        if ($closedButUnverified > 0) {
            return ['state' => 'red', 'artefact' => $closedButUnverified.' completed corrective action(s) were not verified by somebody other than their owner.', 'last_evidenced' => null];
        }

        return ['state' => 'green', 'artefact' => 'Every nonconformity has a corrective action, and every closed one was verified by somebody other than its owner.', 'last_evidenced' => null];
    }

    private function continualImprovementSufficiency(): array
    {
        return $this->existsCheck(CorrectiveAction::query()->whereNotNull('carried_to_occurrence_id'), 'A corrective action carried onto a later exercise occurrence');
    }

    private function supplyChainSufficiency(): array
    {
        return $this->existsCheck(Dependency::query()->where('dependable_type', DependencyType::Vendors->value), 'A dependency on a third party, evidenced by TPRM\'s own continuity records');
    }

    /**
     * The CBN/DORA crosswalk — phase-11-spec §3.4: a view over ISO rows, not a
     * second taxonomy. `cbn.rcf.csat` always renders amber (ADR 0021 §4 — we
     * do not hold the workbook). Every other CBN/BOFIA/NDPA row takes the
     * worst state of the ISO rows it is answered by.
     */
    private function crosswalk(IsoClauseRef $ref): array
    {
        if ($ref === IsoClauseRef::Cbn_rcf_csat) {
            return ['state' => 'amber', 'artefact' => 'Pre-fill available once you upload this year\'s CSAT workbook.', 'last_evidenced' => null];
        }

        $answeredBy = match ($ref) {
            IsoClauseRef::Cbn_rcf_bcdr, IsoClauseRef::Cbn_ob_dr_test => [IsoClauseRef::Iso22301_8_4_5],
            IsoClauseRef::Cbn_rcf_incident => [IsoClauseRef::Iso22320_incident],
            IsoClauseRef::Cbn_rcf_drills, IsoClauseRef::Cbn_ob_failover => [IsoClauseRef::Iso22301_8_5_report],
            IsoClauseRef::Cbn_ob_threshold => [IsoClauseRef::Iso22301_8_4_5],
            IsoClauseRef::Cbn_psb_bcms, IsoClauseRef::Bofia_continuity => [IsoClauseRef::Iso22301_8_4_4],
            IsoClauseRef::Cbn_cg_board => [IsoClauseRef::Iso22301_9_3_results],
            default => [],
        };

        if ($answeredBy === []) {
            return ['state' => 'amber', 'artefact' => 'Evidenced by reference to the institution\'s own records for this obligation.', 'last_evidenced' => null];
        }

        $worst = 'green';
        $order = ['grey' => 0, 'green' => 1, 'amber' => 2, 'red' => 3];

        foreach ($answeredBy as $answering) {
            $state = $this->sufficiency($answering)['state'];

            if ($order[$state] > $order[$worst]) {
                $worst = $state;
            }
        }

        $names = implode(', ', array_map(fn (IsoClauseRef $r) => $r->value, $answeredBy));

        return ['state' => $worst, 'artefact' => 'Answered by: '.$names.'.', 'last_evidenced' => null];
    }

    /**
     * The date column this row's currency is judged by, in priority order —
     * shared between the UPPER BOUND `existsCheck()` applies (R3) and the
     * `last_evidenced` date it has always displayed, so the two can never
     * disagree about which date a row "counts as".
     */
    /**
     * Code review #3, A1: `bestDateColumn()` used to try `Schema::hasColumn()`
     * against a priority list for EVERY `existsCheck()` call — roughly 40
     * `information_schema` round trips per `build()`. Replaced with this
     * static, REVIEWED map — one entry per model this file's `existsCheck()`
     * calls have ever used, each confirmed to reproduce the EXACT column
     * `Schema::hasColumn()` picked before, by checking each model's own
     * migration against the OLD priority order (`approved_at`, `carried_at`,
     * `completed_at`, `declared_at`, `detected_at`, `scheduled_date`,
     * `created_at`) — no behaviour change, only the lookup mechanism. The
     * full before/after mapping is in `docs/bcms/phase-11-notes.md`.
     *
     * `ExerciseOccurrence` stays `scheduled_date` here DELIBERATELY, even
     * though `Iso22398_ladder` (immediately above in `computeSufficiency()`)
     * needs `actual_end` instead — the SAME model answers two DIFFERENT
     * questions at two call sites (`Iso22398_exercise_design` asks whether
     * ANY occurrence with a scenario exists, `Iso22398_ladder` asks whether
     * a COMPLETED one does), so one static per-model column cannot serve
     * both; `Iso22398_ladder`'s own query pre-filters `actual_end` directly,
     * explicitly, rather than through this map.
     */
    private const DATE_COLUMN_BY_MODEL = [
        BiaAssessment::class => 'approved_at',
        Incident::class => 'declared_at',
        Strategy::class => 'approved_at',
        ExerciseOccurrence::class => 'scheduled_date',
        Aar::class => 'approved_at',
        Plan::class => 'approved_at',
        Programme::class => 'approved_at',
        CorrectiveAction::class => 'carried_at',
        Dependency::class => 'created_at',
    ];

    /**
     * QA re-gate #11 item 3 — a design RULING, not a bug fix.
     * `AarService::reopen()` sets `status` back to `draft` AND NULLS
     * `approved_at` — no column anywhere holds the fact "this AAR was once
     * finalised". The schema is frozen; an audit-log reconstruction of the
     * original finalisation date is out of scope for this phase (booked in
     * `docs/bcms/phase-11-notes.md` as a named follow-up: a stamped-once
     * `first_finalised_at`, needing its own ADR and a schema-freeze
     * exception). Every row whose state depends on `bcms_aars.status =
     * 'final'`/`approved_at` therefore reads CURRENT AAR state, not
     * reconstructed for a past period — an AAR reopened after `periodEnd`
     * is invisible to a past pack exactly as if it had never been
     * finalised, even though it genuinely was, as of that date.
     */
    private const AAR_CURRENT_STATE_REASON = 'AAR finalisation is read as currently recorded: an AAR reopened after '
        .'the period is not counted until re-finalised.';

    /**
     * Code review #3, A5: `bcms_objectives.target_value`/`target_date` are
     * LIVE, editable fields with no dated history of their own — the row's
     * EXISTENCE is bound (`created_at <= periodEnd`), but a target could
     * have been changed at any point after the period, and this read would
     * show today's value regardless.
     */
    private const OBJECTIVE_CURRENT_STATE_REASON = 'Objective targets are shown as currently recorded; '
        .'bcms_objectives carries no dated history of when a target value or date was last changed.';

    /**
     * Code review #3, A5: `bcms_programme_scope.rationale` is the same
     * shape — the ROW's existence is bound (`created_at <= periodEnd`, D2),
     * but the rationale TEXT itself (present or not) could have been added
     * or edited after the period with no trace.
     */
    private const SCOPE_RATIONALE_CURRENT_STATE_REASON = 'Exclusion rationale is shown as currently recorded; '
        .'bcms_programme_scope carries no dated history of when a rationale was last added or changed.';

    /**
     * Code review #4, E2: used to return `null` for an unmapped model, and
     * `existsCheck()` then silently applied NO bound and NO label — a new
     * `existsCheck()` caller added later, for a model nobody had reviewed
     * a date column for, would fail open with no signal at all. Throws
     * instead, naming the model. If a model is ever genuinely meant to
     * stay unbounded (no dated column exists to bound it by), it needs an
     * EXPLICIT `DATE_COLUMN_BY_MODEL` entry that forces a current-state
     * reason via `existsCheck()`'s own `$currentStateReason` parameter —
     * not a silent `null`. None of the 9 current callers needs one today.
     */
    private function bestDateColumn(\Illuminate\Database\Eloquent\Model $model): string
    {
        return self::DATE_COLUMN_BY_MODEL[$model::class] ?? throw new \LogicException(
            'No reviewed date column is mapped for ['.$model::class.'] in DATE_COLUMN_BY_MODEL. '
            .'existsCheck() must never silently apply no bound and no label — add an entry naming the correct '
            .'governing date column, or, if the model is genuinely current-state-only, an entry that forces a '
            .'current-state reason via existsCheck()\'s own $currentStateReason parameter.'
        );
    }

    /**
     * R3 (gate 1 code review #2): every `existsCheck()`-driven row was
     * entirely unbounded — a Plan approved next month, or a BIA approved the
     * day AFTER a past pack's own `$to`, still counted toward it, which is
     * one reason the pack did not reproduce for its stated period. An
     * existence/current-state row ("an approved plan is on file") is judged
     * AS OF `$this->periodEnd` — an UPPER bound only, never a lower one: a
     * plan approved three years before the period is still valid, current
     * evidence for it, and a "was it approved DURING the window" reading
     * would wrongly make old-but-still-valid evidence disappear from a
     * report about last month. Where the model carries none of
     * `DATE_COLUMN_PRIORITY`'s columns, the read is genuinely unbounded
     * current state and stays that way (documented in
     * `docs/bcms/phase-11-notes.md`'s per-section table).
     */
    /**
     * QA re-gate #11 item 3 (AAR reopening, see `AAR_CURRENT_STATE_REASON`'s
     * own docblock): `$currentStateReason`, when given, ALSO marks the row
     * `current_state: true` and carries a per-row reason string — an
     * OPTIONAL extension, default null, so every other caller of this
     * generic helper (documented info, operational planning, risk
     * assessment, exercise design/ladder, crisis, incident response, supply
     * chain, continual improvement, 22317/22331 strategy) is unaffected.
     */
    private function existsCheck(\Illuminate\Database\Eloquent\Builder $query, string $noun, ?string $currentStateReason = null): array
    {
        // E2: `bestDateColumn()` now THROWS for an unmapped model rather
        // than returning null, so `$column` is always a real, reviewed
        // column here — the `null` branches this method used to carry for
        // "no column found" are gone with it.
        $column = $this->bestDateColumn($query->getModel());

        $query->where($query->qualifyColumn($column), '<=', $this->periodEnd);

        // `latest()` alone (ORDER BY created_at DESC) is not deterministic on
        // a tie — two rows created in the same second, which a seeded test
        // fixture reaches easily, leave the DB free to return either one on
        // a re-run. The primary key is a real, always-present tiebreaker.
        $found = $query->latest($column)->orderByDesc($query->getModel()->getKeyName())->first();

        if ($found === null) {
            return $this->withCurrentState(['state' => 'red', 'artefact' => $noun.' is not on file.', 'last_evidenced' => null], $currentStateReason);
        }

        $date = $found->getAttribute($column);
        $date = $date instanceof \Illuminate\Support\Carbon ? $date->toDateString() : ($date === null ? null : (string) $date);

        return $this->withCurrentState(['state' => 'green', 'artefact' => $noun.' is on file.', 'last_evidenced' => $date], $currentStateReason);
    }

    /**
     * @param  array{state: string, artefact: string, last_evidenced: ?string}  $row
     * @return array{state: string, artefact: string, last_evidenced: ?string}
     */
    private function withCurrentState(array $row, ?string $reason): array
    {
        if ($reason === null) {
            return $row;
        }

        return $row + ['current_state' => true, 'current_state_reason' => $reason];
    }
}
