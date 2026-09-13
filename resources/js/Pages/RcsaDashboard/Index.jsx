import { Head, Link, router } from "@inertiajs/react";
import AppLayout from "@/Layouts/AppLayout";
import PageHeader from "@thirdline/ui/Components/PageHeader";
import StatusBadge from "@thirdline/ui/Components/StatusBadge";
import HBarChart from "@thirdline/ui/Components/HBarChart";

/**
 * The v1 reporting surface of §10.3 — seven views over one cycle.
 *
 * ONE CYCLE AT A TIME, named at the top. Summing every cycle the bank has run
 * would report the same risk five times and call it five risks.
 *
 * CHARTS ARE CSS, not a charting library — the house convention every shared
 * component here follows. The heat map is a grid of divs for the same reason,
 * and it prints.
 */
const LEVEL_TONE = {
    very_low: "bg-green-100 text-green-900 border-green-200",
    low: "bg-lime-100 text-lime-900 border-lime-200",
    medium: "bg-yellow-100 text-yellow-900 border-yellow-200",
    high: "bg-orange-100 text-orange-900 border-orange-200",
    very_high: "bg-red-100 text-red-900 border-red-200",
};

const LIKELIHOOD = {
    5: "Almost Certain",
    4: "Likely",
    3: "Possible",
    2: "Unlikely",
    1: "Rare",
};
const IMPACT = {
    1: "Very Low",
    2: "Low",
    3: "Medium",
    4: "High",
    5: "Very High",
};

function label(value) {
    if (!value) return "";
    const words = String(value).replace(/_/g, " ");
    return words.charAt(0).toUpperCase() + words.slice(1);
}

