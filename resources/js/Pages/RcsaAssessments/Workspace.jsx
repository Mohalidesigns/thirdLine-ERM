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
import { calculate } from "@/lib/rcsa-calc";
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
    const { flash } = usePage().props;

    const [lines, setLines] = useState(initialLines);
    const [outstanding, setOutstanding] = useState(initialOutstanding);
    const [completion, setCompletion] = useState(assessment.completion_pct);
    const [mode, setMode] = useState("grid");
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

    const replaceLine = useCallback((row) => {
        setLines((current) =>
            current.map((line) => (line.id === row.id ? row : line)),
        );
    }, []);

    /**
     * Paint the computed columns locally, then save.
     *
     * The optimistic values come from the same engine the server uses, so in
     * the normal case the row does not visibly change when the response lands.
     * When it does — because somebody else got there first — the server wins
     * and the conflict banner says so.
     */
    const save = useCallback(
        (line, changes) => {
            if (!editable) return;

            const next = { ...line, ...changes };
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

            replaceLine({
                ...next,
                inherent_score: local.inherentScore,
                inherent_level: local.inherentLevel,
                ce_modifier: local.ceModifier,
                residual_score: local.residualScore,
                residual_level: local.residualLevel,
                risk_treatment: local.riskTreatment,
                appetite_status: local.appetiteStatus,
                is_scored: local.isComplete,
            });

            setSaving((s) => ({ ...s, [line.id]: true }));

            window.axios
                .patch(
                    route("rcsa.assessments.lines.update", [
                        assessment.id,
                        line.id,
                    ]),
                    {
                        ...changes,
                        version: line.version,
                    },
                )
                .then(({ data }) => {
                    // The server's figures replace the painted ones. They agree
                    // unless the two implementations have drifted, which is what
                    // the shared truth table exists to prevent.
                    replaceLine(data.line);
                    setCompletion(data.completion_pct);
                    setConflict(null);
                    refreshOutstanding();
                })
                .catch((error) => {
                    const status = error?.response?.status;

                    if (status === 409 || status === 423) {
                        // Show what is actually there now, so the user can see
                        // the other person's answer rather than only be told
                        // that they lost.
                        if (error.response.data?.line)
                            replaceLine(error.response.data.line);
                        setConflict(
                            error.response.data?.message ??
                                "That risk changed while you were working on it.",
                        );
                    } else {
                        setConflict(
                            "That did not save. Check your connection and try again.",
                        );
                        replaceLine(line);
                    }
                })
                .finally(() => setSaving((s) => ({ ...s, [line.id]: false })));
        },
        [assessment.id, editable, methodology, replaceLine],
    );

    const afterPlanChange = useCallback(() => {
        router.reload({ only: ["lines", "outstanding"] });
    }, []);

    const refreshOutstanding = useCallback(() => {
        window.axios
            .get(route("rcsa.assessments.outstanding", assessment.id))
            .then(({ data }) => setOutstanding(data))
            .catch(() => {});
    }, [assessment.id]);

    /* ------------------------------------------------------------------ */
    /*  Keyboard                                                           */
    /* ------------------------------------------------------------------ */

    const gridRef = useRef(null);

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

    const applyBulk = () => {
        if (!bulkValue || selected.length === 0) return;

        if (
            !window.confirm(
                `Set control effectiveness to "${bulkValue}" on ${selected.length} risks? This is a material change and is recorded against each one.`,
            )
        ) {
            return;
        }

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
        );
    };

    const submit = () => {
        if (outstanding.issues.length > 0) {
            window.alert(
                `${outstanding.issues.length} thing${outstanding.issues.length === 1 ? "" : "s"} still to do. They are listed on the right — click one to jump to it.`,
            );

            return;
        }

        if (
            !window.confirm(
                `Submit ${assessment.business_unit}'s assessment for ORM review?\n\nEvery risk is locked, a PDF record of what you filed is kept, and the operational risk team is told.`,
            )
        ) {
            return;
        }

        router.post(
            route("rcsa.assessments.submit", assessment.id),
            {},
            { preserveScroll: true },
        );
    };

    const jumpTo = (lineId) => {
        const index = visible.findIndex((line) => line.id === lineId);

        if (index === -1) return;

        if (mode === "guided") {
            setCursor(index);

            return;
        }

        gridRef.current
            ?.querySelector(`[data-row="${lineId}"]`)
            ?.scrollIntoView({ behavior: "smooth", block: "center" });
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
                        <div className="flex items-center gap-2">
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
                                    disabled={outstanding.issues.length > 0}
                                    title={
                                        outstanding.issues.length > 0
                                            ? `${outstanding.issues.length} thing(s) still to do — see the panel on the right`
                                            : "Submit for ORM review"
                                    }
                                    className="btn-primary text-sm disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Submit for review
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
                        : ` because it is ${assessment.status.replace(/_/g, " ")}.`}
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
                    <textarea
                        className="form-textarea w-full text-sm"
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
                        {assessment.reopened_count} of {lines.length} risks were
                        reopened — the ones the reviewer flagged. The rest stay
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

            <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
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
                                className="rounded border-gray-300"
                            />
                            Incomplete only
                        </label>

                        <span className="text-sm text-gray-500">
                            {visible.length} of {lines.length} risks
                        </span>

                        {selected.length > 0 && editable && (
                            <div className="ml-auto flex items-center gap-2">
                                <span className="text-sm text-gray-600">
                                    {selected.length} selected
                                </span>
                                <select
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
                                                            className="rounded border-gray-300"
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
                                                        <select
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
                                                                            : `${line.action_plans.length} plan(s)`}
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
                                                        {line.prior ? (
                                                            <span
                                                                className={`text-xs ${line.moved_materially ? "font-medium text-amber-700" : "text-gray-500"}`}
                                                                title={
                                                                    line.moved_materially
                                                                        ? "Moved materially since the last cycle — say why in the rationale."
                                                                        : undefined
                                                                }
                                                            >
                                                                {line.prior
                                                                    .inherent_score ??
                                                                    "—"}
                                                                {line.moved_materially
                                                                    ? " ⚠"
                                                                    : ""}
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
                                                            className="px-4 py-3"
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
                                                        </td>
                                                    </tr>
                                                )}

                                                {(line.comments ?? []).length >
                                                    0 && (
                                                    <tr className="bg-amber-50/50">
                                                        <td
                                                            colSpan={9}
                                                            className="px-4 py-2"
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

                        {outstanding.issues.length === 0 ? (
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
                                                    jumpTo(issue.line_id)
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
                                {outstanding.issues.length > 40 && (
                                    <li className="text-xs text-gray-500">
                                        …and {outstanding.issues.length - 40}{" "}
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
                    <input
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
    scale,
    value,
    onChange,
    disabled,
    cell,
    onKeyDown,
    title,
}) {
    return (
        <select
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
            {Number(score).toFixed(Number.isInteger(Number(score)) ? 0 : 1)}
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
