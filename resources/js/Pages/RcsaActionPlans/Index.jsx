import { useState } from "react";
import { Head, Link, router, useForm, usePage } from "@inertiajs/react";
import AppLayout from "@/Layouts/AppLayout";
import PageHeader from "@thirdline/ui/Components/PageHeader";
import StatusBadge from "@thirdline/ui/Components/StatusBadge";

/**
 * The action-plan tracking register (§9.3), and the owner dashboard inside it.
 *
 * ONE SCREEN, TWO AUDIENCES. "Mine" is a filter, not a second page — building a
 * separate owner dashboard would be two queries and two places for "overdue" to
 * be decided slightly differently.
 *
 * OVERDUE IS SHOWN FROM THE DATE, NOT FROM THE STATUS. The nightly sweep writes
 * the status; the row computes the same thing from the date, so a plan that
 * came due at midnight reads overdue at 09:00 rather than at whatever time the
 * job happens to run.
 */
export default function Index({
    plans,
    summary = {},
    filters = {},
    statuses = [],
    cycles = [],
}) {
    const { flash } = usePage().props;
    const [expanded, setExpanded] = useState(null);

    const filter = (patch) =>
        router.get(
            route("rcsa.action-plans.index"),
            { ...filters, ...patch },
            { preserveState: true, replace: true },
        );

    const mine =
        filters.mine === true || filters.mine === "1" || filters.mine === 1;

    return (
        <AppLayout
            header={
                <PageHeader
                    title="RCSA action plans"
                    subtitle="What was promised about the risks above appetite, and whether it happened"
                    breadcrumbs={[{ label: "RCSA" }, { label: "Action plans" }]}
                    actions={
                        <div className="flex gap-2">
                            <button
                                type="button"
                                className={
                                    mine ? "btn-primary" : "btn-secondary"
                                }
                                onClick={() =>
                                    filter({ mine: mine ? undefined : 1 })
                                }
                            >
                                {mine ? "Showing yours" : "Show only mine"}
                            </button>
                        </div>
                    }
                />
            }
        >
            <Head title="RCSA action plans" />

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

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                <Tile
                    label={mine ? "Yours" : "All plans"}
                    value={summary.total ?? 0}
                />
                <Tile label="Still open" value={summary.open ?? 0} />
                <Tile
                    label="Overdue"
                    value={summary.overdue ?? 0}
                    tone={summary.overdue > 0 ? "warn" : undefined}
                />
                <Tile label="Due in 14 days" value={summary.due_soon ?? 0} />
                <Tile label="Verified closed" value={summary.verified ?? 0} />
            </div>

            <div className="card mb-4">
                <div className="flex flex-wrap items-end gap-3 p-4">
                    <label className="text-xs text-gray-600">
                        <span className="mb-1 block uppercase tracking-wider">
                            Status
                        </span>
                        <select
                            className="form-select text-sm"
                            value={filters.status ?? ""}
                            onChange={(e) =>
                                filter({ status: e.target.value || undefined })
                            }
                        >
                            <option value="">Any status</option>
                            {statuses.map((s) => (
                                <option key={s} value={s}>
                                    {s.replace(/_/g, " ")}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="text-xs text-gray-600">
                        <span className="mb-1 block uppercase tracking-wider">
                            Cycle
                        </span>
                        <select
                            className="form-select text-sm"
                            value={filters.cycle ?? ""}
                            onChange={(e) =>
                                filter({ cycle: e.target.value || undefined })
                            }
                        >
                            <option value="">Every cycle</option>
                            {cycles.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </label>

                    <button
                        type="button"
                        className={
                            filters.overdue
                                ? "btn-primary text-xs"
                                : "btn-secondary text-xs"
                        }
                        onClick={() =>
                            filter({ overdue: filters.overdue ? undefined : 1 })
                        }
                    >
                        Overdue only
                    </button>

                    <button
                        type="button"
                        className={
                            filters.pending_verification
                                ? "btn-primary text-xs"
                                : "btn-secondary text-xs"
                        }
                        onClick={() =>
                            filter({
                                pending_verification:
                                    filters.pending_verification
                                        ? undefined
                                        : 1,
                            })
                        }
                    >
                        Awaiting verification
                    </button>

                    <button
                        type="button"
                        className="btn-secondary text-xs"
                        onClick={() =>
                            router.get(route("rcsa.action-plans.index"))
                        }
                    >
                        Clear
                    </button>
                </div>
            </div>

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Risk</th>
                                <th>What will be done</th>
                                <th>Owner</th>
                                <th>Due</th>
                                <th className="w-40">Progress</th>
                                <th>Status</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {plans.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="py-10 text-center text-sm text-gray-400"
                                    >
                                        No action plans match.
                                    </td>
                                </tr>
                            )}

                            {plans.data.map((plan) => (
                                <PlanRow
                                    key={plan.id}
                                    plan={plan}
                                    open={expanded === plan.id}
                                    onToggle={() =>
                                        setExpanded(
                                            expanded === plan.id
                                                ? null
                                                : plan.id,
                                        )
                                    }
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {plans.links && plans.links.length > 3 && (
                <div className="mt-4 flex flex-wrap gap-1">
                    {plans.links.map((link, i) => (
                        <Link
                            key={i}
                            href={link.url ?? "#"}
                            className={`rounded px-3 py-1 text-xs ${
                                link.active
                                    ? "bg-[var(--color-primary)] text-white"
                                    : "bg-white text-gray-600"
                            } ${link.url ? "" : "pointer-events-none opacity-40"}`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            )}
        </AppLayout>
    );
}

function PlanRow({ plan, open, onToggle }) {
    const overdue = plan.is_overdue;

    return (
        <>
            <tr className={overdue ? "bg-red-50/40" : undefined}>
                <td className="text-sm">
                    <span className="font-medium text-gray-700">
                        {plan.risk_no}
                    </span>
                    <span className="block text-xs text-gray-500">
                        {plan.business_unit}
                    </span>
                </td>
                <td className="max-w-md text-sm text-gray-600">
                    {plan.control_to_implement}
                </td>
                <td className="text-sm text-gray-600">
                    {plan.owner ?? "Unassigned"}
                </td>
                <td className="whitespace-nowrap text-sm">
                    <span
                        className={
                            overdue
                                ? "font-semibold text-red-700"
                                : "text-gray-600"
                        }
                    >
                        {plan.target_date ?? "—"}
                    </span>
                    {plan.original_target_date &&
                        plan.original_target_date !== plan.target_date && (
                            <span
                                className="block text-xs text-gray-400"
                                title="Extended"
                            >
                                was {plan.original_target_date}
                            </span>
                        )}
                    {plan.days_until_due !== null && (
                        <span className="block text-xs text-gray-400">
                            {plan.days_until_due < 0
                                ? `${Math.abs(plan.days_until_due)} days late`
                                : `in ${plan.days_until_due} days`}
                        </span>
                    )}
                </td>
                <td>
                    <div className="flex items-center gap-2">
                        <div className="h-2 w-24 rounded-full bg-gray-100">
                            <div
                                className="h-2 rounded-full bg-[var(--color-primary)]"
                                style={{ width: `${plan.progress_pct}%` }}
                            />
                        </div>
                        <span className="text-xs text-gray-600">
                            {plan.progress_pct}%
                        </span>
                    </div>
                </td>
                <td>
                    <StatusBadge
                        status={
                            overdue && plan.status !== "overdue"
                                ? "overdue"
                                : plan.status
                        }
                    />
                    {plan.has_pending_extension && (
                        <span className="mt-1 block text-[10px] uppercase tracking-wide text-amber-700">
                            Extension requested
                        </span>
                    )}
                </td>
                <td className="text-right">
                    <button
                        type="button"
                        className="text-xs text-[var(--color-primary)] hover:underline"
                        onClick={onToggle}
                    >
                        {open ? "Close" : "Open"}
                    </button>
                </td>
            </tr>

            {open && (
                <tr>
                    <td colSpan={7} className="bg-gray-50 p-4">
                        <PlanPanel plan={plan} />
                    </td>
                </tr>
            )}
        </>
    );
}

function PlanPanel({ plan }) {
    const progress = useForm({ progress_pct: plan.progress_pct, note: "" });
    const complete = useForm({ completion_evidence: "" });
    const extension = useForm({
        proposed_target_date: "",
        extension_reason: "",
    });
    const verdict = useForm({ accept: true, reason: "" });

    const settled = plan.status === "completed" || plan.status === "closed";

    return (
        <div className="grid gap-6 md:grid-cols-3">
            <div className="text-sm text-gray-600">
                <p className="mb-2">
                    <strong className="text-gray-700">Risk:</strong>{" "}
                    {plan.potential_risk}
                </p>
                <p className="mb-2 text-xs">
                    Residual{" "}
                    <strong>
                        {(plan.residual_level ?? "")
                            .replace(/_/g, " ")
                            .replace(/^./, (c) => c.toUpperCase())}
                    </strong>{" "}
                    · {plan.cycle}
                </p>
                {plan.assessment_id && (
                    <Link
                        href={route(
                            "rcsa.assessments.show",
                            plan.assessment_id,
                        )}
                        className="text-xs text-[var(--color-primary)] hover:underline"
                    >
                        Open the assessment
                    </Link>
                )}

                {plan.completion_evidence && (
                    <p className="mt-3 rounded border border-gray-200 bg-white p-2 text-xs">
                        <strong>Evidence:</strong> {plan.completion_evidence}
                    </p>
                )}
                {plan.verified_at && (
                    <p className="mt-2 text-xs text-green-700">
                        Verified by {plan.verified_by} on {plan.verified_at}
                    </p>
                )}
            </div>

            {/* --- The owner's actions -------------------------------------- */}
            {plan.can.update && !settled && (
                <div className="space-y-4">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            progress.patch(
                                route("rcsa.action-plans.progress", plan.id),
                                { preserveScroll: true },
                            );
                        }}
                        className="space-y-2"
                    >
                        <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500">
                            Progress
                        </label>
                        <input
                            type="range"
                            min="0"
                            max="100"
                            step="5"
                            className="w-full"
                            value={progress.data.progress_pct}
                            onChange={(e) =>
                                progress.setData(
                                    "progress_pct",
                                    Number(e.target.value),
                                )
                            }
                        />
                        <input
                            type="text"
                            className="form-input w-full text-sm"
                            placeholder="What has moved? (optional)"
                            value={progress.data.note}
                            onChange={(e) =>
                                progress.setData("note", e.target.value)
                            }
                        />
                        <button
                            type="submit"
                            className="btn-secondary text-xs"
                            disabled={progress.processing}
                        >
                            Save {progress.data.progress_pct}%
                        </button>
                    </form>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            complete.post(
                                route("rcsa.action-plans.complete", plan.id),
                                { preserveScroll: true },
                            );
                        }}
                        className="space-y-2"
                    >
                        <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500">
                            Mark complete
                        </label>
                        <textarea
                            className="form-textarea w-full text-sm"
                            rows={2}
                            placeholder="What was put in place, and how can somebody else check it?"
                            value={complete.data.completion_evidence}
                            onChange={(e) =>
                                complete.setData(
                                    "completion_evidence",
                                    e.target.value,
                                )
                            }
                        />
                        {complete.errors.completion_evidence && (
                            <p className="text-xs text-red-600">
                                {complete.errors.completion_evidence}
                            </p>
                        )}
                        <button
                            type="submit"
                            className="btn-primary text-xs"
                            disabled={complete.processing}
                        >
                            Complete
                        </button>
                    </form>

                    {!plan.has_pending_extension && (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                extension.post(
                                    route(
                                        "rcsa.action-plans.extension",
                                        plan.id,
                                    ),
                                    { preserveScroll: true },
                                );
                            }}
                            className="space-y-2"
                        >
                            <label className="block text-xs font-semibold uppercase tracking-wider text-gray-500">
                                Ask for more time
                            </label>
                            <input
                                type="date"
                                className="form-input w-full text-sm"
                                value={extension.data.proposed_target_date}
                                onChange={(e) =>
                                    extension.setData(
                                        "proposed_target_date",
                                        e.target.value,
                                    )
                                }
                            />
                            {extension.errors.proposed_target_date && (
                                <p className="text-xs text-red-600">
                                    {extension.errors.proposed_target_date}
                                </p>
                            )}
                            <textarea
                                className="form-textarea w-full text-sm"
                                rows={2}
                                placeholder="Why can the original date not be met?"
                                value={extension.data.extension_reason}
                                onChange={(e) =>
                                    extension.setData(
                                        "extension_reason",
                                        e.target.value,
                                    )
                                }
                            />
                            {extension.errors.extension_reason && (
                                <p className="text-xs text-red-600">
                                    {extension.errors.extension_reason}
                                </p>
                            )}
                            <p className="text-xs text-gray-500">
                                The plan stays due on {plan.target_date} until
                                somebody approves the move.
                            </p>
                            <button
                                type="submit"
                                className="btn-secondary text-xs"
                                disabled={extension.processing}
                            >
                                Request extension
                            </button>
                        </form>
                    )}
                </div>
            )}

            {/* --- The second line's actions -------------------------------- */}
            <div className="space-y-4">
                {plan.has_pending_extension && plan.can.decide_extension && (
                    <div className="rounded border border-amber-200 bg-amber-50 p-3">
                        <p className="text-xs text-amber-900">
                            <strong>Extension requested</strong> to{" "}
                            {plan.proposed_target_date}: {plan.extension_reason}
                        </p>
                        <div className="mt-2 flex gap-2">
                            <button
                                type="button"
                                className="btn-primary text-xs"
                                onClick={() =>
                                    router.post(
                                        route(
                                            "rcsa.action-plans.extension.decide",
                                            plan.id,
                                        ),
                                        { approve: true },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Approve
                            </button>
                            <button
                                type="button"
                                className="btn-secondary text-xs"
                                onClick={() =>
                                    router.post(
                                        route(
                                            "rcsa.action-plans.extension.decide",
                                            plan.id,
                                        ),
                                        { approve: false },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Refuse
                            </button>
                        </div>
                    </div>
                )}

                {plan.status === "completed" && plan.can.verify && (
                    <div className="rounded border border-gray-200 bg-white p-3">
                        <p className="mb-2 text-xs text-gray-600">
                            The owner says this is done. Verifying is the second
                            line accepting that it is.
                        </p>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                className="btn-primary text-xs"
                                onClick={() =>
                                    router.post(
                                        route(
                                            "rcsa.action-plans.verify",
                                            plan.id,
                                        ),
                                        { accept: true },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Verify and close
                            </button>
                            <button
                                type="button"
                                className="btn-secondary text-xs"
                                onClick={() => {
                                    const reason = verdict.data.reason.trim();

                                    if (reason.length === 0) {
                                        verdict.setError(
                                            "reason",
                                            "Say what is missing.",
                                        );

                                        return;
                                    }

                                    router.post(
                                        route(
                                            "rcsa.action-plans.verify",
                                            plan.id,
                                        ),
                                        { accept: false, reason },
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                Send back
                            </button>
                        </div>
                        <textarea
                            className="form-textarea mt-2 w-full text-sm"
                            rows={2}
                            placeholder="If sending back: what is missing?"
                            value={verdict.data.reason}
                            onChange={(e) =>
                                verdict.setData("reason", e.target.value)
                            }
                        />
                        {verdict.errors.reason && (
                            <p className="text-xs text-red-600">
                                {verdict.errors.reason}
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
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
