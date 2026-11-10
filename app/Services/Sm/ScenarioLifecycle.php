<?php

namespace App\Services\Sm;

use App\Models\Sm\Note;
use App\Models\Sm\Scenario;
use App\Models\Sm\Signoff;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The lifecycle: the frozen transition map, the `designed` gate, and the two
 * append-only governance writes (ADR 0027 §A1.6–§A1.8, spec 01 §5).
 *
 * LIFECYCLE RULES LIVE IN THE CONTROLLER AND ANSWER WITH A FLASH, NOT A 403
 * (standard §3) — the user is allowed to try, the machine says no and says why.
 * This service is the rule; the controller is the answer. It throws
 * `ScenarioRefused` carrying the exact sentence to flash, so the rule is testable
 * without HTTP and the wording cannot drift between the two.
 *
 * THE CLIENT HOLDS NO STATE MACHINE (spec 01 §3). `transitionsFor()` is filtered by
 * permission AND status on the server and sent verbatim; a client that re-derived
 * the machine from `can` would eventually offer a transition the controller
 * refuses.
 *
 * TWO ANSWER SHAPES, AND THE SPLIT IS DELIBERATE:
 *
 *   - An ILLEGAL MOVE (not in STATUS_TRANSITIONS) is a FLASH — §A1.8, following
 *     `Risk\LossEventController::updateStatus()`.
 *   - A FAILED GATE at `in_design → designed` is a 422 from the Form Request, with
 *     spec 03 §7's messages verbatim on the frozen keys. §A1.5 is explicit that the
 *     allocation invariant "answers 422 on key `allocations`, enforced at the
 *     `in_design → designed` transition", and spec 03 §7 that "these strings must be
 *     the Form Request's messages, not client-side paraphrases".
 *
 * `gate()` is therefore read from two places: the presenter, to render the
 * readiness strip and the Design-complete pre-flight (spec 01 §5.4), and the status
 * Form Request, to produce the 422s.
 */
class ScenarioLifecycle
{
    /**
     * The `in_design → designed` gate, as `{tab, field, label, message}` items.
     *
     * EXACTLY §A1.8'S FROZEN GUARD AND NOTHING ELSE — four requirements: the
     * allocation summing to 100.00, a non-null `risk_taxonomy_node_id`, ≥1 selected
     * cause, and non-empty `background` and `rationale`. Spec 01 §5.4 (patched) says
     * so twice: "`gate` mirrors §A1.8's frozen guard on `in_design → designed` and
     * nothing else … §A1.8 is the authority and where the two could be read as
     * differing, it wins."
     *
     * THE FIVE EXPOSURE FIELDS ARE DELIBERATELY NOT GATED. An earlier draft of spec
     * 03 §7 required both scales and both nullable flags here, and addendum-1 read
     * that draft as settled. Patched spec 03 §7 reverses it: "None of the five
     * exposure fields is gated, and the UI must not gate them … §A1.8 does not list
     * them, so requiring them would block a transition the server allows — a screen
     * that enforces a stricter rule than its backend is a screen that lies about the
     * rules, and it would strand designers the freeze deliberately left unblocked."
     * They surface instead as sign-off warnings (`SignoffWarnings`), which is where a
     * signer can act on them. Gating them needs an amendment to §A1.8.
     *
     * ALSO DELIBERATELY ABSENT: spec 02 §4's extra rows — `nature`, `weighting`,
     * `currency`, `fx_rate_id`, `designed_on` and the owning unit. Spec 04 §3 reads
     * §A1.8 as "four requirements" and rules two of them out by name, "because a
     * field that warns of a requirement the server does not enforce teaches users to
     * ignore the warnings that are real". `nature` and `currency` are NOT NULL with
     * defaults so they are satisfied anyway; the rest are recorded as a spec
     * divergence in the Phase 1 handoff rather than silently enforced.
     *
     * THE STRIP IS NOT A COMPLETENESS SCORE (spec 01 §5.4). Four requirements, each
     * met or not; `gate` reports WHICH are missing and never a percentage, because a
     * percentage over four fields implies they carry equal weight and the frozen guard
     * denies that.
     *
     * @var list<array{tab: string, field: string, label: string, message: string}>
     */
    public const GATE = [
        [
            'tab' => 'overview',
            'field' => 'risk_taxonomy_node_id',
            'label' => 'OP event type',
            'message' => 'Choose the OP event type this scenario maps to.',
        ],
        [
            'tab' => 'overview',
            'field' => 'background',
            'label' => 'Background and context',
            'message' => 'Describe how this scenario materialises.',
        ],
        [
            'tab' => 'overview',
            'field' => 'rationale',
            'label' => 'Why this scenario was designed',
            'message' => 'Say why this scenario was designed.',
        ],
        [
            'tab' => 'qualitative',
            'field' => 'causes',
            'label' => 'Causes',
            'message' => "Select at least one cause. A scenario with no stated cause can't be validated.",
        ],
        [
            'tab' => 'qualitative',
            'field' => 'allocations',
            'label' => 'Business-line allocation',
            'message' => 'Allocate this scenario to at least one business line.',
        ],
    ];

