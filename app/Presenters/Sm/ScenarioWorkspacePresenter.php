<?php

namespace App\Presenters\Sm;

use App\Models\BusinessUnit;
use App\Models\FxRate;
use App\Models\Sm\ImpactType;
use App\Models\Sm\Note;
use App\Models\Sm\Scenario;
use App\Models\Sm\ScenarioCause;
use App\Models\Sm\Scorecard;
use App\Models\Sm\ScorecardComponent;
use App\Models\Sm\Signoff;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\Sm\AllocationService;
use App\Services\Sm\CauseService;
use App\Services\Sm\OpEventTypeCatalogue;
use App\Services\Sm\ScenarioComputationService;
use App\Services\Sm\ScenarioLifecycle;
use App\Services\Sm\ScenarioSettings;
use App\Services\Sm\SignoffWarnings;
use App\Support\Kri\KriWorkingCalendar;
use App\Support\Measures\UnitRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The scenario workspace payload (spec 01 §4, spec 02 §8, spec 03 §2).
 *
 * Standard §1: a controller resolves, authorises and hands a Presenter the job of
 * shaping what the page receives. `Inertia::render` takes the result; it does not
 * compute it. Everything the specs mark **(prop decision)** is decided here and nowhere
 * else, so the three surfaces — payload, 422 keys and `gate.missing[].field` — are one
 * vocabulary.
 *
 * FOUR RULES THIS CLASS EXISTS TO KEEP:
 *
 *   1. THE CLIENT HOLDS NO STATE MACHINE. `transitions` is filtered by permission AND
 *      status on the server and sent verbatim; `can` decides whether an EDITING
 *      affordance renders and never which lifecycle buttons appear (spec 01 §3).
 *   2. NULL IS NEVER COERCED. `insurance_risk`, `financial_risk` and both exposure
 *      scales arrive as `?bool` / `?string`, and every "not yet assessed" sentence is
 *      the SERVER'S wording — the client never derives it from a null, because that
 *      derivation is exactly the judgement standard §5 exists to keep on one side of the
 *      wire (spec 01 §4).
 *   3. DECIMALS ARRIVE AS STRINGS (spec 01 §4.5). `allocation_total`, every `percent`
 *      and `fx.rate` are decimal strings, because a float that has been through
 *      json_encode can arrive as `100` instead of `100.00` and summing those in JS
 *      reintroduces the artefact §A1.5 told the server to avoid.
 *   4. ONLY THE ACTIVE TAB'S DATA SHIPS (spec 01 §4) — `values` on Overview,
 *      `qualitative` on Qualitative, neither for the other — with `qualitativeSummary`
 *      the one exception, because the sign-off modal is shell-level and can be opened
 *      from either tab.
 *
 * DAY COLUMNS ARE COMPARED AS DAYS. `review.days_overdue` and the review states are
 * computed against `KriWorkingCalendar::today()` as `Y-m-d` strings, never with
 * `->isPast()` or a bare `now()` against a DATE column (standard §14).
 */
class ScenarioWorkspacePresenter
{
    /** Spec 01 §4.4: the latest twenty, with a Load-earlier control and no auto-load. */
    public const NOTES_PAGE = 20;

    /**
     * Spec 03 §12.5's recommended cap on the business-unit option list, with a
     * `truncated` flag the UI can turn into "Too many business lines to list — search
     * instead" rather than letting the payload grow silently. §A1.3 bounds it in
     * practice — business_units are departments and divisions, so tens — but a cap that
     * is never hit is still cheaper than a payload nobody measured.
     */
    public const ALLOCATION_OPTION_CAP = 500;

    public function __construct(
        private readonly ScenarioLifecycle $lifecycle,
        private readonly CurrencyService $currencies,
        private readonly OpEventTypeCatalogue $opEvents,
        private readonly ScenarioComputationService $computation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Scenario $scenario, User $user, string $tab, ?int $notesBefore = null): array
    {
        $scenario->loadMissing([
            'taxonomyNode', 'businessUnit', 'risk', 'fxRateRecord', 'creator', 'updater',
            'causes', 'allocations.businessUnit', 'signoffs.signer',
            'scorecard.components', 'frequencyDrivers', 'impactTypes.criteria', 'insuranceCoverages',
        ]);

        $payload = [
            'scenario' => $this->scenario($scenario, $user),
            'review' => $this->review($scenario),
            'gate' => $this->lifecycle->gate($scenario),
            'transitions' => $this->lifecycle->transitionsFor($scenario, $user),
            'tab' => $tab,
            'tabs' => $this->tabs($scenario, $tab),
            'roadmapNote' => config('sm.roadmap_note'),
            'can' => $this->can($scenario, $user),
            // ALWAYS sent, on both tabs: the sign-off modal recaps the qualitative
            // position and can be opened from either (spec 01 §4).
            'qualitativeSummary' => $this->qualitativeSummary($scenario),
            // Server-computed, never in the browser: no `+12 months` in JSX, because a
            // client-side date constant is a fabricated figure and would drift from the
            // tenant's setting the first time an administrator changed it.
            'suggestedReviewDueOn' => ScenarioSettings::suggestedReviewDueOn((int) $scenario->organization_id),
            'signoffs' => $this->signoffs($scenario),
            // The vocabulary behind the rows, as data (spec 01 §5.6.2, spec 02 §6):
            // the modal renders one row per source and the panel one bucket per
            // source INCLUDING a source that never signed — neither is derivable
            // from `signoffs`, which is empty in the pre-sign-off case the modal
            // exists for. Labels come from config so a tenant renaming "Source 1"
            // needs no release; the VALUES are the frozen consts.
            'signoffSources' => $this->options((array) config('sm.sources', Signoff::SOURCES)),
            'signoffDecisions' => $this->options((array) config('sm.decision_labels', Scenario::DECISION_LABELS)),
            'baseCurrency' => $this->currencies->reportingCurrency((int) $scenario->organization_id),
            'urls' => $this->urls($scenario, $tab, $notesBefore),
            // NOT in spec 01 §4's prop list, and required by §5.6.5: the modal renders
            // the outstanding items as a `role="note"` list. Codes come from
            // SignoffWarnings and the WORDING from config, per addendum-1 — the row
            // stores a snapshot of the codes, and this is the LIVE list for the modal.
            // [verify at integration]
            'signoffWarnings' => $this->signoffWarnings($scenario),
        ];

        [$notes, $total] = $this->notes($scenario, $notesBefore);
        $payload['notes'] = $notes;
        $payload['notesMeta'] = ['shown' => count($notes), 'total' => $total];
        $payload['noteKinds'] = $this->noteKinds();

        $payload['values'] = null;
        $payload['qualitative'] = null;
        $payload['scorecard'] = null;
        $payload['frequency'] = null;
        $payload['impact'] = null;
        $payload['insurance'] = null;

        // Only the active tab's payload ships (spec 01 §4).
        if ($tab === 'qualitative') {
            $payload['qualitative'] = $this->qualitative($scenario);
        } elseif ($tab === 'scorecard') {
            $payload['scorecard'] = $this->scorecard($scenario);
        } elseif ($tab === 'frequency') {
            $payload['frequency'] = $this->frequency($scenario);
        } elseif ($tab === 'impact') {
            $payload['impact'] = $this->impact($scenario);
        } elseif ($tab === 'insurance') {
            $payload['insurance'] = $this->insurance($scenario);
        } else {
            $payload['values'] = $this->values($scenario);
            $payload['businessUnits'] = $this->businessUnits((int) $scenario->organization_id);
            $payload['opEventTypes'] = $this->opEventTypes((int) $scenario->organization_id);
            $payload['natures'] = $this->options(Scenario::NATURES);
            $payload['weightings'] = $this->options(Scenario::WEIGHTINGS);
            $payload['currencies'] = $this->currencyOptions();
            $payload['fxRates'] = $this->fxRates((int) $scenario->organization_id);
        }

        return $payload;
    }