export default function Index({
    cycle,
    cycles = [],
    basis,
    headline = {},
    heatMap = { cells: [] },
    drill = null,
    topRisks = [],
    aboveAppetite = [],
    controlEffectiveness = [],
    completion = [],
    actionPlans = {},
    movement = {},
    can = {},
    scopeNotice = null,
}) {
    const go = (patch) =>
        router.get(
            route("rcsa.dashboard.index"),
            { cycle: cycle?.id, basis, ...patch },
            { preserveState: true, replace: true },
        );

    return (
        <AppLayout
            header={
                <PageHeader
                    title="RCSA dashboard"
                    subtitle={
                        cycle
                            ? `${cycle.name} · ${cycle.period_start} → ${cycle.period_end}`
                            : "No cycle yet"
                    }
                    breadcrumbs={[{ label: "RCSA" }, { label: "Dashboard" }]}
                    actions={
                        <div className="flex items-center gap-2">
                            <select
                                className="filter-select"
                                value={cycle?.id ?? ""}
                                onChange={(e) => go({ cycle: e.target.value })}
                            >
                                {cycles.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                            {can.export && (
                                <Link
                                    href={route("rcsa.exports.index")}
                                    className="btn-secondary text-sm"
                                >
                                    Export
                                </Link>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title="RCSA dashboard" />

            {scopeNotice && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    {scopeNotice}
                </div>
            )}

            {!cycle && (
                <div className="card p-10 text-center text-sm text-gray-500">
                    No RCSA cycle has been created yet. The dashboard fills in
                    once a cycle is opened.
                </div>
            )}

            {cycle && (
                <>
                    <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-6">
                        <Tile
                            label="Risks in scope"
                            value={headline.risks ?? 0}
                        />
                        <Tile
                            label="Assessed"
                            value={`${headline.assessed ?? 0} of ${headline.risks ?? 0}`}
                        />
                        <Tile
                            label="Above appetite"
                            value={headline.above_appetite ?? 0}
                            tone={
                                headline.above_appetite > 0 ? "warn" : undefined
                            }
                        />
                        <Tile
                            label="Business units"
                            value={headline.units ?? 0}
                        />
                        <Tile
                            label="Units finished"
                            value={`${headline.units_complete ?? 0} of ${headline.units ?? 0}`}
                        />
                        <Tile
                            label="Average residual"
                            value={headline.average_residual ?? "—"}
                        />
                    </div>

                    <div className="grid gap-4 lg:grid-cols-2">
                        {/* --- 1. The 5×5 heat map, with the toggle §10.3 asks for --- */}
                        <Panel
                            title="Risk heat map"
                            aside={
                                <div className="flex rounded-md border border-gray-300 p-0.5">
                                    {["inherent", "residual"].map((option) => (
                                        <button
                                            key={option}
                                            type="button"
                                            onClick={() =>
                                                go({ basis: option })
                                            }
                                            className={`rounded px-3 py-1 text-xs capitalize ${
                                                basis === option
                                                    ? "bg-[var(--color-primary)] text-white"
                                                    : "text-gray-600"
                                            }`}
                                        >
                                            {option}
                                        </button>
                                    ))}
                                </div>
                            }
                        >
                            <HeatMap
                                cells={heatMap.cells}
                                total={heatMap.total}
                                basis={basis}
                                drill={drill}
                                onDrill={(likelihood, impact) =>
                                    go(
                                        drill &&
                                            drill.likelihood === likelihood &&
                                            drill.impact === impact
                                            ? {
                                                  likelihood: undefined,
                                                  impact: undefined,
                                              }
                                            : { likelihood, impact },
                                    )
                                }
                            />
                        </Panel>

                        {/* --- 2. Top 10 residual risks ------------------------------- */}
                        <Panel title="Top 10 residual risks">
                            {topRisks.length === 0 ? (
                                <Empty>Nothing scored yet.</Empty>
                            ) : (
                                <ol className="space-y-2">
                                    {topRisks.map((risk, index) => (
                                        <li
                                            key={risk.id}
                                            className="flex items-start gap-3 text-sm"
                                        >
                                            <span className="w-5 shrink-0 text-right text-xs text-gray-400">
                                                {index + 1}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <Link
                                                    href={route(
                                                        "rcsa.assessments.show",
                                                        risk.assessment_id,
                                                    )}
                                                    className="font-medium text-gray-700 hover:underline"
                                                >
                                                    {risk.risk_no}
                                                </Link>
                                                <span className="ml-2 text-xs text-gray-500">
                                                    {risk.business_unit}
                                                </span>
                                                <span className="block truncate text-xs text-gray-500">
                                                    {risk.potential_risk}
                                                </span>
                                            </span>
                                            <span
                                                className={`badge shrink-0 border ${LEVEL_TONE[risk.residual_level] ?? "bg-gray-100"}`}
                                            >
                                                {risk.residual_score}
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Panel>

                        {/* --- 3. Above appetite by BU -------------------------------- */}
                        <Panel title="Above appetite, by business unit">
                            {aboveAppetite.length === 0 ? (
                                <Empty>No assessments in this cycle.</Empty>
                            ) : (
                                <HBarChart
                                    data={aboveAppetite.map((row) => ({
                                        label: row.label,
                                        count: row.count,
                                    }))}
                                    color="#dc2626"
                                />
                            )}
                            <p className="mt-3 text-xs text-gray-500">
                                Out of{" "}
                                {aboveAppetite.reduce((n, r) => n + r.total, 0)}{" "}
                                risks assessed across {aboveAppetite.length}{" "}
                                units.
                            </p>
                        </Panel>

                        {/* --- 4. Control effectiveness distribution ------------------ */}
                        <Panel title="Control effectiveness">
                            {controlEffectiveness.every(
                                (row) => row.count === 0,
                            ) ? (
                                <Empty>No control ratings yet.</Empty>
                            ) : (
                                <HBarChart data={controlEffectiveness} />
                            )}
                            <p className="mt-3 text-xs text-gray-500">
                                In the methodology's own order, best to worst —
                                not by size, so the shape means the same thing
                                every quarter.
                            </p>
                        </Panel>

                        {/* --- 5. Completion tracker ---------------------------------- */}
                        <Panel title="Completion by business unit">
                            {completion.length === 0 ? (
                                <Empty>Nothing provisioned.</Empty>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="data-table">
                                        <thead>
                                            <tr>
                                                <th>Unit</th>
                                                <th className="w-40">
                                                    Progress
                                                </th>
                                                <th>Status</th>
                                                <th className="text-right">
                                                    Due in
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {completion.map((row) => (
                                                <tr key={row.id}>
                                                    <td className="text-sm text-gray-700">
                                                        <Link
                                                            href={route(
                                                                "rcsa.assessments.show",
                                                                row.id,
                                                            )}
                                                            className="hover:underline"
                                                        >
                                                            {row.business_unit}
                                                        </Link>
                                                        <span className="block text-xs text-gray-400">
                                                            {row.lines_count}{" "}
                                                            risks
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div className="flex items-center gap-2">
                                                            <div className="h-2 w-24 rounded-full bg-gray-100">
                                                                <div
                                                                    className="h-2 rounded-full bg-[var(--color-primary)]"
                                                                    style={{
                                                                        width: `${row.completion_pct}%`,
                                                                    }}
                                                                />
                                                            </div>
                                                            <span className="text-xs text-gray-600">
                                                                {
                                                                    row.completion_pct
                                                                }
                                                                %
                                                            </span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <StatusBadge
                                                            status={row.status}
                                                        />
                                                    </td>
                                                    <td className="text-right text-sm">
                                                        {row.days_to_due ===
                                                        null ? (
                                                            <span className="text-gray-400">
                                                                —
                                                            </span>
                                                        ) : row.days_to_due <
                                                          0 ? (
                                                            <span className="font-semibold text-red-700">
                                                                {Math.abs(
                                                                    row.days_to_due,
                                                                )}
                                                                d late
                                                            </span>
                                                        ) : (
                                                            <span className="text-gray-600">
                                                                {
                                                                    row.days_to_due
                                                                }
                                                                d
                                                            </span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Panel>

                        {/* --- 6. Action plans by status and ageing ------------------- */}
                        <Panel
                            title="Action plans"
                            aside={
                                can.plans && (
                                    <Link
                                        href={route("rcsa.action-plans.index")}
                                        className="text-xs text-[var(--color-primary)] hover:underline"
                                    >
                                        Open the register
                                    </Link>
                                )
                            }
                        >
                            {actionPlans.total === 0 ? (
                                <Empty>No action plans raised yet.</Empty>
                            ) : (
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <div>
                                        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                                            By status
                                        </p>
                                        <HBarChart
                                            data={actionPlans.by_status}
                                        />
                                    </div>
                                    <div>
                                        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                                            Ageing of the open ones
                                        </p>
                                        <HBarChart
                                            data={actionPlans.ageing}
                                            color="#d97706"
                                        />
                                    </div>
                                </div>
                            )}
                            <p className="mt-3 text-xs text-gray-500">
                                The register outlives the cycle, so this panel
                                is not filtered to {cycle.name}.
                            </p>
                        </Panel>

                        {/* --- 7. Cycle-over-cycle movement --------------------------- */}
                        <Panel
                            title="Movement since the last cycle"
                            aside={
                                <span className="text-xs text-gray-500">
                                    {movement.prior ?? "No prior cycle"}
                                </span>
                            }
                        >
                            <div className="grid grid-cols-4 gap-3 text-center">
                                <Movement
                                    label="Improved"
                                    value={movement.improved}
                                    tone="text-green-700"
                                />
                                <Movement
                                    label="Worsened"
                                    value={movement.worsened}
                                    tone="text-red-700"
                                />
                                <Movement
                                    label="Unchanged"
                                    value={movement.unchanged}
                                    tone="text-gray-600"
                                />
                                <Movement
                                    label="New"
                                    value={movement.new}
                                    tone="text-blue-700"
                                />
                            </div>
                            <p className="mt-3 text-xs text-gray-500">
                                Compared on residual score, through the link the
                                cycle recorded when it was opened. A risk with
                                no prior line counts as new rather than being
                                dropped.
                            </p>
                        </Panel>
                    </div>
                </>
            )}
        </AppLayout>
    );
}

/**
 * The 5×5 grid, drilling through to the assessments behind a cell.
 *
 * §10.3 asks for drill-through, and it is the difference between a picture and
 * a tool: the first question anyone asks of a red cell is "which risks".
 */
function HeatMap({ cells = [], total = 0, basis, drill, onDrill }) {
    if (cells.length === 0) {
        return <Empty>Nothing scored yet.</Empty>;
    }

    const rows = [5, 4, 3, 2, 1];
    const isOpen = (likelihood, impact) =>
        drill && drill.likelihood === likelihood && drill.impact === impact;

    return (
        <div>
            <div className="flex gap-2">
                <div className="flex flex-col justify-around pr-1 text-right text-[10px] text-gray-500">
                    {rows.map((l) => (
                        <span key={l} className="h-12 leading-[3rem]">
                            {LIKELIHOOD[l]}
                        </span>
                    ))}
                </div>

                <div className="flex-1">
                    <div className="grid grid-cols-5 gap-1">
                        {rows.flatMap((likelihood) =>
                            [1, 2, 3, 4, 5].map((impact) => {
                                const cell = cells.find(
                                    (c) =>
                                        c.likelihood === likelihood &&
                                        c.impact === impact,
                                ) ?? {
                                    count: 0,
                                };

                                return (
                                    <button
                                        type="button"
                                        key={`${likelihood}:${impact}`}
                                        disabled={cell.count === 0}
                                        onClick={() =>
                                            onDrill(likelihood, impact)
                                        }
                                        className={`flex h-12 items-center justify-center rounded border text-sm font-semibold transition ${
                                            LEVEL_TONE[cell.level] ??
                                            "border-gray-200 bg-gray-50 text-gray-400"
                                        } ${cell.count === 0 ? "cursor-default opacity-40" : "hover:ring-2 hover:ring-[var(--color-primary)]"} ${
                                            isOpen(likelihood, impact)
                                                ? "ring-2 ring-[var(--color-primary)]"
                                                : ""
                                        }`}
                                        title={`${LIKELIHOOD[likelihood]} × ${IMPACT[impact]} — ${cell.count} risk(s), ${label(cell.level)}`}
                                    >
                                        {cell.count || ""}
                                    </button>
                                );
                            }),
                        )}
                    </div>

                    <div className="mt-1 grid grid-cols-5 gap-1 text-center text-[10px] text-gray-500">
                        {[1, 2, 3, 4, 5].map((i) => (
                            <span key={i}>{IMPACT[i]}</span>
                        ))}
                    </div>
                </div>
            </div>

            <p className="mt-3 text-xs text-gray-500">
                {total} risk{total === 1 ? "" : "s"} plotted on {basis} risk.{" "}
                {basis === "residual" &&
                    "Residual has no likelihood/impact pair of its own, so the likelihood axis is unchanged and the impact axis is the one the residual score implies."}{" "}
                Click a cell to see which risks are in it.
            </p>

            {drill && (
                <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                        {LIKELIHOOD[drill.likelihood]} × {IMPACT[drill.impact]}{" "}
                        — {drill.lines.length} risk
                        {drill.lines.length === 1 ? "" : "s"}
                    </p>

                    {drill.lines.length === 0 ? (
                        <p className="text-sm text-gray-400">
                            Nothing in this cell.
                        </p>
                    ) : (
                        <ul className="space-y-1">
                            {drill.lines.map((line) => (
                                <li
                                    key={line.id}
                                    className="flex items-baseline gap-2 text-sm"
                                >
                                    <Link
                                        href={route(
                                            "rcsa.assessments.show",
                                            line.assessment_id,
                                        )}
                                        className="font-medium text-[var(--color-primary)] hover:underline"
                                    >
                                        {line.risk_no}
                                    </Link>
                                    <span className="text-xs text-gray-500">
                                        {line.business_unit}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-xs text-gray-600">
                                        {line.potential_risk}
                                    </span>
                                    <span className="shrink-0 text-xs text-gray-500">
                                        {line.control_effectiveness ?? "—"} ·
                                        residual {line.residual_score ?? "—"}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}

function Panel({ title, aside, children }) {
    return (
        <div className="card p-4">
            <div className="mb-3 flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold text-gray-700">{title}</h3>
                {aside}
            </div>
            {children}
        </div>
    );
}

function Tile({ label: text, value, tone }) {
    return (
        <div
            className={`rounded-lg border p-4 ${tone === "warn" ? "border-red-200 bg-red-50" : "border-gray-200 bg-white"}`}
        >
            <p className="text-xs uppercase tracking-wider text-gray-500">
                {text}
            </p>
            <p
                className={`mt-1 text-2xl font-semibold ${tone === "warn" ? "text-red-700" : "text-gray-800"}`}
            >
                {value}
            </p>
        </div>
    );
}

function Movement({ label: text, value, tone }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-3">
            <p className={`text-2xl font-semibold ${tone}`}>{value ?? 0}</p>
            <p className="text-xs text-gray-500">{text}</p>
        </div>
    );
}

function Empty({ children }) {
    return <p className="py-8 text-center text-sm text-gray-400">{children}</p>;
}