    /**
     * Spec 03 §7's message for an allocation that has lines but does not sum to
     * 100.00. Kept apart from the "no lines" message above because the two are
     * different failures with different fixes, and spec 03 §5.4 is emphatic that
     * "Not allocated" is never rendered as "0%".
     */
    public const ALLOCATION_NOT_100_MESSAGE = 'Business-line allocation adds to %s%%. It must add to exactly 100%%.';

    public function __construct(
        private readonly AllocationService $allocations,
        private readonly CauseService $causes,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  The gate */
    /* ------------------------------------------------------------------ */

    /**
     * The readiness payload: `{ready, missing[]}` (spec 01 §5.4).
     *
     * Recomputed on every save and every transition attempt, across BOTH tabs, so
     * the shell can tell the truth about readiness without either tab knowing the
     * other's fields. `null` is returned for a status the gate does not apply to —
     * a `draft` has nothing to be ready for, and a strip saying "not ready to
     * submit" on a scenario nobody has started is noise (spec 01 §5.4: "while the
     * status is editable").
     *
     * @return array{ready: bool, missing: list<array{tab: string, field: string, label: string}>}|null
     */
    public function gate(Scenario $scenario): ?array
    {
        if (! in_array($scenario->status, ['draft', 'in_design', 'designed', 'returned_for_rework'], true)) {
            return null;
        }

        $missing = [];

        foreach ($this->missingGateFields($scenario) as $item) {
            $missing[] = ['tab' => $item['tab'], 'field' => $item['field'], 'label' => $item['label']];
        }

        return ['ready' => $missing === [], 'missing' => $missing];
    }

    /**
     * The gate items that are not yet satisfied, WITH their 422 messages.
     *
     * The status Form Request reads this to build its errors, so spec 03 §7's
     * strings are produced in exactly one place and the readiness strip and the 422
     * cannot disagree.
     *
     * @return list<array{tab: string, field: string, label: string, message: string}>
     */
    public function missingGateFields(Scenario $scenario): array
    {
        $scenario->loadMissing(['causes', 'allocations']);

        $total = AllocationService::total($scenario->allocations);
        $missing = [];

        foreach (self::GATE as $item) {
            $failed = match ($item['field']) {
                'risk_taxonomy_node_id' => $scenario->risk_taxonomy_node_id === null,
                'background' => trim((string) $scenario->background) === '',
                'rationale' => trim((string) $scenario->rationale) === '',
                'causes' => CauseService::selectedCodes($scenario->causes) === [],
                // Two distinct failures, two distinct messages: no lines at all,
                // versus lines that do not add to 100.00.
                'allocations' => $total === null,
                default => false,
            };

            if ($failed) {
                $missing[] = $item;
            }
        }

        // The sum rule is separate from the presence rule: a scenario with lines
        // that add to 75.5% has an allocation, it is just not a valid one, and
        // telling the designer "allocate this scenario" would be telling them to do
        // something they already did.
        if ($total !== null && ! AllocationService::isExactly100($total)) {
            foreach ($missing as $index => $item) {
                if ($item['field'] === 'allocations') {
                    unset($missing[$index]);
                }
            }

            $missing[] = [
                'tab' => 'qualitative',
                'field' => 'allocations',
                'label' => 'Business-line allocation',
                'message' => sprintf(self::ALLOCATION_NOT_100_MESSAGE, $total),
            ];
        }

        return array_values($missing);
    }

    /* ------------------------------------------------------------------ */
    /*  Transitions */
    /* ------------------------------------------------------------------ */

    /**
     * The transitions this user may take from here, in the frozen order, with the
     * words and the interaction each needs (spec 01 §5.2).
     *
     * SERVER-FILTERED BY PERMISSION **AND** STATUS. Nothing here is a suggestion
     * the client may extend.
     *
     * @return list<array{to: string, label: string, variant: string, requires_reason: bool, confirm: string|null, opens_modal: string|null}>
     */
    public function transitionsFor(Scenario $scenario, User $user): array
    {
        // Every lifecycle move needs `scenario.validate` (§A1.11 gates the status
        // route with it, and spec 01 §3 states it twice). A designer holding
        // `scenario.update` alone can fill in every field and cannot advance the
        // scenario out of `draft` — so the header renders no button for them, and
        // the readiness strip says so in words rather than leaving a silence that
        // reads as broken.
        if (! $user->can('scenario.validate')) {
            return [];
        }

        $labels = (array) config('sm.transition_labels', []);
        $out = [];

        foreach ($scenario->availableTransitions() as $to) {
            $requiresReason = $to === 'returned_for_rework';

            $out[] = [
                'to' => $to,
                'label' => $labels[$scenario->status.'>'.$to] ?? $this->humanise($to),
                'variant' => $this->variantFor($scenario->status, $to),
                'requires_reason' => $requiresReason,
                'confirm' => $this->confirmFor($scenario, $to),
                'opens_modal' => match (true) {
                    $to === 'signed_off' => 'sign_off',
                    $requiresReason => 'reason',
                    default => null,
                },
            ];
        }

        return $out;
    }

    /**
     * Move the scenario, or throw the sentence the controller flashes.
     *
     * THE STATUS CHANGE AND ITS `challenge` NOTE ARE ONE TRANSACTION (Correction 1
     * §C1.3): "a return whose reason landed in a separate write is a return with no
     * recorded reason after any failure between the two." Spec 01 §5.3 flagged the
     * two-request alternative as "a real defect"; this is the preferred option it
     * named, so a returned scenario can never exist without its reason. The audit
     * row rides the same transaction for the same reason — a trail that records a
     * change the database rolled back is a lie.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ScenarioRefused
     */
    public function transition(Scenario $scenario, string $to, User $actor, array $payload = []): Scenario
    {
        if (! $scenario->canTransitionTo($to)) {
            throw new ScenarioRefused(sprintf(
                'This scenario is %s. It can\'t be moved to %s from there.',
                Str::lower($this->humanise($scenario->status)),
                Str::lower($this->humanise($to))
            ));
        }

        // §A1.8's second transition guard, as corrected by C1.2. A flash, not a 403
        // and not a 422: the user is allowed to try, and the reason is about the
        // RECORD rather than about what they posted. Checked BEFORE the transaction
        // opens, so a refusal writes nothing at all.
        if ($to === 'signed_off') {
            $this->assertSignOffAllowed($scenario);
        }

        $reason = trim((string) ($payload['reason'] ?? ''));

        return DB::transaction(function () use ($scenario, $to, $actor, $payload, $reason) {
            $from = $scenario->status;
            $scenario->status = $to;

            // `validated_on` is stamped by the server and nobody edits it (spec 02
            // §4, §12): a designer who can type a validation date can type a date
            // nobody validated anything on. `designed_on` is NOT stamped — it is a
            // typed input in the workbook, and spec 02 §5.9 is explicit that it is
            // never pre-filled with today, because a date field that silently
            // defaults is how a scenario ends up claiming a design date nobody
            // agreed.
            if ($to === 'validated' && $scenario->validated_on === null) {
                $scenario->validated_on = KriWorkingCalendar::today()->toDateString();
            }

            if (! empty($payload['review_due_on'])) {
                $scenario->review_due_on = $payload['review_due_on'];
            }

            $scenario->updated_by = $actor->id;
            $scenario->save();

            // A return or a reopen carries a reason, and the reason is evidence:
            // spec 01 §5.3 and §5.5 both write it to the notes log as a
            // `challenge`, which is what makes the log trustworthy — the system's
            // own entries are indistinguishable in format from a human's and
            // equally attributable.
            if ($reason !== '') {
                $this->addNote($scenario, 'challenge', $reason, $actor);
            }

            AuditTrailService::record($scenario, 'status_changed', 'status', $from, $to);

            return $scenario;
        });
    }

    /**
     * The `validated → signed_off` guard: §A1.8's approved sign-off, spec 01
     * §5.6.3's independent challenge, and Correction 1 §C1.2's review date.
     *
     * THREE CONDITIONS, all refused with a FLASH and not a 403 (standard §3):
     *
     *   1. ≥1 CURRENT sign-off with `decision = approved` (§A1.8).
     *   2. At least one of them flagged `is_independent_challenge`. "Plan §6.7
     *      approved independent-challenge sign-off as the module's bias mitigation,
     *      and A CONTROL THAT ONLY EXISTS IN THE UI IS NOT A CONTROL: the server
     *      must refuse the sign-off too" (spec 01 §5.6.3).
     *   3. `review_due_on` NOT NULL (C1.2). Spec 01 §5.6.4 required it in the modal
     *      and flagged the mismatch itself — "a UI-only rule, which a stale tab or a
     *      direct POST bypasses". A requirement that exists only in JSX is not a
     *      requirement, and the result of skipping it is a signed-off scenario that
     *      never comes back for its annual refresh: precisely the governance gap
     *      plan §3.3 capability 10 exists to close, and the reason §A1.2 added
     *      `sm_scenarios_org_review_due_idx`.
     *
     * With C1.5 (an author cannot be their own independent challenge) the effect is
     * that AN AUTHOR CANNOT SIGN THEIR OWN SCENARIO OFF, alone or with a co-signer
     * they also happen to be. That is D4's bias-mitigation trail doing its job.
     *
     * @throws ScenarioRefused
     */
    public function assertSignOffAllowed(Scenario $scenario): void
    {
        $scenario->loadMissing('signoffs');

        // ONLY CURRENT ROWS COUNT — the latest per source, and only those with a
        // non-null `signed_at` (§A1.6). A source that signed, was returned, and has
        // not signed again has no current approval, and counting the superseded row
        // would sign off a scenario on evidence that was withdrawn.
        $current = $scenario->currentSignoffs();

        $approved = array_filter($current, fn (Signoff $row) => $row->decision === 'approved');

        if ($approved === []) {
            throw new ScenarioRefused(
                "This scenario can't be signed off yet: no sign-off source has approved it."
            );
        }

        $independent = array_filter(
            $approved,
            fn (Signoff $row) => (bool) $row->is_independent_challenge
        );

        if ($independent === []) {
            throw new ScenarioRefused(
                'At least one signer must be independent of the team that designed this scenario. Tick the box beside that signer.'
            );
        }

        // C1.2. Read the day column as a day: `review_due_on` is cast `date`, so
        // this is a null check and never `->isPast()` (standard §14). The wording is
        // spec 02 §5.9's verbatim: "Set the next review date before signing off."
        if ($scenario->review_due_on === null) {
            throw new ScenarioRefused('Set the next review date before signing off.');
        }
    }

    /* ------------------------------------------------------------------ */
    /*  The two append-only governance writes */
    /* ------------------------------------------------------------------ */

    /**
     * Record the sign-off rows a modal posted, in the order posted, and stamp the next
     * review date the modal collected.
     *
     * Spec 01 §5.6.7–§5.6.9: recording sign-offs and moving the status are TWO WRITES and
     * "genuinely separate acts" — a signer who is not the person that moves the status is a
     * normal case, not an error. So this only appends rows and stamps the date;
     * `transition()` is a separate call the modal makes afterwards, which is also what makes
     * C1.2's `review_due_on` guard satisfiable in one flow.
     *
     * ONE TRANSACTION, for the reason C1.3 gives about the reason-note: a sign-off set that
     * lands without its review date is a signed-off scenario that never comes back for its
     * annual refresh, and a partial write here would be indistinguishable from a full one.
     *
     * `review_due_on` is written HERE rather than through `ScenarioService::update()`
     * because the scenario is `validated` at this point and therefore read-only to content
     * edits (spec 01 §5.5). Setting the next review date is part of SIGNING, not part of
     * editing the record — the read-only rule exists to stop a designer changing what a
     * validator is reading, and this is the validator's own last act before the freeze.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{signoffs: list<Signoff>, flashes: list<string>}
     */
    public function recordSignoffs(Scenario $scenario, array $rows, User $actor, ?string $reviewDueOn = null): array
    {
        return DB::transaction(function () use ($scenario, $rows, $actor, $reviewDueOn) {
            $flashes = [];

            if ($reviewDueOn !== null && $reviewDueOn !== '') {
                $previous = $scenario->review_due_on?->toDateString();

                if ($previous !== $reviewDueOn) {
                    $scenario->review_due_on = $reviewDueOn;
                    $scenario->updated_by = $actor->id;
                    $scenario->save();

                    AuditTrailService::record(
                        $scenario,
                        'update',
                        'review_due_on',
                        $previous,
                        $reviewDueOn
                    );
                }
            }

            $signoffs = [];

            foreach ($rows as $row) {
                $result = $this->recordSignoff($scenario, $row, $actor);
                $signoffs[] = $result['signoff'];

                if ($result['flash'] !== null) {
                    $flashes[] = $result['flash'];
                }
            }

            return ['signoffs' => $signoffs, 'flashes' => $flashes];
        });
    }

    /**
     * Record one sign-off row. APPENDED, never updated (§A1.6) — there is no unique
     * key on (scenario, source), so a second round after a revalidation adds a row
     * and the earlier one stays on the trail.
     *
     * `warnings_acknowledged` is a SNAPSHOT of what was outstanding when this signer
     * signed, and is always an array — `[]` when nothing was outstanding.
     * Addendum-1: a derived list would vanish the moment somebody appended a
     * challenge note a month after sign-off, falsifying the record.
     *
     * @param  array<string, mixed>  $row
     * @return array{signoff: Signoff, flash: string|null}
     */
    public function recordSignoff(Scenario $scenario, array $row, User $actor): array
    {
        $signerId = isset($row['user_id']) && $row['user_id'] !== null && $row['user_id'] !== ''
            ? (int) $row['user_id']
            : null;
        $signer = $signerId === null ? null : User::query()->find($signerId);

        $claimedIndependent = (bool) ($row['is_independent_challenge'] ?? false);

        /*
         * C1.5 — AN AUTHOR CANNOT BE THEIR OWN INDEPENDENT CHALLENGE.
         *
         * Forced to false WITH A FLASH, not a 422: the sign-off itself is
         * legitimate and only the claim of independence is refused, so rejecting the
         * whole row would throw away a real approval to police a checkbox. D4's
         * bias-mitigation trail (PSMOR, the Fed FEDS note) rests on independent
         * challenge MEANING something, and a self-declared challenge by the
         * scenario's author is the author attesting to their own work — the exact
         * failure mode plan §3.1 says expert estimates need. Combined with C1.2's
         * "at least one independent challenge" guard, the effect is that an author
         * cannot sign their own scenario off.
         *
         * A DOMAIN RULE, not a validation rule and not a UI affordance: it is
         * enforced here so a Phase 5 import or a direct POST meets it too.
         *
         * THE NULL EDGE IS DELIBERATE AND MUST NOT BE "FIXED". `user_id` is
         * nullable (§A1.6) so an external committee member or a group CRO without an
         * account can sign; a row with `user_id = NULL` cannot be compared to
         * `created_by`, is not the author by definition, and passes — the snapshot
         * `signatory_name` remains the evidence.
         */
        $isAuthor = $signerId !== null
            && $scenario->created_by !== null
            && $signerId === (int) $scenario->created_by;

        $independent = $claimedIndependent && ! $isAuthor;

        $flash = null;

        if ($claimedIndependent && $isAuthor) {
            $flash = sprintf(
                '%s designed this scenario, so their challenge isn\'t independent. The sign-off was recorded without the independent-challenge flag.',
                (string) ($row['signatory_name'] ?? $signer?->name ?? 'That signer')
            );
        }

        $signoff = Signoff::create([
            'organization_id' => $scenario->organization_id,
            'sm_scenario_id' => $scenario->id,
            'source' => (string) $row['source'],
            'user_id' => $signer?->id,
            // SNAPSHOTS, and the typed text wins over the picked user: spec 01 §4.7
            // makes the typed snapshot the evidence, because `user_id` is
            // nullOnDelete and a sign-off whose signatory has left must still say
            // who signed it. "Signed by (deleted user) 4" is not evidence.
            'signatory_name' => (string) ($row['signatory_name'] ?? $signer?->name ?? ''),
            // A FREE-TEXT CAPACITY, not a platform role name. Spatie names are an
            // implementation detail and renaming one would silently falsify every
            // historical sign-off storing it (§A1.6).
            'role' => (string) ($row['role'] ?? ''),
            'decision' => (string) $row['decision'],
            'is_independent_challenge' => $independent,
            'comment' => isset($row['comment']) && trim((string) $row['comment']) !== ''
                ? trim((string) $row['comment'])
                : null,
            'warnings_acknowledged' => SignoffWarnings::for($scenario),
            'signed_at' => now(),
        ]);

        AuditTrailService::record(
            $scenario,
            'signoff_recorded',
            $signoff->source,
            null,
            $signoff->decision
        );

        $scenario->unsetRelation('signoffs');

        return ['signoff' => $signoff, 'flash' => $flash];
    }

    /**
     * Append a note. There is NO update and NO delete path anywhere in Phase 1 —
     * a corrected note is a new note (§A1.7).
     *
     * `author_name` is a snapshot: an anonymous dissent cannot be followed up,
     * which is worse than none. Identity is not a form field; the author is the
     * authenticated user and the timestamp is the server's.
     */
    public function addNote(Scenario $scenario, string $kind, string $body, User $actor): Note
    {
        $note = Note::create([
            'organization_id' => $scenario->organization_id,
            'sm_scenario_id' => $scenario->id,
            'kind' => $kind,
            'author_id' => $actor->id,
            'author_name' => (string) $actor->name,
            'body' => trim($body),
        ]);

        AuditTrailService::record($scenario, 'note_added', 'kind', null, $kind);

        $scenario->unsetRelation('notes');

        return $note;
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation helpers */
    /* ------------------------------------------------------------------ */

    /**
     * The sign-off a scenario carries from a PREVIOUS approval, or null.
     *
     * Spec 01 §2: "a scenario that left `signed_off` by revalidation and is now
     * editable … This is the only place the earlier sign-off survives on screen, and
     * it must survive — a validator re-reading a reopened scenario needs to know it
     * was once approved."
     *
     * @return array{signed_at: string|null, sources: list<string>}|null
     */
    public function previousSignoff(Scenario $scenario): ?array
    {
        if ($scenario->isFrozen()) {
            return null;
        }

        $scenario->loadMissing('signoffs');

        $approved = $scenario->signoffs
            ->filter(fn (Signoff $row) => $row->signed_at !== null && $row->decision === 'approved');

        if ($approved->isEmpty()) {
            return null;
        }

        $labels = (array) config('sm.sources', Signoff::SOURCES);

        return [
            'signed_at' => $approved->max(fn (Signoff $row) => $row->signed_at)?->toIso8601String(),
            'sources' => $approved
                ->unique('source')
                ->map(fn (Signoff $row) => (string) ($labels[$row->source] ?? $row->source))
                ->values()
                ->all(),
        ];
    }

    /**
     * Spec 01 §5.2's button treatments, keyed by EDGE rather than by target: the
     * same target reads differently depending on where it came from, and `archived`
     * from `draft` is not the same act as `archived` from `signed_off`.
     *
     * Colour is never the sole signal — every button also carries words — so these
     * are presentation, not semantics.
     */
    private function variantFor(string $from, string $to): string
    {
        $edge = self::VARIANTS[$from.'>'.$to] ?? null;

        if ($edge !== null) {
            return $edge;
        }

        // The one rule that holds for every source: a return is a warning.
        return $to === 'returned_for_rework' ? 'warning' : 'secondary';
    }

    /**
     * Spec 01 §5.2's `variant` column. Fourteen edges, four treatments.
     *
     * @var array<string, string>
     */
    private const VARIANTS = [
        'draft>in_design' => 'secondary',
        'draft>archived' => 'danger',
        'in_design>designed' => 'primary',
        'in_design>returned_for_rework' => 'warning',
        'designed>in_validation' => 'primary',
        'designed>in_design' => 'secondary',
        'in_validation>validated' => 'primary',
        'in_validation>returned_for_rework' => 'warning',
        'validated>signed_off' => 'primary',
        'validated>returned_for_rework' => 'warning',
        'signed_off>returned_for_rework' => 'warning',
        'signed_off>in_validation' => 'secondary',
        'signed_off>archived' => 'danger',
        'returned_for_rework>in_design' => 'primary',
    ];

    /**
     * The confirmation sentence, or null when the button acts without one.
     *
     * Keyed by edge, because spec 01 §5.5 gives the two ways out of `signed_off`
     * their own copy on purpose: "The two confirmations name each other, because
     * picking the wrong one is otherwise only discovered after the click — and the
     * difference is whether the user can then do anything about what they found."
     */
    private function confirmFor(Scenario $scenario, string $to): ?string
    {
        $edge = $scenario->status.'>'.$to;

        return match ($edge) {
            'draft>archived' => 'Archive a scenario that was never designed? It stays on the audit trail and leaves every live list.',
            'signed_off>archived' => "Archived scenarios stay on the audit trail and leave every live list. This can't be undone from the screen.",
            'designed>in_validation' => "Submitting hands this scenario to a validator. You won't be able to change it until it is validated or returned.",
            'in_validation>validated' => sprintf(
                'Mark %s as validated? You are recorded as the validator against it.',
                $scenario->reference
            ),
            'signed_off>in_validation' => sprintf(
                'Send %s back into validation? It leaves signed_off and a validator re-reads it as it stands. '
                .'It stays read-only — if it needs changes, use Reopen for revalidation instead. '
                .'It is not eligible for capital quantification while it is open.',
                $scenario->reference
            ),
            default => null,
        };
    }

    private function humanise(string $status): string
    {
        $labels = (array) config('sm.status_labels', []);

        return $labels[$status] ?? (string) Str::of($status)->replace('_', ' ')->ucfirst();
    }

    /**
     * The success flash for a completed transition, keyed by EDGE.
     *
     * The server's strings, not the client's (spec 04 §6.1 makes the same point about the
     * "created" flash): a message the JSX composes is a message a non-JS response, a stale
     * tab and an API caller never see, and two copies of one sentence drift.
     *
     * The two ways out of `signed_off` get DIFFERENT sentences on purpose — spec 01 §5.5:
     * "The two confirmations name each other, because picking the wrong one is otherwise
     * only discovered after the click." The success message is where the user learns which
     * one they got, so it states the consequence rather than just the status.
     */
    public function successMessage(Scenario $scenario, string $from, string $to): string
    {
        return match ($from.'>'.$to) {
            'draft>in_design' => 'Design started. It can be edited now.',
            'in_design>designed' => 'Marked as designed. Submit it for validation when you are ready.',
            'designed>in_validation' => "Submitted for validation. You can't change it until it is validated or returned.",
            'designed>in_design' => 'Design reopened. It can be edited again.',
            'in_validation>validated' => sprintf('Validated on %s. It is now waiting on sign-off.', $scenario->validated_on?->toDateString() ?? ''),
            'validated>signed_off' => 'Signed off. This scenario is now frozen.',
            'signed_off>returned_for_rework' => 'Reopened for revalidation. You can edit this scenario again.',
            'signed_off>in_validation' => 'Sent back into validation. It stays read-only until a validator returns it or validates it.',
            'returned_for_rework>in_design' => 'Design resumed. It can be edited now.',
            default => sprintf('Moved to %s.', Str::lower($this->humanise($to))),
        };
    }
}