    /* ------------------------------------------------------------------ */
    /*  The header record */
    /* ------------------------------------------------------------------ */

    /**
     * Field names are the FROZEN COLUMN NAMES (§A1.2, addendum-4): `designed_on` /
     * `validated_on` / `review_due_on`, `background`, `weighting`, `business_unit_id`,
     * `reputational_exposure`, `legal_compliance_exposure` — with the presenter doing NO
     * RENAMING, so a 422 key, a `gate.missing[].field` and a form field name are one
     * vocabulary across three surfaces.
     *
     * @return array<string, mixed>
     */
    private function scenario(Scenario $scenario, User $user): array
    {
        $taxonomyNode = $scenario->taxonomyNode;

        return [
            'id' => (int) $scenario->id,
            'uuid' => (string) $scenario->uuid,
            'reference' => (string) $scenario->reference,
            'name' => (string) $scenario->name,
            'status' => (string) $scenario->status,
            'status_label' => $this->statusLabel($scenario->status),
            // Spec 01 §2: the `lock` chip and the frozen banner, for signed_off and
            // archived. Overdue review is NOT part of this — it is a separate flag, so a
            // signed-off scenario can be both frozen and past its review date and the two
            // never collapse into one chip.
            'frozen' => $scenario->isFrozen(),
            // §A1.3 — a BUSINESS UNIT, not an entity. There is no `entity_id`.
            'business_unit' => $scenario->businessUnit === null
                ? null
                : ['id' => (int) $scenario->businessUnit->id, 'name' => (string) $scenario->businessUnit->name],
            'nature' => $scenario->nature,
            'nature_label' => $scenario->natureLabel(),
            'weighting' => $scenario->weighting,
            'weighting_label' => $scenario->weightingLabel(),
            // §A1.9: `risk_taxonomies` has NO `code` column, so `name` carries the
            // "OP7 …" prefix verbatim and `basel_level_1` is extracted SERVER-SIDE from
            // the node's description — null when a tenant has edited it, in which case
            // the picker falls back to a flat list (spec 04 §2).
            'op_event_type' => $taxonomyNode === null
                ? null
                : [
                    'id' => (int) $taxonomyNode->id,
                    'name' => (string) $taxonomyNode->name,
                    'basel_level_1' => OpEventTypeCatalogue::baselLevel1From($taxonomyNode->description),
                ],
            // NOT NULL default 'NGN' (§A1.2 row 12), so never absent.
            'currency' => (string) $scenario->currency,
            // THREE columns, one object (§A1.2 rows 13–15): `fx_rate_id` is provenance,
            // the other two are the SNAPSHOT that keeps a signed-off figure from moving
            // when the rate table does. `rate` is a decimal STRING (spec 01 §4.5).
            'fx' => $this->fx($scenario),
            // `url` is null when the caller lacks `risk.view`, and then the reference
            // renders as plain text rather than a dead link (spec 02 §5.7).
            'risk' => $this->risk($scenario, $user),
            'designed_on' => $scenario->designed_on?->toDateString(),
            'validated_on' => $scenario->validated_on?->toDateString(),
            'review_due_on' => $scenario->review_due_on?->toDateString(),
            'created_by' => $this->person($scenario->creator),
            'updated_by' => $this->person($scenario->updater),
            'updated_at' => $scenario->updated_at?->toIso8601String(),
            'previous_signoff' => $this->lifecycle->previousSignoff($scenario),
        ];
    }

    /** @return array<string, mixed>|null */
    private function fx(Scenario $scenario): ?array
    {
        if ($scenario->fx_rate_id === null && $scenario->fx_rate === null && $scenario->fx_rate_date === null) {
            return null;
        }

        $rate = $scenario->fxRateRecord;

        return [
            'fx_rate_id' => $scenario->fx_rate_id === null ? null : (int) $scenario->fx_rate_id,
            // decimal:8 string, never a float — §A1.2 chose 18,8 to match `fx_rates.rate`
            // exactly because "narrower silently rounds a figure an examiner recomputes".
            'rate' => $scenario->fx_rate === null ? null : (string) $scenario->fx_rate,
            'rate_date' => $scenario->fx_rate_date?->toDateString(),
            'from' => $rate?->from_currency,
            'to' => $rate?->to_currency,
        ];
    }

