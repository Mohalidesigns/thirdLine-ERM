import { useMemo, useState } from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import AppLayout from "@/Layouts/AppLayout";
import PageHeader from "@thirdline/ui/Components/PageHeader";
import StatusBadge from "@thirdline/ui/Components/StatusBadge";

/**
 * The band colours the workspace uses, so a reviewer reading a residual sees
 * the same red the assessor saw when they set it.
 */
const LEVEL_TONE = {
    very_low: "bg-green-100 text-green-800",
    low: "bg-lime-100 text-lime-800",
    medium: "bg-yellow-100 text-yellow-800",
    high: "bg-orange-100 text-orange-800",
    very_high: "bg-red-100 text-red-800",
};

/**
 * A band level as a person reads it.
 *
 * The stored value is `very_high` — a key, not a label — and printing it raw is
 * how a screen comes to say "very_high" to the Head of ORM.
 */
function levelLabel(level) {
    if (!level) {
        return "";
    }

    const words = level.replace(/_/g, " ");

    return words.charAt(0).toUpperCase() + words.slice(1);
}

function ScoreBadge({ score, level }) {
    if (score === null || score === undefined) {
        return <span className="text-xs text-gray-400">—</span>;
    }

    return (
        <span
            className={`badge ${LEVEL_TONE[level] ?? "bg-gray-100 text-gray-700"}`}
        >
            {Number(score).toFixed(Number.isInteger(Number(score)) ? 0 : 1)} ·{" "}
            {levelLabel(level)}
        </span>
    );
}

/**
 * One assessment under ORM review (§9.2).
 *
 * READ-ONLY, AND VISIBLY SO. Every number on this screen is text. The strongest
 * thing a reviewer can do to a rating is suggest a different one and send the
 * line back — a reviewer who could type over the answer would turn a
 * self-assessment into an ORM assessment.
 *
 * THE DECISION BUTTONS SAY WHAT THEY WILL DO. "Return for rework" names the
 * number of risks it will reopen before it is pressed, because the one thing
 * everybody gets wrong about this workflow is assuming a return reopens
 * everything.
 */
