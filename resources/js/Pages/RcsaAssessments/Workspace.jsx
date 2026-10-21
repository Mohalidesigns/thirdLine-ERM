import {
    Fragment,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import AppLayout from "@/Layouts/AppLayout";
import PageHeader from "@thirdline/ui/Components/PageHeader";
import ConfirmDialog from "@thirdline/ui/Components/ConfirmDialog";
import StatusBadge from "@thirdline/ui/Components/StatusBadge";
import { assessmentStatusLabel } from "@/lib/labels";
import { calculate } from "@/lib/rcsa-calc";
import { formatScore, pluralise } from "@/lib/rcsa-format";
import useConfirm from "@/lib/useConfirm";
import useElementWidth from "@/lib/useElementWidth";
import ActionPlans from "./ActionPlans";
import GuidedStep from "./GuidedStep";
import HeatPosition from "./HeatPosition";

/**
 * The assessment workspace — steps 4 to 7 of the process flow.
 *
 * TWO MODES, ONE DATA SET. Grid is for somebody rating sixty risks in a
 * sitting; guided is for somebody rating three, twice a year, who needs to be
 * told what "High" means. They read and write the same `lines` state and the
 * same endpoint, which is what lets the toggle keep your place.
 *
 * THE LOCAL CALCULATION IS A PAINT, NOT A SAVE. `calculate()` from
 * resources/js/lib/rcsa-calc.js repaints the residual badge in the same frame
 * as the keystroke, and then the server's own figures overwrite it when the
 * PATCH returns. The client's numbers are never persisted — see
 * RcsaAssessmentService, which reads only the assessed inputs.
 */

/**
 * GRID OR GUIDED, remembered per user (RCSA-10). Not per assessment: somebody
 * who prefers the guided walk-through prefers it on every unit they open, and
 * the choice is a habit of the person, not a property of the data. Storage is
 * a convenience, never a dependency — Safari private mode, a blocked cookie
 * policy and a full quota all make `localStorage` throw, and a workspace that
 * could not open because of a view preference would be absurd. Anything
 * unreadable or unrecognised falls back to the grid.
 */
const MODES = ["grid", "guided"];

const modeKey = (userId) => `rcsa.workspace.mode.${userId ?? "anonymous"}`;

function readMode(userId) {
    try {
        const stored = window.localStorage.getItem(modeKey(userId));

        return MODES.includes(stored) ? stored : "grid";
    } catch {
        return "grid";
    }
}

function writeMode(userId, mode) {
    try {
        window.localStorage.setItem(modeKey(userId), mode);
    } catch {
        // The choice simply does not persist. Nothing else depends on it.
    }
}

const LEVEL_TONE = {
    very_low: "bg-green-100 text-green-800",
    low: "bg-lime-100 text-lime-800",
    medium: "bg-yellow-100 text-yellow-800",
    high: "bg-orange-100 text-orange-800",
    very_high: "bg-red-100 text-red-800",
};

export default function Workspace({
    assessment,
    lines: initialLines,
    methodology,
    impactCriteria = {},
    controlGuidance = [],
    outstanding: initialOutstanding,
    owners = [],
    can = {},
}) {
    const { flash, auth } = usePage().props;
    const userId = auth?.user?.id;

    const [lines, setLines] = useState(initialLines);
    const [outstanding, setOutstanding] = useState(initialOutstanding);

    /**
     * Rows with an autosave PATCH in flight right now, as a REFERENCE COUNT
     * keyed by line id, not a Set. Arrow keys move a row down a column fast
     * enough that a second edit can reach the server before the first one's
     * response does — two overlapping saves on the same row — and a Set's
     * `delete` on the first one to settle would mark the row no-longer-in-
     * flight while the second save is still out. `begin`/`end` keep a count
     * per id instead, so the row stays "in flight" until every save that
     * started on it has settled, not just the most recent.
     *
     * A ref, not state: the resync effect below only needs the current value
     * at the moment `initialLines` changes, and a ref reading does not need
     * to be a dependency or cause an extra render.
     */
    const inFlightRef = useRef(new Map());

    const beginInFlight = useCallback((id) => {
        inFlightRef.current.set(id, (inFlightRef.current.get(id) ?? 0) + 1);
    }, []);

    const endInFlight = useCallback((id) => {
        const remaining = (inFlightRef.current.get(id) ?? 0) - 1;

        if (remaining <= 0) {
            inFlightRef.current.delete(id);
        } else {
            inFlightRef.current.set(id, remaining);
        }
    }, []);

    const isInFlight = useCallback(
        (id) => (inFlightRef.current.get(id) ?? 0) > 0,
        [],
    );

    /**
     * Inertia keeps this same component instance mounted across a reload of
     * this page — a partial `router.reload({ only: [...] })` after a plan
     * change, or the full visit a `form.post` redirect produces after
     * replying to a challenge. Both land as new `lines`/`outstanding` PROPS,
     * but `useState(initialLines)` only reads its argument on the very first
     * render, so without these the grid kept showing "Plan needed" and a
     * dropped thread until the user forced a real browser reload, which is a
     * new component instance and so a new initial value.
     *
     * A ROW WITH A SAVE IN FLIGHT IS SKIPPED, wholesale. A reload triggered
     * by an edit on a DIFFERENT row — another line's plan change, a bulk
     * apply — can be built from a SELECT that ran before THIS row's own
     * PATCH committed, and taking `initialLines` wholesale would overwrite
     * the rating this row just painted with the stale value the reload
     * fetched. It self-heals once the PATCH resolves and `mergeServerLine`
     * applies the authoritative answer, but a demo should not show a
     * keystroke visibly reverting itself first. Relations still come off
     * the fresh prop even for a skipped row — they are what the reload was
     * for, and a rating edit never changes them anyway.
     *
     * A ROW WHOSE LOCAL `version` IS AHEAD IS ALSO SKIPPED, even once the
     * save that got it there has settled. `isInFlight` alone only covers
     * the request itself; a reload built off a SELECT that ran BEFORE this
     * row's PATCH committed can still arrive AFTER the PATCH's own response
     * already updated `lines` via `mergeServerLine` — there is no window
     * where `isInFlight` is true but the stale reload is what's landing.
     * Without this, the resync would put the row back to a value the
     * server no longer holds, and the assessor's very next edit would send
     * that stale `version` and be refused with a 409 it did nothing to
     * cause. `RcsaAssessmentService::apply()` only ever increments
     * `version` (never resets it), so "higher wins" is always correct
     * here, not just usually.
     */
    useEffect(() => {
        setLines((current) => {
            const byId = new Map(current.map((row) => [row.id, row]));

            return initialLines.map((incoming) => {
                const local = byId.get(incoming.id);

                if (!local) return incoming;

                const keepLocal =
                    isInFlight(incoming.id) ||
                    Number(local.version) > Number(incoming.version);

                if (!keepLocal) return incoming;

                return {
                    ...local,
                    comments: incoming.comments ?? [],
                    action_plans: incoming.action_plans ?? [],
                    action_plans_count: incoming.action_plans_count,
                };
            });
        });
    }, [initialLines, isInFlight]);

    useEffect(() => {
        setOutstanding(initialOutstanding);
    }, [initialOutstanding]);

    /**
     * The TRUE number outstanding, which is not `issues.length`.
     *
     * The server caps the list it sends — the panel renders forty and a
     * 2,000-line assessment would otherwise ship an issue per line — so every
     * message about "how many things are left" reads this instead. Falling back
     * to the array keeps an older cached response rendering sensibly rather
     * than showing zero and enabling submit.
     */
    const issueCount = outstanding.issue_count ?? outstanding.issues.length;
    const [completion, setCompletion] = useState(assessment.completion_pct);

    // The header badge. The first saved rating moves a "Not started" assessment
    // to "In progress" on the server, and the line save answers with the new
    // status — the badge follows that instead of waiting for a reload (and
    // instead of guessing). A fresh page load resets it to the prop.
    const [liveStatus, setLiveStatus] = useState(assessment.status);

    useEffect(() => setLiveStatus(assessment.status), [assessment.status]);
    // Lazy initialiser: read storage once, on mount, not on every render.
    const [mode, setModeState] = useState(() => readMode(userId));
    const setMode = useCallback(
        (next) => {
            setModeState(next);
            writeMode(userId, next);
        },
        [userId],
    );
    const [confirmingSubmit, setConfirmingSubmit] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [cursor, setCursor] = useState(0);
    const [incompleteOnly, setIncompleteOnly] = useState(false);
    const [selected, setSelected] = useState([]);
    const [conflict, setConflict] = useState(null);
    const [saving, setSaving] = useState({});
    const [planningFor, setPlanningFor] = useState(null);
    const [rejecting, setRejecting] = useState(false);
    const [rejectReason, setRejectReason] = useState("");

    const editable = assessment.editable && can.complete;

    /**
     * Editability is PER LINE, not per assessment.
     *
     * On a returned assessment the two differ: the assessment accepts edits, but
     * only the risks the ORM flagged were actually reopened. `is_locked` comes
     * off the line's own `locked_at`, which is the same column the server
     * checks — so a row that looks disabled here is a row that would be refused
     * with a 423 there, rather than a row the UI merely discourages.
     */
    const canEditLine = useCallback(
        (line) => editable && !line.is_locked,
        [editable],
    );

    const visible = useMemo(
        () =>
            incompleteOnly ? lines.filter((line) => !line.is_scored) : lines,
        [lines, incompleteOnly],
    );

    /* ------------------------------------------------------------------ */
    /*  Saving                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The row as the client's own engine would show it after `changes`.
     *
     * Pure: it reads nothing but its arguments, so it can run inside a
     * functional state updater against the row AS IT STANDS rather than the
     * `line` a handler closed over. Used for the optimistic paint on every
     * edit, and again to RE-PAINT a still-pending edit on top of a server
     * answer that arrived underneath it (see the outbox below).
     */
    const paintRow = useCallback(
        (row, changes) => {
            const next = { ...row, ...changes };
            const local = calculate(
                {
                    likelihood: next.inherent_likelihood,
                    impact: next.inherent_impact,
                    controlEffectiveness: next.control_effectiveness,
                    residualLikelihood: next.residual_likelihood,
                    residualImpact: next.residual_impact,
                },
                methodology,
            );

            return {
                ...next,
                inherent_score: local.inherentScore,
                inherent_level: local.inherentLevel,
                ce_modifier: local.ceModifier,
                residual_score: local.residualScore,
                residual_level: local.residualLevel,
                risk_treatment: local.riskTreatment,
                appetite_status: local.appetiteStatus,
                is_scored: local.isComplete,
            };
        },
        [methodology],
    );

    const paintLine = useCallback(
        (id, changes) => {
            setLines((current) =>
                current.map((row) =>
                    row.id === id ? paintRow(row, changes) : row,
                ),
            );
        },
        [paintRow],
    );

    /**
     * The autosave endpoint's `line` never carries the reviewer's thread or
     * the action plans.
     *
     * `updateLine()` reads the row off route-model binding and calls
     * `$line->refresh()`, which only reloads relations that were ALREADY
     * loaded on that instance — and this one never eager-loaded `comments`
     * or `actionPlans`. So every autosave response's row has an empty
     * `comments` array and a zeroed `action_plans`, not because the reviewer's
     * challenge or the plan was deleted, but because the endpoint never asked
     * the database about either. Blindly replacing the row with that response
     * is what made a reopened row's challenge thread and Reply box vanish the
     * moment a rating changed.
     *
     * MERGED AGAINST LIVE STATE, via the functional updater, not against the
     * `line` closed over when `save()` was called. Autosave is in flight for
     * real seconds; a plan can be deleted, or a reply can land through the F3
     * resync, on this very row before the PATCH resolves. Reading `comments`/
     * `action_plans` off the stale closure would restore whatever had just
     * been removed, and the staleness would compound on every further edit
     * until a hard reload. Every OTHER field comes from `incoming` — the
     * rating and every calculated column the server just computed are
     * authoritative — only the two relations the endpoint never queried are
     * taken from the row as it stands right now.
     */
    const mergeServerLine = useCallback(
        (incoming, overlay = null) => {
            setLines((current) =>
                current.map((row) => {
                    if (row.id !== incoming.id) return row;

                    const merged = {
                        ...incoming,
                        comments: row.comments ?? [],
                        action_plans: row.action_plans ?? [],
                        action_plans_count:
                            row.action_plans_count ??
                            (row.action_plans ?? []).length,
                    };

                    // `overlay` is an edit the user has already made that the
                    // server has not been told about yet. It goes back on top
                    // of the answer so the row does not flicker to the
                    // previous value between one PATCH settling and the next
                    // being sent.
                    return overlay ? paintRow(merged, overlay) : merged;
                }),
            );
        },
        [paintRow],
    );

    /**
     * `mergeServerLine`'s sibling for when the PATCH never reached the
     * server at all — a network failure, not a 409/423 — and the optimistic
     * paint has to come back off.
     *
     * NARROWER than putting the whole `line` back, on purpose. `line` is the closure
     * captured when `save()` was called: replacing the whole row with it
     * would revert a plan delete or a reply that landed on this same row
     * while the request was in flight, exactly the staleness
     * `mergeServerLine` exists to avoid on the success path. Only the fields
     * the optimistic paint touched — the answered inputs (`changes`), the
     * columns `calculate()` repainted, and `version`, which the paint never
     * bumped but is included for the same belt-and-braces reason the
     * calculated columns are — are put back to what `line` had; everything
     * else, including `comments`/`action_plans`/`action_plans_count`, comes
     * off the row as it stands right now.
     */
    const mergeLocalRevert = useCallback((line, changes) => {
        const revertedFields = [
            ...Object.keys(changes),
            "inherent_score",
            "inherent_level",
            "ce_modifier",
            "residual_score",
            "residual_level",
            "risk_treatment",
            "appetite_status",
            "is_scored",
            "version",
        ];

        setLines((current) =>
            current.map((row) => {
                if (row.id !== line.id) return row;

                const reverted = { ...row };
                revertedFields.forEach((field) => {
                    reverted[field] = line[field];
                });

                return reverted;
            }),
        );
    }, []);

    /**
     * ONE PATCH IN FLIGHT PER ROW, EDITS ON A BUSY ROW COALESCED (RCSA-01).
     *
     * Likelihood, impact and control effectiveness are three separate
     * controls, and a fast assessor sets all three inside a second. Each edit
     * used to fire its own PATCH carrying the `version` the render closure
     * held, so all three went out with the SAME version and overlapped on the
     * server; the row ended up holding all three answers and no scores. The
     * server now treats `version` as a compare-and-swap, which turns the
     * second and third of those into 409s — so the client must not send them
     * concurrently in the first place.
     *
     * The rule, per line id:
     *   1. At most one PATCH is out at any moment.
     *   2. An edit made while one is out is merged into `pending` (a later
     *      edit to the same field wins) and painted at once through
     *      calculate(), exactly like any other edit. Nothing is sent yet.
     *   3. When the in-flight PATCH settles on a 200, the pending object goes
     *      out as ONE PATCH, with `version` taken from that 200's
     *      `line.version` — the server's latest row, never the stale closure
     *      the edit was typed under.
     *   4. On a 409/423 the pending edits are DROPPED. The response carries
     *      the current row and the conflict banner shows the other person's
     *      answer; replaying a stale edit on top of it would overwrite them
     *      with something typed against a row that no longer exists.
     *   5. On a network failure or timeout the pending edits are dropped too,
     *      the optimistic paint is reverted and the page reloaded, as before:
     *      we do not know what the server holds, so we do not guess.
     *
     * `inFlightRef` still counts per id, and the next PATCH is BEGUN before
     * the settled one is ENDED, so the count never dips to zero between two
     * chained requests — that dip is a window in which the resync effect
     * above would let a stale reload overwrite the row's painted edit.
     *
     * The outbox lives in a ref, not state: nothing renders from it, and it
     * has to be read and written synchronously from request callbacks that
     * outlive the render they were created in.
     */
    const outboxRef = useRef(new Map());

    const refreshOutstanding = useCallback(() => {
        window.axios
            .get(route("rcsa.assessments.outstanding", assessment.id))
            .then(({ data }) => setOutstanding(data))
            .catch(() => {});
    }, [assessment.id]);

    const dispatchLine = useCallback(
        (id) => {
            const box = outboxRef.current.get(id);

            if (!box || box.sending || !box.pending) return;

            const changes = box.pending;

            box.pending = null;
            box.sending = true;
            box.inflight = changes;

            setSaving((s) => ({ ...s, [id]: true }));

            beginInFlight(id);

            window.axios
                .patch(
                    route("rcsa.assessments.lines.update", [
                        assessment.id,
                        id,
                    ]),
                    {
                        ...changes,
                        // The version of the latest row the server told us
                        // about — see rule 3.
                        version: box.version,
                    },
                    // Per-request, not global: a stalled PATCH must not hang
                    // the row's in-flight count forever, but every other
                    // call on `window.axios` keeps its own timeout behaviour.
                    // A timeout settles through the network-failure branch
                    // below exactly like any other failed request —
                    // mergeLocalRevert() puts the row back, and end() runs in
                    // .finally() either way.
                    { timeout: 20000 },
                )
                .then(({ data }) => {
                    box.version = data.line.version;
                    box.confirmed = data.line;

                    // The server's figures replace the painted ones. They agree
                    // unless the two implementations have drifted, which is what
                    // the shared truth table exists to prevent. A still-pending
                    // edit is painted back on top (see mergeServerLine).
                    mergeServerLine(data.line, box.pending);
                    setCompletion(data.completion_pct);
                    if (data.status) setLiveStatus(data.status);
                    setConflict(null);

                    // Only the LAST response of a burst refreshes the panel;
                    // the others would be superseded within the second.
                    if (!box.pending) refreshOutstanding();
                })
                .catch((error) => {
                    const status = error?.response?.status;
                    const dropped = box.pending;

                    box.pending = null;

                    if (status === 409 || status === 423) {
                        // Show what is actually there now, so the user can see
                        // the other person's answer rather than only be told
                        // that they lost. Rule 4: pending edits are dropped.
                        if (error.response.data?.line)
                            mergeServerLine(error.response.data.line);
                        setConflict(
                            error.response.data?.message ??
                                "That risk changed while you were working on it.",
                        );
                    } else {
                        // A timeout or a network error means the RESPONSE
                        // never arrived — it does not mean the PATCH never
                        // reached the server. The request may well have
                        // committed; only the acknowledgement was lost. So
                        // the row is put back to what it looked like before
                        // this edit, honestly, rather than left showing a
                        // value the assessor typed but the server may or may
                        // not hold — and then reconciled with a reload, so
                        // the screen shows whatever the server actually has
                        // within one round-trip. If the save DID land, its
                        // `version` is higher than what mergeLocalRevert just
                        // put back, and the resync above keeps the reload's
                        // answer rather than the reverted one.
                        setConflict("That may not have saved — checking…");
                        mergeLocalRevert(box.confirmed, {
                            ...changes,
                            ...(dropped ?? {}),
                        });
                        router.reload({ only: ["lines", "outstanding"] });
                    }
                })
                .finally(() => {
                    box.sending = false;
                    box.inflight = null;

                    // Chain BEFORE ending, so the count stays above zero.
                    if (box.pending) dispatchLine(id);

                    endInFlight(id);

                    if (!box.sending && !box.pending) {
                        if (outboxRef.current.get(id) === box)
                            outboxRef.current.delete(id);
                        setSaving((s) => ({ ...s, [id]: false }));
                    }
                });
        },
        [
            assessment.id,
            mergeServerLine,
            mergeLocalRevert,
            refreshOutstanding,
            beginInFlight,
            endInFlight,
        ],
    );

    /**
     * Paint the computed columns locally, then queue the save.
     *
     * The optimistic values come from the same engine the server uses, so in
     * the normal case the row does not visibly change when the response lands.
     * When it does — because somebody else got there first — the server wins
     * and the conflict banner says so.
     */
    const save = useCallback(
        (line, changes) => {
            if (!editable) return;

            // Every synchronous, throwable step happens BEFORE the outbox is
            // touched: if calculate() or a state setter were ever to throw,
            // a box with nothing to send must not be left behind to wedge
            // the row. paintLine's updater runs later, inside React.
            paintRow(line, changes);

            let box = outboxRef.current.get(line.id);

            if (!box) {
                // Idle row: the render closure IS the latest row, so its
                // version is current. `confirmed` is the pre-edit snapshot a
                // network failure reverts to.
                box = {
                    sending: false,
                    pending: null,
                    inflight: null,
                    version: line.version,
                    confirmed: line,
                };
                outboxRef.current.set(line.id, box);
            }

            box.pending = { ...(box.pending ?? {}), ...changes };

            paintLine(line.id, changes);
            dispatchLine(line.id);
        },
        [editable, paintRow, paintLine, dispatchLine],
    );

    const afterPlanChange = useCallback(() => {
        router.reload({ only: ["lines", "outstanding"] });
    }, []);

    /* ------------------------------------------------------------------ */
    /*  Keyboard                                                           */
    /* ------------------------------------------------------------------ */

    const gridRef = useRef(null);
    const gridWidth = useElementWidth(gridRef, mode);

    useEffect(() => {
        const onKey = (e) => {
            if (mode !== "guided") return;

            if (e.key === "ArrowRight" || e.key === "PageDown") {
                e.preventDefault();
                setCursor((c) => Math.min(c + 1, visible.length - 1));
            }
            if (e.key === "ArrowLeft" || e.key === "PageUp") {
                e.preventDefault();
                setCursor((c) => Math.max(c - 1, 0));
            }
        };

        window.addEventListener("keydown", onKey);

        return () => window.removeEventListener("keydown", onKey);
    }, [mode, visible.length]);

    /**
     * Arrow-key navigation between cells in grid mode.
     *
     * Down and Up move a row and keep the column, which is how somebody rates
     * one question all the way down a list. Enter does the same, because that
     * is what a spreadsheet does.
     */
    const onCellKeyDown = (e, rowIndex, field) => {
        const move = (delta) => {
            e.preventDefault();
            const target = gridRef.current?.querySelector(
                `[data-cell="${rowIndex + delta}:${field}"]`,
            );
            target?.focus();
        };

        if (e.key === "ArrowDown" || e.key === "Enter") move(1);
        if (e.key === "ArrowUp") move(-1);
    };

    /* ------------------------------------------------------------------ */
    /*  Bulk apply                                                         */
    /* ------------------------------------------------------------------ */

    const [bulkValue, setBulkValue] = useState("");
    const [confirm, confirmDialog] = useConfirm();

    const applyBulk = () => {
        if (!bulkValue || selected.length === 0) return;

        confirm({
            title: `Set control effectiveness on ${pluralise(selected.length, "risk")}?`,
            message: `Every selected risk is set to "${bulkValue}". This is a material change and is recorded against each one.`,
            confirmLabel: "Apply",
            variant: "warning",
            onConfirm: () =>
                router.post(
                    route("rcsa.assessments.bulk-apply", assessment.id),
                    { line_ids: selected, control_effectiveness: bulkValue },
                    {
                        preserveScroll: true,
                        onSuccess: () => {
                            setSelected([]);
                            setBulkValue("");
                            router.reload({
                                only: ["lines", "outstanding", "assessment"],
                            });
                        },
                    },
                ),
        });
    };

    /**
     * Submitting locks every risk and tells people, and nothing here can take
     * it back — so it asks first (RCSA-04), in-page, with the consequence
     * stated as numbers rather than "are you sure?". The blockers check stays
     * ahead of the dialog: there is no point confirming something the server
     * is about to refuse.
     *
     * `assessment.reviewer_count` is used when the page supplies it. It is not
     * guessed: the count of people notified is decided server-side (ORM
     * reviewers, or the BU head when that step is on), so without the prop
     * the dialog says "reviewers" rather than invent a figure.
     */
    const submit = () => {
        if (issueCount > 0) {
            window.alert(
                `${pluralise(issueCount, "thing")} still to do. They are listed on the right — click one to jump to it.`,
            );

            return;
        }

        setConfirmingSubmit(true);
    };

    const confirmSubmit = () => {
        setSubmitting(true);

        router.post(
            route("rcsa.assessments.submit", assessment.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setSubmitting(false);
                    setConfirmingSubmit(false);
                },
            },
        );
    };

    const reviewerCount = Number.isInteger(assessment.reviewer_count)
        ? assessment.reviewer_count
        : null;

    /*
     * WHO IT GOES TO is decided server-side (`assessment.submit_target`): the
     * business-unit head when that step is on, the ORM team otherwise. The copy
     * and the button follow it, so a unit is never told "ORM review" about a
     * submission that actually goes to its own head. Absent, it is the ORM
     * wording — what every cycle said before the step existed.
     */
    const toBuHead = assessment.submit_target === "bu_head";
    const unitName = assessment.business_unit ?? "this assessment";

    const submitTitle = toBuHead
        ? "Submit for business-unit approval"
        : "Submit for ORM review";
    const submitLabel = toBuHead ? "Submit for approval" : "Submit for review";

    const submitMessage = toBuHead
        ? `Submit ${unitName} to your business-unit head for approval? ${pluralise(lines.length, "risk")} will be locked until they decide. A PDF record of what you filed is kept.`
        : `Submit ${unitName} for ORM review? ${pluralise(lines.length, "risk")} will be locked and ${
              reviewerCount === null
                  ? "reviewers will be notified"
                  : `${pluralise(reviewerCount, "reviewer")} will be notified`
          }. A PDF record of what you filed is kept.`;

    /**
     * `type` is the outstanding issue's type, when the jump came from one.
     *
     * A "movement" issue is answered by the rationale, and the rationale field
     * lives only on the Guided step — the grid has no column for free text. In
     * Grid mode the blocker used to scroll to a row that offered no way to
     * clear it, so the submit stayed blocked with nothing to click. It now
     * switches to that risk in Guided mode and puts the cursor in the field.
     */
    const jumpTo = (lineId, type = null) => {
        const index = visible.findIndex((line) => line.id === lineId);

        if (index === -1) return;

        if (mode === "guided" || type === "movement") {
            if (mode !== "guided") setMode("guided");
            setCursor(index);

            if (type === "movement") {
                // After the step has rendered for this line.
                window.setTimeout(() => {
                    document.getElementById(`rationale-${lineId}`)?.focus();
                }, 0);
            }

            return;
        }

        // Scroll the TABLE'S container, not the page (RCSA-11).
        // `scrollIntoView` walks every scrollable ancestor, and one of them is
        // the app's main pane — at ~1080px it shifted the whole pane left
        // under the sidebar. Working out the offset against the container and
        // scrolling only that keeps the rest of the page where it is.
        const container = gridRef.current;
        const row = container?.querySelector(`[data-row="${lineId}"]`);

        if (!container || !row) return;

        const header = container.querySelector("thead");
        const headerHeight = header?.getBoundingClientRect().height ?? 0;
        const offset =
            row.getBoundingClientRect().top -
            container.getBoundingClientRect().top +
            container.scrollTop;

        container.scrollTo({
            top: Math.max(
                offset - headerHeight - container.clientHeight / 3,
                0,
            ),
            behavior: "smooth",
        });
    };

    const current = visible[Math.min(cursor, Math.max(visible.length - 1, 0))];

    return (
        <AppLayout
            header={
                <PageHeader
                    title={assessment.business_unit ?? "Assessment"}
                    subtitle={`${assessment.cycle.name}${assessment.cycle.due_date ? ` · due ${assessment.cycle.due_date}` : ""}`}
                    breadcrumbs={[
                        {
                            label: "RCSA cycles",
                            href: route("rcsa.cycles.index"),
                        },
                        {
                            label: assessment.cycle.name,
                            href: route(
                                "rcsa.cycles.show",
                                assessment.cycle.id,
                            ),
                        },
                        { label: assessment.business_unit ?? "Assessment" },
                    ]}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge
                                status={liveStatus}
                                label={assessmentStatusLabel(liveStatus)}
                            />
                            {/*
                             * The optional BU-head step of §9.1. Shown only in
                             * `bu_approval`, and only to somebody other than
                             * whoever submitted — the policy refuses a
                             * self-approval and the button is not drawn for it.
                             */}
                            {can.approve &&
                                assessment.status === "bu_approval" && (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(
                                                    route(
                                                        "rcsa.assessments.approve",
                                                        assessment.id,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="btn-primary text-sm"
                                        >
                                            Approve and send to ORM
                                        </button>
                                        <button
                                            type="button"
                                            // In-page, never window.prompt: it throws
                                            // in the preview browser, and P1 already
                                            // shipped two features that could not be
                                            // used because of it.
                                            onClick={() =>
                                                setRejecting((open) => !open)
                                            }
                                            className="btn-secondary text-sm"
                                        >
                                            Send back
                                        </button>
                                    </>
                                )}

                            {/*
                             * §10.4. Offered on the workspace because that is
                             * where somebody realises they are about to lose
                             * connectivity — not buried on an export screen
                             * they have no permission for.
                             */}
                            {editable && (
                                <>
                                    <a
                                        href={route(
                                            "rcsa.round-trip.working-copy",
                                            assessment.id,
                                        )}
                                        className="btn-secondary text-sm"
                                        title="Download this assessment as a spreadsheet to fill in offline"
                                    >
                                        Working copy
                                    </a>
                                    <label className="btn-secondary cursor-pointer text-sm">
                                        Upload
                                        <input
                                            type="file"
                                            accept=".xlsx"
                                            className="hidden"
                                            onChange={(e) => {
                                                const file =
                                                    e.target.files?.[0];

                                                if (file) {
                                                    router.post(
                                                        route(
                                                            "rcsa.round-trip.store",
                                                            assessment.id,
                                                        ),
                                                        { file },
                                                        { forceFormData: true },
                                                    );
                                                }

                                                e.target.value = "";
                                            }}
                                        />
                                    </label>
                                </>
                            )}

                            {/*
                             * §11's read-only history. Offered to anybody who
                             * may view the assessment: seeing who changed what
                             * on your own unit's work is ordinary, and the
                             * estate-wide trail behind it has its own gate.
                             */}
                            <a
                                href={route("rcsa.audit.show", assessment.id)}
                                className="btn-secondary text-sm"
                                title="Who changed what, and when"
                            >
                                Audit
                            </a>

                            {can.submit && assessment.editable && (
                                <button
                                    type="button"
                                    onClick={submit}
                                    disabled={issueCount > 0}
                                    title={
                                        issueCount > 0
                                            ? `${pluralise(issueCount, "thing")} still to do — see the panel on the right`
                                            : submitTitle
                                    }
                                    className="btn-primary text-sm disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {submitLabel}
                                </button>
                            )}

                            <div className="flex rounded-md border border-gray-300 p-0.5">
                                {["grid", "guided"].map((option) => (
                                    <button
                                        key={option}
                                        type="button"
                                        onClick={() => setMode(option)}
                                        className={`rounded px-3 py-1 text-sm capitalize ${
                                            mode === option
                                                ? "bg-[var(--color-primary)] text-white"
                                                : "text-gray-600"
                                        }`}
                                    >
                                        {option}
                                    </button>
                                ))}
                            </div>
                        </div>
                    }
                />
            }
        >
            <Head
                title={`${assessment.business_unit} — ${assessment.cycle.name}`}
            />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800">
                    {flash.success}
                </div>
            )}

            {!editable && (
                <div className="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                    This assessment is read-only
                    {assessment.cycle.status === "closed"
                        ? " because its cycle is closed."
                        : ` because it is ${assessment.status === "bu_approval" ? "awaiting BU approval" : assessment.status.replace(/_/g, " ")}.`}
                    {assessment.awaiting &&
                        " It is with somebody else for a decision."}
                </div>
            )}

            {rejecting && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            route("rcsa.assessments.reject", assessment.id),
                            { reason: rejectReason },
                            {
                                preserveScroll: true,
                                onSuccess: () => setRejecting(false),
                            },
                        );
                    }}
                    className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4"
                >
                    <label className="mb-1 block text-sm font-medium text-amber-900">
                        Send this back to the assessor
                    </label>
                    <p className="mb-2 text-xs text-amber-800">
                        You have no per-risk challenge, so this reopens every
                        risk in the assessment. Say why — this is what the
                        assessor reads.
                    </p>
                    <textarea aria-label="Reason for reopening"
                        className="form-textarea"
                        rows={2}
                        value={rejectReason}
                        onChange={(e) => setRejectReason(e.target.value)}
                    />
                    <div className="mt-2 flex gap-2">
                        <button
                            type="submit"
                            className="btn-primary text-sm"
                            disabled={rejectReason.trim().length < 10}
                        >
                            Send back
                        </button>
                        <button
                            type="button"
                            className="btn-secondary text-sm"
                            onClick={() => setRejecting(false)}
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            )}

            {/*
             * A RETURNED ASSESSMENT MUST SAY WHAT WAS ASKED OF IT, and how much
             * of it actually reopened. The ORM reopens only the risks it
             * flagged, and an assessor who was not told that spends the morning
             * wondering why the other rows will not accept a keystroke.
             */}
            {assessment.status === "returned" && (
                <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <strong>Returned for rework.</strong>{" "}
                    {assessment.returned_reason && (
                        <span>“{assessment.returned_reason}” </span>
                    )}
                    <span className="mt-1 block">
                        {assessment.reopened_count} of {pluralise(lines.length, "risk")}{" "}
                        {lines.length === 1 ? "was" : "were"} reopened — the ones the reviewer flagged. The rest stay
                        locked exactly as they were filed. Reopened rows carry
                        the reviewer's comment; answer it or change your rating,
                        then submit again.
                    </span>
                </div>
            )}

            {conflict && (
                <div className="mb-4 flex items-start justify-between gap-4 rounded-lg border border-amber-300 bg-amber-50 p-4">
                    <p className="text-sm text-amber-900">{conflict}</p>
                    <button
                        onClick={() => setConflict(null)}
                        className="text-sm text-amber-700 hover:underline"
                    >
                        Dismiss
                    </button>
                </div>
            )}

            {/*
              * RCSA-11. The side panel only sits BESIDE the grid from `xl`
              * up. With the app sidebar open, a 1080px window leaves the main
              * pane ~814px, and a 320px panel took the table down to ~430px —
              * three columns wide. `minmax(0, 1fr)` is what lets the track
              * shrink below the table's own min-content, so the table scrolls
              * inside its card (`overflow-auto` below) instead of widening
              * the page.
              */}
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
                <div className="min-w-0">
                    {/* Toolbar */}
                    <div className="mb-3 flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-2 text-sm text-gray-600">
                            <input
                                type="checkbox"
                                checked={incompleteOnly}
                                onChange={(e) =>
                                    setIncompleteOnly(e.target.checked)
                                }
                                className="form-checkbox"
                            />
                            Incomplete only
                        </label>

                        <span className="text-sm text-gray-500">
                            {visible.length} of {pluralise(lines.length, "risk")}
                        </span>

                        {selected.length > 0 && editable && (
                            <div className="ml-auto flex items-center gap-2">
                                <span className="text-sm text-gray-600">
                                    {selected.length} selected
                                </span>
                                <select aria-label="Bulk action"
                                    className="filter-select"
                                    value={bulkValue}
                                    onChange={(e) =>
                                        setBulkValue(e.target.value)
                                    }
                                >
                                    <option value="">
                                        Set control effectiveness…
                                    </option>
                                    {methodology.controlEffectiveness.map(
                                        (item) => (
                                            <option
                                                key={item.label}
                                                value={item.label}
                                            >
                                                {item.label}
                                            </option>
                                        ),
                                    )}
                                </select>
                                <button
                                    type="button"
                                    onClick={applyBulk}
                                    className="btn-primary text-sm"
                                >
                                    Apply
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setSelected([])}
                                    className="text-sm text-gray-500 hover:underline"
                                >
                                    Clear
                                </button>
                            </div>
                        )}
                    </div>

                    {mode === "grid" ? (
                        <div className="card">
                            <div
                                className="max-h-[70vh] overflow-auto"
                                ref={gridRef}
                            >
                                <table className="data-table">
                                    <thead className="sticky top-0 z-10 bg-white">
                                        <tr>
                                            <th className="w-8" />
                                            <th className="sticky left-0 z-20 bg-white">
                                                Risk
                                            </th>
                                            <th className="w-28">Likelihood</th>
                                            <th className="w-28">Impact</th>
                                            <th className="w-20">Inherent</th>
                                            <th className="w-32">
                                                Control effectiveness
                                            </th>
                                            <th className="w-20">Residual</th>
                                            <th className="w-28">Treatment</th>
                                            <th className="w-24">Last cycle</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {visible.map((line, rowIndex) => (
                                            <Fragment key={line.id}>
                                                <tr
                                                    data-row={line.id}
                                                    className={
                                                        saving[line.id]
                                                            ? "opacity-70"
                                                            : ""
                                                    }
                                                >
                                                    <td>
                                                        <input
                                                            type="checkbox"
                                                            checked={selected.includes(
                                                                line.id,
                                                            )}
                                                            onChange={() =>
                                                                setSelected(
                                                                    (s) =>
                                                                        s.includes(
                                                                            line.id,
                                                                        )
                                                                            ? s.filter(
                                                                                  (
                                                                                      id,
                                                                                  ) =>
                                                                                      id !==
                                                                                      line.id,
                                                                              )
                                                                            : [
                                                                                  ...s,
                                                                                  line.id,
                                                                              ],
                                                                )
                                                            }
                                                            aria-label={`Select ${line.risk_no}`}
                                                            className="form-checkbox"
                                                        />
                                                    </td>

                                                    <td className="sticky left-0 z-10 bg-white">
                                                        <span className="whitespace-nowrap font-mono text-xs text-gray-500">
                                                            {line.risk_no}
                                                        </span>
                                                        <p className="max-w-[320px] truncate text-sm text-gray-700">
                                                            {
                                                                line.potential_risk
                                                            }
                                                        </p>
                                                        {/*
                                                         * On a returned assessment, why this row is or is not
                                                         * open. Without it the assessor sees a grid where some
                                                         * rows take a keystroke and others do not.
                                                         */}
                                                        {line.is_locked && (
                                                            <span className="text-[10px] uppercase tracking-wide text-gray-400">
                                                                Locked as filed
                                                            </span>
                                                        )}
                                                        {!line.is_locked &&
                                                            (line.orm_status ===
                                                                "flagged" ||
                                                                line.orm_status ===
                                                                    "challenged") && (
                                                                <span className="text-[10px] font-semibold uppercase tracking-wide text-amber-700">
                                                                    Reopened by
                                                                    ORM
                                                                </span>
                                                            )}
                                                    </td>

                                                    <td>
                                                        <ScaleSelect
                                                            label={`Likelihood for ${line.risk_no}`}
                                                            scale={
                                                                methodology.likelihood
                                                            }
                                                            value={
                                                                line.inherent_likelihood
                                                            }
                                                            disabled={
                                                                !canEditLine(
                                                                    line,
                                                                )
                                                            }
                                                            cell={`${rowIndex}:likelihood`}
                                                            onKeyDown={(e) =>
                                                                onCellKeyDown(
                                                                    e,
                                                                    rowIndex,
                                                                    "likelihood",
                                                                )
                                                            }
                                                            onChange={(value) =>
                                                                save(line, {
                                                                    inherent_likelihood:
                                                                        value,
                                                                })
                                                            }
                                                        />
                                                    </td>

                                                    <td>
                                                        <ScaleSelect
                                                            label={`Impact for ${line.risk_no}`}
                                                            scale={
                                                                methodology.impact
                                                            }
                                                            value={
                                                                line.inherent_impact
                                                            }
                                                            disabled={
                                                                !canEditLine(
                                                                    line,
                                                                )
                                                            }
                                                            cell={`${rowIndex}:impact`}
                                                            onKeyDown={(e) =>
                                                                onCellKeyDown(
                                                                    e,
                                                                    rowIndex,
                                                                    "impact",
                                                                )
                                                            }
                                                            onChange={(value) =>
                                                                save(line, {
                                                                    inherent_impact:
                                                                        value,
                                                                })
                                                            }
                                                            title={impactTitle(
                                                                impactCriteria,
                                                                line.inherent_impact,
                                                            )}
                                                        />
                                                    </td>

                                                    {/* Computed: greyed and not editable, per §8.2 */}
                                                    <td className="bg-gray-50">
                                                        <Badge
                                                            score={
                                                                line.inherent_score
                                                            }
                                                            level={
                                                                line.inherent_level
                                                            }
                                                        />
                                                    </td>

                                                    <td>
                                                        <select aria-label={`Control effectiveness for ${line.risk_no}`}
                                                            className="filter-select w-full"
                                                            disabled={
                                                                !canEditLine(
                                                                    line,
                                                                )
                                                            }
                                                            value={
                                                                line.control_effectiveness ??
                                                                ""
                                                            }
                                                            data-cell={`${rowIndex}:control`}
                                                            onKeyDown={(e) =>
                                                                onCellKeyDown(
                                                                    e,
                                                                    rowIndex,
                                                                    "control",
                                                                )
                                                            }
                                                            onChange={(e) =>
                                                                save(line, {
                                                                    control_effectiveness:
                                                                        e.target
                                                                            .value ||
                                                                        null,
                                                                })
                                                            }
                                                        >
                                                            <option value="">
                                                                —
                                                            </option>
                                                            {methodology.controlEffectiveness.map(
                                                                (item) => (
                                                                    <option
                                                                        key={
                                                                            item.label
                                                                        }
                                                                        value={
                                                                            item.label
                                                                        }
                                                                        title={
                                                                            item.description
                                                                        }
                                                                    >
                                                                        {
                                                                            item.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </td>

                                                    <td className="bg-gray-50">
                                                        <Badge
                                                            score={
                                                                line.residual_score
                                                            }
                                                            level={
                                                                line.residual_level
                                                            }
                                                        />
                                                    </td>

                                                    <td className="bg-gray-50">
                                                        {line.appetite_status ? (
                                                            <div>
                                                                <span
                                                                    className={`text-xs ${
                                                                        line.appetite_status.startsWith(
                                                                            "Above",
                                                                        )
                                                                            ? "font-medium text-red-700"
                                                                            : "text-green-700"
                                                                    }`}
                                                                >
                                                                    {
                                                                        line.risk_treatment
                                                                    }
                                                                </span>
                                                                {/*
                                                                 * Step 7. The plan block is offered on the row
                                                                 * that needs it, at the moment the residual
                                                                 * lands above appetite — not saved up as a
                                                                 * surprise when somebody presses submit.
                                                                 */}
                                                                {line.appetite_status.startsWith(
                                                                    "Above",
                                                                ) && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() =>
                                                                            setPlanningFor(
                                                                                planningFor ===
                                                                                    line.id
                                                                                    ? null
                                                                                    : line.id,
                                                                            )
                                                                        }
                                                                        className={`mt-0.5 block text-xs underline ${
                                                                            (
                                                                                line.action_plans ??
                                                                                []
                                                                            )
                                                                                .length ===
                                                                            0
                                                                                ? "text-red-700"
                                                                                : "text-gray-500"
                                                                        }`}
                                                                    >
                                                                        {(
                                                                            line.action_plans ??
                                                                            []
                                                                        )
                                                                            .length ===
                                                                        0
                                                                            ? "Plan needed"
                                                                            : pluralise(line.action_plans.length, "plan")}
                                                                    </button>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <span className="text-xs text-gray-400">
                                                                —
                                                            </span>
                                                        )}
                                                    </td>

                                                    <td>
                                                        {line.prior &&
                                                        line.moved_materially ? (
                                                            // The rationale is a Guided-step field; the
                                                            // marker is the way there from the grid.
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    jumpTo(
                                                                        line.id,
                                                                        "movement",
                                                                    )
                                                                }
                                                                className="text-xs font-medium text-amber-700 underline decoration-dotted hover:text-amber-900"
                                                                title="Moved materially since the last cycle — click to give the rationale."
                                                            >
                                                                {formatScore(
                                                                    line.prior
                                                                        .inherent_score,
                                                                )}{" "}
                                                                ⚠
                                                            </button>
                                                        ) : line.prior ? (
                                                            <span className="text-xs text-gray-500">
                                                                {formatScore(
                                                                    line.prior
                                                                        .inherent_score,
                                                                )}
                                                            </span>
                                                        ) : (
                                                            <span className="text-xs text-gray-400">
                                                                new
                                                            </span>
                                                        )}
                                                    </td>
                                                </tr>

                                                {planningFor === line.id && (
                                                    <tr className="bg-gray-50/70">
                                                        <td
                                                            colSpan={9}
                                                        >
                                                            {/* Held to the grid's visible width, so
                                                                "Add plan" is never off the edge of a
                                                                scrolled grid (2026-10-21 E2E). */}
                                                            <div
                                                                className="sticky left-0"
                                                                style={
                                                                    gridWidth
                                                                        ? {
                                                                              width:
                                                                                  gridWidth -
                                                                                  32,
                                                                          }
                                                                        : undefined
                                                                }
                                                            >
                                                            <ActionPlans
                                                                assessmentId={
                                                                    assessment.id
                                                                }
                                                                line={line}
                                                                owners={owners}
                                                                editable={canEditLine(
                                                                    line,
                                                                )}
                                                                onChanged={
                                                                    afterPlanChange
                                                                }
                                                            />
                                                            </div>
                                                        </td>
                                                    </tr>
                                                )}

                                                {(line.comments ?? []).length >
                                                    0 && (
                                                    <tr className="bg-amber-50/50">
                                                        <td
                                                            colSpan={9}
                                                        >
                                                            <OrmThread
                                                                assessmentId={
                                                                    assessment.id
                                                                }
                                                                line={line}
                                                                canReply={canEditLine(
                                                                    line,
                                                                )}
                                                            />
                                                        </td>
                                                    </tr>
                                                )}
                                            </Fragment>
                                        ))}

                                        {visible.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={9}
                                                    className="py-10 text-center text-sm text-gray-400"
                                                >
                                                    {incompleteOnly
                                                        ? "Every risk has been assessed."
                                                        : "This assessment has no risks."}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ) : (
                        <GuidedStep
                            line={current}
                            index={cursor}
                            total={visible.length}
                            methodology={methodology}
                            impactCriteria={impactCriteria}
                            controlGuidance={controlGuidance}
                            editable={editable}
                            onChange={(changes) =>
                                current && save(current, changes)
                            }
                            onMove={(delta) =>
                                setCursor((c) =>
                                    Math.min(
                                        Math.max(c + delta, 0),
                                        visible.length - 1,
                                    ),
                                )
                            }
                        />
                    )}

                    {mode === "guided" && current && (
                        <div className="mt-4">
                            <ActionPlans
                                assessmentId={assessment.id}
                                line={current}
                                owners={owners}
                                editable={editable}
                                onChanged={afterPlanChange}
                            />
                        </div>
                    )}
                </div>

                {/* Progress and validation (§8.3) */}
                <aside className="space-y-4">
                    <div className="rounded-lg border border-gray-200 bg-white p-4">
                        <p className="text-xs uppercase tracking-wider text-gray-500">
                            Progress
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-gray-800">
                            {completion}%
                        </p>
                        <div className="mt-2 h-2 rounded-full bg-gray-100">
                            <div
                                className="h-2 rounded-full bg-[var(--color-primary)] transition-all"
                                style={{ width: `${completion}%` }}
                            />
                        </div>
                        <p className="mt-2 text-sm text-gray-600">
                            {outstanding.scored} of {outstanding.total} assessed
                        </p>
                    </div>

                    {current && mode === "guided" && (
                        <HeatPosition
                            line={current}
                            methodology={methodology}
                        />
                    )}

                    <div className="rounded-lg border border-gray-200 bg-white p-4">
                        <p className="text-xs uppercase tracking-wider text-gray-500">
                            Before this can be submitted
                        </p>

                        {issueCount === 0 ? (
                            <p className="mt-2 text-sm text-green-700">
                                Nothing outstanding. Every risk is assessed and
                                every one above appetite has a plan.
                            </p>
                        ) : (
                            <ul className="mt-2 space-y-2">
                                {outstanding.issues
                                    .slice(0, 40)
                                    .map((issue, index) => (
                                        <li
                                            key={`${issue.line_id}-${issue.type}-${index}`}
                                            className="text-sm"
                                        >
                                            {/* Click-to-jump, never a generic toast. */}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    jumpTo(
                                                        issue.line_id,
                                                        issue.type,
                                                    )
                                                }
                                                className="text-left text-gray-700 hover:text-[var(--color-primary)]"
                                            >
                                                <span className="font-mono text-xs text-gray-500">
                                                    {issue.risk_no}
                                                </span>{" "}
                                                {issue.message}
                                            </button>
                                        </li>
                                    ))}
                                {issueCount > outstanding.issues.length && (
                                    <li className="text-xs text-gray-500">
                                        …and{" "}
                                        {issueCount - outstanding.issues.length}{" "}
                                        more.
                                    </li>
                                )}
                            </ul>
                        )}
                    </div>

                    <Link
                        href={route("rcsa.cycles.show", assessment.cycle.id)}
                        className="block text-sm text-gray-500 hover:underline"
                    >
                        ← Back to {assessment.cycle.name}
                    </Link>
                </aside>
            </div>

            <ConfirmDialog
                show={confirmingSubmit}
                title={submitTitle}
                message={submitMessage}
                confirmLabel="Submit"
                cancelLabel="Cancel"
                variant="primary"
                processing={submitting}
                onConfirm={confirmSubmit}
                onCancel={() => !submitting && setConfirmingSubmit(false)}
            />
            {confirmDialog}
        </AppLayout>
    );
}

/**
 * What the ORM said about this risk, and the assessor's answer.
 *
 * ON THE WORKSPACE, not only on the review screen. The assessor is the one who
 * has to act on a challenge, and a reopened row with a red mark and no words is
 * the thing that makes a review cycle run twice.
 */
function OrmThread({ assessmentId, line, canReply }) {
    const [reply, setReply] = useState("");
    const [sending, setSending] = useState(false);

    const send = (e) => {
        e.preventDefault();
        setSending(true);
        router.post(
            route("rcsa.assessments.lines.respond", [assessmentId, line.id]),
            { body: reply },
            {
                preserveScroll: true,
                onSuccess: () => setReply(""),
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <div className="text-xs">
            <ol className="space-y-1">
                {line.comments.map((comment) => (
                    <li key={comment.id}>
                        <span className="text-gray-500">
                            {comment.by} ·{" "}
                            {comment.type === "challenge"
                                ? "challenged"
                                : "replied"}{" "}
                            · {comment.at}
                        </span>
                        <p className="text-gray-700">{comment.body}</p>
                        {comment.suggested &&
                            Object.keys(comment.suggested).length > 0 && (
                                <p className="text-gray-500">
                                    Suggested{" "}
                                    {Object.entries(comment.suggested)
                                        .map(
                                            ([key, value]) =>
                                                `${key.replace(/_/g, " ")} → ${value}`,
                                        )
                                        .join(", ")}{" "}
                                    — a suggestion, not a change. Apply it or
                                    say why not.
                                </p>
                            )}
                    </li>
                ))}
            </ol>

            {canReply && (
                <form onSubmit={send} className="mt-2 flex gap-2">
                    <input aria-label="Reply"
                        type="text"
                        className="form-input flex-1 text-xs"
                        placeholder="Reply to the reviewer…"
                        value={reply}
                        onChange={(e) => setReply(e.target.value)}
                    />
                    <button
                        type="submit"
                        className="btn-secondary text-xs"
                        disabled={sending || reply.trim().length < 5}
                    >
                        Reply
                    </button>
                </form>
            )}
        </div>
    );
}

function ScaleSelect({
    label,
    scale,
    value,
    onChange,
    disabled,
    cell,
    onKeyDown,
    title,
}) {
    return (
        // The accessible name is the control and the risk it belongs to. It
        // used to be `title` — the impact criteria, a paragraph — and the
        // likelihood select, which has no title, had no name at all.
        <select aria-label={label}
            className="filter-select w-full"
            disabled={disabled}
            value={value ?? ""}
            data-cell={cell}
            title={title}
            onKeyDown={onKeyDown}
            onChange={(e) =>
                onChange(e.target.value === "" ? null : Number(e.target.value))
            }
        >
            <option value="">—</option>
            {scale.map((item) => (
                <option
                    key={item.value}
                    value={item.value}
                    title={item.description}
                >
                    {item.value} · {item.label}
                </option>
            ))}
        </select>
    );
}

function Badge({ score, level }) {
    if (score === null || score === undefined) {
        return <span className="text-xs text-gray-400">—</span>;
    }

    return (
        <span
            className={`badge ${LEVEL_TONE[level] ?? "bg-gray-100 text-gray-700"}`}
        >
            {formatScore(score)}
        </span>
    );
}

/**
 * The impact criteria as a tooltip — the workbook's guidance, on the selector
 * rather than in a spreadsheet nobody opens (§3.2).
 */
function impactTitle(criteria, value) {
    const row = criteria?.[value];

    if (!row) return undefined;

    return Object.entries(row)
        .map(([dimension, text]) => `${dimension.replace(/_/g, " ")}: ${text}`)
        .join("\n");
}
