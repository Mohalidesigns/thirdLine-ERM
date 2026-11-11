<?php

namespace App\Models\Sm;

use App\Models\BusinessUnit;
use App\Models\FxRate;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskTaxonomy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One operational-risk scenario theme — the aggregate root of Scenario Modelling
 * & Analysis (ADR 0027 D4, Amendment 1 §A1.2).
 *
 * THE WORKBOOK IS THE SPECIFICATION, NOT AN INSPIRATION (D1). Every vocabulary
 * below is verbatim from `plans/scenario-modelling/VOCABULARY-EXTRACTION.md` as
 * reconciled by Amendment 1 §A1.8, because Phase 5's Excel export has to
 * round-trip a customer's own signed-off strings. Where the plan's prose and the
 * extraction disagreed, the extraction won and §A1.8 records the divergence.
 *
 * THE CONSTS ARE THE AUTHORITY FOR THE VALUE SET; `config/sm.php` IS THE
 * AUTHORITY FOR THE WORDING. Addendum-3 reversed §A1.8's refusal of a config
 * file for wording only: a tenant may rename "External Salesforce /
 * Distribution" without a front-end release, but cannot make the database hold a
 * value no code knows. `Sm/ConfigMatchesFrozenVocabularyTest` asserts the split.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $reference
 * @property string $name
 * @property string $nature
 * @property string $status
 * @property int|null $risk_id
 * @property int|null $risk_taxonomy_node_id
 * @property int|null $business_unit_id
 * @property string|null $weighting
 * @property string $currency
 * @property int|null $fx_rate_id
 * @property string|null $fx_rate
 * @property \Illuminate\Support\Carbon|null $fx_rate_date
 * @property \Illuminate\Support\Carbon|null $designed_on
 * @property \Illuminate\Support\Carbon|null $validated_on
 * @property \Illuminate\Support\Carbon|null $review_due_on
 * @property string|null $background
 * @property string|null $rationale
 * @property string|null $reputational_exposure
 * @property string|null $legal_compliance_exposure
 * @property bool|null $insurance_risk
 * @property bool|null $financial_risk
 * @property bool $eligible_to_transfer
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Scenario extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'sm_scenarios';

    /* ------------------------------------------------------------------ */
    /*  Lifecycle */
    /* ------------------------------------------------------------------ */

    /**
     * The eight lifecycle states, in lifecycle order (plan §4.6, §A1.8).
     *
     * `string(24)` — wide enough for `returned_for_rework` (19) with room, per
     * ADR 0014's lesson about sizing a column for the values it will hold.
     *
     * @var list<string>
     */
    public const STATUSES = [
        'draft',
        'in_design',
        'designed',
        'in_validation',
        'validated',
        'signed_off',
        'archived',
        'returned_for_rework',
    ];

    /**
     * The legal moves, as a REAL CONST rather than an inline local.
     *
     * `LossEvent::STATUSES`' docblock names `LossEventController::STATUS_TRANSITIONS`
     * as the authority for its transitions — that const does not exist anywhere
     * in app/; it is a local variable in one method, so the cross-reference is
     * stale and no test can assert the map (§A1.8). This one is assertable,
     * which is the difference between a lifecycle rule somebody can check and
     * one somebody has to read a controller to find.
     *
     * TWO CORRECTIONS FROM ADDENDUM-4, which wins over §A1.8 where they differ:
     *
     *   - `archived` IS TERMINAL in v1. The `archived → in_design` edge §A1.8
     *     drew is removed: the coordinator ruled no restore, and spec 01 §5.5's
     *     table already says `archived` → Lifecycle: none. The annual refresh off
     *     `review_due_on` therefore acts on a SIGNED_OFF scenario, through
     *     reopen-for-revalidation.
     *   - `signed_off → returned_for_rework` EXISTS. Spec 01 §5.5's "Reopen for
     *     revalidation" sets `returned_for_rework` and writes the reason to the
     *     notes log as a `challenge`; §A1.8's `signed_off → in_validation` alone
     *     cannot express that in one step, and a validator cannot validate an
     *     edit that has not been made yet.
     *
     * `signed_off → in_validation` is still here: it is D4's "post-sign-off
     * edits require revalidation", as an edge rather than a rule buried in a
     * service. Nothing else moves a scenario — a terminal state with no exit is
     * how a record becomes uncorrectable, and an edge nobody drew is how one
     * becomes unrevivable.
     *
     * Enforced in the CONTROLLER per standard §3: an illegal target is a flash
     * message, not a 403. The precedent is
     * `Risk\LossEventController::updateStatus()`.
     *
     * @var array<string, list<string>>
     */
    public const STATUS_TRANSITIONS = [
        'draft' => ['in_design', 'archived'],
        'in_design' => ['designed', 'returned_for_rework'],
        'designed' => ['in_validation', 'in_design'],
        'in_validation' => ['validated', 'returned_for_rework'],
        'validated' => ['signed_off', 'returned_for_rework'],
        'signed_off' => ['returned_for_rework', 'in_validation', 'archived'],
        'archived' => [],
        'returned_for_rework' => ['in_design'],
    ];

    /**
     * Statuses whose content is read-only to everyone (spec 01 §5.5). A
     * validator reading a record the designer can still change is validating
     * nothing, which is why `in_validation` is already read-only before any
     * sign-off.
     *
     * @var list<string>
     */
    public const READ_ONLY_STATUSES = ['in_validation', 'validated', 'signed_off', 'archived'];

    /**
     * Statuses that freeze the record and carry the navy `lock` chip (spec 01
     * §2). Overdue review is NOT one of them: it is a separate flag, so a
     * signed-off scenario can be both frozen and past its review date.
     *
     * @var list<string>
     */
    public const FROZEN_STATUSES = ['signed_off', 'archived'];

    /* ------------------------------------------------------------------ */
    /*  Vocabularies — verbatim from VOCABULARY-EXTRACTION.md */
    /* ------------------------------------------------------------------ */

    /**
     * Type of scenario, workbook dropdown [44,13]–[46,13]. Default `local`:
     * a classification the designer knows at creation, where `local` is the
     * narrowest claim — the shape of `risks.status` defaulting `draft`.
     *
     * @var array<string, string> value => workbook label
     */
    public const NATURES = [
        'group' => 'Group scenario',
        'standard' => 'Standard scenario',
        'local' => 'Local scenario',
    ];

    public const DEFAULT_NATURE = 'local';

    /**
     * Weighting factor, workbook dropdown [49,13]–[52,13]. NULLABLE with NO
     * default (§A1.2): it is a judgement, and `->default('medium_low')` would be
     * a fabricated figure on every scenario nobody has weighted.
     *
     * NAME COLLISION, recorded because it constrains a Phase 2 column: the
     * scorecard's COMPONENT weighting is a different vocabulary — 1 Very low …
     * 5 Very high plus an implicit 0 for a blank cell (spike §1). Phase 2's
     * `sm_scorecard_components` must not reuse this const or the bare name
     * `weighting`.
     *
     * @var array<string, string> value => workbook label
     */
    public const WEIGHTINGS = [
        'low' => 'Low',
        'medium_low' => 'Medium-low',
        'medium_high' => 'Medium-high',
        'high' => 'High',
    ];

    /**
     * Reputational impact — SIX STORED VALUES, and the plan was wrong.
     *
     * Plan §4.2 said "6-point (None → Severe)"; the workbook dropdown
     * ([55,13]–[61,13]) has SEVEN options whose first two are not levels. It is
     * modelled as 5 graded levels + `no_impact` + NULL-as-not-yet-assessed:
     * "No impact" is an ASSESSMENT, "not yet assessed" is the ABSENCE of one, and
     * collapsing them into a `none` value would be a fabricated answer (§5).
     *
     * NOT reused from `LossEvent::SEVERITIES` (`insignificant … catastrophic`):
     * the workbook's top level is `severe`, and adopting the loss-event list
     * would rename a scale a customer already signed off, to save four lines.
     *
     * Labels are the workbook's FULL dropdown strings, verbatim. `config/sm.php`
     * splits each into a short `label` plus a `description` for the radio group
     * (spec 03 §6.2) — the split is wording, and wording is config's (addendum-3).
     *
     * @var array<string, string> value => workbook label
     */
    public const REPUTATIONAL_EXPOSURES = [
        'no_impact' => 'No impact',
        'insignificant' => 'Insignificant: Minor isolated stakeholder concerns / impacts',
        'minor' => 'Minor: Serious segmented stakeholder concerns/incidents',
        'moderate' => 'Moderate: Concerns becoming broader and more vocalised within the industry',
        'major' => 'Major: Negative public exposure with significant impact',
        'severe' => 'Severe: Dramatic loss of stakeholder confidence undermining business viability.  Extensive negative public exposure',
    ];

    /**
     * What the reputational scale reads when nobody has answered. The workbook's
     * own string, and NOT the same as the legal scale's (§A1.8: the two
     * dropdowns literally differ, and D1 plus Phase 5's round-trip require the
     * stored value to map back to that exact string).
     */
    public const REPUTATIONAL_UNSET_LABEL = 'Impact: not yet assessed';

    /**
     * Legal / regulatory / compliance risk, workbook dropdown [64,13]–[69,13].
     *
     * `no`, NOT `no_impact`. Two near-identical value sets is the price of an
     * exact export; normalising them would be cheaper and wrong (§A1.8).
     *
     * The workbook's legal dropdown is a BARE LIST with no descriptions — the
     * five graded descriptions belong to the reputational scale, and copying them
     * here would put words in the artefact's mouth about legal exposure that it
     * never says (spec 03 §6.3). So `config/sm.php` ships this scale with
     * `description: null` on every option.
     *
     * @var array<string, string> value => workbook label
     */
    public const LEGAL_COMPLIANCE_EXPOSURES = [
        'no' => 'No',
        'insignificant' => 'Insignificant',
        'minor' => 'Minor',
        'moderate' => 'Moderate',
        'major' => 'Major',
        'severe' => 'Severe',
    ];

    /** What the legal scale reads when nobody has answered (spec 03 §6.1). */
    public const LEGAL_UNSET_LABEL = 'Not yet assessed';

    /**
     * The TWO sign-off decisions (§A1.6, upheld by the coordinator's Phase 1
     * ruling).
     *
     * `approved_with_conditions` was REFUSED: no transition branches on it, and a
     * condition is a `sm_scenario_notes` row of kind `assumption` (Phase 1) or an
     * action (Phase 3). A third value nothing reads is how a workflow acquires a
     * state nobody can leave. A signer who declines without returning the
     * scenario records a DISSENT NOTE instead — that is the coordinator's ruling,
     * and it is why `scenario.note` is a separate permission from
     * `scenario.update`.
     *
     * @var list<string>
     */
    public const DECISIONS = ['approved', 'returned_for_rework'];

    /** @var array<string, string> value => label */
    public const DECISION_LABELS = [
        'approved' => 'Approved',
        'returned_for_rework' => 'Returned for rework',
    ];

    /* ------------------------------------------------------------------ */
    /*  Columns */
    /* ------------------------------------------------------------------ */

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'reference',
        'name',
        'nature',
        'status',
        'risk_id',
        'risk_taxonomy_node_id',
        'business_unit_id',
        'weighting',
        'currency',
        'fx_rate_id',
        'fx_rate',
        'fx_rate_date',
        'designed_on',
        'validated_on',
        'review_due_on',
        'background',
        'rationale',
        'reputational_exposure',
        'legal_compliance_exposure',
        'insurance_risk',
        'financial_risk',
        'eligible_to_transfer',
        'created_by',
        'updated_by',
    ];

    /**
     * `fx_rate` is `decimal:8` — exactly `fx_rates.rate`'s precision, because
     * narrower silently rounds a figure an examiner recomputes (§A1.2).
     *
     * THE FOUR DAY COLUMNS are cast `date`, and standard §14 applies to every
     * reader: a `date` cast comes back as midnight at the START of that day, so
     * `->isPast()` is true for the whole of the last day and a bare `now()`
     * against a DATE column gets widened to that same midnight. Compare day to
     * day — `whereDate('review_due_on', '<', now()->toDateString())` — never
     * `->isPast()`/`->isFuture()` and never a bare `now()`. That is also why the
     * columns are `_on` and not `_at` (ADR 0026).
     *
     * THE THREE FLAGS are cast `boolean`, and two of them are NULLABLE. A
     * nullable boolean under the `boolean` cast reads back `null`, not `false`:
     * `boolean` is in `HasAttributes::$primitiveCastTypes` and `castAttribute()`
     * returns null unchanged for those, so `?bool` is real and §5's "absent, not
     * zero" survives the cast. Nothing may coerce it — a screen that renders
     * "not yet assessed" as "No" is the exact defect the nullable column exists
     * to prevent.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'risk_id' => 'integer',
            'risk_taxonomy_node_id' => 'integer',
            'business_unit_id' => 'integer',
            'fx_rate_id' => 'integer',
            'fx_rate' => 'decimal:8',
            'fx_rate_date' => 'date',
            'designed_on' => 'date',
            'validated_on' => 'date',
            'review_due_on' => 'date',
            'insurance_risk' => 'boolean',
            'financial_risk' => 'boolean',
            'eligible_to_transfer' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        // Aggregate root, so a uuid (ADR 0007 deviation 4). Set here rather than
        // in a DB default because MySQL 8 and MariaDB 10.4 disagree about
        // `uuid()` as an expression default, and because
        // QuantificationScenario::boot() already does it this way.
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle questions */
    /* ------------------------------------------------------------------ */

    /** Whether $to is a legal next state from this scenario's current one. */
    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::STATUS_TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * The states this scenario may move to. The controller filters this by
     * permission before it reaches the payload — the client holds NO state
     * machine and re-derives nothing (spec 01 §3).
     *
     * @return list<string>
     */
    public function availableTransitions(): array
    {
        return self::STATUS_TRANSITIONS[$this->status] ?? [];
    }

    /**
     * Whether `$target` is reachable from this scenario's current status by ANY
     * number of legal moves — not merely by the next one.
     *
     * COMPUTED FROM THE MAP, NEVER FROM A STATUS LIST. A hardcoded "these six can
     * still be signed off" is a second copy of `STATUS_TRANSITIONS`, and the two
     * diverge the first time an edge is added or removed — silently, because each
     * half still passes its own tests. Breadth-first over the const, so `draft`
     * reaches `signed_off` through five moves and `archived` reaches nothing
     * (Addendum-4: terminal in v1).
     *
     * NOTE WHAT THIS DOES NOT ANSWER. `signed_off` reaches `signed_off` — through
     * reopen-for-revalidation and the whole design path back — so reachability alone
     * cannot tell a caller whether a write is allowed NOW. A frozen record is refused
     * on `isFrozen()`, and every guard that uses this method needs both questions.
     */
    public function canReach(string $target): bool
    {
        $seen = [$this->status => true];
        $queue = [$this->status];

        while ($queue !== []) {
            foreach (self::STATUS_TRANSITIONS[array_shift($queue)] ?? [] as $next) {
                if ($next === $target) {
                    return true;
                }

                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return false;
    }

    /** Read-only to everyone except through a lifecycle transition. */
    public function isReadOnly(): bool
    {
        return in_array($this->status, self::READ_ONLY_STATUSES, true);
    }

    /** Frozen, and carrying the navy `lock` chip. */
    public function isFrozen(): bool
    {
        return in_array($this->status, self::FROZEN_STATUSES, true);
    }

    /** The chip's words. Humanised server-side so a tenant can rename a stage
     *  without a front-end release (spec 00 §3.3). */
    public function statusLabel(): string
    {
        return (string) Str::of($this->status)->replace('_', ' ')->ucfirst();
    }

    public function natureLabel(): ?string
    {
        return $this->nature === null ? null : (self::NATURES[$this->nature] ?? $this->nature);
    }

    public function weightingLabel(): ?string
    {
        return $this->weighting === null ? null : (self::WEIGHTINGS[$this->weighting] ?? $this->weighting);
    }

    /**
     * The reputational answer in words — including the unset state, which the
     * client must never derive from a null (spec 01 §4: "that derivation is
     * exactly the judgement the module's whole §0.5 discipline exists to keep on
     * one side of the wire").
     */
    public function reputationalExposureLabel(): ?string
    {
        return $this->reputational_exposure === null
            ? self::REPUTATIONAL_UNSET_LABEL
            : (self::REPUTATIONAL_EXPOSURES[$this->reputational_exposure] ?? $this->reputational_exposure);
    }

    public function legalComplianceExposureLabel(): ?string
    {
        return $this->legal_compliance_exposure === null
            ? self::LEGAL_UNSET_LABEL
            : (self::LEGAL_COMPLIANCE_EXPOSURES[$this->legal_compliance_exposure] ?? $this->legal_compliance_exposure);
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    /**
     * The annual-refresh prompt's query (plan §4.2 capability 10, §A1.2's
     * `sm_scenarios_org_review_due_idx`).
     *
     * DAY TO DAY, per standard §14 — `whereDate` against today's DATE STRING, not
     * a bare `now()` against a DATE column and not `->isPast()`. $today is a
     * `Y-m-d` string the caller computes in Africa/Lagos.
     */
    public function scopeReviewOverdue(Builder $query, string $today): Builder
    {
        // An archived scenario has no review cadence any more (spec 00 §2), and
        // a signed-off one that is past its date is the point of the prompt — so
        // `archived` alone is excluded, not the whole frozen pair.
        return $query->whereNotNull('review_due_on')
            ->whereDate('review_due_on', '<', $today)
            ->where('status', '!=', 'archived');
    }

    /**
     * Scenarios with no review date that should have one. Excludes `draft` (no
     * cadence yet) and `archived` (none any more) — counting either would inflate
     * a number whose only job is to say "these approved scenarios will never come
     * back for review" (spec 00 §2).
     */
    public function scopeReviewUndated(Builder $query): Builder
    {
        return $query->whereNull('review_due_on')
            ->whereNotIn('status', ['draft', 'archived']);
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Risk, $this> */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class, 'risk_id');
    }

    /**
     * The Basel OP event type (OP1–OP17), seeded per tenant under framework
     * 'Basel III' (§A1.9). `risk_taxonomies` has NO `code` column, so the OP
     * number is part of `name`.
     *
     * @return BelongsTo<RiskTaxonomy, $this>
     */
    public function taxonomyNode(): BelongsTo
    {
        return $this->belongsTo(RiskTaxonomy::class, 'risk_taxonomy_node_id');
    }

    /**
     * The OWNING UNIT. `business_units`, not `entities` — §A1.3: ADR 0026
     * decision 0.5 settled that business_units are authoritative after KRIs'
     * `entity_id` turned out to be written by nothing, and a second org FK beside
     * the authoritative one would repeat that defect one ADR later.
     *
     * @return BelongsTo<BusinessUnit, $this>
     */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }

    /** Provenance only; the snapshot is `fx_rate` + `fx_rate_date`. */
    public function fxRateRecord(): BelongsTo
    {
        return $this->belongsTo(FxRate::class, 'fx_rate_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<ScenarioCause, $this> */
    public function causes(): HasMany
    {
        return $this->hasMany(ScenarioCause::class, 'sm_scenario_id')->orderBy('sort_order');
    }

    /** @return HasMany<BusinessLineAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(BusinessLineAllocation::class, 'sm_scenario_id');
    }

    /**
     * EVERY sign-off row, in the order they were signed. There is deliberately no
     * unique key on (scenario, source), so this may hold several rows for one
     * source — the current one per source is `currentSignoffs()`.
     *
     * @return HasMany<Signoff, $this>
     */
    public function signoffs(): HasMany
    {
        return $this->hasMany(Signoff::class, 'sm_scenario_id');
    }

    /** @return HasMany<Note, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'sm_scenario_id');
    }

    /**
     * The CURRENT sign-off per source — the latest row by `signed_at` (§A1.6).
     *
     * ONLY NON-NULL `signed_at` ROWS COUNT toward the lifecycle; Phase 1 writes a
     * row only from the sign action so every row it creates is non-null, but the
     * filter is here rather than assumed because `requested_at` arrives in Phase 3
     * and a null-`signed_at` row must not then start counting.
     *
     * Ties are broken by `id` descending so the result is deterministic on both
     * engines — two rows for one source sharing a second is reachable from a
     * double-click, and "latest" that changes between MariaDB and MySQL 8 is a
     * governance record that reads differently in CI than in production.
     *
     * @return array<string, Signoff> keyed by source
     */
    public function currentSignoffs(): array
    {
        $rows = $this->signoffs()
            ->whereNotNull('signed_at')
            ->orderBy('signed_at')
            ->orderBy('id')
            ->get();

        $current = [];

        foreach ($rows as $row) {
            $current[$row->source] = $row;
        }

        return $current;
    }
}