export default function Show({
    assessment,
    lines = [],
    summary = {},
    history = [],
    can = {},
}) {
    const { flash } = usePage().props;
    const [openLine, setOpenLine] = useState(null);
    const [decision, setDecision] = useState(null);

    const flagged = useMemo(
        () =>
            lines.filter(
                (l) =>
                    l.orm_status === "flagged" || l.orm_status === "challenged",
            ),
        [lines],
    );

    const claim = () =>
        router.post(
            route("rcsa.review.claim", assessment.id),
            {},
            { preserveScroll: true },
        );

    const mark = (line, verdict) =>
        router.post(
            route("rcsa.review.lines.mark", [assessment.id, line.id]),
            { verdict },
            { preserveScroll: true },
        );

    return (
        <AppLayout
            header={
                <PageHeader
                    title={`${assessment.business_unit} — ORM review`}
                    subtitle={`${assessment.cycle} · filed by ${assessment.submitted_by ?? "unknown"}${assessment.submitted_at ? ` on ${assessment.submitted_at}` : ""}`}
                    breadcrumbs={[
                        {
                            label: "ORM review",
                            href: route("rcsa.review.index"),
                        },
                        { label: assessment.business_unit ?? "Assessment" },
                    ]}
                    actions={
                        <div className="flex items-center gap-2">
                            <StatusBadge status={assessment.status} />
                            {assessment.has_snapshot && (
                                <a
                                    href={route(
                                        "rcsa.review.snapshot",
                                        assessment.id,
                                    )}
                                    className="btn-secondary text-xs"
                                    title="The PDF as it was filed. Not re-rendered from today's data."
                                >
                                    As filed (PDF)
                                </a>
                            )}
                            {!assessment.is_mine && (
                                <button
                                    type="button"
                                    onClick={claim}
                                    className="btn-primary"
                                >
                                    {assessment.reviewer
                                        ? "Take over"
                                        : "Start review"}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={`Review — ${assessment.business_unit}`} />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
                    {flash.error}
                </div>
            )}

            {assessment.escalated && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                    <strong>Escalated.</strong> {assessment.escalation_reason}
                </div>
            )}

            {/* --- The review summary panel (§9.2) ------------------------- */}
            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                <Tile label="Risks" value={summary.total ?? 0} />
                <Tile label="Accepted" value={summary.accepted ?? 0} />
                <Tile
                    label="Flagged"
                    value={summary.flagged ?? 0}
                    tone={summary.flagged > 0 ? "warn" : undefined}
                />
                <Tile label="Not looked at" value={summary.pending ?? 0} />
                <Tile
                    label="Above appetite"
                    value={summary.above_appetite ?? 0}
                    tone={summary.above_appetite > 0 ? "warn" : undefined}
                />
            </div>

            {/* --- The decisions ------------------------------------------ */}
            <div className="card mb-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    {can.validate && (
                        <button
                            type="button"
                            className="btn-primary"
                            onClick={() => setDecision("validate")}
                        >
                            Validate
                        </button>
                    )}
                    {can.return && (
                        <button
                            type="button"
                            className="btn-secondary"
                            onClick={() => setDecision("return")}
                        >
                            Return for rework
                            {flagged.length > 0 && ` (${flagged.length})`}
                        </button>
                    )}
                    {can.escalate && (
                        <button
                            type="button"
                            className="btn-secondary"
                            onClick={() => setDecision("escalate")}
                        >
                            Escalate
                        </button>
                    )}

                    <span className="ml-auto text-xs text-gray-500">
                        {flagged.length === 0
                            ? "Flag or challenge a risk before returning — a return with nothing flagged reopens nothing."
                            : `Returning reopens ${flagged.length} risk${flagged.length === 1 ? "" : "s"}; the other ${(summary.total ?? 0) - flagged.length} stay locked.`}
                    </span>
                </div>

                {decision && (
                    <DecisionForm
                        kind={decision}
                        assessment={assessment}
                        flaggedCount={flagged.length}
                        onCancel={() => setDecision(null)}
                    />
                )}
            </div>

            {/* --- The read-only grid ------------------------------------- */}
            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Risk</th>
                                <th>Process</th>
                                <th className="text-right">L</th>
                                <th className="text-right">I</th>
                                <th className="text-right">Inherent</th>
                                <th>Control</th>
                                <th className="text-right">Residual</th>
                                <th>Appetite</th>
                                <th>Plans</th>
                                <th>ORM</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((line) => (
                                <RowGroup
                                    key={line.id}
                                    line={line}
                                    assessment={assessment}
                                    open={openLine === line.id}
                                    onToggle={() =>
                                        setOpenLine(
                                            openLine === line.id
                                                ? null
                                                : line.id,
                                        )
                                    }
                                    onMark={mark}
                                    canReview={can.claim}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* --- The workflow history (§9.1) ---------------------------- */}
            {history.length > 0 && (
                <div className="card mt-4 p-4">
                    <h3 className="mb-3 text-sm font-semibold text-gray-700">
                        Workflow history
                    </h3>
                    <ol className="space-y-2">
                        {history.map((event) => (
                            <li key={event.id} className="flex gap-3 text-sm">
                                <span className="w-40 shrink-0 text-xs text-gray-400">
                                    {event.at}
                                </span>
                                <span className="text-gray-700">
                                    <strong>{event.by ?? "System"}</strong>{" "}
                                    {event.event === "escalate"
                                        ? "escalated it"
                                        : `moved it ${event.from ? `from ${event.from.replace(/_/g, " ")} ` : ""}to ${event.to.replace(/_/g, " ")}`}
                                    {event.reason && (
                                        <span className="block text-xs text-gray-500">
                                            “{event.reason}”
                                        </span>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ol>
                </div>
            )}
        </AppLayout>
    );
}

function RowGroup({ line, assessment, open, onToggle, onMark, canReview }) {
    return (
        <>
            <tr className={line.above_appetite ? "bg-red-50/40" : undefined}>
                <td className="text-sm">
                    <span className="font-medium text-gray-700">
                        {line.risk_no}
                    </span>
                    <span className="block max-w-md text-xs text-gray-500">
                        {line.potential_risk}
                    </span>
                </td>
                <td className="text-sm text-gray-600">{line.process_name}</td>
                <td className="text-right text-sm tabular-nums text-gray-600">
                    {line.inherent_likelihood ?? "—"}
                </td>
                <td className="text-right text-sm tabular-nums text-gray-600">
                    {line.inherent_impact ?? "—"}
                </td>
                <td className="text-right text-sm tabular-nums text-gray-700">
                    <ScoreBadge
                        score={line.inherent_score}
                        level={line.inherent_level}
                    />
                </td>
                <td className="text-sm text-gray-600">
                    {line.control_effectiveness ?? "—"}
                </td>
                <td className="text-right text-sm tabular-nums text-gray-700">
                    <ScoreBadge
                        score={line.residual_score}
                        level={line.residual_level}
                    />
                </td>
                <td className="text-xs text-gray-600">
                    {line.appetite_status}
                </td>
                <td className="text-sm text-gray-600">
                    {line.action_plans.length || "—"}
                </td>
                <td>
                    <StatusBadge status={line.orm_status} />
                </td>
                <td className="whitespace-nowrap text-right">
                    {canReview && (
                        <>
                            <button
                                type="button"
                                className="mr-2 text-xs text-green-700 hover:underline"
                                onClick={() => onMark(line, "accepted")}
                            >
                                Accept
                            </button>
                            <button
                                type="button"
                                className="text-xs text-[var(--color-primary)] hover:underline"
                                onClick={onToggle}
                            >
                                {open ? "Close" : "Challenge"}
                            </button>
                        </>
                    )}
                </td>
            </tr>

            {open && (
                <tr>
                    <td colSpan={11} className="bg-gray-50 p-4">
                        <ChallengePanel line={line} assessment={assessment} />
                    </td>
                </tr>
            )}

            {!open && line.comments.length > 0 && (
                <tr>
                    <td
                        colSpan={11}
                        className="bg-gray-50/60 px-4 py-2 text-xs text-gray-600"
                    >
                        {line.comments.length} comment
                        {line.comments.length === 1 ? "" : "s"} — latest: “
                        {line.comments[line.comments.length - 1].body}”
                    </td>
                </tr>
            )}
        </>
    );
}

/**
 * The challenge form: a comment, and optionally the rating the reviewer thinks
 * the line should carry. The suggestion is recorded; nothing applies it.
 */
function ChallengePanel({ line, assessment }) {
    const form = useForm({
        body: "",
        verdict: "challenged",
        suggested: {
            inherent_likelihood: "",
            inherent_impact: "",
            control_effectiveness: "",
        },
    });

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            suggested: Object.fromEntries(
                Object.entries(data.suggested).filter(([, v]) => v !== ""),
            ),
        }));
        form.post(
            route("rcsa.review.lines.challenge", [assessment.id, line.id]),
            {
                preserveScroll: true,
                onSuccess: () => form.reset(),
            },
        );
    };

    return (
        <div className="grid gap-4 md:grid-cols-2">
            <div>
                <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                    Conversation
                </h4>
                {line.comments.length === 0 && (
                    <p className="text-sm text-gray-400">
                        Nothing said about this risk yet.
                    </p>
                )}
                <ol className="space-y-2">
                    {line.comments.map((c) => (
                        <li
                            key={c.id}
                            className="rounded border border-gray-200 bg-white p-2 text-sm"
                        >
                            <span className="text-xs text-gray-500">
                                {c.by} · {c.at} · {c.type}
                            </span>
                            <p className="text-gray-700">{c.body}</p>
                            {c.suggested &&
                                Object.keys(c.suggested).length > 0 && (
                                    <p className="mt-1 text-xs text-gray-500">
                                        Suggested:{" "}
                                        {Object.entries(c.suggested)
                                            .map(
                                                ([k, v]) =>
                                                    `${k.replace(/_/g, " ")} → ${v}`,
                                            )
                                            .join(", ")}
                                    </p>
                                )}
                        </li>
                    ))}
                </ol>

                {line.assessment_rationale && (
                    <p className="mt-3 rounded border border-gray-200 bg-white p-2 text-xs text-gray-600">
                        <strong>Assessor's rationale:</strong>{" "}
                        {line.assessment_rationale}
                    </p>
                )}
            </div>

            <form onSubmit={submit} className="space-y-3">
                <div>
                    <label className="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500">
                        Challenge
                    </label>
                    <textarea
                        className="form-textarea w-full text-sm"
                        rows={3}
                        value={form.data.body}
                        onChange={(e) => form.setData("body", e.target.value)}
                        placeholder="What do you want the assessor to change, and why?"
                    />
                    {form.errors.body && (
                        <p className="mt-1 text-xs text-red-600">
                            {form.errors.body}
                        </p>
                    )}
                </div>

                <div className="grid grid-cols-3 gap-2">
                    <label className="text-xs text-gray-600">
                        <span className="mb-1 block">Suggested L</span>
                        <input
                            type="number"
                            min="1"
                            max="5"
                            className="form-input w-full text-sm"
                            value={form.data.suggested.inherent_likelihood}
                            onChange={(e) =>
                                form.setData("suggested", {
                                    ...form.data.suggested,
                                    inherent_likelihood: e.target.value,
                                })
                            }
                        />
                    </label>
                    <label className="text-xs text-gray-600">
                        <span className="mb-1 block">Suggested I</span>
                        <input
                            type="number"
                            min="1"
                            max="5"
                            className="form-input w-full text-sm"
                            value={form.data.suggested.inherent_impact}
                            onChange={(e) =>
                                form.setData("suggested", {
                                    ...form.data.suggested,
                                    inherent_impact: e.target.value,
                                })
                            }
                        />
                    </label>
                    <label className="text-xs text-gray-600">
                        <span className="mb-1 block">Verdict</span>
                        <select
                            className="form-select w-full text-sm"
                            value={form.data.verdict}
                            onChange={(e) =>
                                form.setData("verdict", e.target.value)
                            }
                        >
                            <option value="challenged">Challenged</option>
                            <option value="flagged">Flagged</option>
                        </select>
                    </label>
                </div>

                <p className="text-xs text-gray-500">
                    A suggestion is recorded, not applied — the assessor changes
                    their answer or defends it.
                </p>

                <button
                    type="submit"
                    className="btn-primary"
                    disabled={form.processing}
                >
                    Record challenge
                </button>
            </form>
        </div>
    );
}

function DecisionForm({ kind, assessment, flaggedCount, onCancel }) {
    const form = useForm({ reason: "" });

    const routes = {
        validate: route("rcsa.review.validate", assessment.id),
        return: route("rcsa.review.return", assessment.id),
        escalate: route("rcsa.review.escalate", assessment.id),
    };

    const copy = {
        validate: {
            title: "Validate this assessment",
            help: "It stays read-only and closes with the cycle. A reason is optional.",
            cta: "Validate",
        },
        return: {
            title: "Return for rework",
            help:
                flaggedCount === 0
                    ? "Nothing is flagged, so this would reopen nothing. Flag or challenge the risks you want changed first."
                    : `${flaggedCount} flagged risk${flaggedCount === 1 ? "" : "s"} will be reopened. Every other risk stays locked as filed.`,
            cta: "Return",
        },
        escalate: {
            title: "Escalate",
            help: "It stays under review and goes to the top of the queue, with the Head of ORM notified.",
            cta: "Escalate",
        },
    }[kind];

    const submit = (e) => {
        e.preventDefault();
        form.post(routes[kind], { preserveScroll: true });
    };

    return (
        <form
            onSubmit={submit}
            className="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4"
        >
            <h4 className="text-sm font-semibold text-gray-700">
                {copy.title}
            </h4>
            <p className="mb-2 text-xs text-gray-600">{copy.help}</p>

            <textarea
                className="form-textarea w-full text-sm"
                rows={3}
                value={form.data.reason}
                onChange={(e) => form.setData("reason", e.target.value)}
                placeholder={
                    kind === "validate"
                        ? "Optional note"
                        : "Say why — the assessor reads this"
                }
            />
            {form.errors.reason && (
                <p className="mt-1 text-xs text-red-600">
                    {form.errors.reason}
                </p>
            )}

            <div className="mt-3 flex gap-2">
                <button
                    type="submit"
                    className="btn-primary"
                    disabled={form.processing}
                >
                    {copy.cta}
                </button>
                <button
                    type="button"
                    className="btn-secondary"
                    onClick={onCancel}
                >
                    Cancel
                </button>
            </div>
        </form>
    );
}

function Tile({ label, value, tone }) {
    return (
        <div
            className={`rounded-lg border p-4 ${tone === "warn" ? "border-red-200 bg-red-50" : "border-gray-200 bg-white"}`}
        >
            <p className="text-xs uppercase tracking-wider text-gray-500">
                {label}
            </p>
            <p
                className={`mt-1 text-2xl font-semibold ${tone === "warn" ? "text-red-700" : "text-gray-800"}`}
            >
                {value}
            </p>
        </div>
    );
}