    /** @return array<string, mixed>|null */
    private function risk(Scenario $scenario, User $user): ?array
    {
        $risk = $scenario->risk;

        if ($risk === null) {
            return null;
        }

        return [
            'id' => (int) $risk->id,
            'risk_code' => (string) $risk->risk_code,
            'title' => (string) $risk->title,
            'url' => $user->can('risk.view') ? route('risk.register.show', $risk) : null,
        ];
    }

    /** @return array{id: int, name: string}|null */
    private function person(?User $user): ?array
    {
        return $user === null
            ? null
            : ['id' => (int) $user->id, 'name' => (string) $user->name];
    }

    /* ------------------------------------------------------------------ */
    /*  Review */
    /* ------------------------------------------------------------------ */

    /**
     * `{due_on, days_overdue}` — server-computed over `review_due_on`, in Africa/Lagos,
     * against the day boundary (spec 01 §4.2, §4.3).
     *
     * `days_overdue` is null ONLY when no review date is set, and renders `—`; it never
     * renders `0` for an absence. A date that is set and not yet past is `0`, and the
     * callout renders on `> 0` alone.
     *
     * @return array{due_on: string|null, days_overdue: int|null}
     */
    private function review(Scenario $scenario): array
    {
        $dueOn = $scenario->review_due_on?->toDateString();

        if ($dueOn === null) {
            return ['due_on' => null, 'days_overdue' => null];
        }

        $today = KriWorkingCalendar::today();

        // Day to day, never `->isPast()` on a date cast (standard §14).
        $days = $today->toDateString() > $dueOn
            ? (int) KriWorkingCalendar::day($dueOn)->diffInDays($today)
            : 0;

        return ['due_on' => $dueOn, 'days_overdue' => $days];
    }

    /* ------------------------------------------------------------------ */
    /*  Tabs, permissions, urls */
    /* ------------------------------------------------------------------ */

    /**
     * Phase 2: SIX live entries (Overview, Qualitative, Frequency, Impact, Insurance, Scorecard).
     *
     * TABS ARE LINKS, NOT BUTTONS, so a validator can send a colleague "the qualitative
     * tab of SM-2026-014" and a tab switch is a navigation the unsaved-changes guard
     * intercepts for free.
     *
     * @return list<array{key: string, label: string, url: string, live: bool}>
     */
    private function tabs(Scenario $scenario, string $tab): array
    {
        return [
            [
                'key' => 'overview',
                'label' => 'Overview & Governance',
                'url' => route('scenario.show', ['scenario' => $scenario, 'tab' => 'overview']),
                'live' => true,
            ],
            [
                'key' => 'qualitative',
                'label' => 'Qualitative',
                'url' => route('scenario.show', ['scenario' => $scenario, 'tab' => 'qualitative']),
                'live' => true,
            ],
            [
                'key' => 'frequency',
                'label' => 'Frequency',
                'url' => route('scenario.show', ['scenario' => $scenario, 'tab' => 'frequency']),
                'live' => true,
            ],
            [
                'key' => 'impact',
                'label' => 'Impact',
                'url' => route('scenario.show', ['scenario' => $scenario, 'tab' => 'impact']),
                'live' => true,
            ],
            [
                'key' => 'insurance',
                'label' => 'Insurance',
                'url' => route('scenario.show', ['scenario' => $scenario, 'tab' => 'insurance']),
                'live' => true,
            ],
            [
                'key' => 'scorecard',
                'label' => 'Scorecard',
                'url' => route('scenario.show', ['scenario' => $scenario, 'tab' => 'scorecard']),
                'live' => true,
            ],
        ];
    }

    /**
     * Permissions for UI affordances (spec 01 §3, ADR 0027 Amendment 2 §A2.7).
     *
     * `can` decides whether an EDITING AFFORDANCE renders. It never decides which
     * lifecycle buttons appear — `transitions` does, because the server has already
     * filtered that list by permission and by status (spec 01 §3).
     *
     * @return array<string, bool>
     */
    private function can(Scenario $scenario, User $user): array
    {
        return [
            'view' => $user->can('view', $scenario),
            'create' => $user->can('scenario.create'),
            'update' => $user->can('update', $scenario) && ! $scenario->isReadOnly(),
            'design' => $user->can('scenario.design') && ! $scenario->isReadOnly(),
            'validate' => $user->can('transition', $scenario),
            'sign_off' => $user->can('signOff', $scenario),
            // Spec 01 §5.5: notes close at `archived` ONLY — a note is commentary on a
            // frozen record, not a change to it, so `signed_off` still allows one.
            'note' => $user->can('note', $scenario) && $scenario->status !== 'archived',
        ];
    }

    /**
     * Mirrors the route table exactly (spec 01 §4, ADR 0027 Amendment 2 §A2.7).
     *
     * @return array<string, string|null>
     */
    private function urls(Scenario $scenario, string $tab, ?int $notesBefore): array
    {
        return [
            'index' => route('scenario.index'),
            'scenario' => route('scenario.update', $scenario),
            'causes' => route('scenario.causes.update', $scenario),
            'allocations' => route('scenario.allocations.update', $scenario),
            'scorecard' => route('scenario.scorecard.update', $scenario),
            'frequency' => route('scenario.frequency.update', $scenario),
            'impact' => route('scenario.impact.update', $scenario),
            'insurance' => route('scenario.insurance.update', $scenario),
            'status' => route('scenario.status', $scenario),
            'signoffs' => route('scenario.signoffs.store', $scenario),
            'notes' => route('scenario.notes.store', $scenario),
            // The Load-earlier cursor. A plain reload of this URL would return the same
            // latest twenty, so the cursor names the oldest note already on screen and the
            // server returns the twenty before it — which is what makes spec 01 §4.4's
            // "reloads only: ['notes','notesMeta'] and prepends" true. Null when there is
            // nothing earlier. [verify at integration]
            'notesEarlier' => $this->notesEarlierUrl($scenario, $tab, $notesBefore),
            // A SHELL prop, not an Overview-tab one, so a linked risk can be searched from
            // any tab if a later phase needs it (spec 01 §4).
            'riskSearch' => route('scenario.risk-search'),
            // Null when the product has no FX-rates screen to link to: "a link that 403s
            // or 404s must not appear" (spec 02 §5.6, §13.4). Phase 1 has no such route,
            // so this is null today — and `Route::has()` rather than a hard-coded null
            // means the link appears the day the screen lands, with no presenter change.
            'fxRates' => Route::has('risk.fx-rates.index')
                ? route('risk.fx-rates.index')
                : null,
        ];
    }

    private function notesEarlierUrl(Scenario $scenario, string $tab, ?int $notesBefore): ?string
    {
        $total = $scenario->notes()->count();

        if ($total <= self::NOTES_PAGE) {
            return null;
        }

        $oldest = $this->oldestShownNoteId($scenario, $notesBefore);

        if ($oldest === null) {
            return null;
        }

        return route('scenario.show', [
            'scenario' => $scenario,
            'tab' => $tab,
            'notes_before' => $oldest,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Qualitative summary — always sent */
    /* ------------------------------------------------------------------ */

    /**
     * Every label here is the SERVER'S WORDING, including "Impact: not yet assessed".
     * The client never derives an unset label from a null (spec 01 §4).
     *
     * `allocation_total` is a decimal STRING or null — null means "not allocated", never
     * "0.00" (spec 03 §5.4).
     *
     * @return array<string, mixed>
     */
    private function qualitativeSummary(Scenario $scenario): array
    {
        $yesNo = (array) config('sm.yes_no', []);

        $flagLabel = function (?bool $value) use ($yesNo): ?string {
            // NULL stays NULL: a nullable boolean under the `boolean` cast reads back
            // null, not false (§A1.2), and rendering that as "No" is the exact defect the
            // nullable column exists to prevent.
            if ($value === null) {
                return (string) ($yesNo['unset'] ?? 'Not assessed');
            }

            return (string) ($value ? ($yesNo['yes'] ?? 'Yes') : ($yesNo['no'] ?? 'No'));
        };

        return [
            'causes_selected' => count(CauseService::selectedCodes($scenario->causes)),
            'allocation_lines' => $scenario->allocations->count(),
            'allocation_total' => AllocationService::total($scenario->allocations),
            'reputational_label' => $scenario->reputationalExposureLabel(),
            'legal_label' => $scenario->legalComplianceExposureLabel(),
            'insurance_risk_label' => $flagLabel($scenario->insurance_risk),
            'financial_risk_label' => $flagLabel($scenario->financial_risk),
            // NEVER null — the column is NOT NULL default false (§A1.2 row 25), so there is
            // no unanswered state to render (spec 01 §4, spec 03 §6.4).
            'eligible_to_transfer_label' => (string) $flagLabel((bool) $scenario->eligible_to_transfer),
        ];
    }

    /**
     * The LIVE warning list for the sign-off modal, with wording from config.
     *
     * The codes are what get SNAPSHOTTED onto a sign-off row; the messages are never
     * stored (addendum-1: storing wording would freeze text a tenant may reconfigure).
     *
     * @return array{codes: list<string>, messages: list<string>, note: string|null}
     */
    private function signoffWarnings(Scenario $scenario): array
    {
        $rendered = SignoffWarnings::withMessages($scenario);

        return [
            'codes' => array_column($rendered, 'code'),
            'messages' => array_column($rendered, 'message'),
            'note' => config('sm.signoff.warnings_note'),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Sign-offs and notes */
    /* ------------------------------------------------------------------ */

    /**
     * EVERY row, each with `is_current` — the server's determination.
     *
     * §A1.6 puts no unique key on (scenario, source), so several rows for one source is
     * normal after a revalidation. The client never sorts or picks "latest" itself: with
     * `signed_at` nullable and only non-null rows counting toward the lifecycle,
     * duplicating that logic in JS is how two surfaces come to disagree about who signed
     * (spec 01 §4.6). Ordered by source so a `filter(is_current)` keeps the workbook's
     * Source 1 / 2 / 3 order.
     *
     * @return list<array<string, mixed>>
     */
    private function signoffs(Scenario $scenario): array
    {
        $order = array_flip(array_keys(Signoff::SOURCES));
        $current = $scenario->currentSignoffs();
        $labels = (array) config('sm.sources', Signoff::SOURCES);
        $decisions = (array) config('sm.decision_labels', Scenario::DECISION_LABELS);

        return $scenario->signoffs
            ->sortBy([
                fn (Signoff $a, Signoff $b) => ($order[$a->source] ?? 99) <=> ($order[$b->source] ?? 99),
                fn (Signoff $a, Signoff $b) => $b->signed_at <=> $a->signed_at,
                fn (Signoff $a, Signoff $b) => $b->id <=> $a->id,
            ])
            ->values()
            ->map(fn (Signoff $row) => [
                'id' => (int) $row->id,
                'source' => (string) $row->source,
                'source_label' => (string) ($labels[$row->source] ?? $row->source),
                // SNAPSHOTS (§A1.6, spec 01 §4.7): NOT NULL, and the evidence rather than
                // the join. `user` is nullable and supplementary.
                'signatory_name' => (string) $row->signatory_name,
                'role' => (string) $row->role,
                'user' => $this->person($row->signer),
                'decision' => (string) $row->decision,
                'decision_label' => (string) ($decisions[$row->decision] ?? $row->decision),
                'is_independent_challenge' => (bool) $row->is_independent_challenge,
                'comment' => $row->comment,
                'signed_at' => $row->signed_at?->toIso8601String(),
                'is_current' => ($current[$row->source] ?? null)?->id === $row->id,
                // The snapshot addendum-1 added the column for: what was outstanding when
                // THIS signer signed, never recomputed. Codes only; the wording is config.
                'warnings_acknowledged' => $row->warningCodes(),
            ])
            ->all();
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function notes(Scenario $scenario, ?int $notesBefore): array
    {
        $total = $scenario->notes()->count();

        $query = $scenario->notes()->orderByDesc('created_at')->orderByDesc('id');

        if ($notesBefore !== null) {
            // A cursor on id rather than on created_at: two notes written in the same
            // second are reachable from a workshop, and an id cursor is strict.
            $query->where('id', '<', $notesBefore);
        }

        $rows = $query->limit(self::NOTES_PAGE)->get();

        return [
            $rows->map(fn (Note $note) => [
                'id' => (int) $note->id,
                // `kind`, not `type` — §A1.7 froze the column spelling and addendum-4 says
                // the presenter does no renaming.
                'kind' => (string) $note->kind,
                'kind_label' => $note->kindLabel(),
                'body' => (string) $note->body,
                // A snapshot: an anonymous dissent cannot be followed up, which is worse
                // than none (§A1.7).
                'author_name' => (string) $note->author_name,
                'author' => $this->person($note->author),
                'created_at' => $note->created_at?->toIso8601String(),
            ])->all(),
            $total,
        ];
    }

    private function oldestShownNoteId(Scenario $scenario, ?int $notesBefore): ?int
    {
        $query = $scenario->notes()->orderByDesc('created_at')->orderByDesc('id');

        if ($notesBefore !== null) {
            $query->where('id', '<', $notesBefore);
        }

        return $query->limit(self::NOTES_PAGE)->get()->last()?->id;
    }

    /**
     * Labels and hints from the model consts, not from config: §A1.8 puts `KINDS` on
     * `Sm\Note`, and addendum-3's config list does not include the notes vocabulary.
     * The HINTS are the point — "they are what makes a risk officer write a useful note
     * instead of 'discussed'" (spec 02 §7.1).
     *
     * @return list<array{value: string, label: string, hint: string}>
     */
    private function noteKinds(): array
    {
        return array_map(
            fn (string $kind) => [
                'value' => $kind,
                'label' => Note::KIND_LABELS[$kind] ?? (string) Str::of($kind)->replace('_', ' ')->ucfirst(),
                'hint' => Note::KIND_HINTS[$kind] ?? '',
            ],
            Note::KINDS
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Overview tab */
    /* ------------------------------------------------------------------ */

    /**
     * A FLAT object keyed by the frozen column names, not a nested model dump, so the 422
     * error keys and the `gate.missing[].field` anchors line up with the form state
     * one-for-one (spec 02 §8).
     *
     * `validated_on` is here as READ-ONLY CONTEXT so the Dates card can render it without a
     * second prop; it is never posted, and `UpdateScenarioRequest` has no rule for it so it
     * could not be written even if it were.
     *
     * @return array<string, mixed>
     */
    private function values(Scenario $scenario): array
    {
        return [
            'name' => (string) $scenario->name,
            'business_unit_id' => $scenario->business_unit_id === null ? null : (int) $scenario->business_unit_id,
            'risk_taxonomy_node_id' => $scenario->risk_taxonomy_node_id === null ? null : (int) $scenario->risk_taxonomy_node_id,
            'nature' => $scenario->nature,
            'weighting' => $scenario->weighting,
            'currency' => (string) $scenario->currency,
            'fx_rate_id' => $scenario->fx_rate_id === null ? null : (int) $scenario->fx_rate_id,
            'risk_id' => $scenario->risk_id === null ? null : (int) $scenario->risk_id,
            'background' => $scenario->background,
            'rationale' => $scenario->rationale,
            'designed_on' => $scenario->designed_on?->toDateString(),
            'validated_on' => $scenario->validated_on?->toDateString(),
            'review_due_on' => $scenario->review_due_on?->toDateString(),
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function businessUnits(int $organizationId): array
    {
        return BusinessUnit::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (BusinessUnit $unit) => ['id' => (int) $unit->id, 'name' => (string) $unit->name])
            ->all();
    }

    /**
     * Reads THE TAXONOMY, never the const (spec 02 §5.4): "or an extension would be
     * invisible". A tenant's CBN ORMS sub-types appear because the query reads the
     * tenant's own nodes.
     *
     * NO `code` FIELD: `risk_taxonomies` has no such column (§A1.9), the OP number is part
     * of `name`, and there is nothing for the client to concatenate or format.
     *
     * @return list<array{id: int, name: string, parent: array{id: int, name: string}|null, basel_level_1: string|null}>
     */
    private function opEventTypes(int $organizationId): array
    {
        return $this->opEvents->nodesFor($organizationId)
            ->map(fn ($node) => [
                'id' => (int) $node->id,
                'name' => (string) $node->name,
                'parent' => $node->parent === null
                    ? null
                    : ['id' => (int) $node->parent->id, 'name' => (string) $node->parent->name],
                'basel_level_1' => OpEventTypeCatalogue::baselLevel1From($node->description),
            ])
            ->all();
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{value: string, label: string}>
     */
    private function options(array $map): array
    {
        $out = [];

        foreach ($map as $value => $label) {
            $out[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $out;
    }

    /**
     * The product's own unit registry — real currencies, not sub-units like kobo and cent.
     * There is no other currency list in this codebase to read, and inventing one here
     * would be a second authority.
     *
     * @return list<array{code: string, name: string}>
     */
    private function currencyOptions(): array
    {
        $out = [];

        foreach (UnitRegistry::all() as $unit) {
            if (($unit['category'] ?? null) !== 'currency' || ($unit['base'] ?? null) !== null) {
                continue;
            }

            $out[] = ['code' => (string) $unit['code'], 'name' => (string) $unit['name']];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, from: string, to: string, rate: string, rate_date: string|null}>
     */
    private function fxRates(int $organizationId): array
    {
        return FxRate::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (FxRate $rate) => [
                'id' => (int) $rate->id,
                'from' => (string) $rate->from_currency,
                'to' => (string) $rate->to_currency,
                // Decimal STRING, never a float (spec 01 §4.5, spec 02 §5.6).
                'rate' => (string) $rate->rate,
                'rate_date' => $rate->rate_date?->toDateString(),
            ])
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Qualitative tab */
    /* ------------------------------------------------------------------ */

    /**
     * The full Qualitative payload (spec 03 §2), sent ONLY on that tab.
     *
     * The checklist, both scales and the yes/no labels all arrive AS DATA: config/sm.php
     * supplies the wording and the model consts supply the legal value set (addendum-3).
     * Nothing in spec 03 §4–§6 may be hard-coded in JSX.
     *
     * @return array<string, mixed>
     */
    private function qualitative(Scenario $scenario): array
    {
        $selected = [];

        foreach ($scenario->causes as $cause) {
            if (! (bool) $cause->is_selected) {
                continue;
            }

            // Keyed by code, so a deselect-and-reselect cannot reorder the payload and
            // every `causes.{code}.note` error key is stable (spec 03 §2).
            $selected[(string) $cause->code] = ['note' => $cause->note];
        }

        return [
            'causes' => [
                'checklist' => $this->checklist(),
                'selected' => (object) $selected,
            ],
            'allocation' => $this->allocation($scenario),
            'exposures' => $this->exposures($scenario),
            'yesNo' => (object) config('sm.yes_no', ['yes' => 'Yes', 'no' => 'No', 'unset' => 'Not assessed']),
        ];
    }

    /**
     * The 23 frozen codes with config's wording.
     *
     * `side` and `cause_group` come from the CONST, not from config: they are column
     * values and §A1.4 derives them from the code at insert, so letting config edit them
     * would let a tenant store a `side` the service would never write.
     *
     * @return list<array<string, mixed>>
     */
    private function checklist(): array
    {
        $groupLabels = (array) config('sm.causes.group_labels', []);
        $configured = [];

        foreach ((array) config('sm.causes.checklist', []) as $item) {
            $configured[(string) $item['code']] = $item;
        }

        $out = [];
        $sort = 0;

        foreach (ScenarioCause::CHECKLIST as $code => [$side, $group, $label]) {
            $item = $configured[$code] ?? [];

            $out[] = [
                'code' => (string) $code,
                'side' => (string) $side,
                // Singular, because it is a COLUMN VALUE (`process`, `system`). The header
                // reads "Processes" / "Systems" — that is `group_label`, the workbook's
                // wording, and the UI renders that one (spec 03 §4.1).
                'cause_group' => (string) $group,
                'group_label' => (string) ($groupLabels[$group] ?? Str::of($group)->ucfirst()),
                // Config wins on wording so a tenant can rename a cause without a release;
                // the const is the fallback so a config with a missing entry still renders.
                'label' => (string) ($item['label'] ?? $label),
                'requires_note' => (bool) ($item['requires_note'] ?? ScenarioCause::requiresNote((string) $code)),
                'sort' => (int) ($item['sort'] ?? $sort),
            ];

            $sort++;
        }

        usort($out, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return $out;
    }

    /**
     * @return array{lines: list<array<string, mixed>>, options: list<array{id: int, name: string}>, saved_total: string|null, truncated: bool}
     */
    private function allocation(Scenario $scenario): array
    {
        $organizationId = (int) $scenario->organization_id;

        // Spec 03 §12.5's recommendation: cap the option list and say so, rather than
        // letting the payload grow silently. §A1.3 bounds it in practice — business_units
        // are departments and divisions, so tens — but a cap that is never hit is still
        // cheaper than a payload nobody measured.
        $units = BusinessUnit::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->limit(self::ALLOCATION_OPTION_CAP + 1)
            ->get(['id', 'name']);

        $truncated = $units->count() > self::ALLOCATION_OPTION_CAP;

        if ($truncated) {
            $units = $units->take(self::ALLOCATION_OPTION_CAP);
        }

        $lines = $scenario->allocations
            ->map(fn ($line) => [
                // A server-supplied stable string: React keys and the undo affordance both
                // need identity that survives a re-render, and an array index does not.
                'key' => (string) $line->id,
                'business_unit_id' => (int) $line->business_unit_id,
                'label' => (string) ($line->businessUnit?->name ?? '—'),
                // Decimal STRING (spec 01 §4.5).
                'percent' => (string) $line->percent,
            ])
            ->values()
            ->all();

        return [
            'lines' => $lines,
            // BUSINESS UNITS ONLY, with no `kind` discriminator: §A1.5 refused
            // `business_processes` and the coordinator upheld it (addendum-4).
            'options' => $units
                ->map(fn (BusinessUnit $unit) => ['id' => (int) $unit->id, 'name' => (string) $unit->name])
                ->all(),
            // NULL when there are no lines, never "0.00": "0% allocated" is a claim that
            // the scenario has been allocated and nothing landed anywhere — a different and
            // false statement (spec 03 §5.4, standard §5).
            'saved_total' => AllocationService::total($scenario->allocations),
            'truncated' => $truncated,
        ];
    }

    /**
     * Both scales as `{value, unset_label, options[]}`, plus the three flags.
     *
     * The keys are the FROZEN COLUMN NAMES — `reputational_exposure` and
     * `legal_compliance_exposure`, not `reputational` and `legal` — so the payload, the
     * post body and the 422 keys are one vocabulary (addendum-4).
     *
     * The ORDINAL of each scale lives in the `options[]` ARRAY ORDER and nowhere else
     * (spec 03 §12.8): the consts define the order, config preserves it, and nothing
     * stores an ordinal.
     *
     * `eligible_to_transfer` is a plain BOOL, never nullable: §A1.2 row 25 froze it NOT
     * NULL default false so Phase 2's netting stays a two-case formula (spec 03 §6.4).
     *
     * @return array<string, mixed>
     */
    private function exposures(Scenario $scenario): array
    {
        return [
            'reputational_exposure' => $this->scale(
                $scenario->reputational_exposure,
                (array) config('sm.exposures.reputational.options', []),
                (string) config('sm.exposures.reputational.unset_label', Scenario::REPUTATIONAL_UNSET_LABEL)
            ),
            'legal_compliance_exposure' => $this->scale(
                $scenario->legal_compliance_exposure,
                (array) config('sm.exposures.legal.options', []),
                (string) config('sm.exposures.legal.unset_label', Scenario::LEGAL_UNSET_LABEL)
            ),
            // NULL stays NULL — never coerced to false (standard §5).
            'insurance_risk' => $scenario->insurance_risk,
            'financial_risk' => $scenario->financial_risk,
            'eligible_to_transfer' => (bool) $scenario->eligible_to_transfer,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array{value: string|null, unset_label: string, options: list<array{value: string, label: string, description: string|null}>}
     */
    private function scale(?string $value, array $options, string $unsetLabel): array
    {
        $out = [];

        foreach ($options as $option) {
            $out[] = [
                'value' => (string) $option['value'],
                'label' => (string) $option['label'],
                // Null means NO DESCRIPTION LINE renders — no placeholder and no invented
                // sentence. The legal scale ships null on every option because the
                // workbook's legal dropdown is a bare list of six labels (spec 03 §6.3).
                'description' => isset($option['description']) ? (string) $option['description'] : null,
            ];
        }

        return ['value' => $value, 'unset_label' => $unsetLabel, 'options' => $out];
    }

    /* ------------------------------------------------------------------ */
    /*  Phase 2 Quantitative Tabs (Scorecard, Frequency, Impact, Insurance) */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function scorecard(Scenario $scenario): array
    {
        $scorecard = $scenario->scorecard;
        $controlNature = $scorecard?->control_nature ?? 'mixed';

        $components = [];
        $existing = $scorecard ? $scorecard->components->keyBy('component_code') : collect();

        $sort = 1;
        foreach (ScorecardComponent::COMPONENTS as $code => $name) {
            $row = $existing->get($code);
            $eff = $row?->efficiency_level ?? 0;
            $weight = $row?->weighting_factor ?? 0;
            $score = $row?->score ?? 0;
            $levelScore = $row ? (string) $row->level_score : '0.00';
            $adjScore = $row ? (string) $row->adjusted_score : '0.00';
            $wtdScore = $row ? (string) $row->weighted_score : '0.0000';

            $components[] = [
                'component_code' => $code,
                'component_name' => $name,
                'efficiency_level' => (int) $eff,
                'weighting_factor' => (int) $weight,
                'score' => (int) $score,
                'level_score' => $levelScore,
                'adjusted_score' => $adjScore,
                'weighting_score' => (int) $weight,
                'weighted_score' => $wtdScore,
                'notes' => $row?->notes,
                'sort_order' => $sort++,
            ];
        }

        $natureOptions = [];
        foreach (Scorecard::CONTROL_NATURES as $val => $data) {
            $natureOptions[] = [
                'value' => $val,
                'label' => $data['label'],
                'deduction' => sprintf('-%d%%', abs((int) ($data['deduction'] * 100))),
            ];
        }

        $globalScoreFormatted = $scorecard?->global_score !== null
            ? sprintf('%.2f%%', ((float) $scorecard->global_score) * 100)
            : null;

        return [
            'id' => $scorecard?->id,
            'control_nature' => $controlNature,
            'nature_options' => $natureOptions,
            'components' => $components,
            'level_options' => [
                ['value' => 0, 'label' => '— Please select —'],
                ['value' => 1, 'label' => '1. Very low (0.20)'],
                ['value' => 2, 'label' => '2. Low (0.40)'],
                ['value' => 3, 'label' => '3. Medium (0.55)'],
                ['value' => 4, 'label' => '4. High (0.70)'],
                ['value' => 5, 'label' => '5. Very high (0.90)'],
            ],
            'weight_options' => [
                ['value' => 0, 'label' => '— Please select —'],
                ['value' => 1, 'label' => '1. Very low (1)'],
                ['value' => 2, 'label' => '2. Low (2)'],
                ['value' => 3, 'label' => '3. Medium (3)'],
                ['value' => 4, 'label' => '4. High (4)'],
                ['value' => 5, 'label' => '5. Very high (5)'],
            ],
            'total_weight' => $scorecard?->total_weight,
            'total_weighted_score' => $scorecard?->total_weighted_score !== null ? (string) $scorecard->total_weighted_score : null,
            'global_score' => $scorecard?->global_score !== null ? (string) $scorecard->global_score : null,
            'global_score_formatted' => $globalScoreFormatted,
            'is_read_only' => $scenario->isReadOnly(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function frequency(Scenario $scenario): array
    {
        $drivers = $scenario->frequencyDrivers;
        $scorecard = $scenario->scorecard;
        $scorecardScore = $scorecard?->global_score !== null ? (float) $scorecard->global_score : null;

        $driverRows = [];
        if ($drivers && $drivers->isNotEmpty()) {
            foreach ($drivers as $d) {
                $driverRows[] = [
                    'id' => $d->id,
                    'driver_type' => $d->driver_type,
                    'criterion' => $d->criterion,
                    'computation_role' => $d->computation_role,
                    'value' => (string) $d->value,
                    'data_source' => $d->data_source,
                    'sm_scorecard_id' => $d->sm_scorecard_id,
                    'is_linked_to_scorecard' => $d->computation_role === 'control_score_complement',
                    'sort_order' => $d->sort_order,
                ];
            }
        } else {
            $driverRows = [
                [
                    'id' => null,
                    'driver_type' => 'exposed_staff_count',
                    'criterion' => 'Exposed Staff count',
                    'computation_role' => 'count',
                    'value' => '0',
                    'data_source' => 'HR information',
                    'sm_scorecard_id' => null,
                    'is_linked_to_scorecard' => false,
                    'sort_order' => 1,
                ],
                [
                    'id' => null,
                    'driver_type' => 'retention_plan_efficiency',
                    'criterion' => 'Retention plan efficiency',
                    'computation_role' => 'control_score_complement',
                    'value' => $scorecardScore !== null ? (string) $scorecardScore : '',
                    'data_source' => 'Scorecard',
                    'sm_scorecard_id' => $scorecard?->id,
                    'is_linked_to_scorecard' => true,
                    'sort_order' => 2,
                ],
                [
                    'id' => null,
                    'driver_type' => 'competition_pressure_prob',
                    'criterion' => 'Competition pressure probability',
                    'computation_role' => 'probability',
                    'value' => '',
                    'data_source' => 'Expert judgement',
                    'sm_scorecard_id' => null,
                    'is_linked_to_scorecard' => false,
                    'sort_order' => 3,
                ],
            ];
        }

        $calc = $this->computation->computeFrequency(
            collect($driverRows)->map(fn ($r) => [
                'computation_role' => $r['computation_role'],
                'value' => (float) $r['value'],
                'sm_scorecard_id' => $r['sm_scorecard_id'],
                'use_scorecard' => $r['is_linked_to_scorecard'] ?? false,
            ])->all(),
            $scorecardScore
        );

        return [
            'drivers' => $driverRows,
            'scorecard_global_score' => $scorecardScore !== null ? (string) $scorecardScore : null,
            'total_frequency' => sprintf('%.4f', $calc['total_frequency']),
            'interval_years' => sprintf('%.8f', $calc['interval_years']),
            'interval_display' => $calc['interval_display'],
            'is_read_only' => $scenario->isReadOnly(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function impact(Scenario $scenario): array
    {
        $types = $scenario->impactTypes;

        $typeRows = [];
        $sumTyp = 0.0;
        $sumSer = 0.0;
        $sumExt = 0.0;

        if ($types) {
            foreach ($types as $t) {
                $criteria = [];
                foreach ($t->criteria as $c) {
                    $criteria[] = [
                        'id' => $c->id,
                        'label' => $c->label,
                        'unit' => $c->unit,
                        'computation_role' => $c->computation_role,
                        'data_source' => $c->data_source,
                        'typical_value' => (string) $c->typical_value,
                        'serious_value' => (string) $c->serious_value,
                        'extreme_value' => (string) $c->extreme_value,
                        'sort_order' => $c->sort_order,
                    ];
                }

                $typ = (float) $t->typical_total;
                $ser = (float) $t->serious_total;
                $ext = (float) $t->extreme_total;
                $sumTyp += $typ;
                $sumSer += $ser;
                $sumExt += $ext;

                $typeRows[] = [
                    'id' => $t->id,
                    'name' => $t->name,
                    'aggregation_template' => $t->aggregation_template,
                    'eligible_to_transfer' => (bool) $t->eligible_to_transfer,
                    'sort_order' => $t->sort_order,
                    'totals' => [
                        'typical' => (string) $t->typical_total,
                        'serious' => (string) $t->serious_total,
                        'extreme' => (string) $t->extreme_total,
                    ],
                    'criteria' => $criteria,
                ];
            }
        }

        $templates = [];
        foreach (ImpactType::TEMPLATES as $val => $data) {
            $templates[] = [
                'value' => $val,
                'label' => $data['label'],
                'description' => $data['description'],
            ];
        }

        $freqCalc = $this->computation->computeFrequency(
            $scenario->frequencyDrivers->map(fn ($d) => [
                'computation_role' => $d->computation_role,
                'value' => (float) $d->value,
                'sm_scorecard_id' => $d->sm_scorecard_id,
            ])->all(),
            $scenario->scorecard ? (float) $scenario->scorecard->global_score : null
        );

        return [
            'currency' => (string) $scenario->currency,
            'frequency_interval' => sprintf('%.8f', $freqCalc['interval_years']),
            'templates' => $templates,
            'types' => $typeRows,
            'gross_impact' => [
                'typical' => sprintf('%.2f', $sumTyp),
                'serious' => sprintf('%.2f', $sumSer),
                'extreme' => sprintf('%.2f', $sumExt),
            ],
            'is_read_only' => $scenario->isReadOnly(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function insurance(Scenario $scenario): array
    {
        $coverages = $scenario->insuranceCoverages ? $scenario->insuranceCoverages->keyBy('severity') : collect();
        $severities = ['typical', 'serious', 'extreme'];

        $coverageRows = [];
        $isUninsured = true;

        foreach ($severities as $sev) {
            $cov = $coverages->get($sev);
            $trans = (float) ($cov?->transferable_amount ?? 0.0);
            if ($trans > 0.0) {
                $isUninsured = false;
            }

            $coverageRows[$sev] = [
                'transferable_amount' => (string) ($cov?->transferable_amount ?? '0.00'),
                'deductible_local' => (string) ($cov?->deductible_local ?? '0.00'),
                'limit_local' => (string) ($cov?->limit_local ?? '0.00'),
                'deductible_group' => (string) ($cov?->deductible_group ?? '0.00'),
                'limit_group' => (string) ($cov?->limit_group ?? '0.00'),
                'recovery_rate' => (string) ($cov?->recovery_rate ?? '1.0000'),
                'probability_of_cover' => (string) ($cov?->probability_of_cover ?? '1.0000'),
                'insurer_default_haircut' => (string) ($cov?->insurer_default_haircut ?? '0.0000'),
                'residual_amount' => (string) ($cov?->residual_amount ?? '0.00'),
                'total_haircut' => (string) ($cov?->total_haircut ?? '0.0000'),
                'haircut_impact' => (string) ($cov?->haircut_impact ?? '0.00'),
                'gross_impact' => (string) ($cov?->gross_impact ?? '0.00'),
                'net_impact' => (string) ($cov?->net_impact ?? '0.00'),
            ];
        }

        return [
            'currency' => (string) $scenario->currency,
            'severities' => $severities,
            'coverages' => $coverageRows,
            'is_uninsured' => $isUninsured,
            'is_read_only' => $scenario->isReadOnly(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Shared helpers */
    /* ------------------------------------------------------------------ */

    private function statusLabel(string $status): string
    {
        return (string) config(
            'sm.status_labels.'.$status,
            (string) Str::of($status)->replace('_', ' ')->ucfirst()
        );
    }
}
